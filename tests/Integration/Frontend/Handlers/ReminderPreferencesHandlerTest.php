<?php
namespace AdorationScheduler\Tests\Integration\Frontend\Handlers;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Frontend\Handlers\ReminderPreferencesHandler;
use AdorationScheduler\Services\ReminderScheduler;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Exercises ReminderPreferencesHandler::save_and_reschedule() — the save
 * logic pulled out of handle() specifically so it's testable (handle()
 * itself always ends in exit(), see that method's docblock).
 */
class ReminderPreferencesHandlerTest extends AdorationIntegrationTestCase
{
    private const AS_GROUP = 'adoration-scheduler';

    public function test_saves_channel_and_lead_time_preferences(): void
    {
        $person_id = $this->make_person();

        $ok = ReminderPreferencesHandler::save_and_reschedule($person_id, false, true, 12);
        $this->assertTrue($ok);

        $person = (new PersonsRepository())->find($person_id);
        $repo = new PersonsRepository();
        $this->assertFalse($repo->is_email_reminder_opt_in($person));
        $this->assertTrue($repo->is_sms_reminder_opt_in($person));
        $this->assertSame(12, $repo->get_reminder_lead_hours($person));
    }

    /**
     * The dropdown only offers specific values — a tampered/garbage POST
     * value must fall back to 24, not be stored verbatim.
     */
    public function test_rejects_a_lead_hours_value_outside_the_whitelist(): void
    {
        $person_id = $this->make_person();

        ReminderPreferencesHandler::save_and_reschedule($person_id, true, false, 999);

        $person = (new PersonsRepository())->find($person_id);
        $this->assertSame(24, (new PersonsRepository())->get_reminder_lead_hours($person));
    }

    /**
     * The behavior this whole feature exists for: changing the lead time
     * must actually move an already-scheduled reminder for an upcoming
     * confirmed signup, not just apply to signups made afterward.
     */
    public function test_changing_lead_time_reschedules_an_already_pending_reminder(): void
    {
        $chapel_id   = $this->make_chapel();
        $schedule_id = $this->make_schedule($chapel_id);
        $date        = gmdate('Y-m-d', strtotime('+10 days'));
        $slot_id     = $this->make_slot($schedule_id, $chapel_id, [
            'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
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
        $expected_slot_ts = strtotime($date . ' 09:00:00 UTC');

        $before = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);
        $this->assertSame($expected_slot_ts - DAY_IN_SECONDS, (int)$before);

        ReminderPreferencesHandler::save_and_reschedule($person_id, true, false, 6);

        $after = as_next_scheduled_action(ReminderScheduler::CRON_HOOK, [$signup_id], self::AS_GROUP);
        $this->assertSame($expected_slot_ts - (6 * HOUR_IN_SECONDS), (int)$after);
        $this->assertNotEquals($before, $after);
    }

}
