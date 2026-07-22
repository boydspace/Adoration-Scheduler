<?php
namespace AdorationScheduler\Tests\Integration\Core;

use AdorationScheduler\Core\Installer;
use AdorationScheduler\Tests\Support\AdorationIntegrationTestCase;

/**
 * Verifies Installer::install()'s dbDelta() calls actually produce the
 * schema the rest of the plugin assumes, against a real MySQL install.
 *
 * The unit suite (tests/Unit) fakes $wpdb entirely, so it can't catch a
 * CREATE TABLE statement dbDelta() silently refuses to apply (e.g. a
 * malformed index definition), or a column dbDelta() fails to add to an
 * already-existing table — dbDelta is notoriously unreliable at loosening
 * an existing constraint (see Installer::schema_looks_ok()'s docblock).
 * This is exactly the gap tests/Integration/README.md calls out as the
 * top priority for this suite.
 */
class InstallerSchemaTest extends AdorationIntegrationTestCase
{
    public function test_install_creates_expected_tables_and_columns(): void
    {
        global $wpdb;

        Installer::install();

        $prefix = $wpdb->prefix . 'adoration_';

        $expected = [
            $prefix . 'chapels'              => ['id', 'name', 'slug', 'is_active'],
            $prefix . 'schedules'            => ['id', 'chapel_id', 'name', 'slug', 'type', 'privacy_mode', 'status'],
            $prefix . 'date_patterns'        => ['id', 'schedule_id', 'date', 'day_of_week', 'week_of_month'],
            $prefix . 'segments'             => ['id', 'schedule_id', 'date_pattern_id', 'start_time', 'end_time'],
            $prefix . 'slots'                => ['id', 'schedule_id', 'chapel_id', 'date', 'start_time', 'end_time', 'start_at', 'end_at', 'max_adorers'],
            $prefix . 'persons'              => ['id', 'wp_user_id', 'first_name', 'last_name', 'title', 'email', 'phone', 'approval_status', 'calendar_token', 'email_reminder_opt_in', 'sms_reminder_opt_in', 'reminder_lead_hours'],
            $prefix . 'signups'              => ['id', 'person_id', 'schedule_id', 'slot_id', 'date', 'status', 'is_active', 'needs_replacement'],
            $prefix . 'standing_commitments' => ['id', 'schedule_id', 'person_id'],
            $prefix . 'waitlist'             => ['id'],
        ];

        foreach ($expected as $table => $columns) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $this->assertSame($table, $exists, "Expected table `{$table}` to exist after Installer::install().");

            $actual_columns = $wpdb->get_col("DESCRIBE {$table}");
            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $actual_columns,
                    "Expected column `{$column}` on table `{$table}`."
                );
            }
        }
    }
}
