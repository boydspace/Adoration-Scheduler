<?php
namespace AdorationScheduler\Tests\Integration\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Domain\Repositories\AttendanceRepository;
use AdorationScheduler\Core\Installer;
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
        $this->chapel_id = $this->make_chapel('Attendance Test Chapel ' . wp_generate_password(8, false, false));
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
        $this->assertNull((new AttendanceRepository())->find_by_signup($signup_id));
    }

    public function test_checkin_and_checkout_are_mirrored_to_2_0_attendance(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $attendance = new AttendanceRepository();

        $this->assertTrue($this->repo->check_in($signup_id, 'kiosk'));
        $present = $attendance->find_by_signup($signup_id);
        $this->assertNotNull($present);
        $this->assertSame('scheduled', $present['attendance_type']);
        $this->assertSame('present', $present['status']);
        $this->assertSame('kiosk', $present['check_in_method']);
        $this->assertSame((int)$this->repo->find($signup_id)['person_id'], (int)$present['attendee_person_id']);

        $this->assertTrue($this->repo->check_out($signup_id));
        $completed = $attendance->find_by_signup($signup_id);
        $this->assertSame('completed', $completed['status']);
        $this->assertNotEmpty($completed['checked_out_at']);
        $this->assertSame($present['id'], $completed['id'], 'Checkout must update the existing attendance record.');
    }

    public function test_legacy_migration_is_idempotent_and_preserves_2_0_edits(): void
    {
        global $wpdb;

        $signup_id = $this->make_signup('-45 minutes', '+15 minutes');
        $legacy_time = '2026-07-18 09:15:00';
        $wpdb->update(
            $wpdb->prefix . 'adoration_signups',
            ['checked_in_at' => $legacy_time, 'check_in_method' => 'admin'],
            ['id' => $signup_id]
        );

        Installer::migrate_legacy_attendance();
        $attendance = new AttendanceRepository();
        $migrated = $attendance->find_by_signup($signup_id);
        $this->assertNotNull($migrated);
        $this->assertSame($legacy_time, $migrated['checked_in_at']);
        $this->assertSame('1', $migrated['migrated_from_legacy']);

        $wpdb->update(
            $wpdb->prefix . 'adoration_attendance',
            ['notes' => 'Coordinator verified this record.'],
            ['id' => (int)$migrated['id']]
        );
        Installer::migrate_legacy_attendance();

        $after_second_install = $attendance->find_by_signup($signup_id);
        $this->assertSame($migrated['id'], $after_second_install['id']);
        $this->assertSame('Coordinator verified this record.', $after_second_install['notes']);
    }

    public function test_model_records_substitute_without_changing_scheduled_person(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $signup = $this->repo->find($signup_id);
        $substitute_id = $this->make_person(['email' => 'substitute-' . wp_generate_password(8, false, false) . '@example.test']);
        $attendance = new AttendanceRepository();

        $attendance_id = $attendance->record([
            'signup_id' => $signup_id,
            'slot_id' => (int)$signup['slot_id'],
            'schedule_id' => (int)$signup['schedule_id'],
            'chapel_id' => $this->chapel_id,
            'scheduled_person_id' => (int)$signup['person_id'],
            'attendee_person_id' => $substitute_id,
            'attendance_type' => 'substitute',
            'check_in_method' => 'kiosk',
        ]);

        $this->assertGreaterThan(0, $attendance_id);
        $row = $attendance->find_by_signup($signup_id);
        $this->assertSame('substitute', $row['attendance_type']);
        $this->assertSame((int)$signup['person_id'], (int)$row['scheduled_person_id']);
        $this->assertSame($substitute_id, (int)$row['attendee_person_id']);

        $this->repo->check_in($signup_id, 'self');
        $after_legacy_tap = $attendance->find_by_signup($signup_id);
        $this->assertSame('substitute', $after_legacy_tap['attendance_type']);
        $this->assertSame($substitute_id, (int)$after_legacy_tap['attendee_person_id']);
    }

    public function test_claimed_replacement_checks_in_as_substitute_and_rotates_old_link(): void
    {
        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $original = $this->repo->find($signup_id);
        $original_person_id = (int)$original['person_id'];
        $old_token = $this->repo->get_or_create_checkin_token($signup_id);
        $substitute_id = $this->make_person([
            'first_name' => 'Substitute',
            'last_name' => 'Adorer',
            'email' => 'claimed-substitute-' . wp_generate_password(8, false, false) . '@example.test',
        ]);

        $this->assertTrue($this->repo->mark_needs_replacement($signup_id, $original_person_id, 'Please cover this hour.'));
        $this->assertSame('ok', $this->repo->claim_replacement($signup_id, $substitute_id));

        $claimed = $this->repo->find($signup_id);
        $this->assertSame($substitute_id, (int)$claimed['person_id']);
        $this->assertSame($original_person_id, (int)$claimed['replacement_requested_by']);
        $this->assertSame($substitute_id, (int)$claimed['replacement_claimed_by']);
        $this->assertNull($this->repo->find_by_checkin_token($old_token), 'The original adorer\'s emailed link must stop working after reassignment.');
        $new_token = $this->repo->get_or_create_checkin_token($signup_id);
        $this->assertNotSame($old_token, $new_token);

        $roster = $this->repo->list_current_for_chapel($this->chapel_id);
        $this->assertCount(1, $roster);
        $this->assertSame('Substitute', $roster[0]['person_first_name']);
        $this->assertSame($original_person_id, (int)$roster[0]['replacement_requested_by']);
        $this->assertSame($substitute_id, (int)$roster[0]['replacement_claimed_by']);

        $this->assertTrue($this->repo->check_in($signup_id, 'kiosk'));
        $attendance = (new AttendanceRepository())->find_by_signup($signup_id);
        $this->assertSame('substitute', $attendance['attendance_type']);
        $this->assertSame($original_person_id, (int)$attendance['scheduled_person_id']);
        $this->assertSame($substitute_id, (int)$attendance['attendee_person_id']);
        $this->assertSame('kiosk', $attendance['check_in_method']);

        $this->assertTrue($this->repo->check_out($signup_id));
        $completed = (new AttendanceRepository())->find_by_signup($signup_id);
        $this->assertSame('substitute', $completed['attendance_type']);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame($substitute_id, (int)$completed['attendee_person_id']);
    }

    public function test_model_records_multiple_guests_without_signups(): void
    {
        global $wpdb;

        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $signup = $this->repo->find($signup_id);
        $attendance = new AttendanceRepository();
        $base = [
            'slot_id' => (int)$signup['slot_id'],
            'schedule_id' => (int)$signup['schedule_id'],
            'chapel_id' => $this->chapel_id,
            'attendance_type' => 'guest',
            'check_in_method' => 'kiosk',
        ];

        $first_id = $attendance->record($base + ['guest_name' => 'Guest One']);
        $second_id = $attendance->record($base + ['guest_name' => 'Guest Two']);

        $this->assertGreaterThan(0, $first_id);
        $this->assertGreaterThan(0, $second_id);
        $this->assertNotSame($first_id, $second_id);
        $count = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE slot_id = %d AND attendance_type = 'guest'",
                $wpdb->prefix . 'adoration_attendance',
                (int)$signup['slot_id']
            )
        );
        $this->assertSame(2, $count);
        $this->assertSame(0, $attendance->record($base + ['guest_name' => '']));
    }

    public function test_kiosk_guest_checkin_requires_current_slot_at_token_chapel(): void
    {
        global $wpdb;

        $signup_id = $this->make_signup('-5 minutes', '+55 minutes');
        $signup = $this->repo->find($signup_id);
        $token = (new ChapelsRepository())->get_or_create_kiosk_token($this->chapel_id);

        $this->assertSame(
            'ok',
            CheckInService::record_kiosk_guest($token, (int)$signup['slot_id'], '  Visiting Adorer  ')
        );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE slot_id = %d AND attendance_type = 'guest' LIMIT 1",
                $wpdb->prefix . 'adoration_attendance',
                (int)$signup['slot_id']
            ),
            ARRAY_A
        );
        $this->assertIsArray($row);
        $this->assertSame('Visiting Adorer', $row['guest_name']);
        $this->assertNull($row['signup_id']);
        $this->assertNull($row['attendee_person_id']);
        $this->assertSame('kiosk', $row['check_in_method']);

        $this->assertSame('invalid_name', CheckInService::record_kiosk_guest($token, (int)$signup['slot_id'], ''));
        $this->assertSame('invalid_token', CheckInService::record_kiosk_guest('wrong-token', (int)$signup['slot_id'], 'Guest'));

        $other_chapel = $this->make_chapel('Guest Wrong Chapel ' . wp_generate_password(8, false, false));
        $other_schedule = $this->make_schedule($other_chapel, ['slug' => 'guest-other-' . wp_generate_password(8, false, false)]);
        $other_signup_id = $this->make_signup('-5 minutes', '+55 minutes', $other_chapel, $other_schedule);
        $other_signup = $this->repo->find($other_signup_id);
        $this->assertSame(
            'invalid_slot',
            CheckInService::record_kiosk_guest($token, (int)$other_signup['slot_id'], 'Wrong Chapel Guest')
        );

        $future_signup_id = $this->make_signup('+2 hours', '+3 hours');
        $future_signup = $this->repo->find($future_signup_id);
        $this->assertSame(
            'invalid_slot',
            CheckInService::record_kiosk_guest($token, (int)$future_signup['slot_id'], 'Future Guest')
        );
    }

    public function test_guest_can_check_into_completely_uncovered_current_hour(): void
    {
        global $wpdb;

        $start = new \DateTimeImmutable('-5 minutes', wp_timezone());
        $end = new \DateTimeImmutable('+55 minutes', wp_timezone());
        $slot_id = $this->make_slot($this->schedule_id, $this->chapel_id, [
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ]);
        $token = (new ChapelsRepository())->get_or_create_kiosk_token($this->chapel_id);

        $this->assertSame([], $this->repo->list_current_for_chapel($this->chapel_id));
        $this->assertSame('ok', CheckInService::record_kiosk_guest($token, $slot_id, 'Walk-in Guest'));
        $count = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE slot_id = %d AND attendance_type = 'guest'",
                $wpdb->prefix . 'adoration_attendance',
                $slot_id
            )
        );
        $this->assertSame(1, $count);
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
