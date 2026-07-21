<?php
namespace AdorationScheduler\Tests\Integration\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
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
}
