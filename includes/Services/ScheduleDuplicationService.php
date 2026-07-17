<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Services\SlotGenerator;

class ScheduleDuplicationService {

    /**
     * Admin-post action name.
     */
    public const ACTION = 'adoration_duplicate_schedule';

    /**
     * Granular capability (future-friendly).
     * If not yet added to roles, we fall back to manage_options.
     */
    private const CAP_MANAGE_SCHEDULES = 'adoration_manage_schedules';

    /**
     * Basic rate limiting for duplicate (prevents accidental double-clicks).
     */
    private const RL_WINDOW_SECONDS = 60; // 1 minute
    private const RL_MAX_ATTEMPTS   = 20; // per user per schedule per window

    /**
     * Register admin-post hook.
     */
    public static function register(): void {
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_duplicate_schedule']);
    }

    /**
     * Duplicate a schedule:
     * - copies schedule row (new name/slug, status=draft)
     * - copies date_patterns + segments by introspecting table columns
     * - regenerates slots after copy (best-effort)
     */
    public static function handle_duplicate_schedule(): void {

        self::require_admin_cap(self::CAP_MANAGE_SCHEDULES);

        // Hard guard: only allow GET/POST (some hosts send HEAD)
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        if (!in_array($method, ['GET','POST'], true)) {
            wp_safe_redirect(admin_url('admin.php?page=adoration_scheduler_schedules'));
            exit;
        }

        // Keep your existing GET-based behavior so we don't have to touch UI
        $source_id = isset($_GET['schedule_id']) ? (int) wp_unslash($_GET['schedule_id']) : 0;
        if ($source_id <= 0) {
            self::redirect_with_toast('Missing schedule ID.', 'error');
        }

        if (!self::rate_limit_ok('duplicate', $source_id)) {
            self::redirect_with_toast('Too many attempts. Please wait a moment and try again.', 'error');
        }

        check_admin_referer('adoration_duplicate_schedule_' . $source_id);

        $repo = new SchedulesRepository();

        // include_deleted=true so you can duplicate a trashed schedule if desired (optional)
        $src = method_exists($repo, 'find_by_id')
            ? $repo->find_by_id($source_id, true)
            : $repo->find($source_id);

        if (!$src) {
            self::redirect_with_toast('Schedule not found.', 'error');
        }

        // -------------------------
        // Build new schedule payload
        // -------------------------
        $base_name = (string)($src['name'] ?? 'Schedule');
        $new_name  = self::generate_copy_name($base_name);

        // Slug: base off original slug if present, otherwise name
        $base_slug = sanitize_title((string)($src['slug'] ?? $base_name));
        if ($base_slug === '') $base_slug = 'schedule';

        $new_slug = self::unique_slug($repo, $base_slug . '-copy');

        // Copy everything we care about (and keep behavior consistent)
        $payload = [
            'chapel_id'           => (int)($src['chapel_id'] ?? 0),
            'name'                => $new_name,
            'slug'                => $new_slug,
            'type'                => (string)($src['type'] ?? 'event'),
            'start_date'          => $src['start_date'] ?? null,
            'end_date'            => $src['end_date'] ?? null,
            'is_overnight'        => !empty($src['is_overnight']) ? 1 : 0,
            'default_slot_length' => (int)($src['default_slot_length'] ?? 60),
            'default_min_adorers' => (int)($src['default_min_adorers'] ?? 1),
            'default_max_adorers' => array_key_exists('default_max_adorers', $src) ? $src['default_max_adorers'] : null,
            'privacy_mode'        => (string)($src['privacy_mode'] ?? 'counts_only'),

            // Always start duplicated schedules as draft (safer)
            'status'              => 'draft',

            // Copy settings_json as-is (email templates, etc.)
            'settings_json'       => $src['settings_json'] ?? null,
        ];

        $new_id = (int)$repo->create($payload);
        if ($new_id <= 0) {
            self::redirect_with_toast('Could not duplicate schedule (insert failed).', 'error');
        }

        // -------------------------
        // Copy dependents
        // -------------------------
        self::copy_dependents($source_id, $new_id);

        // -------------------------
        // Regenerate slots (best-effort)
        // -------------------------
        self::regenerate_slots_best_effort($new_id);

        // Redirect to edit page
        $edit_url = add_query_arg([
            'page'         => 'adoration_scheduler_schedules',
            'action'       => 'edit',
            'schedule_id'  => $new_id,
            'tab'          => 'overview',
            'as_toast'      => 'Schedule duplicated.',
            'as_toast_type' => 'success',
        ], admin_url('admin.php'));

        wp_safe_redirect($edit_url);
        exit;
    }

