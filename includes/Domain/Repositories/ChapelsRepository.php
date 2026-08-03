<?php
namespace AdorationScheduler\Domain\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Repository is the persistence boundary for chapel records.

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
        $sql = $wpdb->prepare("SELECT * FROM %i WHERE is_active = 1 ORDER BY name ASC", $this->table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Return all chapels (active + inactive).
     */
    public function list_all(): array {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM %i ORDER BY name ASC", $this->table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM %i WHERE slug = %s LIMIT 1", $this->table, 'main-chapel')
        );
        if ($id > 0) return $id;

        // 2) Fallback: lowest id
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i ORDER BY id ASC LIMIT 1", $this->table));
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($this->table, [
            'name'      => 'Main Chapel',
            'slug'      => 'main-chapel',
            'is_active' => 1,
        ], [ '%s', '%s', '%d' ]);

        return (int)$wpdb->insert_id;
    }

    public function count_active(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE is_active = 1", $this->table));
    }

    public function find(int $id): ?array {
        global $wpdb;
        $id = (int)$id;
        if ($id <= 0) return null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $this->table, $id),
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
        if (array_key_exists('checkin_early_minutes', $data)) {
            $update['checkin_early_minutes'] = max(0, min(120, (int)$data['checkin_early_minutes']));
        }
        if (array_key_exists('guest_checkin_enabled', $data)) {
            $update['guest_checkin_enabled'] = !empty($data['guest_checkin_enabled']) ? 1 : 0;
        }
        if (array_key_exists('checkout_enabled', $data)) {
            $update['checkout_enabled'] = !empty($data['checkout_enabled']) ? 1 : 0;
        }
        if (array_key_exists('kiosk_name_display', $data)) {
            $name_display = sanitize_key((string)$data['kiosk_name_display']);
            $allowed = ['first_last_initial', 'first_name', 'initials', 'full_name'];
            $update['kiosk_name_display'] = in_array($name_display, $allowed, true)
                ? $name_display
                : 'first_last_initial';
        }

        if (empty($update)) return true;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $sch = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE chapel_id = %d", $schedules_table, $chapel_id)
        );
        if ($sch > 0) return true;

        // Slots referencing chapel_id
        $sl = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE chapel_id = %d", $slots_table, $chapel_id)
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $r = $wpdb->delete($this->table, [ 'id' => $id ], [ '%d' ]);
        return ($r !== false && $r > 0);
    }

    // -------------------------------------------------------------------------
    // KIOSK CHECK-IN TOKEN (2026-07-18)
    //
    // Identifies a physical chapel for the public, no-login "who's on right
    // now, tap to check in" kiosk page — a QR code printed for the chapel
    // entrance encodes this URL. Mirrors PersonsRepository's calendar_token
    // pattern, but scoped to a location rather than a person.
    // -------------------------------------------------------------------------

    public function get_or_create_kiosk_token(int $chapel_id): ?string {
        if ($chapel_id <= 0) return null;

        $chapel = $this->find($chapel_id);
        if (!$chapel) return null;

        $existing = trim((string)($chapel['kiosk_token'] ?? ''));
        if ($existing !== '') return $existing;

        return $this->regenerate_kiosk_token($chapel_id);
    }

    /**
     * Issue a brand-new kiosk token, replacing any previous one (e.g. if a
     * printed QR code is lost/compromised). Any old QR code stops working.
     */
    public function regenerate_kiosk_token(int $chapel_id): ?string {
        global $wpdb;

        $chapel_id = (int)$chapel_id;
        if ($chapel_id <= 0) return null;

        $token = bin2hex(random_bytes(32));

        $res = $wpdb->update(
            $this->table,
            ['kiosk_token' => $token],
            ['id' => $chapel_id],
            ['%s'],
            ['%d']
        );

        return ($res === 1) ? $token : null;
    }

    /**
     * Look up a chapel by its raw kiosk token (as it appears in the kiosk
     * page's URL). Returns null on no match.
     */
    public function find_by_kiosk_token(string $token): ?array {
        global $wpdb;

        $token = trim($token);
        if ($token === '') return null;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE kiosk_token = %s LIMIT 1", $this->table, $token),
            ARRAY_A
        );

        return $row ?: null;
    }
}
