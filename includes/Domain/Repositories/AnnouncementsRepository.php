<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined('ABSPATH') ) exit;

/**
 * Admin broadcast announcements — the "news" piece of the modular
 * front-end shortcode family (shown via [adoration_announcements]),
 * standing in for the private Facebook group's news posts.
 */
class AnnouncementsRepository
{
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_announcements';
    }

    /**
     * @param bool $show_public  Show on the public front page via
     *                           [adoration_public_announcements] (no sign-in
     *                           required).
     * @param bool $show_private Show to signed-in, approved members via the
     *                           gated [adoration_announcements] shortcode.
     * @param int  $image_id     Optional WP attachment ID for the slider
     *                           card's image. 0/null = no image.
     */
    public function create(
        string $title,
        string $body,
        ?int $created_by = null,
        bool $show_public = false,
        bool $show_private = true,
        ?int $image_id = null
    ): int {
        global $wpdb;

        $title = sanitize_text_field($title);
        $body  = wp_kses_post($body);

        if ($title === '') return 0;

        // ✅ Manual ordering (2026-07-19): new announcements are appended to
        // the end of the admin-controlled order (highest sort_order + 10),
        // not forced to the top — an admin who has hand-arranged the list
        // can then move a new one up if it should jump the queue.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $max_order  = $wpdb->get_var("SELECT MAX(sort_order) FROM {$this->table}");
        $sort_order = ($max_order === null) ? 0 : ((int)$max_order + 10);

        $ok = $wpdb->insert(
            $this->table,
            [
                'title'        => $title,
                'body'         => $body,
                'is_active'    => 1,
                'show_public'  => $show_public ? 1 : 0,
                'show_private' => $show_private ? 1 : 0,
                'image_id'     => $image_id ? (int)$image_id : null,
                'sort_order'   => $sort_order,
                'created_by'   => $created_by ? (int)$created_by : null,
            ],
            ['%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d']
        );

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function update(
        int $id,
        string $title,
        string $body,
        bool $show_public = false,
        bool $show_private = true,
        ?int $image_id = null
    ): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $title = sanitize_text_field($title);
        $body  = wp_kses_post($body);
        if ($title === '') return false;

        $res = $wpdb->update(
            $this->table,
            [
                'title'        => $title,
                'body'         => $body,
                'show_public'  => $show_public ? 1 : 0,
                'show_private' => $show_private ? 1 : 0,
                'image_id'     => $image_id ? (int)$image_id : null,
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%d', '%d'],
            ['%d']
        );

        return $res !== false;
    }

    public function set_active(int $id, bool $active): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $res = $wpdb->update(
            $this->table,
            ['is_active' => $active ? 1 : 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        return $res !== false;
    }

    public function delete(int $id): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $res = $wpdb->delete($this->table, ['id' => $id], ['%d']);
        return ($res !== false && (int)$res > 0);
    }

    /**
     * ✅ 2026-07-19: replaces the earlier neighbor-swap move(int, string)
     * method — Andy reported the Up/Down button version "doesn't do
     * anything" and asked for drag-to-sort instead. Called by
     * AnnouncementsReorderAjax after a jQuery UI Sortable drag with the
     * full list of announcement IDs in their new on-screen order; assigns
     * sequential sort_order values (0, 10, 20, ...) in one pass so the
     * whole list is rewritten atomically rather than adjusted one swap at
     * a time.
     *
     * @param int[] $ordered_ids Announcement IDs, top-to-bottom.
     */
    public function reorder(array $ordered_ids): bool {
        global $wpdb;

        $ordered_ids = array_values(array_unique(array_map('intval', $ordered_ids)));
        $ordered_ids = array_filter($ordered_ids, fn($id) => $id > 0);
        if (empty($ordered_ids)) return false;

        $order = 0;
        foreach ($ordered_ids as $id) {
            $wpdb->update($this->table, ['sort_order' => $order], ['id' => $id], ['%d'], ['%d']);
            $order += 10;
        }

        return true;
    }

    public function find(int $id): ?array {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return null;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Active announcements, in admin-defined order (sort_order ASC, ties
     * broken by newest-first) — for the front-end [adoration_announcements]
     * shortcode. Superseded by list_active_private() as of 2026-07-19, kept
     * for any external/custom caller still using the old "all active,
     * regardless of public/private" behavior.
     */
    public function list_active(int $limit = 10): array {
        global $wpdb;

        $limit = max(1, min(50, (int)$limit));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Active + public announcements, in admin-defined order — for the
     * ungated front-page [adoration_public_announcements] shortcode. No
     * sign-in required, so this only ever pulls rows explicitly marked
     * show_public.
     */
    public function list_active_public(int $limit = 10): array {
        global $wpdb;

        $limit = max(1, min(50, (int)$limit));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE is_active = 1 AND show_public = 1 ORDER BY sort_order ASC, created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Active + private announcements, in admin-defined order — for the
     * gated [adoration_announcements] shortcode shown to signed-in,
     * approved members.
     */
    public function list_active_private(int $limit = 10): array {
        global $wpdb;

        $limit = max(1, min(50, (int)$limit));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE is_active = 1 AND show_private = 1 ORDER BY sort_order ASC, created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * All announcements (active + inactive), in admin-defined order — for
     * the admin management page.
     */
    public function list_all(int $limit = 100, int $offset = 0): array {
        global $wpdb;

        $limit  = max(1, min(500, (int)$limit));
        $offset = max(0, (int)$offset);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} ORDER BY sort_order ASC, created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function count_all(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }
}