    /**
     * Copy rows in date_patterns and segments from $from_id to $to_id.
     * Uses SHOW COLUMNS so we don’t have to hardcode schema.
     */
    private static function copy_dependents(int $from_id, int $to_id): void {
        global $wpdb;

        $from_id = (int)$from_id;
        $to_id   = (int)$to_id;

        $tables = [
            $wpdb->prefix . 'adoration_date_patterns',
            $wpdb->prefix . 'adoration_segments',
        ];

        foreach ($tables as $table) {
            if (!self::table_exists($table)) {
                continue;
            }

            $cols = self::get_table_columns($table);
            if (empty($cols)) {
                continue;
            }

            // Remove primary key-ish columns we never copy
            $cols = array_values(array_filter($cols, function($c){
                return !in_array($c, ['id'], true);
            }));

            if (empty($cols)) {
                continue;
            }

            // Build INSERT INTO t (col1,col2,...) SELECT ... FROM t WHERE schedule_id=from
            // For schedule_id column, we inject "to_id AS schedule_id"
            $select_parts = [];
            foreach ($cols as $c) {
                if ($c === 'schedule_id') {
                    $select_parts[] = '%d AS schedule_id';
                } else {
                    $select_parts[] = $c;
                }
            }

            $sql = sprintf(
                "INSERT INTO %s (%s) SELECT %s FROM %s WHERE schedule_id = %%d",
                $table,
                implode(',', $cols),
                implode(',', $select_parts),
                $table
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query($wpdb->prepare($sql, $to_id, $from_id));
        }
    }

    private static function make_slot_generator_best_effort(): ?object {
        $class = SlotGenerator::class;
        if (!class_exists($class)) {
            $class = '\\AdorationScheduler\\Domain\\Services\\SlotGenerator';
            if (!class_exists($class)) return null;
        }

        try {
            $ref  = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();

            if (!$ctor || $ctor->getNumberOfRequiredParameters() === 0) {
                return $ref->newInstance();
            }

            $args = [];
            foreach ($ctor->getParameters() as $p) {
                $type = $p->getType();
                $typeName = ($type instanceof \ReflectionNamedType) ? ltrim($type->getName(), '\\') : '';

                switch ($typeName) {
                    case 'AdorationScheduler\\Domain\\Repositories\\SchedulesRepository':
                        $args[] = new SchedulesRepository();
                        break;
                    case 'AdorationScheduler\\Domain\\Repositories\\SlotsRepository':
                        $args[] = new SlotsRepository();
                        break;
                    case 'AdorationScheduler\\Domain\\Repositories\\SegmentsRepository':
                        $args[] = new SegmentsRepository();
                        break;
                    case 'AdorationScheduler\\Domain\\Repositories\\DatePatternsRepository':
                        $args[] = new DatePatternsRepository();
                        break;
                    default:
                        if ($p->isDefaultValueAvailable()) {
                            $args[] = $p->getDefaultValue();
                        } elseif ($p->allowsNull()) {
                            $args[] = null;
                        } else {
                            error_log('[AdorationScheduler] SlotGenerator ctor param unsupported: ' . ($typeName ?: ('$' . $p->getName())));
                            return null;
                        }
                }
            }

            return $ref->newInstanceArgs($args);

        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] SlotGenerator init failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function regenerate_slots_best_effort(int $schedule_id): void {
        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return;

        $gen = self::make_slot_generator_best_effort();
        if (!$gen) return;

        // SlotGenerator's real API takes the full schedule row (array), not just an ID,
        // and exposes generate_for_event_schedule() / sync_for_event_schedule() / preview_for_event_schedule().
        $repo = new SchedulesRepository();
        $schedule = method_exists($repo, 'find_by_id')
            ? $repo->find_by_id($schedule_id, true)
            : $repo->find($schedule_id);

        if (!$schedule) {
            error_log('[AdorationScheduler] Slot regenerate skipped: schedule not found id=' . $schedule_id);
            return;
        }

        $candidates = [
            'generate_for_event_schedule',
            'sync_for_event_schedule',
        ];

        foreach ($candidates as $m) {
            if (method_exists($gen, $m)) {
                try {
                    $gen->{$m}($schedule);
                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] Slot regenerate failed: ' . $e->getMessage());
                }
                return;
            }
        }

        error_log('[AdorationScheduler] Slot regenerate skipped: no usable SlotGenerator method found for schedule id=' . $schedule_id);
    }

    private static function generate_copy_name(string $base): string {
        $base = trim($base);
        if ($base === '') $base = 'Schedule';
        return $base . ' (Copy)';
    }

    private static function unique_slug($repo, string $slug): string {
        $slug = sanitize_title($slug);
        if ($slug === '') $slug = 'schedule-copy';

        if (!method_exists($repo, 'exists_slug')) {
            return $slug;
        }

        if (!$repo->exists_slug($slug)) {
            return $slug;
        }

        $base = $slug;
        $i = 2;
        while ($repo->exists_slug($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private static function redirect_with_toast(string $message, string $type = 'info'): void {
        $url = add_query_arg([
            'page'          => 'adoration_scheduler_schedules',
            'as_toast'      => $message,
            'as_toast_type' => $type,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        $table = trim($table);
        if ($table === '') return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return !empty($exists);
    }

    private static function get_table_columns(string $table): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array)$wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $cols = [];
        foreach ($rows as $r) {
            if (!empty($r['Field'])) {
                $cols[] = (string)$r['Field'];
            }
        }
        return $cols;
    }

    // ----------------- guards -----------------

    private static function require_admin_cap(string $capability): void
    {
        if (!is_admin()) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }

        $capability = sanitize_key($capability);
        $allowed = false;

        if ($capability !== '' && current_user_can($capability)) {
            $allowed = true;
        } elseif (current_user_can('manage_options')) {
            $allowed = true;
        }

        if (!$allowed) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }
    }

    private static function rate_limit_ok(string $action, int $schedule_id): bool
    {
        $action = sanitize_key($action);
        if ($action === '') $action = 'action';

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) return false;

        $key = 'as_rl_admin_' . md5($action . '|' . $schedule_id . '|' . $user_id);

        $data = get_transient($key);
        if (!is_array($data)) {
            $data = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $now   = time();
        $start = (int)($data['start'] ?? $now);
        $count = (int)($data['count'] ?? 0);

        if (($now - $start) >= self::RL_WINDOW_SECONDS) {
            $start = $now;
            $count = 0;
        }

        $count++;
        $data['count'] = $count;
        $data['start'] = $start;

        $ttl = max(5, self::RL_WINDOW_SECONDS - ($now - $start)) + 5;
        set_transient($key, $data, $ttl);

        return ($count <= self::RL_MAX_ATTEMPTS);
    }
}
