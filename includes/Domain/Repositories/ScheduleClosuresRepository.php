<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ScheduleClosuresRepository
 *
 * A "closure" is an admin-declared date-TIME range (e.g. "Dec 24 4:00 PM through
 * Dec 26 4:00 PM" for Christmas) during which a schedule is shut down. It spans
 * multiple calendar days with arbitrary start/end times, unlike a single-date
 * slot cancellation.
 *
 * Closures are purely additive/declarative records — they do not themselves
 * touch slots or signups. Applying a closure (cancelling overlapping signups,
 * deactivating overlapping slots) is done by the admin action that creates the
 * row (see EditSchedulePage's adoration_add_closure handler), and
 * PerpetualSlotGenerator::sync_window() consults active closures to avoid
 * generating new slots inside a closure window going forward.
 *
 * Admin-only feature — not surfaced to parishioners.
 */
class ScheduleClosuresRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_schedule_closures';
    }

    public function create(array $data): int {
        global $wpdb;

        $schedule_id = (int)($data['schedule_id'] ?? 0);
        $chapel_id   = (int)($data['chapel_id'] ?? 0);
        $start_at    = trim((string)($data['start_at'] ?? ''));
        $end_at      = trim((string)($data['end_at'] ?? ''));
        $reason      = isset($data['reason']) ? sanitize_text_field((string)$data['reason']) : null;
        $created_by  = isset($data['created_by']) ? (int)$data['created_by'] : 0;

        if ($schedule_id <= 0 || $start_at === '' || $end_at === '') return 0;
        if (strtotime($start_at) === false || strtotime($end_at) === false) return 0;
        if ($start_at >= $end_at) return 0;

        $ok = $wpdb->insert($this->table, [
            'schedule_id' => $schedule_id,
            'chapel_id'   => $chapel_id,
            'start_at'    => $start_at,
            'end_at'      => $end_at,
            'reason'      => ($reason !== '' ? $reason : null),
            'created_by'  => ($created_by > 0 ? $created_by : null),
        ], [
            '%d', '%d', '%s', '%s', '%s', '%d'
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
     * All closures for a schedule, most recent first.
     */
    public function list_for_schedule(int $schedule_id): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE schedule_id = %d ORDER BY start_at DESC",
            $schedule_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Closures for a schedule that overlap a given [range_start, range_end) window
     * (e.g. the visible month on the Coverage Calendar, or PerpetualSlotGenerator's
     * rolling window). Standard interval-overlap test.
     */
    public function list_overlapping(int $schedule_id, string $range_start, string $range_end): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0 || $range_start === '' || $range_end === '') return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND start_at < %s AND end_at > %s
             ORDER BY start_at ASC",
            $schedule_id,
            $range_end,
            $range_start
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public function delete(int $id): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);
        return ($result !== false && (int)$result > 0);
    }
}
