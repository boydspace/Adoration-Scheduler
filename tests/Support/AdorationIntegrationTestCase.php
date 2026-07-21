<?php
namespace AdorationScheduler\Tests\Support;

use AdorationScheduler\Core\Installer;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

/**
 * Base class for the "integration" suite (see tests/bootstrap-integration.php).
 * Extends the real WP_UnitTestCase — every test method runs inside WordPress's
 * own per-test DB transaction, which is rolled back afterward. That covers
 * this plugin's dbDelta()-created tables too (same $wpdb connection, InnoDB),
 * so fixtures created here never need manual cleanup.
 *
 * Fixture helpers call the plugin's own repositories rather than hand-rolling
 * SQL, so tests exercise the exact same insert/validation logic production
 * code path uses.
 */
abstract class AdorationIntegrationTestCase extends \WP_UnitTestCase
{
    /**
     * Loading adoration-scheduler.php via muplugins_loaded (see
     * tests/bootstrap-integration.php) runs the plugin's own add_action()
     * calls, but never fires the real `activate_{plugin}` hook — that only
     * happens through WordPress's actual activate_plugin() flow, which
     * nothing in this test process triggers. So the plugin's custom tables
     * don't exist yet by the time a test runs unless something calls
     * Installer::install() itself. Doing it here, once per test, is cheap
     * (dbDelta() is idempotent) and means every integration test can rely
     * on the schema existing without caring about run order.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Installer::install();
    }

    protected function make_chapel(string $name = 'Test Chapel'): int
    {
        return (new ChapelsRepository())->create($name);
    }

    protected function make_schedule(int $chapel_id, array $overrides = []): int
    {
        return (new SchedulesRepository())->create(array_merge([
            'chapel_id' => $chapel_id,
            'name'      => 'Test Schedule',
            'slug'      => 'test-schedule-' . wp_generate_password(8, false, false),
            'type'      => 'event',
            'status'    => 'active',
        ], $overrides));
    }

    protected function make_slot(int $schedule_id, int $chapel_id, array $overrides = []): int
    {
        $row = array_merge([
            'schedule_id' => $schedule_id,
            'chapel_id'   => $chapel_id,
            'date'        => gmdate('Y-m-d', strtotime('+1 day')),
            'start_time'  => '09:00:00',
            'end_time'    => '10:00:00',
            'min_adorers' => 1,
            'max_adorers' => 2,
            'is_active'   => 1,
        ], $overrides);

        // hours_report_by_person() (and other real duration math) prefers
        // the canonical start_at/end_at datetime columns when present —
        // derive them from date/start_time/end_time by default, same as
        // production slot-generation code does, unless a test overrides
        // them explicitly.
        if (!array_key_exists('start_at', $row)) {
            $row['start_at'] = $row['date'] . ' ' . $row['start_time'];
        }
        if (!array_key_exists('end_at', $row)) {
            $row['end_at'] = $row['date'] . ' ' . $row['end_time'];
        }

        return (new SlotsRepository())->insert($row);
    }

    protected function make_person(array $overrides = []): int
    {
        $email = $overrides['email'] ?? ('test-' . wp_generate_password(10, false, false) . '@example.test');

        return (new PersonsRepository())->upsert_by_email(array_merge([
            'first_name' => 'Test',
            'last_name'  => 'Person',
            'email'      => $email,
        ], $overrides));
    }
}
