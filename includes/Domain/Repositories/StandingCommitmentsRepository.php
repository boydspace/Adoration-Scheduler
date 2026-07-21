<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StandingCommitmentsRepository
 *
 * A "standing commitment" is a person's permanent weekly hour in a perpetual
 * adoration schedule (e.g. "Tuesday 3:00 AM – 4:00 AM"). It is the source of
 * truth for who owns an hour; PerpetualSlotGenerator reads active commitments
 * to auto-create the actual per-date signup each time it materializes a new
 * dated slot for that weekday/time.
 *
 * Reactivation pattern mirrors adoration_signups: `is_active` is part of the
 * unique key, so ending a commitment and later re-taking the same hour reuses
 * the same row instead of piling up duplicates.
 *
 * SAFETY RULE:
 * - This repository MUST NEVER delete records from adoration_persons.
 */
class StandingCommitmentsRepository {

    private string $table;
    private string $persons_table;
    private string $schedules_table;

    public function __construct() {
        global $wpdb;
        $this->table           = $wpdb->prefix . 'adoration_standing_commitments';
        $this->persons_table   = $wpdb->prefix . 'adoration_persons';
        $this->schedules_table = $wpdb->prefix . 'adoration_schedules';
    }

    /**
     * Normalize a time string to HH:MM:SS.
     */
    private function normalize_time(string $time): string {
        $time = trim($time);
        if ($time === '') return '';

        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        $parts = explode(':', $time);
        if (count($parts) < 2) return '';

        $h = (int)$parts[0];
        $m = (int)$parts[1];
        $s = isset($parts[2]) ? (int)$parts[2] : 0;

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) return '';

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * Find an existing row (active OR inactive) for this exact
     * schedule/day/start_time/person, used to support the reactivation pattern.
     */
    public function find_by_person_day_time(int $schedule_id, int $person_id, int $day_of_week, string $start_time, bool $active_only = false): ?array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $person_id   = (int)$person_id;
        $day_of_week = (int)$day_of_week;
        $start_time  = $this->normalize_time($start_time);

        if ($schedule_id <= 0 || $person_id <= 0 || $start_time === '') return null;

