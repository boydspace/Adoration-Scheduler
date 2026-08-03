<?php
namespace AdorationScheduler\Domain\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Repository is the persistence boundary for immutable signup audit records.

if ( ! defined('ABSPATH') ) exit;

class SignupAuditRepository
{
    /**
     * Use the same "adoration_" prefix convention as the rest of the plugin.
     */
    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'adoration_signup_audit';
    }

    /**
     * Append a new audit event (immutable).
     *
     * @param int         $signup_id
     * @param string      $event_type  e.g. status_changed, admin_cancelled, admin_deleted, admin_resend_email
     * @param array       $meta        lightweight JSON-able data: from/to/template/result/etc
     * @param int|null    $actor_user_id WordPress user ID (admin) if applicable
     * @param string|null $actor_label cached readable label e.g. "Fr. Andy (admin)" (optional)
     * @return int Inserted row ID on success, 0 on failure
     */
    public function log(
        int $signup_id,
        string $event_type,
        array $meta = [],
        ?int $actor_user_id = null,
        ?string $actor_label = null
    ): int {
        global $wpdb;

        $signup_id  = (int)$signup_id;
        $event_type = sanitize_key($event_type);

        if ($signup_id <= 0 || $event_type === '') {
            return 0;
        }

        // Ensure meta is always stored as JSON or NULL.
        $meta_json = null;
        if (!empty($meta)) {
            // Best-effort: if encoding fails, store a tiny error blob (never break the main action).
            $encoded = wp_json_encode($meta);
            $meta_json = ($encoded !== false) ? $encoded : wp_json_encode(['_encode_error' => true]);
        }

        // Keep timestamps consistent with WP timezone (matches your existing tables using CURRENT_TIMESTAMP).
        $created_at = current_time('mysql'); // local WP time

        $table = $this->table_name();

        $data = [
            'signup_id'     => $signup_id,
            'event_type'    => $event_type,
            'actor_user_id' => $actor_user_id ? (int)$actor_user_id : null,
            'actor_label'   => $actor_label ? (string)$actor_label : null,
            'meta'          => $meta_json,
            'created_at'    => $created_at,
        ];

        $formats = [
            '%d', // signup_id
            '%s', // event_type
            '%d', // actor_user_id (nullable ok)
            '%s', // actor_label (nullable ok)
            '%s', // meta (nullable ok)
            '%s', // created_at
        ];

        // wpdb::insert handles NULL values fine.
        $ok = $wpdb->insert($table, $data, $formats);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /**
     * Fetch audit events for a signup (most recent first).
     *
     * @return array<int, array<string,mixed>> Each row: id, signup_id, event_type, actor_user_id, actor_label, meta, created_at
     */
    public function get_for_signup(int $signup_id, int $limit = 50, int $offset = 0): array {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return [];

        $limit  = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);

        $table = $this->table_name();

        $sql = $wpdb->prepare(
            "SELECT id, signup_id, event_type, actor_user_id, actor_label, meta, created_at
             FROM %i
             WHERE signup_id = %d
             ORDER BY created_at DESC, id DESC
             LIMIT %d OFFSET %d",
            $table,
            $signup_id,
            $limit,
            $offset
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = (array)$wpdb->get_results($sql, ARRAY_A);

        // Decode meta JSON for convenience (best-effort).
        foreach ($rows as &$r) {
            if (!isset($r['meta']) || $r['meta'] === null || $r['meta'] === '') {
                $r['meta'] = [];
                continue;
            }
            $decoded = json_decode((string)$r['meta'], true);
            $r['meta'] = is_array($decoded) ? $decoded : [];
        }
        unset($r);

        return $rows;
    }

    /** Attendance-correction history grouped by signup ID for report screens. */
    public function get_attendance_events_for_signups(array $signup_ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $signup_ids), static fn($id) => $id > 0)));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolated fragment contains placeholders only.
            "SELECT id, signup_id, event_type, actor_user_id, actor_label, meta, created_at
               FROM %i
              WHERE event_type = 'attendance_outcome_changed'
                AND signup_id IN ({$placeholders})
              ORDER BY created_at DESC, id DESC",
            ...array_merge([$this->table_name()], $ids)
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $grouped = [];
        foreach ((array)$rows as $row) {
            $decoded = json_decode((string)($row['meta'] ?? ''), true);
            $row['meta'] = is_array($decoded) ? $decoded : [];
            $grouped[(int)$row['signup_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * Convenience helper: build a stable, readable actor label.
     * You can call this from handlers before log(), or ignore it entirely.
     */
    public function build_actor_label(?int $user_id = null): ?string {
        $user_id = $user_id ? (int)$user_id : 0;
        if ($user_id <= 0) return null;

        $u = get_user_by('id', $user_id);
        if (!$u) return null;

        $name = trim((string)($u->display_name ?? ''));
        if ($name === '') {
            $name = trim((string)($u->user_login ?? ''));
        }
        if ($name === '') return null;

        return $name;
    }
}
