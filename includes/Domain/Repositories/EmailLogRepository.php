<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined('ABSPATH') ) exit;

class EmailLogRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_email_log';
    }

    public function insert(array $row): int {
        global $wpdb;

        $defaults = [
            'created_at'    => current_time('mysql'),
            'to_email'      => '',
            'type'          => '',
            'context'       => '',
            'schedule_id'   => null,
            'signup_id'     => null,
            'subject'       => '',
            'body'          => '',
            'headers'       => '',
            'success'       => 0,
            'error_message' => '',
        ];

        $row = array_merge($defaults, $row);

        $ok = $wpdb->insert($this->table, [
            'created_at'    => (string)$row['created_at'],
            'to_email'      => (string)$row['to_email'],
            'type'          => (string)$row['type'],
            'context'       => (string)$row['context'],
            'schedule_id'   => $row['schedule_id'] !== null ? (int)$row['schedule_id'] : null,
            'signup_id'     => $row['signup_id'] !== null ? (int)$row['signup_id'] : null,
            'subject'       => (string)$row['subject'],
            'body'          => (string)$row['body'],
            'headers'       => (string)$row['headers'],
            'success'       => (int)$row['success'],
            'error_message' => (string)$row['error_message'],
        ], [
            '%s','%s','%s','%s',
            $row['schedule_id'] !== null ? '%d' : null,
            $row['signup_id'] !== null ? '%d' : null,
            '%s','%s','%s','%d','%s'
        ]);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function find(int $id): ?array {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return null;

        $sql = $wpdb->prepare("SELECT * FROM %i WHERE id = %d LIMIT 1", $this->table, $id);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $row = $wpdb->get_row($sql, ARRAY_A);

        return $row ? (array)$row : null;
    }

    public function query(array $args): array {
        global $wpdb;

        $defaults = [
            's' => '',
            'type' => '',
            'success' => '', // '1' or '0' or ''
            'paged' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];
        $args = array_merge($defaults, $args);

        $paged    = max(1, (int)$args['paged']);
        $per_page = max(1, min(100, (int)$args['per_page']));
        $offset   = ($paged - 1) * $per_page;

        $s = trim((string)$args['s']);
        $like = '%' . $wpdb->esc_like($s) . '%';

        $type = trim((string)$args['type']);

        $success = (string)$args['success'];
        $has_success_filter = ($success === '1' || $success === '0') ? 1 : 0;
        $success_val = $has_success_filter ? (int)$success : 0;

        $allowed_orderby = ['id','created_at','to_email','type','context','success'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper((string)$args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // The (%s = '' OR ...) / (%d = 0 OR ...) form lets each filter stay
        // optional without interpolating a conditional WHERE fragment.
        $count_prepared = $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE (%s = '' OR (to_email LIKE %s OR subject LIKE %s OR type LIKE %s OR context LIKE %s))
               AND (%s = '' OR type = %s)
               AND (%d = 0 OR success = %d)",
            $this->table, $s, $like, $like, $like, $like, $type, $type, $has_success_filter, $success_val
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $total = (int) $wpdb->get_var($count_prepared);

        // $orderby is checked against $allowed_orderby above and $order is
        // forced to ASC/DESC — neither is ever raw user input.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows_prepared = $wpdb->prepare(
            "SELECT * FROM %i
             WHERE (%s = '' OR (to_email LIKE %s OR subject LIKE %s OR type LIKE %s OR context LIKE %s))
               AND (%s = '' OR type = %s)
               AND (%d = 0 OR success = %d)
             ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $this->table, $s, $like, $like, $like, $like, $type, $type, $has_success_filter, $success_val, $per_page, $offset
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = (array) $wpdb->get_results($rows_prepared, ARRAY_A);

        return [
            'total' => $total,
            'rows'  => $rows,
        ];
    }

    /**
     * Delete rows older than N days (based on created_at).
     * Returns number of rows deleted.
     */
    public function delete_older_than_days(int $days): int {
        global $wpdb;

        $days = (int)$days;
        if ($days <= 0) return 0;

        // MySQL interval
        $sql = $wpdb->prepare(
            "DELETE FROM %i WHERE created_at < (NOW() - INTERVAL %d DAY)",
            $this->table,
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $res = $wpdb->query($sql);
        return ($res === false) ? 0 : (int)$res;
    }

    /**
     * Delete by IDs (bulk).
     * Returns number of rows deleted.
     */
    public function delete_ids(array $ids): int {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) return 0;

        // Dynamic-length IN-clause: the placeholder count varies with the
        // number of IDs, so the arg list can't be individually enumerated.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "DELETE FROM %i WHERE id IN ({$placeholders})",
            ...array_merge([$this->table], $ids)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $res = $wpdb->query($sql);
        return ($res === false) ? 0 : (int)$res;
    }

    /**
     * Export rows matching filters (no paging, capped).
     * Returns array rows.
     */
    public function export_rows(array $args, int $limit = 5000): array {
        global $wpdb;

        $limit = max(1, min(50000, (int)$limit));

        $defaults = [
            's' => '',
            'type' => '',
            'success' => '', // '1' or '0' or ''
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];
        $args = array_merge($defaults, $args);

        $s = trim((string)$args['s']);
        $like = '%' . $wpdb->esc_like($s) . '%';

        $type = trim((string)$args['type']);

        $success = (string)$args['success'];
        $has_success_filter = ($success === '1' || $success === '0') ? 1 : 0;
        $success_val = $has_success_filter ? (int)$success : 0;

        $allowed_orderby = ['id','created_at','to_email','type','context','success'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper((string)$args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // The (%s = '' OR ...) / (%d = 0 OR ...) form lets each filter stay
        // optional without interpolating a conditional WHERE fragment.
        // $orderby is checked against $allowed_orderby above and $order is
        // forced to ASC/DESC — neither is ever raw user input.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $prepared = $wpdb->prepare(
            "SELECT * FROM %i
             WHERE (%s = '' OR (to_email LIKE %s OR subject LIKE %s OR type LIKE %s OR context LIKE %s))
               AND (%s = '' OR type = %s)
               AND (%d = 0 OR success = %d)
             ORDER BY {$orderby} {$order} LIMIT %d",
            $this->table, $s, $like, $like, $like, $like, $type, $type, $has_success_filter, $success_val, $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        return (array) $wpdb->get_results($prepared, ARRAY_A);
    }
}
