<?php
namespace AdorationScheduler\Tests\Integration\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Services\CheckInService;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Protects the attendance state transitions and the time-sensitive queries
 * used by both personal check-in links and chapel kiosks.
 */
class CheckInRepositoryIntegrationTest extends AdorationIntegrationTestCase
{
    private SignupsRepository $repo;
    private int $chapel_id;
    private int $schedule_id;
    private int $person_sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SignupsRepository();
        $this->chapel_id = $this->make_chapel('Attendance Test Chapel');
        $this->schedule_id = $this->make_schedule($this->chapel_id);
    }

    public function test_checkin_token_is_stable_and_resolves_only_its_signup(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');

        $first = $this->repo->get_or_create_checkin_token($signup_id);
        $second = $this->repo->get_or_create_checkin_token($signup_id);

        $this->assertNotNull($first);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame($first, $second, 'A printed or emailed bearer token must remain stable.');
        $this->assertSame($signup_id, (int)$this->repo->find_by_checkin_token($first)['id']);
        $this->assertNull($this->repo->find_by_checkin_token(''));
        $this->assertNull($this->repo->find_by_checkin_token(str_repeat('f', 64)));
    }

    public function test_personal_checkin_urls_contain_signup_token_and_normalized_mode(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $token = $this->repo->get_or_create_checkin_token($signup_id);

        $checkin_url = CheckInService::build_checkin_url($signup_id, 'unexpected');
        $checkout_url = CheckInService::build_checkin_url($signup_id, 'out');

        $this->assertStringContainsString('action=adoration_checkin', $checkin_url);
        $this->assertStringContainsString('token=' . $token, $checkin_url);
        $this->assertStringContainsString('mode=in', $checkin_url);
        $this->assertStringContainsString('mode=out', $checkout_url);
        $this->assertNull(CheckInService::build_checkin_url(999999999));
    }

    public function test_kiosk_token_regeneration_invalidates_old_link(): void
    {
        $chapels = new ChapelsRepository();
        $first = $chapels->get_or_create_kiosk_token($this->chapel_id);
        $url = CheckInService::build_kiosk_url($this->chapel_id);
        $second = $chapels->regenerate_kiosk_token($this->chapel_id);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertStringContainsString('action=adoration_kiosk', $url);
        $this->assertStringContainsString('token=' . $first, $url);
        $this->assertNotSame($first, $second);
        $this->assertNull($chapels->find_by_kiosk_token($first));
        $this->assertSame($this->chapel_id, (int)$chapels->find_by_kiosk_token($second)['id']);
        $this->assertNull($chapels->regenerate_kiosk_token(999999999));
        $this->assertNull(CheckInService::build_kiosk_url(999999999));
    }

    public function test_nonexistent_signup_cannot_receive_token_or_attendance(): void
    {
        $missing_id = 999999999;

        $this->assertNull($this->repo->get_or_create_checkin_token($missing_id));
        $this->assertFalse($this->repo->check_in($missing_id, 'kiosk'));
        $this->assertFalse($this->repo->check_out($missing_id));
        $this->assertFalse($this->repo->set_attendance_admin($missing_id, true));
        $this->assertFalse($this->repo->set_attendance_admin($missing_id, false));
    }

    public function test_check_in_is_idempotent_and_preserves_original_method_and_time(): void
    {
        global $wpdb;

        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $this->assertTrue($this->repo->check_in($signup_id, 'kiosk'));

        $original_time = '2026-07-18 09:15:00';
        $wpdb->update(
            $wpdb->prefix . 'adoration_signups',
            ['checked_in_at' => $original_time],
            ['id' => $signup_id]
        );

        $this->assertTrue($this->repo->check_in($signup_id, 'admin'));
        $row = $this->repo->find($signup_id);
        $this->assertSame($original_time, $row['checked_in_at']);
        $this->assertSame('kiosk', $row['check_in_method']);
    }

    public function test_invalid_check_in_method_falls_back_to_self(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');

        $this->assertTrue($this->repo->check_in($signup_id, 'invented-method'));
        $this->assertSame('self', $this->repo->find($signup_id)['check_in_method']);
    }

    public function test_checkout_requires_checkin_and_is_idempotent(): void
    {
        global $wpdb;

        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $this->assertFalse($this->repo->check_out($signup_id));

        $this->assertTrue($this->repo->check_in($signup_id));
        $this->assertTrue($this->repo->check_out($signup_id));

        $original_time = '2026-07-18 10:15:00';
        $wpdb->update(
            $wpdb->prefix . 'adoration_signups',
            ['checked_out_at' => $original_time],
            ['id' => $signup_id]
        );

        $this->assertTrue($this->repo->check_out($signup_id));
        $this->assertSame($original_time, $this->repo->find($signup_id)['checked_out_at']);
    }

    public function test_admin_can_mark_present_and_clear_all_attendance_fields(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');

        $this->assertTrue($this->repo->set_attendance_admin($signup_id, true));
        $present = $this->repo->find($signup_id);
        $this->assertNotEmpty($present['checked_in_at']);
        $this->assertSame('admin', $present['check_in_method']);

        $this->assertTrue($this->repo->check_out($signup_id));
        $this->assertTrue($this->repo->set_attendance_admin($signup_id, false));
        $absent = $this->repo->find($signup_id);
        $this->assertNull($absent['checked_in_at']);
        $this->assertNull($absent['checked_out_at']);
        $this->assertNull($absent['check_in_method']);
    }

    public function test_current_kiosk_list_is_limited_to_current_chapel_and_time(): void
    {
        $current_id = $this->make_signup('-5 minutes', '+55 minutes');
        $this->make_signup('+2 hours', '+3 hours');

        $other_chapel = $this->make_chapel('Other Chapel');
        $other_schedule = $this->make_schedule($other_chapel, ['slug' => 'other-' . wp_generate_password(8, false, false)]);
        $this->make_signup('-5 minutes', '+55 minutes', $other_chapel, $other_schedule);

        $rows = $this->repo->list_current_for_chapel($this->chapel_id);

        $this->assertCount(1, $rows);
        $this->assertSame($current_id, (int)$rows[0]['id']);
        $this->assertSame([], $this->repo->list_current_for_chapel(0));
    }

    public function test_no_show_query_obeys_grace_and_excludes_resolved_rows(): void
    {
        global $wpdb;

        $late_id = $this->make_signup('-45 minutes', '+15 minutes');
        $inside_grace_id = $this->make_signup('-10 minutes', '+50 minutes');
        $checked_in_id = $this->make_signup('-45 minutes', '+15 minutes');
        $already_alerted_id = $this->make_signup('-45 minutes', '+15 minutes');

        $this->repo->check_in($checked_in_id, 'self');
        $wpdb->update(
            $wpdb->prefix . 'adoration_signups',
            ['no_show_alert_sent_at' => current_time('mysql')],
            ['id' => $already_alerted_id]
        );

        $ids = array_map('intval', array_column($this->repo->find_unchecked_in_past_grace(30), 'id'));

        $this->assertContains($late_id, $ids);
        $this->assertNotContains($inside_grace_id, $ids);
        $this->assertNotContains($checked_in_id, $ids);
        $this->assertNotContains($already_alerted_id, $ids);
    }

    public function test_mark_no_show_alert_sent_deduplicates_and_ignores_invalid_ids(): void
    {
        $signup_id = $this->make_signup('-45 minutes', '+15 minutes');

        $this->repo->mark_no_show_alert_sent([0, $signup_id, $signup_id, -2]);

        $this->assertNotEmpty($this->repo->find($signup_id)['no_show_alert_sent_at']);
        $this->repo->mark_no_show_alert_sent([]);
    }

    private function make_signup(
        string $start_modifier,
        string $end_modifier,
        ?int $chapel_id = null,
        ?int $schedule_id = null
    ): int {
        $chapel_id = $chapel_id ?? $this->chapel_id;
        $schedule_id = $schedule_id ?? $this->schedule_id;
        $start = new \DateTimeImmutable($start_modifier, wp_timezone());
        $end = new \DateTimeImmutable($end_modifier, wp_timezone());
        $person_id = $this->make_person([
            'email' => 'attendance-' . (++$this->person_sequence) . '-' . wp_generate_password(8, false, false) . '@example.test',
        ]);
        $slot_id = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ]);

        return $this->repo->create([
            'person_id' => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id' => $slot_id,
            'date' => $start->format('Y-m-d'),
            'status' => 'confirmed',
            'created_via' => 'admin',
        ]);
    }
}
