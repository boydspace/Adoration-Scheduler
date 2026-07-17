<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class ChapelsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_chapels';
    }

    /**
     * Return all active chapels ordered by name.
     */
    public function list_active(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY name ASC";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Return all chapels (active + inactive).
     */
    public function list_all(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $sql = "SELECT * FROM {$this->table} ORDER BY name ASC";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Returns the default chapel ID.
     *
     * Rule:
     *  1) If slug = 'main-chapel' exists, that's default.
     *  2) Otherwise, the lowest (oldest) id is default.
     */
    public function get_default_chapel_id(): int {
        global $wpdb;

        // 1) Prefer main-chapel slug if present
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->table} WHERE slug = %s LIMIT 1", 'main-chapel')
        );
        if ($id > 0) return $id;

        // 2) Fallback: lowest id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $id = (int) $wpdb->get_var("SELECT id FROM {$this->table} ORDER BY id ASC LIMIT 1");
        return $id > 0 ? $id : 0;
    }

    public function is_default_chapel(int $id): bool {
        $id = (int)$id;
        if ($id <= 0) return false;
        $default_id = $this->get_default_chapel_id();
        return ($default_id > 0 && $id === $default_id);
    }

    /**
     * Ensure at least one chapel exists; returns its ID.
     * Creates "Main Chapel" as default if none exist.
     */
    public function ensure_default_chapel_exists(): int {
        global $wpdb;

        $default_id = $this->get_default_chapel_id();
        if ($default_id > 0) return $default_id;

        // Nothing exists — create Main Chapel
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($this->table, [
            'name'      => 'Main Chapel',
            'slug'      => 'main-chapel',
            'is_active' => 1,
        ], [ '%s', '%s', '%d' ]);

        return (int)$wpdb->insert_id;
    }

    public function count_active(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE is_active = 1");
    }

    public function find(int $id): ?array {
        global $wpdb;
        $id = (int)$id;
        if ($id <= 0) return null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function create(string $name, ?string $slug = null, bool $is_active = true): int {
        global $wpdb;

        $name = sanitize_text_field($name);
        if ($name === '') return 0;

        $slug = $slug !== null && $slug !== '' ? $slug : $name;
        $slug = sanitize_title($slug);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert($this->table, [
            'name'      => $name,
            'slug'      => $slug,
            'is_active' => $is_active ? 1 : 0,
        ], [ '%s', '%s', '%d' ]);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function update(int $id, array $data): bool {
        global $wpdb;

        $id = (int)$id;
        if ($id <= 0) return false;

        $update = [];

        if (array_key_exists('name', $data)) {
            $update['name'] = sanitize_text_field((string)$data['name']);
        }
        if (array_key_exists('slug', $data)) {
            $update['slug'] = sanitize_title((string)$data['slug']);
        }
        if (array_key_exists('is_active', $data)) {
            $update['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }

        if (empty($update)) return true;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r = $wpdb->update($this->table, $update, [ 'id' => $id ]);
        return $r !== false;
    }

    /**
     * Returns TRUE if this chapel ID is referenced by schedules or slots.
     * (We block delete if in-use.)
     */
    public function is_in_use(int $chapel_id): bool {
        global $wpdb;
        $chapel_id = (int)$chapel_id;
        if ($chapel_id <= 0) return false;

        $schedules_table = $wpdb->prefix . 'adoration_schedules';
        $slots_table     = $wpdb->prefix . 'adoration_slots';

        // Schedules referencing chapel_id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $sch = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$schedules_table} WHERE chapel_id = %d", $chapel_id)
        );
        if ($sch > 0) return true;

        // Slots referencing chapel_id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $sl = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$slots_table} WHERE chapel_id = %d", $chapel_id)
        );
        return $sl > 0;
    }

    /**
     * Delete chapel row.
     * NOTE: Caller should enforce business rules (default/in-use/last active).
     */
    public function delete(int $id): bool {
        global $wpdb;
        $id = (int)$id;
        if ($id <= 0) return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r = $wpdb->delete($this->table, [ 'id' => $id ], [ '%d' ]);
        return ($r !== false && $r > 0);
    }
}
