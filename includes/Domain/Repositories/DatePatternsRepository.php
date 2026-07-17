<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class DatePatternsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_date_patterns';
    }

    public function create(int $schedule_id, string $date): int {
        global $wpdb;

        $ok = $wpdb->insert($this->table, [
            'schedule_id' => $schedule_id,
            'date' => $date,
            'sort_order' => 0,
        ], ['%d','%s','%d']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /**
     * Event-style rows only (a specific calendar date). Excludes perpetual
     * weekday templates (date IS NULL) — see list_weekday_templates_for_schedule().
     */
    public function list_for_schedule(int $schedule_id): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE schedule_id = %d AND date IS NOT NULL ORDER BY date ASC",
            $schedule_id
        );
        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    // -------------------------------------------------------------------------
    // Perpetual schedules: weekday templates (day_of_week set, date NULL)
    // -------------------------------------------------------------------------

    /**
     * Create a recurring weekday template row (0=Sunday..6=Saturday).
     * Idempotent: returns the existing row's ID if one already exists for this weekday.
     * Excludes monthly templates (week_of_month IS NOT NULL) — those are a distinct
     * pattern on the same day_of_week column, see create_monthly_template() below.
     */
    public function create_weekday_template(int $schedule_id, int $day_of_week): int {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $day_of_week = (int)$day_of_week;
        if ($schedule_id <= 0 || $day_of_week < 0 || $day_of_week > 6) return 0;

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE schedule_id = %d AND day_of_week = %d AND week_of_month IS NULL LIMIT 1",
            $schedule_id,
            $day_of_week
        ));
        if ($existing_id > 0) return $existing_id;

        $ok = $wpdb->insert($this->table, [
            'schedule_id' => $schedule_id,
            'date'        => null,
            'day_of_week' => $day_of_week,
            'sort_order'  => $day_of_week,
        ], ['%d','%s','%d','%d']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /**
     * List weekday template rows (date IS NULL, day_of_week IS NOT NULL,
     * week_of_month IS NULL) for a schedule, ordered Sunday..Saturday.
     * Excludes monthly templates, which also have day_of_week set.
     */
    public function list_weekday_templates_for_schedule(int $schedule_id): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND day_of_week IS NOT NULL AND week_of_month IS NULL
             ORDER BY day_of_week ASC",
            $schedule_id
        );

        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Find the weekday template row for a specific day_of_week (0-6), if any.
     * Excludes monthly templates (week_of_month IS NOT NULL).
     */
    public function find_weekday_template(int $schedule_id, int $day_of_week): ?array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $day_of_week = (int)$day_of_week;
        if ($schedule_id <= 0 || $day_of_week < 0 || $day_of_week > 6) return null;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND day_of_week = %d AND week_of_month IS NULL
             LIMIT 1",
            $schedule_id,
            $day_of_week
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }

    // -------------------------------------------------------------------------
    // Monthly schedules: nth-weekday-of-month templates (day_of_week AND
    // week_of_month set, date NULL). week_of_month: 1-5 = nth occurrence,
    // 6 = "last" occurrence of that weekday in the month.
    // -------------------------------------------------------------------------

    /**
     * Create a recurring nth-weekday-of-month template row.
     * Idempotent: returns the existing row's ID if one already exists for this
     * exact (week_of_month, day_of_week) combination.
     */
    public function create_monthly_template(int $schedule_id, int $week_of_month, int $day_of_week): int {
        global $wpdb;

        $schedule_id   = (int)$schedule_id;
        $week_of_month = (int)$week_of_month;
        $day_of_week   = (int)$day_of_week;

        if ($schedule_id <= 0 || $day_of_week < 0 || $day_of_week > 6) return 0;
        if ($week_of_month < 1 || $week_of_month > 6) return 0; // 1-5 nth, 6 = last

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE schedule_id = %d AND day_of_week = %d AND week_of_month = %d
             LIMIT 1",
            $schedule_id,
            $day_of_week,
            $week_of_month
        ));
        if ($existing_id > 0) return $existing_id;

        $ok = $wpdb->insert($this->table, [
            'schedule_id'   => $schedule_id,
            'date'          => null,
            'day_of_week'   => $day_of_week,
            'week_of_month' => $week_of_month,
            'sort_order'    => ($week_of_month * 10) + $day_of_week,
        ], ['%d','%s','%d','%d','%d']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /**
     * List monthly template rows (week_of_month IS NOT NULL) for a schedule,
     * ordered by week-of-month then weekday.
     */
    public function list_monthly_templates_for_schedule(int $schedule_id): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND week_of_month IS NOT NULL
             ORDER BY week_of_month ASC, day_of_week ASC",
            $schedule_id
        );

        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Find the monthly template row for a specific (week_of_month, day_of_week), if any.
     */
    public function find_monthly_template(int $schedule_id, int $week_of_month, int $day_of_week): ?array {
        global $wpdb;

        $schedule_id   = (int)$schedule_id;
        $week_of_month = (int)$week_of_month;
        $day_of_week   = (int)$day_of_week;
        if ($schedule_id <= 0 || $day_of_week < 0 || $day_of_week > 6 || $week_of_month < 1 || $week_of_month > 6) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND day_of_week = %d AND week_of_month = %d
             LIMIT 1",
            $schedule_id,
            $day_of_week,
            $week_of_month
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }
}
