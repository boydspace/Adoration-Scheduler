<?php
namespace AdorationScheduler\Frontend\Handlers;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

/**
 * Hybrid auth (Phase 2): lets a signed-in person set, change, or remove the
 * optional password on their own account, from the My Adoration dashboard.
 * Mirrors UpdateContactInfoHandler's pattern exactly.
 */
class PasswordSetHandler
{
    public const ACTION = 'adoration_set_password';

    private const MIN_LENGTH = 8;

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION,        [__CLASS__, 'handle']);
    }

    private static function redirect_with_toast(string $url, string $msg, string $type = 'success'): void
    {
        $url = remove_query_arg(['as_toast', 'as_toast_type', 'as_toast_sticky'], $url);
        $url = add_query_arg([
            'as_toast'      => rawurlencode($msg),
            'as_toast_type' => $type,
        ], $url);

        wp_safe_redirect($url);
        exit;
    }

    public static function handle(): void
    {
        $return = isset($_POST['return']) ? esc_url_raw((string) $_POST['return']) : home_url('/');

        // Must be signed in (or a WP admin previewing a matching person record).
        $person = MagicLinkService::current_person_or_admin_match();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again to manage your password.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_set_password_' . $person_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        $mode = isset($_POST['mode']) ? sanitize_key((string) $_POST['mode']) : 'set';
        $repo = new PersonsRepository();

        if ($mode === 'remove') {
            if (!$repo->clear_password($person_id)) {
                self::redirect_with_toast($return, 'Could not remove your password. Please try again.', 'error');
            }
            self::redirect_with_toast($return, 'Password removed. You can still sign in with an emailed link.', 'success');
        }

        // mode === 'set' (covers both first-time set and change)
        $new_password     = isset($_POST['new_password'])     ? (string) wp_unslash($_POST['new_password'])     : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) wp_unslash($_POST['confirm_password']) : '';

        if ($new_password === '' || $confirm_password === '') {
            self::redirect_with_toast($return, 'Please fill in both password fields.', 'error');
        }

        if (strlen($new_password) < self::MIN_LENGTH) {
            self::redirect_with_toast($return, sprintf('Password must be at least %d characters.', self::MIN_LENGTH), 'error');
        }

        if (!hash_equals($new_password, $confirm_password)) {
            self::redirect_with_toast($return, 'Passwords do not match.', 'error');
        }

        if (!$repo->set_password($person_id, $new_password)) {
            self::redirect_with_toast($return, 'Could not save your password. Please try again.', 'error');
        }

        self::redirect_with_toast($return, 'Your password has been set.', 'success');
    }
}
