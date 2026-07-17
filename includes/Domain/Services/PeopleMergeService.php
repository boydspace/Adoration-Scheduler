<?php
namespace AdorationScheduler\Domain\Services;

use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) exit;

class PeopleMergeService {

    public static function register(): void {
        add_action('admin_post_adoration_merge_people', [__CLASS__, 'handle']);
    }

    public static function handle(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        check_admin_referer('adoration_merge_people');

        $page = sanitize_key((string) wp_unslash($_POST['page'] ?? 'adoration_scheduler_people_merge'));
        if ($page === '') $page = 'adoration_scheduler_people_merge';

        $from_id = isset($_POST['from_person_id']) ? (int) wp_unslash($_POST['from_person_id']) : 0;
        $to_id   = isset($_POST['to_person_id'])   ? (int) wp_unslash($_POST['to_person_id'])   : 0;

        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            self::redirect_with($page, [
                'merge_error'     => 'invalid',
                'from_person_id'  => (string)$from_id,
                'to_person_id'    => (string)$to_id,
            ]);
        }

        $persons = new PersonsRepository();
        $from = $persons->find($from_id);
        $to   = $persons->find($to_id);

        if (!$from || !$to) {
            self::redirect_with($page, [
                'merge_error'     => 'not_found',
                'from_person_id'  => (string)$from_id,
                'to_person_id'    => (string)$to_id,
            ]);
        }

        // 1) Try repository-driven merge first (if your SignupsRepository supports it)
        $signupsRepo = self::make_signups_repo();
        if ($signupsRepo) {
            $from_signups = self::get_signups_for_person($signupsRepo, $from_id);
            $to_signups   = self::get_signups_for_person($signupsRepo, $to_id);

            if (is_array($from_signups) && is_array($to_signups)) {
                $to_slot_ids = [];
                foreach ($to_signups as $s) {
                    $sid = (int)($s['slot_id'] ?? 0);
                    if ($sid > 0) $to_slot_ids[$sid] = true;
                }

                $moved = 0;
                $skipped = 0;

                foreach ($from_signups as $s) {
                    $signup_id = (int)($s['id'] ?? 0);
                    $slot_id   = (int)($s['slot_id'] ?? 0);
                    if ($signup_id <= 0 || $slot_id <= 0) continue;

                    // If "to" already has this slot, delete the "from" signup (duplicate)
                    if (isset($to_slot_ids[$slot_id])) {
                        if (self::delete_signup($signupsRepo, $signup_id, $from_id, $slot_id)) {
                            $skipped++;
                        }
                        continue;
                    }

                    // Otherwise reassign
                    if (self::reassign_signup_to_person($signupsRepo, $signup_id, $to_id, $slot_id, $from_id)) {
                        $moved++;
                        $to_slot_ids[$slot_id] = true;
                    }
                }

                $deleted = self::delete_source_person($persons, $from_id);

                self::redirect_with($page, [
                    'merged'         => '1',
                    'merge_moved'    => (string)$moved,
                    'merge_skipped'  => (string)$skipped,
                    'merge_deleted'  => $deleted ? '1' : '0',
                    'from_person_id' => (string)$from_id,
                    'to_person_id'   => (string)$to_id,
                ]);
            }
        }

        // 2) Fallback: do merge via $wpdb (works even if repo methods don't match)
        $result = self::merge_via_wpdb($from_id, $to_id);
        if (empty($result['ok'])) {
            self::redirect_with($page, [
                'merge_error'     => $result['error'] ?: 'missing_reassign',
                'from_person_id'  => (string)$from_id,
                'to_person_id'    => (string)$to_id,
            ]);
        }

        // delete source person if possible
        $deleted = self::delete_source_person($persons, $from_id);

