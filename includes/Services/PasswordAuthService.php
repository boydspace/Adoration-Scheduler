<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) {
    exit;
}

use AdorationScheduler\Domain\Repositories\PersonsRepository;

/**
 * Hybrid auth (Phase 2): optional "sign in with password" path.
 *
 * Magic-link stays the permanent/universal login option (see
 * MagicLinkService); this only succeeds for a person who has explicitly set
 * a password from their dashboard (PasswordSetHandler). Forgetting a
 * password just means falling back to the magic link — there's no separate
 * reset flow, since the magic link already is the recovery mechanism.
 */
class PasswordAuthService
{
    public const ACTION = 'adoration_password_login';

    // Rate limiting (login attempts) — tighter than magic-link requests,
    // since a password is guessable in a way a high-entropy emailed token
    // isn't.
    private const RL_IP_WINDOW_SECONDS    = 900; // 15 minutes
    private const RL_IP_MAX_PER_WINDOW    = 15;
    private const RL_EMAIL_WINDOW_SECONDS = 900; // 15 minutes
    private const RL_EMAIL_MAX_PER_WINDOW = 5;

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle_login']);
        add_action('admin_post_' . self::ACTION,        [__CLASS__, 'handle_login']);
    }

    public static function handle_login(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== self::ACTION) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $return = isset($_POST['return']) ? (string) wp_unslash($_POST['return']) : '';
        $return = $return ? self::safe_url($return) : '';
        if (!$return) $return = wp_get_referer();
        if (!$return) $return = home_url('/');

        check_admin_referer(self::ACTION);

        // Generic failure message — never leaks whether the account exists
        // or whether it has a password set, same non-leakage rule as
        // MagicLinkService::handle_request().
        $generic_fail = 'Incorrect email or password.';

        $email = isset($_POST['email']) ? strtolower(trim((string) wp_unslash($_POST['email']))) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if ($email === '' || !is_email($email) || $password === '') {
            self::redirect_with_toast($return, $generic_fail, 'error');
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '') $ip = '0.0.0.0';

        if (!MagicLinkService::is_local_or_debug_request($ip)) {
            $ip_key = self::rl_key('ip', $ip);
            $ip_count = (int) get_transient($ip_key);
            if ($ip_count >= self::RL_IP_MAX_PER_WINDOW) {
                self::redirect_with_toast($return, 'Too many sign-in attempts. Please try again later.', 'error');
            }
            set_transient($ip_key, $ip_count + 1, self::RL_IP_WINDOW_SECONDS);

            $email_key = self::rl_key('email', $email);
            $email_count = (int) get_transient($email_key);
            if ($email_count >= self::RL_EMAIL_MAX_PER_WINDOW) {
                self::redirect_with_toast($return, 'Too many sign-in attempts. Please try again later.', 'error');
            }
            set_transient($email_key, $email_count + 1, self::RL_EMAIL_WINDOW_SECONDS);
        }

        global $wpdb;
        $persons = $wpdb->prefix . 'adoration_persons';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $person = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$persons} WHERE email = %s LIMIT 1", $email),
            ARRAY_A
        );

        $repo = new PersonsRepository();

        if (!is_array($person) || empty($person['id']) || !$repo->verify_password($person, $password)) {
            self::redirect_with_toast($return, $generic_fail, 'error');
        }

        $person_id = (int)$person['id'];

        if (!MagicLinkService::start_session_for_person($person_id)) {
            self::redirect_with_toast($return, 'Could not sign you in. Please try again.', 'error');
        }

        $r = isset($_POST['r']) ? (string) wp_unslash($_POST['r']) : '';
        $r = $r ? self::safe_url($r) : '';
        if (!$r) $r = home_url('/my-adoration/');

        self::redirect_with_toast($r, 'Signed in.', 'success');
    }

    private static function rl_key(string $type, string $value): string
    {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$type));
        if ($type === '') $type = 'x';

        $value = trim((string)$value);
        if ($value === '') $value = 'empty';

        return 'adoration_pwd_rl_' . $type . '_' . md5($value);
    }

    /**
     * Only allow relative paths -> home_url(), or same-host absolute URLs.
     */
    private static function safe_url(string $url): string
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

    private static function redirect_with_toast(string $url, string $text, string $type = 'success'): void
    {
        $url = remove_query_arg(['as_toast', 'as_toast_type', 'as_toast_sticky'], $url);

        $type = sanitize_key($type);
        $allowed = ['success', 'error', 'warning', 'info'];
        if (!in_array($type, $allowed, true)) $type = 'info';

        $text = sanitize_text_field($text);
        if (strlen($text) > 300) $text = substr($text, 0, 300);

        $url = add_query_arg([
            'as_toast'        => $text,
            'as_toast_type'   => $type,
            'as_toast_sticky' => ($type === 'error') ? '1' : '0',
        ], $url);

        wp_safe_redirect($url);
        exit;
    }
}
