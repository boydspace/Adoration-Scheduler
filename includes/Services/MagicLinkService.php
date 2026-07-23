<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) {
    exit;
}

class MagicLinkService
{
    private const COOKIE_NAME = 'adoration_session';
    private const SESSION_TTL_SECONDS = 60 * 60 * 24 * 30; // 30 days
    private const LINK_TTL_SECONDS    = 60 * 15;           // 15 minutes

    // Rate limiting (magic link requests)
    private const RL_IP_WINDOW_SECONDS    = 60;   // 1 minute window
    private const RL_IP_MAX_PER_WINDOW    = 2;    // max 2 per minute per IP
    private const RL_EMAIL_WINDOW_SECONDS = 600;  // 10 minute window
    private const RL_EMAIL_MAX_PER_WINDOW = 3;    // max 3 per 10 minutes per email

    // AJAX conversion (2026-07-20): set by ajax_request() before calling the
    // SAME handle_request() the full-page "email me a sign-in link" form
    // uses. finish_redirect() (which every wp_safe_redirect()+exit in
    // handle_request() now goes through) branches on this flag to return
    // JSON instead of redirecting - same rate-limiting, same
    // never-leak-whether-the-email-exists messaging either way.
    private static bool $is_ajax = false;

    public static function register(): void
    {
        // Request link
        add_action('admin_post_nopriv_adoration_magic_request', [__CLASS__, 'handle_request']);
        add_action('admin_post_adoration_magic_request',        [__CLASS__, 'handle_request']);

        add_action('wp_ajax_adoration_magic_request',        [__CLASS__, 'ajax_request']);
        add_action('wp_ajax_nopriv_adoration_magic_request', [__CLASS__, 'ajax_request']);

        // Consume link
        add_action('admin_post_nopriv_adoration_magic_consume', [__CLASS__, 'handle_consume']);
        add_action('admin_post_adoration_magic_consume',        [__CLASS__, 'handle_consume']);

        // Logout
        add_action('admin_post_nopriv_adoration_magic_logout',  [__CLASS__, 'handle_logout']);
        add_action('admin_post_adoration_magic_logout',         [__CLASS__, 'handle_logout']);
    }

    public static function ajax_request(): void
    {
        self::$is_ajax = true;
        self::handle_request();
    }

