<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * SignupCancellationService
 *
 * Cancels a signup for the currently logged-in plugin user (MagicLinkService session).
 *
 * Endpoint:
 *  - POST admin-post.php?action=adoration_cancel_signup
 *  - POST admin-post.php?action=adoration_cancel_signup (nopriv also supported)
 *
 * POST fields:
 *  - signup_id (int)
 *  - return (url) where to redirect back
 *  - _wpnonce (action: adoration_cancel_signup_{signup_id})
 */
class SignupCancellationService
{
    public const ACTION = 'adoration_cancel_signup';

    /**
     * Basic rate-limit for cancel attempts (guard rail against abuse).
     */
    private const RL_WINDOW_SECONDS = 60; // 1 minute window
    private const RL_MAX_ATTEMPTS   = 8;  // per (person, signup, ip) per window

    // AJAX conversion (2026-07-20): set by ajax_cancel() before calling the
    // SAME handle() used by the full-page admin-post flow, so every exit
    // point (routed through finish_redirect() below) can branch between a
    // redirect and a JSON response without duplicating the cancel logic.
    private static bool $is_ajax = false;

    public static function register(): void
    {
        add_action('admin_post_nopriv_adoration_cancel_signup', [__CLASS__, 'handle']);
        add_action('admin_post_adoration_cancel_signup',        [__CLASS__, 'handle']);

        add_action('wp_ajax_' . self::ACTION,        [__CLASS__, 'ajax_cancel']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'ajax_cancel']);
    }

    public static function ajax_cancel(): void
    {
        self::$is_ajax = true;
        self::handle();
    }

    public static function handle(): void
    {
        // POST-only hard guard
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        // Determine return URL (safe)
        $return = isset($_POST['return']) ? (string) wp_unslash($_POST['return']) : '';
        $return = $return ? self::safe_redirect_url($return) : '';
        if (!$return) $return = wp_get_referer();
        if (!$return) $return = home_url('/my-adoration/');

        $fail = self::add_toast($return, 'error', 'Could not cancel that signup. Please try again.');

        // Must be logged in via plugin session
        if (!class_exists('\\AdorationScheduler\\Services\\MagicLinkService')) {
            self::finish_redirect($fail);
        }

        $person = \AdorationScheduler\Services\MagicLinkService::current_person_or_admin_match();
        if (!$person || empty($person['id'])) {
            $fail2 = self::add_toast($return, 'error', 'Please sign in to manage your signups.');
            self::finish_redirect($fail2);
        }
        $person_id = (int) $person['id'];

        // Validate input
        $signup_id = isset($_POST['signup_id']) ? (int) wp_unslash($_POST['signup_id']) : 0;
        if ($signup_id <= 0) {
            self::finish_redirect($fail);
        }

        // Rate-limit early (per person+signup+ip)
        if (!self::rate_limit_ok($person_id, $signup_id)) {
            $throttled = self::add_toast($return, 'error', 'Too many attempts. Please wait a moment and try again.');
            self::finish_redirect($throttled);
        }

        // Nonce check: action is per-signup id
        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adoration_cancel_signup_' . $signup_id)) {
            self::finish_redirect($fail);
        }

        // Cancel in DB (only if it belongs to this person)
        $ok = self::cancel_signup($signup_id, $person_id);

        if (!$ok) {
            self::finish_redirect($fail);
        }

        // IMPORTANT: unschedule any pending reminders for this signup (best-effort)
        try {
            if (class_exists('\\AdorationScheduler\\Services\\ReminderScheduler')
                && method_exists('\\AdorationScheduler\\Services\\ReminderScheduler', 'unschedule_for_signup')) {
                \AdorationScheduler\Services\ReminderScheduler::unschedule_for_signup($signup_id);
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] SignupCancellationService unschedule failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
        }

        $success = self::add_toast($return, 'success', 'Signup cancelled.');
        self::finish_redirect($success);
    }

    /**
     * Terminates the request: redirects with a toast (normal form submit)
     * or sends a JSON response (AJAX submit), reusing the same message
     * that was already built into $url by add_toast().
     */
    private static function finish_redirect(string $url): void
    {
        if (self::$is_ajax) {
            $parts = wp_parse_url($url);
            $query = [];
            if (!empty($parts['query'])) parse_str($parts['query'], $query);

            $message = isset($query['as_toast']) ? rawurldecode((string) $query['as_toast']) : 'Done.';
            $type    = isset($query['as_toast_type']) ? (string) $query['as_toast_type'] : 'info';

            if ($type === 'error') {
                wp_send_json_error(['message' => $message, 'type' => $type]);
            }
            wp_send_json_success(['message' => $message, 'type' => $type]);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Basic rate limiter: counts attempts in a rolling window using transients.
     */
    private static function rate_limit_ok(int $person_id, int $signup_id): bool
    {
        $ip = self::client_ip();
        $key = 'as_rl_cancel_' . md5($person_id . '|' . $signup_id . '|' . $ip);

        $data = get_transient($key);
        if (!is_array($data)) {
            $data = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $now = time();
        $start = (int)($data['start'] ?? $now);
        $count = (int)($data['count'] ?? 0);

        // Reset window if expired
        if (($now - $start) >= self::RL_WINDOW_SECONDS) {
            $start = $now;
            $count = 0;
        }

        $count++;
        $data['count'] = $count;
        $data['start'] = $start;

        // Store for the remaining window (+ a small buffer)
        $ttl = max(5, self::RL_WINDOW_SECONDS - ($now - $start)) + 5;
        set_transient($key, $data, $ttl);

        return ($count <= self::RL_MAX_ATTEMPTS);
    }

    private static function client_ip(): string
    {
        // Minimal, safe-ish IP extraction (best-effort). Avoid trusting forwarded headers blindly.
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string)$_SERVER['REMOTE_ADDR'];
        }
        $ip = trim($ip);
        if ($ip === '') return '0.0.0.0';

        // Normalize IPv6/IPv4 strings to a bounded length
        if (strlen($ip) > 64) {
            $ip = substr($ip, 0, 64);
        }
        return $ip;
    }

    /**
     * Cancel the signup if it belongs to this person.
     *
     * Returns true if:
     * - updated successfully, OR
     * - already cancelled (and we best-effort ensure is_active=0 if column exists)
     *
     * Returns false if:
     * - row not found / not owned by this person
     *
     * Public (was private) so integration tests can exercise the actual
     * cancel logic directly — handle() itself always ends in exit() via
     * finish_redirect(), which isn't practical to run under PHPUnit.
     */
    public static function cancel_signup(int $signup_id, int $person_id): bool
    {
        global $wpdb;

        // MUST match Installer table naming
        $table = $wpdb->prefix . 'adoration_signups';

        $has_updated_at = self::table_has_column($table, 'updated_at');
        $has_is_active  = self::table_has_column($table, 'is_active');

        // Pull row (include slot_id so we can prevent uniq collisions when setting is_active=0)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, person_id, slot_id, status" . ($has_is_active ? ", is_active" : "") . "
                 FROM {$table}
                 WHERE id = %d AND person_id = %d
                 LIMIT 1",
                $signup_id,
                $person_id
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['id'])) {
            return false;
        }

        $slot_id       = (int)($row['slot_id'] ?? 0);
        $status        = strtolower((string)($row['status'] ?? ''));
        $is_active_val = $has_is_active ? (int)($row['is_active'] ?? 1) : 1;

        // Store UTC
        $now = gmdate('Y-m-d H:i:s');

        /**
         * If we use UNIQUE(person_id, slot_id, is_active),
         * then setting is_active=0 can FAIL if another inactive row already exists.
         *
         * Defensive fix: before we set THIS row inactive, delete any other row
         * with the same (person_id, slot_id, is_active=0) that is NOT this row.
         *
         * We keep the most recent “current” record and prune older inactive duplicates.
         */
        if ($has_is_active && $slot_id > 0) {
            try {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table}
                         WHERE person_id = %d
                           AND slot_id = %d
                           AND is_active = 0
                           AND id <> %d",
                        $person_id,
                        $slot_id,
                        $signup_id
                    )
                );
            } catch (\Throwable $e) {
                // Don't fail cancellation because cleanup failed; we try the update anyway.
                error_log('[AdorationScheduler] SignupCancellationService cleanup inactive dupes failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            }
        }

        // If already cancelled, still ensure is_active=0 (best-effort)
        if ($status === 'cancelled') {
            if ($has_is_active && $is_active_val !== 0) {
                $data = ['is_active' => 0];
                $fmt  = ['%d'];

                if ($has_updated_at) {
                    $data['updated_at'] = $now;
                    $fmt[] = '%s';
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $updated = $wpdb->update(
                    $table,
                    $data,
                    [
                        'id'        => $signup_id,
                        'person_id' => $person_id,
                    ],
                    $fmt,
                    ['%d', '%d']
                );

                if ($updated === false) {
                    error_log('[AdorationScheduler] SignupCancellationService: already-cancelled but failed to set is_active=0 signup_id=' . $signup_id . ' err=' . $wpdb->last_error);
                }
            }
            return true;
        }

        $data = [
            'status' => 'cancelled',
        ];
        $fmt  = ['%s'];

        // mark inactive if column exists
        if ($has_is_active) {
            $data['is_active'] = 0;
            $fmt[] = '%d';
        }

        if ($has_updated_at) {
            $data['updated_at'] = $now;
            $fmt[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $updated = $wpdb->update(
            $table,
            $data,
            [
                'id'        => $signup_id,
                'person_id' => $person_id,
            ],
            $fmt,
            ['%d', '%d']
        );

        // A confirmed seat was just freed — offer it to whoever's been
        // waiting longest for this slot (best-effort; never blocks cancel).
        if ($updated !== false && $slot_id > 0
            && class_exists('\\AdorationScheduler\\Services\\WaitlistService')
            && method_exists('\\AdorationScheduler\\Services\\WaitlistService', 'promote_next_for_slot')) {
            try {
                \AdorationScheduler\Services\WaitlistService::promote_next_for_slot($slot_id);
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] SignupCancellationService waitlist promotion failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            }
        }

        return ($updated !== false);
    }

    /**
     * Best-effort, safe check for a column existing on a table.
     */
    private static function table_has_column(string $table, string $column): bool
    {
        global $wpdb;

        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($column === '') return false;

        try {
            $like = $wpdb->esc_like($column);
            $sql  = "SHOW COLUMNS FROM `{$table}` LIKE %s";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_var($wpdb->prepare($sql, $like));
            return !empty($found);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Add toast args (new system only).
     */
    private static function add_toast(string $url, string $type, string $text, bool $sticky = false): string
    {
        $url = remove_query_arg(['as_toast','as_toast_type','as_toast_sticky'], $url);

        $type = sanitize_key($type);
        $allowed = ['success','error','warning','info'];
        if (!in_array($type, $allowed, true)) $type = 'info';

        $text = sanitize_text_field($text);
        $text = trim($text);
        if ($text === '') {
            $text = ($type === 'error') ? 'Action failed.' : 'Done.';
        }
        if (strlen($text) > 300) {
            $text = substr($text, 0, 300);
        }

        return add_query_arg([
            'as_toast'        => rawurlencode($text),
            'as_toast_type'   => $type,
            'as_toast_sticky' => $sticky ? '1' : '0',
        ], $url);
    }

    private static function safe_redirect_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';

        if (strpos($url, '/') === 0) {
            return home_url($url);
        }

        $safe = wp_validate_redirect($url, '');
        if (!$safe) return '';

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $safe_host = wp_parse_url($safe, PHP_URL_HOST);

        if ($home_host && $safe_host && strtolower((string)$home_host) !== strtolower((string)$safe_host)) {
            return '';
        }

        return $safe;
    }
}
