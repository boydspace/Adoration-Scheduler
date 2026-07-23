<?php
namespace AdorationScheduler\Tests\Integration\Services;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Services\ReminderScheduler;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Proves ReminderScheduler::schedule_24h() actually computes the right
 * Action Scheduler timestamp from a person's own reminder_lead_hours
 * preference (not just the historical hardcoded 24h), and that the
 * unschedule/reschedule pair ReminderPreferencesHandler relies on when a
 * preference changes actually moves a pending reminder — both added in the
 * same pass that had zero test coverage before this.
 */
class ReminderSchedulerTest extends AdorationIntegrationTestCase
{
    private const AS_GROUP = 'adoration-scheduler';

    private function make_far_future_signup(int $lead_hours = 24): array
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $date        = gmdate('Y-m-d', strtotime('+10 days'));
        $slot_id     = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ]);
        $person_id = $this->make_person();
        (new PersonsRepository())->set_reminder_preferences($person_id, true, false, $lead_hours);

        $signup_id = (new SignupsRepository())->create([
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => $date,
            'status'      => 'confirmed',
            'created_via' => 'admin',
        ]);
        $this->assertGreaterThan(0, $signup_id);

        $expected_slot_ts = strtotime($date . ' 09:00:00 UTC');

        return [$signup_id, $person_id, $expected_slot_ts];
    }

    public function test_schedule_24h_uses_the_persons_default_lead_time(): void
    {
        [$signup_id, , $expected_slot_ts] = $this->make_far_future_signup(24);

        (new ReminderScheduler())->schedule_24h($signup_id);

        $scheduled_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);

        $this->assertNotFalse($scheduled_ts, 'Expected a reminder to be scheduled.');
        $this->assertSame($expected_slot_ts - DAY_IN_SECONDS, (int)$scheduled_ts);
    }

    public function test_schedule_24h_uses_a_custom_lead_time(): void
    {
        [$signup_id, , $expected_slot_ts] = $this->make_far_future_signup(6);

        (new ReminderScheduler())->schedule_24h($signup_id);

        $scheduled_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);

        $this->assertNotFalse($scheduled_ts);
        $this->assertSame($expected_slot_ts - (6 * HOUR_IN_SECONDS), (int)$scheduled_ts);
    }

    /**
     * Mirrors what ReminderPreferencesHandler does after saving a new
     * preference: unschedule_for_signup() then schedule_24h() again, so an
     * already-pending reminder actually moves rather than firing at the
     * stale offset.
     */
    public function test_rescheduling_after_a_lead_time_change_moves_the_pending_reminder(): void
    {
        [$signup_id, $person_id, $expected_slot_ts] = $this->make_far_future_signup(6);

        $scheduler = new ReminderScheduler();
        $scheduler->schedule_24h($signup_id);

        $first_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);
        $this->assertSame($expected_slot_ts - (6 * HOUR_IN_SECONDS), (int)$first_ts);

        (new PersonsRepository())->set_reminder_preferences($person_id, true, false, 48);
        ReminderScheduler::unschedule_for_signup($signup_id);
        $scheduler->schedule_24h($signup_id);

        $rescheduled_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);
        $this->assertNotFalse($rescheduled_ts);
        $this->assertSame($expected_slot_ts - (48 * HOUR_IN_SECONDS), (int)$rescheduled_ts);
        $this->assertNotEquals($first_ts, $rescheduled_ts, 'The reminder must actually move, not just add a second one.');
    }

    public function test_does_not_schedule_when_the_computed_reminder_time_is_already_past(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);

        // Slot is only 2 hours away, but the default 24h lead time would
        // compute a reminder time 22 hours in the past.
        $future_ts = time() + (2 * HOUR_IN_SECONDS);
        $date      = gmdate('Y-m-d', $future_ts);
        $start     = gmdate('H:i:s', $future_ts);

        $slot_id   = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => $start, 'end_time' => gmdate('H:i:s', $future_ts + 3600),
        ]);
        $person_id = $this->make_person();

        $signup_id = (new SignupsRepository())->create([
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => $date,
            'status'      => 'confirmed',
            'created_via' => 'admin',
        ]);
        $this->assertGreaterThan(0, $signup_id);

        (new ReminderScheduler())->schedule_24h($signup_id);

        $scheduled_ts = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);
        $this->assertFalse($scheduled_ts, 'No reminder should be scheduled when the computed time is already in the past.');
    }
}