        $active_sql = $active_only ? " AND is_active = 1 " : "";

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND person_id = %d AND day_of_week = %d AND start_time = %s
             {$active_sql}
             ORDER BY is_active DESC, id DESC
             LIMIT 1",
            $schedule_id,
            $person_id,
            $day_of_week,
            $start_time
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }

    /**
     * Create a new standing commitment, or reactivate a previously-ended one for
     * the same person/hour if one exists. Returns the row ID, or 0 on failure.
     *
     * Does NOT check capacity (max_adorers) here — callers (admin UI) should check
     * count_active_for_day_time() against the schedule's configured capacity first.
     */
    public function create(array $data): int {
        global $wpdb;

        $schedule_id = (int)($data['schedule_id'] ?? 0);
        $chapel_id   = (int)($data['chapel_id'] ?? 0);
        $person_id   = (int)($data['person_id'] ?? 0);
        $day_of_week = (int)($data['day_of_week'] ?? -1);
        $start_time  = $this->normalize_time((string)($data['start_time'] ?? ''));
        $end_time    = $this->normalize_time((string)($data['end_time'] ?? ''));
        $notes       = isset($data['notes']) ? sanitize_text_field((string)$data['notes']) : null;

        if ($schedule_id <= 0 || $person_id <= 0 || $day_of_week < 0 || $day_of_week > 6) return 0;
        if ($start_time === '' || $end_time === '') return 0;

        // Reactivate an existing (ended) row for this exact person/hour if present.
        $existing = $this->find_by_person_day_time($schedule_id, $person_id, $day_of_week, $start_time, false);
        if ($existing && (int)($existing['is_active'] ?? 0) === 0) {
            $ok = $this->reactivate((int)$existing['id']);
            return $ok ? (int)$existing['id'] : 0;
        }
        if ($existing && (int)($existing['is_active'] ?? 0) === 1) {
            // Already active — nothing to do.
            return (int)$existing['id'];
        }

        $today = current_time('Y-m-d');

        $ok = $wpdb->insert($this->table, [
            'schedule_id' => $schedule_id,
            'chapel_id'   => $chapel_id,
            'person_id'   => $person_id,
            'day_of_week' => $day_of_week,
            'start_time'  => $start_time,
            'end_time'    => $end_time,
            'is_active'   => 1,
            'started_on'  => $today,
            'ended_on'    => null,
            'notes'       => ($notes !== '' ? $notes : null),
        ], [
            '%d','%d','%d','%d','%s','%s','%d','%s','%s','%s'
        ]);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function find(int $id): ?array {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return null;

        $sql = $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }

    /**
     * List commitments for a schedule, joined with person name/email for display.
     */
    public function list_for_schedule(int $schedule_id, bool $active_only = true): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $active_sql = $active_only ? " AND c.is_active = 1 " : "";

        $sql = $wpdb->prepare(
            "SELECT c.*, p.title AS title, p.first_name AS first_name, p.last_name AS last_name, p.email AS email, p.phone AS phone
             FROM {$this->table} c
             LEFT JOIN {$this->persons_table} p ON p.id = c.person_id
             WHERE c.schedule_id = %d {$active_sql}
             ORDER BY c.day_of_week ASC, c.start_time ASC",
            $schedule_id
        );

        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Active commitments for a specific weekday+start_time slot (used both for
     * capacity checks and by PerpetualSlotGenerator to auto-create signups).
     */
    public function list_active_for_day_time(int $schedule_id, int $day_of_week, string $start_time): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $day_of_week = (int)$day_of_week;
        $start_time  = $this->normalize_time($start_time);

        if ($schedule_id <= 0 || $start_time === '') return [];

        $sql = $wpdb->prepare(
            "SELECT c.*, p.first_name AS first_name, p.last_name AS last_name, p.email AS email, p.phone AS phone
             FROM {$this->table} c
             LEFT JOIN {$this->persons_table} p ON p.id = c.person_id
             WHERE c.schedule_id = %d AND c.day_of_week = %d AND c.start_time = %s AND c.is_active = 1
             ORDER BY c.id ASC",
            $schedule_id,
            $day_of_week,
            $start_time
        );

        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    public function count_active_for_day_time(int $schedule_id, int $day_of_week, string $start_time): int {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $day_of_week = (int)$day_of_week;
        $start_time  = $this->normalize_time($start_time);

        if ($schedule_id <= 0 || $start_time === '') return 0;

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE schedule_id = %d AND day_of_week = %d AND start_time = %s AND is_active = 1",
            $schedule_id,
            $day_of_week,
            $start_time
        );

        return (int)$wpdb->get_var($sql);
    }

    /**
     * Full weekly grid for a schedule: [day_of_week][start_time] => array of active
     * commitment rows (joined with person info). Used to render the coverage grid.
     */
    public function grid_for_schedule(int $schedule_id): array {
        $rows = $this->list_for_schedule($schedule_id, true);

        $grid = [];
        foreach ($rows as $row) {
            $dow = (int)($row['day_of_week'] ?? 0);
            $st  = (string)($row['start_time'] ?? '');
            if (!isset($grid[$dow])) $grid[$dow] = [];
            if (!isset($grid[$dow][$st])) $grid[$dow][$st] = [];
            $grid[$dow][$st][] = $row;
        }

        return $grid;
    }

    /**
     * List commitments for a person, joined with the schedule name (used by the
     * parishioner-facing "My Adoration" portal to show "Your Standing Hours").
     */
    public function list_for_person(int $person_id, bool $active_only = true): array {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return [];

        $active_sql = $active_only ? " AND c.is_active = 1 " : "";

        $sql = $wpdb->prepare(
            "SELECT c.*, sc.name AS schedule_name
             FROM {$this->table} c
             LEFT JOIN {$this->schedules_table} sc ON sc.id = c.schedule_id
             WHERE c.person_id = %d {$active_sql}
             ORDER BY c.day_of_week ASC, c.start_time ASC",
            $person_id
        );

        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Reactivate a previously-ended commitment row.
     */
    public function reactivate(int $id): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $today = current_time('Y-m-d');

        $result = $wpdb->update(
            $this->table,
            ['is_active' => 1, 'started_on' => $today, 'ended_on' => null],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        return ($result !== false);
    }

    /**
     * End (deactivate) a commitment. The row is kept for history; a person can
     * later re-take the same hour and it will be reactivated rather than duplicated.
     */
    public function end(int $id): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $today = current_time('Y-m-d');

        $result = $wpdb->update(
            $this->table,
            ['is_active' => 0, 'ended_on' => $today],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );

        return ($result !== false);
    }
}
