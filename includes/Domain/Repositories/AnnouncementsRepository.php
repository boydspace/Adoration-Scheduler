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

    public function create(string $title, string $body, ?int $created_by = null): int {
        global $wpdb;

        $title = sanitize_text_field($title);
        $body  = wp_kses_post($body);

        if ($title === '') return 0;

        $ok = $wpdb->insert(
            $this->table,
            [
                'title'      => $title,
                'body'       => $body,
                'is_active'  => 1,
                'created_by' => $created_by ? (int)$created_by : null,
            ],
            ['%s', '%s', '%d', '%d']
        );

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function update(int $id, string $title, string $body): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $title = sanitize_text_field($title);
        $body  = wp_kses_post($body);
        if ($title === '') return false;

        $res = $wpdb->update(
            $this->table,
            ['title' => $title, 'body' => $body],
            ['id' => $id],
            ['%s', '%s'],
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
     * Active announcements, most recent first — for the front-end
     * [adoration_announcements] shortcode.
     */
    public function list_active(int $limit = 10): array {
        global $wpdb;

        $limit = max(1, min(50, (int)$limit));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * All announcements (active + inactive), most recent first — for the
     * admin management page.
     */
    public function list_all(int $limit = 100, int $offset = 0): array {
        global $wpdb;

        $limit  = max(1, min(500, (int)$limit));
        $offset = max(0, (int)$offset);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
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
