<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

/**
 * Replacement requests (Phase 3): a person marks an upcoming signup as
 * needing coverage without cancelling it outright — they stay on the hook
 * until a substitute claims it. Admins and anyone who's opted in as a
 * substitute get emailed; the My Adoration dashboard shows both the open
 * "Coverage Needed" list and recently fulfilled requests.
 *
 * Mirrors UpdateContactInfoHandler's signed-in-person pattern for both
 * actions, and AccessRequestHandler's plain wp_mail() notification style
 * (this is an internal/admin-facing notice, not a templated parishioner
 * lifecycle email, so it deliberately doesn't go through NotificationService).
 */
class ReplacementRequestService
{
    public const ACTION_REQUEST          = 'adoration_request_replacement';
    public const ACTION_CLAIM            = 'adoration_claim_replacement';
    public const ACTION_CANCEL           = 'adoration_cancel_replacement';
    public const ACTION_OPEN_TO_EVERYONE = 'adoration_replacement_open_to_everyone';

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION_REQUEST, [__CLASS__, 'handle_request']);
        add_action('admin_post_' . self::ACTION_REQUEST,        [__CLASS__, 'handle_request']);

        add_action('admin_post_nopriv_' . self::ACTION_CLAIM, [__CLASS__, 'handle_claim']);
        add_action('admin_post_' . self::ACTION_CLAIM,        [__CLASS__, 'handle_claim']);

        add_action('admin_post_nopriv_' . self::ACTION_CANCEL, [__CLASS__, 'handle_cancel']);
        add_action('admin_post_' . self::ACTION_CANCEL,        [__CLASS__, 'handle_cancel']);

        add_action('admin_post_nopriv_' . self::ACTION_OPEN_TO_EVERYONE, [__CLASS__, 'handle_open_to_everyone']);
        add_action('admin_post_' . self::ACTION_OPEN_TO_EVERYONE,        [__CLASS__, 'handle_open_to_everyone']);
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

    public static function handle_request(): void
    {
        $return = isset($_POST['return']) ? esc_url_raw((string) $_POST['return']) : home_url('/');

        $person = MagicLinkService::current_person();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again to request a replacement.', 'error');
        }

        $signup_id = isset($_POST['signup_id']) ? (int) $_POST['signup_id'] : 0;
        if ($signup_id <= 0) {
            self::redirect_with_toast($return, 'Invalid signup.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_request_replacement_' . $signup_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        $note = isset($_POST['note']) ? (string) wp_unslash($_POST['note']) : '';

        // Direct-to-person swap requests: an optional specific target.
        // Validated here (exists, not the requester themselves) rather than
        // trusting the posted ID blindly, since it drives claim exclusivity.
        $target_person_id = isset($_POST['target_person_id']) ? (int) $_POST['target_person_id'] : 0;
        if ($target_person_id > 0) {
            if ($target_person_id === $person_id) {
                self::redirect_with_toast($return, 'You can\'t ask yourself to cover your own hour.', 'error');
            }
            $persons_repo = new PersonsRepository();
            $target = $persons_repo->find($target_person_id);
            if (!$target) {
                self::redirect_with_toast($return, 'That person could not be found. Please pick someone from the list.', 'error');
            }
        }

        $repo = new SignupsRepository();
        $ok = $repo->mark_needs_replacement($signup_id, $person_id, $note, $target_person_id > 0 ? $target_person_id : null);

        if (!$ok) {
            self::redirect_with_toast($return, 'Could not submit that request. Please try again.', 'error');
        }

        self::notify_admin_and_substitutes($signup_id, $note);

        $msg = ($target_person_id > 0)
            ? 'Replacement request sent. You\'ll stay on the schedule until they respond or you open it to everyone.'
            : 'Replacement requested. You\'ll stay on the schedule until someone covers it.';
        self::redirect_with_toast($return, $msg, 'success');
    }

    /**
     * "Open to everyone instead": the requester revokes exclusive targeting
     * on their own still-open request, reopening it to the general
     * "Coverage Needed" pool, then broadcasts the notification that was
     * originally skipped in favor of a single-target email.
     */
    public static function handle_open_to_everyone(): void
    {
        $return = isset($_POST['return']) ? esc_url_raw((string) $_POST['return']) : home_url('/');

        $person = MagicLinkService::current_person();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again.', 'error');
        }

        $signup_id = isset($_POST['signup_id']) ? (int) $_POST['signup_id'] : 0;
        if ($signup_id <= 0) {
            self::redirect_with_toast($return, 'Invalid signup.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_replacement_open_to_everyone_' . $signup_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        $repo = new SignupsRepository();
        $ok = $repo->clear_replacement_target($signup_id, $person_id);

        if (!$ok) {
            self::redirect_with_toast($return, 'Could not update that request (it may already be covered).', 'error');
        }

        $ctx = $repo->get_replacement_context($signup_id);
        self::notify_admin_and_substitutes($signup_id, (string)($ctx['replacement_note'] ?? ''));

        self::redirect_with_toast($return, 'Opened to everyone — any substitute can now claim it.', 'success');
    }

    public static function handle_claim(): void
    {
        $return = isset($_POST['return']) ? esc_url_raw((string) $_POST['return']) : home_url('/');

        $person = MagicLinkService::current_person();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again to claim this slot.', 'error');
        }

        $signup_id = isset($_POST['signup_id']) ? (int) $_POST['signup_id'] : 0;
        if ($signup_id <= 0) {
            self::redirect_with_toast($return, 'Invalid signup.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_claim_replacement_' . $signup_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        $repo = new SignupsRepository();
        $result = $repo->claim_replacement($signup_id, $person_id);

        switch ($result) {
            case 'ok':
                self::redirect_with_toast($return, 'Thank you! You\'re now covering that hour.', 'success');
                break;
            case 'own_request':
                self::redirect_with_toast($return, 'You can\'t claim your own replacement request.', 'error');
                break;
            case 'already_booked':
                self::redirect_with_toast($return, 'You\'re already signed up for that time.', 'error');
                break;
            case 'not_open':
                self::redirect_with_toast($return, 'That request has already been covered.', 'info');
                break;
            case 'not_found':
                self::redirect_with_toast($return, 'That request no longer exists.', 'error');
                break;
            case 'not_your_request':
                self::redirect_with_toast($return, 'This request was asked of someone else.', 'error');
                break;
            default:
                self::redirect_with_toast($return, 'Could not claim that slot. Please try again.', 'error');
        }
    }

    public static function handle_cancel(): void
    {
        $return = isset($_POST['return']) ? esc_url_raw((string) $_POST['return']) : home_url('/');

        $person = MagicLinkService::current_person();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again.', 'error');
        }

        $signup_id = isset($_POST['signup_id']) ? (int) $_POST['signup_id'] : 0;
        if ($signup_id <= 0) {
            self::redirect_with_toast($return, 'Invalid signup.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'adoration_cancel_replacement_' . $signup_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        $repo = new SignupsRepository();
        $ok = $repo->cancel_replacement_request($signup_id, $person_id);

        if (!$ok) {
            self::redirect_with_toast($return, 'Could not cancel that request (it may already be covered).', 'error');
        }

        self::redirect_with_toast($return, 'Replacement request cancelled.', 'success');
    }

    /**
     * Notify about an open replacement request. Plain wp_mail() (not the
     * templated NotificationService) — same treatment as
     * AccessRequestHandler's admin notice.
     *
     * Direct-to-person swap requests: if the request is exclusively
     * targeted at someone, ONLY the admin + that one person are notified
     * (not the whole substitute pool) — broadcasting a "private ask" to
     * everyone would defeat the point of asking a specific person. Once
     * the requester reopens it (handle_open_to_everyone), this same method
     * runs again and — since the target is now cleared — falls through to
     * the normal broadcast-to-all-substitutes behavior.
     */
    private static function notify_admin_and_substitutes(int $signup_id, string $note): void
    {
        $signups_repo = new SignupsRepository();
        $ctx = $signups_repo->get_replacement_context($signup_id);
        if (!$ctx) return;

        $requester_name = trim((string)($ctx['first_name'] ?? '') . ' ' . (string)($ctx['last_name'] ?? ''));
        if ($requester_name === '') $requester_name = '(unnamed)';

        $date  = (string)($ctx['date'] ?? '');
        $date_lbl = $date ? date_i18n(get_option('date_format'), strtotime($date . ' 00:00:00')) : '';

        $time_lbl = trim(
            (string)($ctx['slot_start_time'] ?? '') !== '' && (string)($ctx['slot_end_time'] ?? '') !== ''
                ? date_i18n(get_option('time_format'), strtotime('1970-01-01 ' . $ctx['slot_start_time']))
                  . ' – ' . date_i18n(get_option('time_format'), strtotime('1970-01-01 ' . $ctx['slot_end_time']))
                : ''
        );

        $chapel   = (string)($ctx['chapel_name'] ?? '');
        $schedule = (string)($ctx['schedule_name'] ?? '');

        $slot_label = trim($date_lbl . ' ' . $time_lbl . ($chapel !== '' ? " • {$chapel}" : ''));

        $my_adoration_url = home_url('/my-adoration/');

        $target_person_id = (int)($ctx['replacement_target_person_id'] ?? 0);
        $target_email = (string)($ctx['target_email'] ?? '');
        $target_name  = trim((string)($ctx['target_first_name'] ?? '') . ' ' . (string)($ctx['target_last_name'] ?? ''));

        $subject = '[' . get_bloginfo('name') . '] Coverage needed: ' . $slot_label;

        $body  = "{$requester_name} requested a replacement for their Adoration commitment:\n\n";
        $body .= "When: {$slot_label}\n";
        if ($schedule !== '') $body .= "Schedule: {$schedule}\n";
        if (trim($note) !== '') $body .= "Note: " . trim($note) . "\n";
        if ($target_person_id > 0 && $target_name !== '') {
            $body .= "\nThis was asked specifically of {$target_name}.\n";
        }
        $body .= "\nSign in to view or claim it: {$my_adoration_url}\n";

        $recipients = [];

        $admin_email = get_option('admin_email');
        if ($admin_email && is_email($admin_email)) {
            $recipients[] = $admin_email;
        }

        if ($target_person_id > 0) {
            // Exclusive ask: only the targeted person, not the whole pool.
            if ($target_email !== '' && is_email($target_email)) {
                $recipients[] = $target_email;
            }
        } else {
            $persons_repo = new PersonsRepository();
            foreach ($persons_repo->list_opted_in_substitute_emails() as $email) {
                $recipients[] = $email;
            }
        }

        $recipients = array_values(array_unique(array_filter($recipients, 'is_email')));
        if (empty($recipients)) return;

        try {
            // Send individually rather than one multi-recipient call, so
            // substitutes can't see each other's addresses.
            foreach ($recipients as $to) {
                wp_mail($to, $subject, $body);
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] ReplacementRequestService: notify failed: ' . $e->getMessage());
        }
    }
}
