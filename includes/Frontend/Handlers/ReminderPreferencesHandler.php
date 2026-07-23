<?php
namespace AdorationScheduler\Frontend\Handlers;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Services\ReminderScheduler;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;

/**
 * Saves a person's own reminder channel preferences (email/SMS/lead time),
 * set from ReminderPreferencesShortcode. Built on the same shape as
 * UpdateContactInfoHandler — dual admin_post/wp_ajax hooks, an $is_ajax
 * flag, and a private redirect_with_toast() copy (every handler in this
 * codebase keeps its own rather than sharing one).
 */
class ReminderPreferencesHandler
{
    public const ACTION = 'adoration_update_reminder_preferences';

    /**
     * Curated dropdown options (hours before the slot) — also the
     * server-side whitelist for the posted value, not just a client-side
     * convenience. ReminderPreferencesShortcode renders this same list.
     */
    public const LEAD_HOURS_OPTIONS = [1, 2, 3, 4, 6, 8, 12, 18, 24, 36, 48, 72];

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

        $person = MagicLinkService::current_person_or_admin_match();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again to update your preferences.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_update_reminder_prefs_' . $person_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        // Checkboxes are only present in POST when checked — absence means "off".
        $email_opt_in = isset($_POST['email_reminders']);
        $sms_opt_in   = isset($_POST['sms_reminders']);

        $lead_hours = isset($_POST['reminder_lead_hours']) ? (int) $_POST['reminder_lead_hours'] : 24;

        $ok = self::save_and_reschedule($person_id, $email_opt_in, $sms_opt_in, $lead_hours);

        if (!$ok) {
            self::redirect_with_toast($return, 'Could not save your preferences. Please try again.', 'error');
        }

        self::redirect_with_toast($return, 'Reminder preferences updated.', 'success');
    }

    /**
     * The actual save-plus-reschedule logic, pulled out of handle() so
     * integration tests can exercise it directly — handle() itself always
     * ends in exit() via redirect_with_toast()/wp_send_json_*(), which
     * isn't practical to run under PHPUnit (same rationale as
     * SignupCancellationService::cancel_signup()).
     */
    public static function save_and_reschedule(int $person_id, bool $email_opt_in, bool $sms_opt_in, int $lead_hours): bool
    {
        // Server-side whitelist, not just trusting the client — falls
        // back to 24 (today's long-standing default) for anything else.
        if (!in_array($lead_hours, self::LEAD_HOURS_OPTIONS, true)) {
            $lead_hours = 24;
        }

        $ok = (new PersonsRepository())->set_reminder_preferences($person_id, $email_opt_in, $sms_opt_in, $lead_hours);

        if (!$ok) {
            return false;
        }

        // ✅ Best-effort reschedule (2026-07-21): schedule_24h() locks in an
        // absolute Action Scheduler timestamp at signup-creation time, so
        // just saving a new lead-time preference doesn't retroactively
        // move reminders already scheduled under the old one. Re-run the
        // same unschedule/schedule pair SignupsRepository's cancellation
        // cleanup already uses, for every upcoming confirmed signup this
        // person has, so the new preference actually takes effect on
        // signups made before this save, not only ones made after.
        try {
            $ids = (new SignupsRepository())->list_upcoming_confirmed_ids_for_person($person_id);
            if (!empty($ids)) {
                $scheduler = new ReminderScheduler();
                foreach ($ids as $signup_id) {
                    ReminderScheduler::unschedule_for_signup((int)$signup_id);
                    $scheduler->schedule_24h((int)$signup_id);
                }
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] ReminderPreferencesHandler reschedule failed for person_id=' . $person_id . ': ' . $e->getMessage());
        }

        return true;
    }
}
