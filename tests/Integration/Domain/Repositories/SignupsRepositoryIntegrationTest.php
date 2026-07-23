<?php
namespace AdorationScheduler\Tests\Integration\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\EmailLogRepository;
use AdorationScheduler\Services\ReminderScheduler;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Exercises SignupsRepository's real JOIN and GROUP BY queries against a
 * real MySQL install with real fixture rows — the exact gap
 * tests/Integration/README.md calls out as unverifiable by the unit suite,
 * which fakes $wpdb and can't tell if a JOIN condition or GROUP BY column
 * list is actually correct SQL, let alone whether it returns the right rows.
 */
class SignupsRepositoryIntegrationTest extends AdorationIntegrationTestCase
{
    public function test_list_for_slot_joins_person_fields(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $slot_id     = $this->make_slot($schedule_id, $chapel_id);
        $person_id   = $this->make_person([
            'first_name' => 'Frances',
            'last_name'  => 'Xavier',
            'email'      => 'frances-xavier@example.test',
        ]);

        $repo = new SignupsRepository();
        $signup_id = $repo->create([
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => gmdate('Y-m-d', strtotime('+1 day')),
            'status'      => 'confirmed',
            'created_via' => 'admin',
        ]);

        $this->assertGreaterThan(0, $signup_id, 'Expected the signup to insert successfully.');

        $rows = $repo->list_for_slot($slot_id, true);

        $this->assertCount(1, $rows);
        $this->assertSame('Frances', $rows[0]['first_name']);
        $this->assertSame('Xavier', $rows[0]['last_name']);
        $this->assertSame('frances-xavier@example.test', $rows[0]['email']);
        $this->assertSame('confirmed', $rows[0]['status']);
    }

    public function test_create_rejects_duplicate_signup_for_same_person_slot_date(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $slot_id     = $this->make_slot($schedule_id, $chapel_id);
        $person_id   = $this->make_person();
        $date        = gmdate('Y-m-d', strtotime('+1 day'));

        $repo = new SignupsRepository();

        $first = $repo->create([
            'person_id' => $person_id, 'schedule_id' => $schedule_id,
            'slot_id' => $slot_id, 'date' => $date, 'status' => 'confirmed',
        ]);
        $duplicate = $repo->create([
            'person_id' => $person_id, 'schedule_id' => $schedule_id,
            'slot_id' => $slot_id, 'date' => $date, 'status' => 'confirmed',
        ]);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $duplicate, 'A second confirmed signup for the same person+slot+date should be rejected.');
        $this->assertCount(1, $repo->list_for_slot($slot_id, true));
    }

    public function test_hours_report_by_person_aggregates_confirmed_signups_via_real_join(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $date        = gmdate('Y-m-d', strtotime('+1 day'));

        // Two one-hour slots for the same person on the same schedule.
        $slot_a = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ]);
        $slot_b = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => '10:00:00', 'end_time' => '11:00:00',
        ]);

        $person_id = $this->make_person([
            'first_name' => 'Ignatius',
            'last_name'  => 'Loyola',
            'email'      => 'ignatius-loyola@example.test',
        ]);

        $repo = new SignupsRepository();
        $repo->create(['person_id' => $person_id, 'schedule_id' => $schedule_id, 'slot_id' => $slot_a, 'date' => $date, 'status' => 'confirmed']);
        $repo->create(['person_id' => $person_id, 'schedule_id' => $schedule_id, 'slot_id' => $slot_b, 'date' => $date, 'status' => 'confirmed']);

        $rows = $repo->hours_report_by_person($schedule_id, $date, $date);

        $this->assertCount(1, $rows, 'Expected one aggregated row for the one person with confirmed signups.');
        $this->assertSame($person_id, (int)$rows[0]['person_id']);
        $this->assertSame('Ignatius', $rows[0]['first_name']);
        $this->assertSame(2, $rows[0]['signup_count']);
        $this->assertSame(120, $rows[0]['total_minutes'], 'Two one-hour slots should sum to 120 minutes via the real TIMESTAMPDIFF/TIMEDIFF SQL.');
    }

    /**
     * ✅ Full end-to-end signup path (2026-07-21): the one gap
     * tests/Integration/README.md flagged as "real end-to-end signup flows"
     * — create() itself fires a confirmation email AND schedules the 24h
     * reminder for a real 'confirmed' signup, not just persisting the row.
     * Verified via EmailLogRepository (what NotificationService::send_mail()
     * actually records, real or not — see its own docblock) and Action
     * Scheduler's real schedule, rather than reaching into wp_mail()'s
     * internals.
     */
    public function test_create_fires_confirmation_email_and_schedules_reminder(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $date        = gmdate('Y-m-d', strtotime('+10 days'));
        $slot_id     = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ]);
        $person_id = $this->make_person([
            'first_name' => 'Kateri',
            'last_name'  => 'Tekakwitha',
            'email'      => 'kateri-tekakwitha@example.test',
        ]);

        $repo = new SignupsRepository();
        $signup_id = $repo->create([
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => $date,
            'status'      => 'confirmed',
            'created_via' => 'admin',
        ]);

        $this->assertGreaterThan(0, $signup_id);

        $log = (new EmailLogRepository())->query(['type' => 'signup_confirmation']);
        $this->assertSame(1, $log['total'], 'Expected exactly one confirmation email attempt logged.');
        $this->assertSame('kateri-tekakwitha@example.test', $log['rows'][0]['to_email']);

        $expected_slot_ts = strtotime($date . ' 09:00:00 UTC');
        $scheduled_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], 'adoration-scheduler');
        $this->assertSame($expected_slot_ts - DAY_IN_SECONDS, (int)$scheduled_ts, 'Expected the 24h reminder to be scheduled off the real slot time.');
    }

    /**
     * The opposite of the test above: create()'s confirmation email is
     * deliberately skipped when created_via is 'standing_commitment' — the
     * commitment itself already got one dedicated confirmation email (see
     * SignupsRepository::create()'s docblock on the duplicate-email bug
     * this guards against). Reminder scheduling is unaffected.
     */
    public function test_create_skips_confirmation_email_for_standing_commitment_signups(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $date        = gmdate('Y-m-d', strtotime('+10 days'));
        $slot_id     = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ]);
        $person_id = $this->make_person([
            'email' => 'standing-commitment-adorer@example.test',
        ]);

        $repo = new SignupsRepository();
        $signup_id = $repo->create([
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => $date,
            'status'      => 'confirmed',
            'created_via' => 'standing_commitment',
        ]);

        $this->assertGreaterThan(0, $signup_id);

        $log = (new EmailLogRepository())->query(['type' => 'signup_confirmation']);
        $this->assertSame(0, $log['total'], 'Standing-commitment auto-fills must not send their own per-date confirmation email.');

        $scheduled_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], 'adoration-scheduler');
        $this->assertNotFalse($scheduled_ts, 'The 24h reminder should still be scheduled even though the confirmation email is skipped.');
    }
}
