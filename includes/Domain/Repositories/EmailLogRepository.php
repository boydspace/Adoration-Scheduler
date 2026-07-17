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

        $sql = $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id);
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

        $where = "WHERE 1=1";
        $params = [];

        $s = trim((string)$args['s']);
        if ($s !== '') {
            $where .= " AND (to_email LIKE %s OR subject LIKE %s OR type LIKE %s OR context LIKE %s)";
            $like = '%' . $wpdb->esc_like($s) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $type = trim((string)$args['type']);
        if ($type !== '') {
            $where .= " AND type = %s";
            $params[] = $type;
        }

        $success = (string)$args['success'];
        if ($success === '1' || $success === '0') {
            $where .= " AND success = %d";
            $params[] = (int)$success;
        }

        $allowed_orderby = ['id','created_at','to_email','type','context','success'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper((string)$args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Total
        $sql_count = "SELECT COUNT(*) FROM {$this->table} {$where}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($sql_count, ...$params) : $sql_count);

        // Rows
        $sql_rows = "SELECT * FROM {$this->table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params_rows = array_merge($params, [$per_page, $offset]);

        $rows = (array) $wpdb->get_results($wpdb->prepare($sql_rows, ...$params_rows), ARRAY_A);

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
            "DELETE FROM {$this->table} WHERE created_at < (NOW() - INTERVAL %d DAY)",
            $days
        );

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

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "DELETE FROM {$this->table} WHERE id IN ({$placeholders})",
            ...$ids
        );

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

        $where = "WHERE 1=1";
        $params = [];

        $s = trim((string)$args['s']);
        if ($s !== '') {
            $where .= " AND (to_email LIKE %s OR subject LIKE %s OR type LIKE %s OR context LIKE %s)";
            $like = '%' . $wpdb->esc_like($s) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $type = trim((string)$args['type']);
        if ($type !== '') {
            $where .= " AND type = %s";
            $params[] = $type;
        }

        $success = (string)$args['success'];
        if ($success === '1' || $success === '0') {
            $where .= " AND success = %d";
            $params[] = (int)$success;
        }

        $allowed_orderby = ['id','created_at','to_email','type','context','success'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper((string)$args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$this->table} {$where} ORDER BY {$orderby} {$order} LIMIT %d";
        $params2 = array_merge($params, [$limit]);

        return (array) $wpdb->get_results($wpdb->prepare($sql, ...$params2), ARRAY_A);
    }
}
