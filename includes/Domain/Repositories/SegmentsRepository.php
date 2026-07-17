<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class SegmentsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_segments';
    }

    public function create(array $data): int {
        global $wpdb;

        $ok = $wpdb->insert($this->table, [
            'schedule_id'      => (int)($data['schedule_id'] ?? 0),
            'date_pattern_id'  => (int)($data['date_pattern_id'] ?? 0),
            'start_time'       => (string)($data['start_time'] ?? ''),
            'end_time'         => (string)($data['end_time'] ?? ''),
            'slot_length'      => ($data['slot_length'] ?? null), // may be null
            'sort_order'       => 0,
        ]);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function list_for_date_pattern(int $date_pattern_id): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE date_pattern_id = %d ORDER BY start_time ASC",
            $date_pattern_id
        );
        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    public function list_for_schedule(int $schedule_id): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE schedule_id = %d ORDER BY date_pattern_id ASC, start_time ASC",
            $schedule_id
        );
        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Delete a single segment row by ID.
     * Returns number of rows deleted (0 or 1).
     */
    public function delete(int $segment_id): int {
        global $wpdb;
        if ($segment_id <= 0) return 0;

        $n = $wpdb->delete(
            $this->table,
            ['id' => (int)$segment_id],
            ['%d']
        );

        return (int)($n ?: 0);
    }

    /**
     * Delete all segments for a given date pattern.
     * Returns number of rows deleted.
     */
    public function delete_for_date_pattern(int $date_pattern_id): int {
        global $wpdb;
        if ($date_pattern_id <= 0) return 0;

        $n = $wpdb->delete(
            $this->table,
            ['date_pattern_id' => (int)$date_pattern_id],
            ['%d']
        );

        return (int)($n ?: 0);
    }
}
