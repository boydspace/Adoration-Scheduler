<?php
namespace AdorationScheduler\Tests\Unit\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Tests\Support\AdorationTestCase;

/**
 * Covers the two duplicate-signup guards used by standing-commitment
 * auto-fill (PerpetualSlotGenerator::apply_standing_commitments()):
 *
 * - exists_for_slot_person_date(): the original guard, scoped to one exact
 *   slot_id.
 * - exists_confirmed_for_schedule_datetime(): added 2026-07-17 as a
 *   cross-slot guard — catches the case where two different slot rows exist
 *   for the same schedule/date/time (whatever the cause), so the same
 *   person doesn't get auto-signed-up (and emailed) once per duplicate.
 *
 * These don't execute real SQL (see FakeWpdb's docblock) — they assert the
 * repository builds a query that actually filters on what it's supposed to,
 * and correctly turns the database's answer into a bool.
 */
final class SignupsRepositoryDedupeTest extends AdorationTestCase
{
    private SignupsRepository $repo;
    private \AdorationScheduler\Tests\Support\FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = $this->fake_wpdb();
        $this->repo = new SignupsRepository();
    }

    // --- exists_for_slot_person_date() ------------------------------------

    public function test_exists_for_slot_person_date_true_when_db_returns_a_row(): void
    {
        $this->wpdb->will_return_var(1);

        $result = $this->repo->exists_for_slot_person_date(42, 7, '2026-07-22', 'confirmed');

        $this->assertTrue($result);

        $sql = $this->wpdb->last_query()['sql'];
        $this->assertStringContainsString('slot_id = 42', $sql);
        $this->assertStringContainsString('person_id = 7', $sql);
        $this->assertStringContainsString("date = '2026-07-22'", $sql);
        $this->assertStringContainsString("status = 'confirmed'", $sql);
    }

    public function test_exists_for_slot_person_date_false_when_db_returns_nothing(): void
    {
        $this->wpdb->will_return_var(null);

        $this->assertFalse($this->repo->exists_for_slot_person_date(42, 7, '2026-07-22', 'confirmed'));
    }

    public function test_exists_for_slot_person_date_null_status_omits_status_filter(): void
    {
        $this->wpdb->will_return_var(1);

        $this->repo->exists_for_slot_person_date(42, 7, '2026-07-22', null);

        $sql = $this->wpdb->last_query()['sql'];
        $this->assertStringNotContainsString('status', $sql, 'status=null means "any status" — the query must not filter on it.');
    }

    public function test_exists_for_slot_person_date_invalid_inputs_short_circuit_without_querying(): void
    {
        $this->assertFalse($this->repo->exists_for_slot_person_date(0, 7, '2026-07-22'));
        $this->assertFalse($this->repo->exists_for_slot_person_date(42, 0, '2026-07-22'));
        $this->assertFalse($this->repo->exists_for_slot_person_date(42, 7, ''));
        $this->assertNull($this->wpdb->last_query(), 'Invalid input should never reach the database.');
    }

    // --- exists_confirmed_for_schedule_datetime() (the cross-slot guard) --

    public function test_cross_slot_guard_true_when_another_slot_has_a_confirmed_signup_same_datetime(): void
    {
        $this->wpdb->will_return_var(1);

        $result = $this->repo->exists_confirmed_for_schedule_datetime(3, 7, '2026-07-22', '02:00:00');

        $this->assertTrue($result);

        $sql = $this->wpdb->last_query()['sql'];
        // Must filter (in the WHERE clause) by schedule/person/date/time — the
        // whole point is catching a *different* slot_id at the same datetime,
        // so the WHERE clause itself must not narrow by slot_id (the JOIN
        // condition legitimately references slot_id as a join key, which is
        // fine — that's not a filter).
        $this->assertStringContainsString('schedule_id = 3', $sql);
        $this->assertStringContainsString('person_id = 7', $sql);
        $this->assertStringContainsString("date = '2026-07-22'", $sql);
        $this->assertStringContainsString("start_time = '02:00:00'", $sql);
        $this->assertStringContainsString("status = 'confirmed'", $sql, 'Must only match confirmed signups, not cancelled ones.');

        $where_clause = substr($sql, (int) strpos($sql, 'WHERE'));
        $this->assertStringNotContainsString('s.slot_id', $where_clause, 'The WHERE clause must not narrow by slot_id — that would defeat the cross-slot guard.');
    }

    public function test_cross_slot_guard_false_when_no_matching_row(): void
    {
        $this->wpdb->will_return_var(null);

        $this->assertFalse($this->repo->exists_confirmed_for_schedule_datetime(3, 7, '2026-07-22', '02:00:00'));
    }

    public function test_cross_slot_guard_invalid_inputs_short_circuit_without_querying(): void
    {
        $this->assertFalse($this->repo->exists_confirmed_for_schedule_datetime(0, 7, '2026-07-22', '02:00:00'));
        $this->assertFalse($this->repo->exists_confirmed_for_schedule_datetime(3, 0, '2026-07-22', '02:00:00'));
        $this->assertFalse($this->repo->exists_confirmed_for_schedule_datetime(3, 7, '', '02:00:00'));
        $this->assertFalse($this->repo->exists_confirmed_for_schedule_datetime(3, 7, '2026-07-22', ''));
        $this->assertNull($this->wpdb->last_query(), 'Invalid input should never reach the database.');
    }

    public function test_cross_slot_guard_truncates_start_time_to_hms(): void
    {
        $this->wpdb->will_return_var(1);

        // A caller passing a full start_at datetime rather than a bare time
        // shouldn't produce a query that can never match a slots.start_time
        // column (which is just HH:MM:SS).
        $this->repo->exists_confirmed_for_schedule_datetime(3, 7, '2026-07-22', '02:00:00.123456');

        $sql = $this->wpdb->last_query()['sql'];
        $this->assertStringContainsString("start_time = '02:00:00'", $sql);
    }
}