    /**
     * Returns the currently authenticated person row (adoration_persons.*) or null.
     *
     * Cookie stores raw session token; DB stores hashes (and may also store raw token).
     */
    public static function current_person(): ?array
    {
        global $wpdb;

        $raw = isset($_COOKIE[self::COOKIE_NAME]) ? trim((string)$_COOKIE[self::COOKIE_NAME]) : '';
        if ($raw === '') return null;

        $sessions = $wpdb->prefix . 'adoration_sessions';
        $persons  = $wpdb->prefix . 'adoration_persons';

        $token_hash = self::hash_token($raw);
        $now = gmdate('Y-m-d H:i:s');

        /**
         * ✅ FIX:
         * Your sessions schema supports multiple identifiers:
         * - session_token_hash (hash)
         * - session_hash (hash)
         * - session_token (raw)
         *
         * So be robust and match ANY of them.
         * Also respect revoked_at if present.
         */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT p.*
            FROM {$sessions} s
            INNER JOIN {$persons} p ON p.id = s.person_id
            WHERE s.expires_at > %s
              AND (
                    (s.session_token_hash IS NOT NULL AND s.session_token_hash = %s)
                 OR (s.session_hash IS NOT NULL AND s.session_hash = %s)
                 OR (s.session_token IS NOT NULL AND s.session_token = %s)
              )
              AND (s.revoked_at IS NULL OR s.revoked_at = '0000-00-00 00:00:00')
            LIMIT 1
        ", $now, $token_hash, $token_hash, $raw), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Same as current_person(), but with a fallback: a WP admin (or anyone
     * with any of this plugin's "manage" capabilities) who has no
     * parishioner session gets matched to the person record with their own
     * WP account email, if one exists. This is the single canonical
     * implementation of the "admin preview" convention used across the
     * dashboard-family shortcodes (originally duplicated inline in
     * PersonDashboardTrait::guard_and_get_person(), AccountStatusShortcode,
     * and ScheduleShortcode) — reuse this instead of re-deriving it.
     *
     * Pass $is_admin_match by reference if the caller needs to know whether
     * the match came from the fallback (e.g. to hide a "Log out" button,
     * since there's no real session to clear in that case).
     */
    public static function current_person_or_admin_match(?bool &$is_admin_match = null): ?array
    {
        $is_admin_match = false;

        $person = self::current_person();
        if ($person) return $person;

        if (!is_user_logged_in()) return null;

        $is_staff = false;
        foreach ([
            'manage_options',
            'adoration_manage_signups',
            'adoration_manage_schedules',
            'adoration_manage_people',
            'adoration_manage_settings',
        ] as $cap) {
            if (current_user_can($cap)) { $is_staff = true; break; }
        }
        if (!$is_staff) return null;

        $email = (string)(wp_get_current_user()->user_email ?? '');
        if ($email === '') return null;

        if (!class_exists(\AdorationScheduler\Domain\Repositories\PersonsRepository::class)) return null;

        try {
            $repo = new \AdorationScheduler\Domain\Repositories\PersonsRepository();
            $matched = $repo->find_by_email($email);
            if ($matched) {
                $is_admin_match = true;
                return $matched;
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Admin->person email match failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Request a magic link email.
     *
     * IMPORTANT SAFETY:
     * - Only runs for admin-post.php?action=adoration_magic_request
     * - Only accepts POST
     * - Never leaks whether an email exists
     */
    public static function handle_request(): void
    {
        // Hard-guard against accidental invocation / misrouted posts.
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== 'adoration_magic_request') {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] MagicLinkService::handle_request HIT method=' . $method . ' uri=' . $uri);
        }

        if ($method !== 'POST') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        // Return URL (where the form lives)
        $return = isset($_POST['return']) ? (string) wp_unslash($_POST['return']) : '';
        $return = $return ? self::safe_redirect_url($return) : '';
        if (!$return) $return = wp_get_referer();
        if (!$return) $return = home_url('/');

        // ✅ Toast success (never leak account existence)
        $success = self::add_toast(
            $return,
            'success',
            'If that email exists in our system, a sign-in link has been sent.'
        );

        // Nonce (only after confirming POST)
        check_admin_referer('adoration_magic_request');

        $email = isset($_POST['email']) ? strtolower(trim((string) wp_unslash($_POST['email']))) : '';
        if ($email === '' || !is_email($email)) {
            $err = self::add_toast($return, 'error', 'Please enter a valid email address.');
            self::finish_redirect($err);
        }

        /**
         * RATE LIMITING
         * ✅ Skipped for local/dev requests (WP_DEBUG on, or the request is
         * from localhost) so repeated QA testing doesn't get silently
         * throttled — production visitors on real IPs are still fully
         * rate-limited either way.
         */
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '') $ip = '0.0.0.0';

        $blocked = false;

        if (!self::is_local_or_debug_request($ip)) {
            // Per-IP limiter
            $ip_key = self::rl_key('ip', $ip);
            $ip_count = (int) get_transient($ip_key);
            if ($ip_count >= self::RL_IP_MAX_PER_WINDOW) {
                $blocked = true;
            } else {
                set_transient($ip_key, $ip_count + 1, self::RL_IP_WINDOW_SECONDS);
            }

            // Per-email limiter
            $email_key = self::rl_key('email', $email);
            $email_count = (int) get_transient($email_key);
            if ($email_count >= self::RL_EMAIL_MAX_PER_WINDOW) {
                $blocked = true;
            } else {
                set_transient($email_key, $email_count + 1, self::RL_EMAIL_WINDOW_SECONDS);
            }
        }

        if ($blocked) {
            error_log('[AdorationScheduler] MagicLink rate-limited ip=' . $ip . ' email=' . $email);
            // still return success toast (no leakage)
            self::finish_redirect($success);
        }

        global $wpdb;
        $persons = $wpdb->prefix . 'adoration_persons';
        $magic   = $wpdb->prefix . 'adoration_magic_links';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $person = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$persons} WHERE email = %s LIMIT 1", $email),
            ARRAY_A
        );

        if (!is_array($person) || empty($person['id'])) {
            // Don't leak whether the email exists
            self::finish_redirect($success);
        }

        $person_id = (int)$person['id'];

        // Create one-time token
        $raw_token  = wp_generate_password(32, false, false);
        $token_hash = self::hash_token($raw_token);

        $now     = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + self::LINK_TTL_SECONDS);

