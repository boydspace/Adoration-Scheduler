<?php
namespace AdorationScheduler\Domain\Repositories;

use AdorationScheduler\Domain\Repositories\ChapelsRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class SchedulesRepository {

    private string $table;

    /**
     * Soft-delete status flag (WordPress-style "Trash")
     * NOTE: Your admin table expects 'trash' / 'trashed'.
     */
    public const STATUS_TRASH = 'trash';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_schedules';
    }

    /**
     * Normalize status (treat "trashed" as "trash" for safety)
     */
    private function normalize_status(string $status): string {
        $s = strtolower(trim($status));
        if ($s === 'trashed') return self::STATUS_TRASH;
        return $s;
    }

    /**
     * ✅ NEW: Ensure we always have a real chapel id.
     */
    private function normalize_chapel_id($chapel_id): int {
        $chapel_id = (int)($chapel_id ?? 0);
        if ($chapel_id > 0) return $chapel_id;

        // fallback to Main Chapel
        if (class_exists('\AdorationScheduler\Domain\Repositories\ChapelsRepository')) {
            $chapelsRepo = new ChapelsRepository();
            return (int) $chapelsRepo->ensure_main_chapel_exists();
        }

        return 1; // ultra-fallback (should never happen)
    }

    /**
     * List schedules (excludes trash by default).
     *
     * @param bool $include_deleted Back-compat param name: "deleted" now means "include trash".
     */
    public function list_all( int $limit = 50, bool $include_deleted = false ): array {
        global $wpdb;

        if ($include_deleted) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
                $limit
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return (array) $wpdb->get_results( $sql, ARRAY_A );
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status <> %s ORDER BY created_at DESC LIMIT %d",
            self::STATUS_TRASH,
            $limit
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Every schedule, every status (draft/active/trash alike), with the
     * chapel name joined in — for the admin CSV/XLSX export. Unlike
     * list_all()/admin_list(), this is intentionally unfiltered and
     * unpaginated: an export should reflect everything that exists, not
     * just what the on-screen "All" view (which hides trash) shows.
     */
    public function export_rows(): array {
        global $wpdb;

        $chapels = $wpdb->prefix . 'adoration_chapels';

        $sql = "SELECT s.*, c.name AS chapel_name
                FROM {$this->table} s
                LEFT JOIN {$chapels} c ON c.id = s.chapel_id
                ORDER BY s.name ASC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * ✅ Perpetual adoration: active, non-trashed schedules of type 'perpetual'.
     * Used by PerpetualScheduleGeneratorService's rolling-window cron job.
     */
    public function list_active_perpetual(): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE type = %s AND status = %s ORDER BY id ASC",
            'perpetual',
            'active'
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * ✅ Monthly recurrence: active, non-trashed schedules of type 'monthly'.
     * Used by MonthlyScheduleGeneratorService's rolling-window cron job.
     */
    public function list_active_monthly(): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE type = %s AND status = %s ORDER BY id ASC",
            'monthly',
            'active'
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Find schedule by ID (excludes trash by default).
     */
    public function find( int $id ): ?array {
        return $this->find_by_id($id, false);
    }

    /**
     * Find schedule by ID with optional include "deleted" (trash).
     */
    public function find_by_id( int $id, bool $include_deleted = false ): ?array {
        global $wpdb;

        if ($include_deleted) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d AND status <> %s",
                $id,
                self::STATUS_TRASH
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row( $sql, ARRAY_A );
        return $row ?: null;
    }

    /**
     * ✅ Perpetual adoration Quick Setup: narrow update of just is_overnight,
     * used when a full-24-hour segment is added and the schedule doesn't have
     * Overnight enabled yet (otherwise that segment would silently produce zero
     * slots). Does NOT touch any other column.
     */
    public function set_overnight(int $id, bool $on): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $result = $wpdb->update(
            $this->table,
            ['is_overnight' => $on ? 1 : 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        return ($result !== false);
    }

    /**
     * Find schedule by slug (excludes trash by default).
     */
    public function find_by_slug( string $slug ): ?array {
        return $this->find_by_slug_with_deleted($slug, false);
    }

    /**
     * Find schedule by slug with optional include "deleted" (trash).
     */
    public function find_by_slug_with_deleted( string $slug, bool $include_deleted = false ): ?array {
        global $wpdb;

        $slug = sanitize_title( $slug );

        if ($include_deleted) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE slug = %s",
                $slug
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE slug = %s AND status <> %s",
                $slug,
                self::STATUS_TRASH
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row( $sql, ARRAY_A );
        return $row ?: null;
    }

    /**
     * Create a schedule (event or perpetual).
     * Returns inserted ID or 0 on failure.
     */
    public function create( array $data ): int {
        global $wpdb;

        // default_max_adorers: treat '' as NULL
        $default_max_adorers = null;
        if (array_key_exists('default_max_adorers', $data)) {
            $raw = $data['default_max_adorers'];
            if ($raw === '' || $raw === null) {
                $default_max_adorers = null;
            } else {
                $default_max_adorers = (int) $raw;
                if ($default_max_adorers < 0) $default_max_adorers = null;
            }
        }

        $insert = [
            'chapel_id'            => $this->normalize_chapel_id($data['chapel_id'] ?? 0),
            'name'                 => sanitize_text_field( $data['name'] ?? '' ),
            'slug'                 => sanitize_title( $data['slug'] ?? '' ),
            'type'                 => sanitize_text_field( $data['type'] ?? 'event' ),
            'start_date'           => ! empty( $data['start_date'] ) ? $data['start_date'] : null,
            'end_date'             => ! empty( $data['end_date'] ) ? $data['end_date'] : null,
            'is_overnight'         => !empty($data['is_overnight']) ? 1 : 0,
            'default_slot_length'  => (int) ( $data['default_slot_length'] ?? 60 ),
            'default_min_adorers'  => (int) ( $data['default_min_adorers'] ?? 1 ),
            'default_max_adorers'  => $default_max_adorers,
            'privacy_mode'         => sanitize_text_field( $data['privacy_mode'] ?? 'counts_only' ),
            'status'               => sanitize_text_field( $data['status'] ?? 'draft' ),
            'settings_json'        => isset($data['settings_json']) ? (string)$data['settings_json'] : null,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert( $this->table, $insert );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Check if a slug exists (used when creating).
     * EXCLUDES trash.
     */
    public function exists_slug( string $slug ): bool {
        global $wpdb;
        $slug = sanitize_title( $slug );
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND status <> %s",
            $slug,
            self::STATUS_TRASH
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var( $sql ) > 0;
    }

    /**
     * Check if a slug exists for a different schedule (used when editing).
     * EXCLUDES trash.
     */
    public function exists_slug_except_id( string $slug, int $id ): bool {
        global $wpdb;

        $slug = sanitize_title( $slug );

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND id <> %d AND status <> %s",
            $slug,
            $id,
            self::STATUS_TRASH
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var( $sql ) > 0;
    }

    /**
     * Update basic editable schedule fields.
     * NOTE: Do not allow setting status to 'trash' via edit form.
     */
    public function update_basic_info( int $id, array $data ): bool {
        global $wpdb;

        $status = sanitize_text_field( $data['status'] ?? 'draft' );
        if ($this->normalize_status($status) === self::STATUS_TRASH) {
            $status = 'draft';
        }

        // Allow NULL dates in DB
        $start_date = $data['start_date'] ?? null;
        $end_date   = $data['end_date'] ?? null;

        $start_date = ($start_date === '' ? null : $start_date);
        $end_date   = ($end_date === '' ? null : $end_date);

        // Defaults
        $default_slot_length = isset($data['default_slot_length']) ? (int)$data['default_slot_length'] : 60;
        if ($default_slot_length <= 0) $default_slot_length = 60;

        $default_min_adorers = isset($data['default_min_adorers']) ? (int)$data['default_min_adorers'] : 1;
        if ($default_min_adorers < 0) $default_min_adorers = 0;

        // default_max_adorers: treat '' as NULL
        $default_max_adorers = null;
        if (array_key_exists('default_max_adorers', $data)) {
            $raw = $data['default_max_adorers'];
            if ($raw === '' || $raw === null) {
                $default_max_adorers = null;
            } else {
                $default_max_adorers = (int)$raw;
                if ($default_max_adorers < 0) $default_max_adorers = null;
            }
        }

        $is_overnight = !empty($data['is_overnight']) ? 1 : 0;

        // ✅ NEW: chapel_id persisted here
        $chapel_id = $this->normalize_chapel_id($data['chapel_id'] ?? 0);

        // ✅ Perpetual adoration: how many days ahead the rolling generator keeps materialized.
        $rolling_window_days = isset($data['rolling_window_days']) ? (int)$data['rolling_window_days'] : 60;
        if ($rolling_window_days <= 0) $rolling_window_days = 60;
        if ($rolling_window_days > 366) $rolling_window_days = 366;

        $update = [
            'chapel_id'           => $chapel_id,
            'name'                => sanitize_text_field( $data['name'] ?? '' ),
            'slug'                => sanitize_title( $data['slug'] ?? '' ),
            'status'              => sanitize_text_field($status),
            'privacy_mode'        => sanitize_text_field( $data['privacy_mode'] ?? 'counts_only' ),
            'start_date'          => $start_date,
            'end_date'            => $end_date,
            'is_overnight'        => $is_overnight,
            'default_slot_length' => $default_slot_length,
            'default_min_adorers' => $default_min_adorers,
            'default_max_adorers' => $default_max_adorers,
            'rolling_window_days' => $rolling_window_days,
        ];

        // Formats must match $update order
        $formats = [
            '%d', // chapel_id
            '%s', // name
            '%s', // slug
            '%s', // status
            '%s', // privacy_mode
            '%s', // start_date
            '%s', // end_date
            '%d', // is_overnight
            '%d', // default_slot_length
            '%d', // default_min_adorers
            ($default_max_adorers === null ? '%s' : '%d'),
            '%d', // rolling_window_days
        ];

        if ($default_max_adorers === null) {
            $update['default_max_adorers'] = null; // let wpdb store NULL
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $this->table,
            $update,
            [ 'id' => (int)$id ],
            $formats,
            [ '%d' ]
        );

        if ($result === false) {
            error_log('[AdorationScheduler] update_basic_info failed: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }

    public function count_signups_for_schedule(int $schedule_id): int {
        global $wpdb;
        $signups = $wpdb->prefix . 'adoration_signups';

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$signups} WHERE schedule_id = %d",
            $schedule_id
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int)$wpdb->get_var($sql);
    }

    /**
     * -----------------------------------------------------------------
     * TRASH / RESTORE / DELETE PERMANENTLY (WordPress-like behavior)
     * -----------------------------------------------------------------
     *
     * ✅ Requirement:
     * - You can ALWAYS trash a schedule even if it has signups.
     * - You can ALWAYS permanently delete a schedule from Trash,
     *   even if it has signups (we will cascade delete dependents).
     *
     * Back-compat:
     * - soft_delete() stays, but no longer blocks on signups.
     * - trash() is an alias that admin UI can call.
     */

    /**
     * Move schedule to Trash.
     * Allowed even if signups exist.
     */
    public function trash(int $id): bool {
        return $this->soft_delete($id);
    }

    /**
     * Soft-delete a schedule (move to Trash).
     * Allowed even if signups exist.
     */
    public function soft_delete(int $id): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $this->table,
            [ 'status' => self::STATUS_TRASH ],
            [ 'id' => (int)$id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Restore a trashed schedule (sets to draft by default).
     */
    public function restore(int $id, string $status = 'draft'): bool {
        global $wpdb;

        $status = sanitize_text_field($status);
        if (!in_array($status, ['draft','active'], true)) {
            $status = 'draft';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $this->table,
            [ 'status' => $status ],
            [ 'id' => (int)$id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Permanently delete a schedule row (hard delete).
     *
     * Rules:
     * - Only allowed if schedule is currently in Trash.
     * - Allowed even if signups exist.
     * - Performs a cascade delete of related records first.
     */
    public function delete_permanently(int $id): bool {
        global $wpdb;

        $row = $this->find_by_id($id, true);
        if (!$row) return false;

        $status = $this->normalize_status((string)($row['status'] ?? ''));
        if ($status !== self::STATUS_TRASH) {
            return false; // refuse to hard delete non-trashed schedules
        }

        // Cascade delete dependents (ignore "0 rows" as OK; only false is failure)
        if (!$this->delete_dependents_for_schedule($id)) {
            // If cascade fails, do NOT delete the schedule row
            error_log('[AdorationScheduler] delete_permanently blocked: dependent delete failed for schedule ' . (int)$id . ' :: ' . $wpdb->last_error);
            return false;
        }

        // Finally delete schedule row
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $deleted = $wpdb->delete(
            $this->table,
            [ 'id' => (int)$id ],
            [ '%d' ]
        );

        return ($deleted !== false && $deleted > 0);
    }

    /**
     * Delete all dependent data for a schedule.
     *
     * Order matters if you have constraints:
     * - signups typically reference slots
     * - segments reference date patterns
     *
     * If you add more tables later, extend this list.
     */
    private function delete_dependents_for_schedule(int $schedule_id): bool {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return true;

        $t_signups = $wpdb->prefix . 'adoration_signups';
        $t_slots   = $wpdb->prefix . 'adoration_slots';
        $t_segs    = $wpdb->prefix . 'adoration_segments';
        $t_dates   = $wpdb->prefix . 'adoration_date_patterns';

        // 1) Signups
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r1 = $wpdb->delete($t_signups, [ 'schedule_id' => $schedule_id ], [ '%d' ]);
        if ($r1 === false) return false;

        // 2) Slots
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r2 = $wpdb->delete($t_slots, [ 'schedule_id' => $schedule_id ], [ '%d' ]);
        if ($r2 === false) return false;

        // 3) Segments
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r3 = $wpdb->delete($t_segs, [ 'schedule_id' => $schedule_id ], [ '%d' ]);
        if ($r3 === false) return false;

        // 4) Date patterns
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r4 = $wpdb->delete($t_dates, [ 'schedule_id' => $schedule_id ], [ '%d' ]);
        if ($r4 === false) return false;

        return true;
    }

    /**
     * ------------------------------------------------------------
     * Admin list helpers (for WP_List_Table)
     * ------------------------------------------------------------
     */
    public function admin_list(array $args = []): array {
        global $wpdb;

        $defaults = [
            'per_page'   => 20,
            'paged'      => 1,
            'search'     => '',
            'status'     => '', // '', 'draft', 'active', 'trash'
            'orderby'    => 'created_at',
            'order'      => 'DESC',

            // Filters (YYYY-MM-DD)
            'start_from' => '',
            'start_to'   => '',
            'end_from'   => '',
            'end_to'     => '',
        ];
        $a = array_merge($defaults, $args);

        $per_page = max(1, (int)$a['per_page']);
        $paged    = max(1, (int)$a['paged']);
        $offset   = ($paged - 1) * $per_page;

        $search = trim((string)$a['search']);
        $status = sanitize_key((string)$a['status']);

        $allowed_orderby = [
            'created_at' => 'created_at',
            'name'       => 'name',
            'slug'       => 'slug',
            'status'     => 'status',
            'start_date' => 'start_date',
            'end_date'   => 'end_date',
        ];
        $orderby = $allowed_orderby[(string)$a['orderby']] ?? 'created_at';
        $order   = strtoupper((string)$a['order']) === 'ASC' ? 'ASC' : 'DESC';

        $where  = [];
        $params = [];

        if ($status === self::STATUS_TRASH) {
            $where[]  = "status = %s";
            $params[] = self::STATUS_TRASH;
        } elseif ($status !== '') {
            $where[]  = "status = %s";
            $params[] = $status;
        } else {
            $where[]  = "status <> %s";
            $params[] = self::STATUS_TRASH;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = "(name LIKE %s OR slug LIKE %s)";
            $params[] = $like;
            $params[] = $like;
        }

        $start_from = sanitize_text_field((string)$a['start_from']);
        $start_to   = sanitize_text_field((string)$a['start_to']);
        $end_from   = sanitize_text_field((string)$a['end_from']);
        $end_to     = sanitize_text_field((string)$a['end_to']);

        if ($start_from !== '') {
            $where[]  = "start_date >= %s";
            $params[] = $start_from;
        }
        if ($start_to !== '') {
            $where[]  = "start_date <= %s";
            $params[] = $start_to;
        }
        if ($end_from !== '') {
            $where[]  = "end_date >= %s";
            $params[] = $end_from;
        }
        if ($end_to !== '') {
            $where[]  = "end_date <= %s";
            $params[] = $end_to;
        }

        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $count_sql = "SELECT COUNT(*) FROM {$this->table} {$where_sql}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, $params));

        $items_sql = "SELECT * FROM {$this->table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $items_params = array_merge($params, [$per_page, $offset]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $items = (array)$wpdb->get_results($wpdb->prepare($items_sql, $items_params), ARRAY_A);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Counts by status for Views() links.
     */
    public function admin_counts_by_status(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array)$wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM {$this->table} GROUP BY status",
            ARRAY_A
        );

        $out = [
            'all'    => 0,
            'draft'  => 0,
            'active' => 0,
            'trash'  => 0,
        ];

        foreach ($rows as $r) {
            $s = $this->normalize_status((string)($r['status'] ?? ''));
            $c = (int)($r['c'] ?? 0);

            if (!isset($out[$s])) {
                $out[$s] = 0;
            }

            $out[$s] += $c;
            $out['all'] += $c;
        }

        return $out;
    }

    /**
     * ----------------------------
     * Settings JSON helpers
     * ----------------------------
     */

    public function get_settings(int $id): array {
        $row = $this->find_by_id($id, true);
        if (!$row) return [];

        $json = (string)($row['settings_json'] ?? '');
        if ($json === '') return [];

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function update_settings(int $id, array $settings): bool {
        global $wpdb;

        $json = wp_json_encode($settings);
        if ($json === false) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $this->table,
            [ 'settings_json' => $json ],
            [ 'id' => (int)$id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    public function get_email_templates(int $schedule_id): array {
        $settings = $this->get_settings($schedule_id);

        $block = $settings['email_templates'] ?? null;
        if (!is_array($block)) {
            return ['enabled' => false, 'templates' => []];
        }

        $enabled = !empty($block['enabled']);
        $templates = is_array($block) ? $block : [];

        return [
            'enabled'   => (bool)$enabled,
            'templates' => $templates,
        ];
    }

    public function save_email_templates(int $schedule_id, array $templates, bool $enabled): bool {
        $settings = $this->get_settings($schedule_id);

        $block = $templates;
        $block['enabled'] = $enabled ? 1 : 0;

        $settings['email_templates'] = $block;

        return $this->update_settings($schedule_id, $settings);
    }

    public function get_enabled_email_templates_only(int $schedule_id): array {
        $block = $this->get_email_templates($schedule_id);
        if (empty($block['enabled'])) return [];

        $templates = $block['templates'] ?? [];
        return is_array($templates) ? $templates : [];
    }
}
