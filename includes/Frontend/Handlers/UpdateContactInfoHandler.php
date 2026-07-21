<?php
namespace AdorationScheduler\Frontend\Handlers;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Utils\ClergyTitles;

class UpdateContactInfoHandler
{
    public const ACTION = 'adoration_update_contact_info';

    // AJAX conversion (2026-07-20): see ReplacementRequestService for the
    // same pattern.
    private static bool $is_ajax = false;

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);

        add_action('wp_ajax_' . self::ACTION,        [__CLASS__, 'ajax_handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'ajax_handle']);
    }

    public static function ajax_handle(): void
    {
        self::$is_ajax = true;
        self::handle();
    }

    private static function normalize_phone_us(?string $raw): ?string
    {
        $raw = (string)($raw ?? '');
        $raw = trim($raw);

        // Allow clearing phone number.
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) return null;

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return sprintf('(%s) %s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 4)
        );
    }

    private static function redirect_with_toast(string $url, string $msg, string $type = 'success'): void
    {
        if (self::$is_ajax) {
            if ($type === 'error') {
                wp_send_json_error(['message' => $msg, 'type' => $type]);
            }
            wp_send_json_success(['message' => $msg, 'type' => $type]);
        }

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
            self::redirect_with_toast($return, 'Please sign in again to update your information.', 'error');
        }

        // Nonce
        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_update_contact_' . $person_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        // Allowed fields only (email is intentionally NOT accepted here)
        $first = isset($_POST['first_name']) ? sanitize_text_field((string) $_POST['first_name']) : '';
        $last  = isset($_POST['last_name'])  ? sanitize_text_field((string) $_POST['last_name'])  : '';
        $title       = ClergyTitles::resolve_from_post('title');
        $parish = isset($_POST['parish']) ? sanitize_text_field((string) $_POST['parish']) : '';

        $phone_raw = isset($_POST['phone']) ? (string) $_POST['phone'] : '';
        $phone = self::normalize_phone_us($phone_raw);
        if ($phone === null) {
            self::redirect_with_toast($return, 'Please enter a valid 10-digit phone number.', 'error');
        }

        // ✅ Replacement requests (Phase 3): checkbox is only present in POST
        // when checked, so its absence means "opted out".
        $substitute_opt_in = isset($_POST['substitute_opt_in']) ? 1 : 0;

        global $wpdb;
        $persons = $wpdb->prefix . 'adoration_persons';

        // Update only the approved columns.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $updated = $wpdb->update(
            $persons,
            [
                'first_name'        => $first,
                'last_name'         => $last,
                'phone'             => $phone, // '' is allowed to clear
                'substitute_opt_in' => $substitute_opt_in,
                'title'             => ($title !== '' ? $title : null),
                'parish'            => ($parish !== '' ? $parish : null),
            ],
            ['id' => $person_id],
            ['%s', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            self::redirect_with_toast($return, 'Could not save changes. Please try again.', 'error');
        }

        self::redirect_with_toast($return, 'Your contact information has been updated.', 'success');
    }
}