        // Optional: invalidate old unused links for this person
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$magic} SET used_at = %s WHERE person_id = %d AND used_at IS NULL",
            $now,
            $person_id
        ));

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : null;
        if (is_string($ua) && strlen($ua) > 255) $ua = substr($ua, 0, 255);

        /**
         * ✅ FIX: selector is REQUIRED + UNIQUE in your schema.
         * If we don't insert it, MySQL inserts '' which immediately collides on the UNIQUE index.
         */
        $selector = self::generate_selector_24();

        // Insert magic link row (retry on rare selector collision)
        $ok = false;
        for ($i = 0; $i < 5; $i++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $ok = $wpdb->insert($magic, [
                'person_id'   => $person_id,
                'selector'    => $selector,
                'token_hash'  => $token_hash,
                'created_at'  => $now,
                'expires_at'  => $expires,

                // Your table has BOTH request_ip and ip; populate both for compatibility.
                'request_ip'  => $ip,
                'ip'          => $ip,

                'user_agent'  => $ua,
            ], ['%d','%s','%s','%s','%s','%s','%s','%s']);

            if ($ok !== false) {
                break;
            }

            // If selector collision (extremely unlikely), regenerate + retry.
            if (is_string($wpdb->last_error) && stripos($wpdb->last_error, 'selector') !== false) {
                $selector = self::generate_selector_24();
                continue;
            }

            // Otherwise don't loop.
            break;
        }

        if ($ok === false) {
            error_log('[AdorationScheduler] MagicLink insert FAILED last_error=' . $wpdb->last_error);
            error_log('[AdorationScheduler] MagicLink insert last_query=' . $wpdb->last_query);
            // still return success toast (no leakage)
            self::finish_redirect($success);
        }

        // Redirect destination after login
        $r = isset($_POST['r']) ? (string) wp_unslash($_POST['r']) : '';
        $r = $r ? self::safe_redirect_url($r) : '';
        if (!$r) $r = home_url('/my-adoration/');

        // Consume must go to admin-post.php
        $consume_url = add_query_arg([
            'action' => 'adoration_magic_consume',
            't'      => $raw_token,
            'r'      => $r,
        ], admin_url('admin-post.php'));

        /**
         * SEND EMAIL via NotificationService (single gateway)
         */
        $person_name = '';
        $first = isset($person['first_name']) ? trim((string)$person['first_name']) : '';
        $last  = isset($person['last_name']) ? trim((string)$person['last_name']) : '';
        $title = isset($person['title']) ? trim((string)$person['title']) : '';
        $person_name = trim($first . ' ' . $last);
        if ($person_name === '' && isset($person['name'])) $person_name = trim((string)$person['name']);

        $sent = NotificationService::send_magic_link([
            'to_email'      => $email,
            'title'         => $title,
            'first_name'    => $first,
            'last_name'     => $last,
            'person_name'   => $person_name,
            'schedule_name' => 'Adoration',

            // Provide BOTH keys so templates can use {manage_url} or {magic_url}
            'magic_url'     => $consume_url,
            'manage_url'    => $consume_url,

            'context'       => 'frontend',
            'person_id'     => $person_id,
            'token'         => $raw_token,
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] MagicLink send_magic_link sent=' . ($sent ? 'YES' : 'NO') . ' to=' . $email . ' person_id=' . $person_id);
        }

        // Always redirect with success toast, regardless of $sent (avoid leakage)
        self::finish_redirect($success);
    }

    /**
     * Terminates handle_request(): redirects with a toast (normal form
     * submit) or sends a JSON response (AJAX submit) built from the same
     * message already encoded into $url by add_toast() - so the two
     * transports never drift out of sync with each other.
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
     * Consume a magic link: admin-post.php?action=adoration_magic_consume&t=...&r=...
     */
    public static function handle_consume(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== 'adoration_magic_consume') {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] MagicLinkService::handle_consume HIT url=' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
        }

        $t = isset($_GET['t']) ? trim((string) wp_unslash($_GET['t'])) : '';
        $r = isset($_GET['r']) ? (string) wp_unslash($_GET['r']) : '';
        $r = $r ? self::safe_redirect_url($r) : '';
        if (!$r) $r = home_url('/my-adoration/');

        $fail = self::add_toast($r, 'error', 'That sign-in link is invalid or has expired. Please request a new one.');

        if ($t === '') {
            error_log('[AdorationScheduler] Magic consume missing token');
            wp_safe_redirect($fail);
            exit;
        }

        global $wpdb;
        $magic    = $wpdb->prefix . 'adoration_magic_links';
        $sessions = $wpdb->prefix . 'adoration_sessions';

        $token_hash = self::hash_token($t);
        $now = gmdate('Y-m-d H:i:s');

        // Atomic one-time use
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $updated = $wpdb->query($wpdb->prepare("
            UPDATE {$magic}
            SET used_at = %s
            WHERE token_hash = %s
              AND used_at IS NULL
              AND expires_at > %s
            LIMIT 1
        ", $now, $token_hash, $now));

        if ($updated !== 1) {
            error_log('[AdorationScheduler] Magic consume invalid/expired/already-used token_hash=' . substr($token_hash, 0, 12) . '... updated=' . (string)$updated);
            wp_safe_redirect($fail);
            exit;
        }

        // Fetch person_id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT person_id
            FROM {$magic}
            WHERE token_hash = %s
            LIMIT 1
        ", $token_hash), ARRAY_A);

        if (!is_array($row) || empty($row['person_id'])) {
            error_log('[AdorationScheduler] Magic consume: token marked used but person_id missing token_hash=' . substr($token_hash, 0, 12) . '...');
            wp_safe_redirect($fail);
            exit;
        }

        $person_id = (int)$row['person_id'];

        if (!self::start_session_for_person($person_id)) {
            $fail2 = self::add_toast($r, 'error', 'Could not sign you in. Please try again.');
            wp_safe_redirect($fail2);
            exit;
        }

        $ok_toast = self::add_toast($r, 'success', 'Signed in.');
        wp_safe_redirect($ok_toast);
        exit;
    }

    /**
     * Create a new session row for a person and set the session cookie.
     * Shared by both the magic-link consume flow and password sign-in
     * (PasswordAuthService), so there's exactly one place that knows how to
     * mint a session.
     */
    public static function start_session_for_person(int $person_id): bool
    {
        if ($person_id <= 0) return false;

        global $wpdb;
        $sessions = $wpdb->prefix . 'adoration_sessions';
        $now      = gmdate('Y-m-d H:i:s');

        /**
         * ✅ FIX: your sessions table REQUIRES:
         * - session_hash (CHAR(64) UNIQUE NOT NULL)
         * - session_token (VARCHAR(80) UNIQUE NOT NULL)
         *
         * We also populate session_token_hash to keep compatibility.
         */
        $session_token_raw  = wp_generate_password(64, false, false); // <= 80 chars
        $session_hash       = self::hash_token($session_token_raw);   // 64-char hex
        $expires_at         = gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS);

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : null;
        if (is_string($ua) && strlen($ua) > 255) $ua = substr($ua, 0, 255);

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;

        // Insert session (retry on rare UNIQUE collision)
        $ok = false;
        for ($i = 0; $i < 5; $i++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $ok = $wpdb->insert($sessions, [
                'person_id'          => $person_id,
                'session_hash'       => $session_hash,
                'session_token'      => $session_token_raw,
                'session_token_hash' => $session_hash, // compatibility
                'created_at'         => $now,
                'expires_at'         => $expires_at,
                'ip'                 => $ip,
                'user_agent'         => $ua,
            ], ['%d','%s','%s','%s','%s','%s','%s','%s']);

            if ($ok !== false) {
                break;
            }

            // Regenerate on collisions
            if (is_string($wpdb->last_error) && (stripos($wpdb->last_error, 'session_hash') !== false || stripos($wpdb->last_error, 'session_token') !== false)) {
                $session_token_raw = wp_generate_password(64, false, false);
                $session_hash      = self::hash_token($session_token_raw);
                continue;
            }

            break;
        }

        if ($ok === false) {
            error_log('[AdorationScheduler] SESSION INSERT FAILED');
            error_log('[AdorationScheduler] last_error=' . $wpdb->last_error);
            error_log('[AdorationScheduler] last_query=' . $wpdb->last_query);
            error_log('[AdorationScheduler] sessions_table=' . $sessions);
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] SESSION INSERT OK insert_id=' . (int)$wpdb->insert_id);
        }

        self::set_session_cookie($session_token_raw);

        return true;
    }

    public static function handle_logout(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== 'adoration_magic_logout') {
            return;
        }

        check_admin_referer('adoration_magic_logout');

        $return = wp_get_referer();
        if (!$return) $return = home_url('/');

        $raw = isset($_COOKIE[self::COOKIE_NAME]) ? trim((string)$_COOKIE[self::COOKIE_NAME]) : '';
        if ($raw !== '') {
            global $wpdb;
            $sessions = $wpdb->prefix . 'adoration_sessions';

            $hash = self::hash_token($raw);

            /**
             * ✅ FIX:
             * Delete by ANY of the possible identifiers, depending on schema/state.
             */
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$sessions}
                 WHERE (session_token_hash IS NOT NULL AND session_token_hash = %s)
                    OR (session_hash IS NOT NULL AND session_hash = %s)
                    OR (session_token IS NOT NULL AND session_token = %s)",
                $hash, $hash, $raw
            ));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] Logout deleted_sessions=' . (string)$deleted);
            }
        }

        self::clear_session_cookie();

        $out = self::add_toast($return, 'success', 'Signed out.');
        wp_safe_redirect($out);
        exit;
    }

    /**
     * Self-service account deletion: unlike handle_logout() (which only
     * ends the CURRENT browser's session), this revokes every session and
     * pending magic link this person has across every device, and clears
     * the current request's cookie too. Called by AccountDeletionService
     * right before PersonsRepository::anonymize_person() wipes the row's
     * PII, so nothing is left that could still authenticate as this
     * person after their data is gone.
     */
    public static function revoke_all_for_person(int $person_id): void
    {
        if ($person_id <= 0) return;

        global $wpdb;

        $sessions = $wpdb->prefix . 'adoration_sessions';
        $links    = $wpdb->prefix . 'adoration_magic_links';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($sessions, ['person_id' => $person_id], ['%d']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($links, ['person_id' => $person_id], ['%d']);

        self::clear_session_cookie();
    }

    private static function hash_token(string $raw): string
    {
        return hash_hmac('sha256', (string)$raw, wp_salt('auth'));
    }

    /**
     * Generate a 24-char selector (matches CHAR(24) column).
     */
    private static function generate_selector_24(): string
    {
        try {
            // 16 random bytes => 32 hex chars; take first 24
            return substr(bin2hex(random_bytes(16)), 0, 24);
        } catch (\Throwable $e) {
            // Fallback if random_bytes unavailable for some reason
            return substr(md5(uniqid((string)mt_rand(), true)), 0, 24);
        }
    }

    private static function set_session_cookie(string $raw_session_token): void
    {
        $cookie_args = [
            'expires'  => time() + self::SESSION_TTL_SECONDS,
            'path'     => '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE_NAME, $raw_session_token, $cookie_args);
        } else {
            $path = $cookie_args['path'] . '; samesite=Lax';
            setcookie(self::COOKIE_NAME, $raw_session_token, $cookie_args['expires'], $path, $cookie_args['domain'], $cookie_args['secure'], $cookie_args['httponly']);
        }
    }

    private static function clear_session_cookie(): void
    {
        $cookie_args = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE_NAME, '', $cookie_args);
        } else {
            $path = $cookie_args['path'] . '; samesite=Lax';
            setcookie(self::COOKIE_NAME, '', $cookie_args['expires'], $path, $cookie_args['domain'], $cookie_args['secure'], $cookie_args['httponly']);
        }
    }

    private static function safe_redirect_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';

        // Allow site-relative paths
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

    /**
     * ✅ True when a magic-link request should skip rate limiting:
     * requests from a local-machine IP (typical of a Laragon/local dev
     * setup, where every test click shares the same 127.0.0.1 address and
     * would otherwise trip the limiter almost immediately), or when
     * WP_DEBUG is on. Filterable in case a site wants different logic.
     */
    public static function is_local_or_debug_request(string $ip): bool
    {
        $local_ips = ['127.0.0.1', '::1'];
        $is_local  = in_array($ip, $local_ips, true);
        $is_debug  = defined('WP_DEBUG') && WP_DEBUG;

        return (bool) apply_filters(
            'adoration_scheduler_skip_magic_link_rate_limit',
            $is_local || $is_debug,
            $ip
        );
    }

    private static function rl_key(string $type, string $value): string
    {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$type));
        if ($type === '') $type = 'x';

        $value = trim((string)$value);
        if ($value === '') $value = 'empty';

        return 'adoration_magic_rl_' . $type . '_' . md5($value);
    }

    /**
     * Add frontend toast args (new system only).
     * Plugin.php should convert these into a one-shot cookie + clean URL.
     */
    private static function add_toast(string $url, string $type, string $text, bool $sticky = false): string
    {
        // Only remove the new toast args + old magic flags (if they ever appear),
        // but DO NOT reference legacy adoration_msg/adoration_text anymore.
        $url = remove_query_arg([
            'as_toast','as_toast_type','as_toast_sticky',
            'magic_sent','magic_error','logged_out',
        ], $url);

        $type = sanitize_key($type);
        $allowed = ['success','error','warning','info'];
        if (!in_array($type, $allowed, true)) $type = 'info';

        $text = sanitize_text_field($text);
        $text = trim($text);
        if ($text === '') {
            $text = ($type === 'error') ? 'Something went wrong.' : 'Done.';
        }
        if (strlen($text) > 300) {
            $text = substr($text, 0, 300);
        }

        return add_query_arg([
            'as_toast'        => rawurlencode($text),
            'as_toast_type'   => $type,
            'as_toast_sticky' => $sticky ? '1' : '0', // always explicit
        ], $url);
    }
}