        self::redirect_with($page, [
            'merged'         => '1',
            'merge_moved'    => (string)($result['moved'] ?? 0),
            'merge_skipped'  => (string)($result['skipped'] ?? 0),
            'merge_deleted'  => $deleted ? '1' : '0',
            'from_person_id' => (string)$from_id,
            'to_person_id'   => (string)$to_id,
        ]);
    }

    // -----------------------------
    // Redirect (includes toast)
    // -----------------------------
    private static function redirect_with(string $page, array $args): void {
        $page = sanitize_key($page);
        if ($page === '') {
            $page = 'adoration_scheduler_people_merge';
        }

        $toast = self::toast_payload_from_args($args);

        if ($toast) {
            $type = sanitize_key((string)($toast['type'] ?? 'success'));
            $allowed = ['success','error','warning','info'];
            if (!in_array($type, $allowed, true)) $type = 'success';

            // Do NOT rawurlencode; add_query_arg() handles encoding.
            $msg = sanitize_text_field(wp_strip_all_tags((string)($toast['message'] ?? '')));
            if (strlen($msg) > 300) {
                $msg = substr($msg, 0, 300);
            }

            $args['as_toast']      = $msg;
            $args['as_toast_type'] = $type;

            if (!empty($toast['sticky'])) {
                $args['as_toast_sticky'] = '1';
            }
        }

        $base = admin_url('admin.php?page=' . $page);
        $url  = add_query_arg($args, $base);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Decide toast message/type based on redirect args.
     * We keep existing args intact so the page UI can still render details.
     */
    private static function toast_payload_from_args(array $args): ?array {
        // Error cases
        if (!empty($args['merge_error'])) {
            $code = sanitize_key((string)$args['merge_error']);
            $msg  = self::toast_message_for_error_code($code);
            if ($msg === '') {
                $msg = __('Merge failed.', 'adoration-scheduler');
            }
            return [
                'message' => $msg,
                'type'    => 'error',
                'sticky'  => true,
            ];
        }

        // Success cases
        if (!empty($args['merged']) && (string)$args['merged'] === '1') {
            $moved   = isset($args['merge_moved']) ? (int)$args['merge_moved'] : 0;
            $skipped = isset($args['merge_skipped']) ? (int)$args['merge_skipped'] : 0;

            $deleted = isset($args['merge_deleted']) && (string)$args['merge_deleted'] === '1';

            $parts = [];
            $parts[] = __('People merged.', 'adoration-scheduler');

            if ($moved > 0) {
                $parts[] = sprintf(
                    /* translators: %d is number of signups moved */
                    _n('%d signup moved.', '%d signups moved.', $moved, 'adoration-scheduler'),
                    $moved
                );
            }

            if ($skipped > 0) {
                $parts[] = sprintf(
                    /* translators: %d is number of duplicates removed/skipped */
                    _n('%d duplicate removed.', '%d duplicates removed.', $skipped, 'adoration-scheduler'),
                    $skipped
                );
            }

            if (!$deleted) {
                $parts[] = __('Source person was not deleted.', 'adoration-scheduler');
            }

            return [
                'message' => implode(' ', $parts),
                'type'    => 'success',
                'sticky'  => false,
            ];
        }

        return null;
    }

    private static function toast_message_for_error_code(string $code): string {
        switch ($code) {
            case 'invalid':
                return __('Please choose two different people to merge.', 'adoration-scheduler');
            case 'not_found':
                return __('One or both selected people could not be found.', 'adoration-scheduler');
            case 'missing_signups_repo':
                return __('Could not merge: signups storage not found.', 'adoration-scheduler');
            case 'missing_reassign':
                return __('Could not merge: signups table is missing required columns.', 'adoration-scheduler');
            default:
                return '';
        }
    }

    private static function delete_source_person(PersonsRepository $persons, int $from_id): bool {
        try {
            if (method_exists($persons, 'delete_person')) {
                return (bool)$persons->delete_person($from_id);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return false;
    }

    // -----------------------------
    // Repo helpers (best effort)
    // -----------------------------
    private static function make_signups_repo() {
        $candidates = [
            '\\AdorationScheduler\\Domain\\Repositories\\SignupsRepository',
            '\\AdorationScheduler\\Repositories\\SignupsRepository',
        ];
        foreach ($candidates as $c) {
            if (class_exists($c)) {
                try { return new $c(); } catch (\Throwable $e) {}
            }
        }
        return null;
    }

    private static function get_signups_for_person($repo, int $person_id): ?array {
        $method_candidates = [
            'list_signups_for_person',
            'get_signups_for_person',
            'find_by_person_id',
        ];

        foreach ($method_candidates as $m) {
            if (!method_exists($repo, $m)) continue;
            try {
                $rows = $repo->$m($person_id);
                if (!is_array($rows)) continue;

                $out = [];
                foreach ($rows as $r) {
                    $out[] = is_array($r) ? $r : (array)$r;
                }
                return $out;
            } catch (\Throwable $e) {}
        }
        return null;
    }

    private static function delete_signup($repo, int $signup_id, int $person_id, int $slot_id): bool {
        $method_candidates = ['delete_signup','delete','delete_by_id','remove_signup'];
        foreach ($method_candidates as $m) {
            if (!method_exists($repo, $m)) continue;
            try { return (bool)$repo->$m($signup_id); } catch (\Throwable $e) {}
        }

        $method_candidates2 = ['delete_by_person_and_slot','delete_signup_for_person_slot'];
        foreach ($method_candidates2 as $m) {
            if (!method_exists($repo, $m)) continue;
            try { return (bool)$repo->$m($person_id, $slot_id); } catch (\Throwable $e) {}
        }

        return false;
    }

    private static function reassign_signup_to_person($repo, int $signup_id, int $to_person_id, int $slot_id, int $from_person_id): bool {
        $method_candidates = [
            'reassign_signup_person',
            'reassign_person_in_signup',
            'reassign_person_in_signups',
            'update_signup_person',
            'set_signup_person',
        ];

        foreach ($method_candidates as $m) {
            if (!method_exists($repo, $m)) continue;

            try {
                $ok = null;

                try { $ok = $repo->$m($signup_id, $to_person_id); } catch (\Throwable $e) {}
                if ($ok === null) {
                    try { $ok = $repo->$m($from_person_id, $to_person_id, $slot_id); } catch (\Throwable $e) {}
                }
                if ($ok === null) {
                    try { $ok = $repo->$m($signup_id, $slot_id, $to_person_id); } catch (\Throwable $e) {}
                }

                if ($ok !== null) return (bool)$ok;
            } catch (\Throwable $e) {}
        }

        return false;
    }

    // -----------------------------
    // ✅ Fallback: merge via $wpdb
    // -----------------------------
    private static function merge_via_wpdb(int $from_id, int $to_id): array {
        global $wpdb;

        $tables_to_try = [
            $wpdb->prefix . 'adoration_signups',
            $wpdb->prefix . 'adoration_scheduler_signups',
            $wpdb->prefix . 'as_signups',
        ];

        $signup_table = '';
        foreach ($tables_to_try as $t) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
            if ($found === $t) {
                $signup_table = $t;
                break;
            }
        }

        if ($signup_table === '') {
            return ['ok' => false, 'error' => 'missing_signups_repo', 'moved' => 0, 'skipped' => 0];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = $wpdb->get_col("DESCRIBE `$signup_table`", 0);
        $cols = array_map('strval', (array)$cols);

        if (!in_array('person_id', $cols, true) || !in_array('slot_id', $cols, true)) {
            return ['ok' => false, 'error' => 'missing_reassign', 'moved' => 0, 'skipped' => 0];
        }

        // Get TO slot_ids
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $to_slots = $wpdb->get_col(
            $wpdb->prepare("SELECT slot_id FROM `$signup_table` WHERE person_id = %d", $to_id)
        );
        $to_slots = array_map('intval', (array)$to_slots);
        $to_slots = array_values(array_filter($to_slots, fn($v) => $v > 0));

        $skipped = 0;

        if (!empty($to_slots)) {
            $placeholders = implode(',', array_fill(0, count($to_slots), '%d'));
            $params = array_merge([$from_id], $to_slots);

            // Delete duplicates from FROM where TO already has that slot
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `$signup_table` WHERE person_id = %d AND slot_id IN ($placeholders)",
                    $params
                )
            );
            $skipped = (int)$wpdb->rows_affected;
        }

        // Move remaining FROM signups to TO
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `$signup_table` SET person_id = %d WHERE person_id = %d",
                $to_id,
                $from_id
            )
        );
        $moved = (int)$wpdb->rows_affected;

        return ['ok' => true, 'error' => '', 'moved' => $moved, 'skipped' => $skipped];
    }
}
