<?php
namespace AdorationScheduler\Public;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Utils\ClergyTitles;

if ( ! defined('ABSPATH') ) exit;

/**
 * Public "Request Access" submission — the entry point for a brand-new
 * visitor when AccessGateService's approval gate is turned on.
 *
 * Deliberately does NOT sign the requester in or create any session; it
 * only creates (or resubmits) a person record in 'pending' status and
 * notifies the site admin. The requester gets access once an admin
 * approves them from the People page, then signs in via the normal
 * magic-link flow.
 *
 * Reuses SignupHandler's honeypot/rate-limit/redirect helpers (already
 * promoted to public static for exactly this kind of reuse).
 */
class AccessRequestHandler
{
    public const ACTION = 'adoration_request_access';

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== self::ACTION) {
            return;
        }

        if ( ! class_exists(SignupHandler::class)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        check_admin_referer(self::ACTION);

        // Shared anti-spam/rate-limit guards (each redirects+exits on failure).
        SignupHandler::validate_honeypot();
        SignupHandler::verify_turnstile_or_bail();
        SignupHandler::rate_limit_by_ip();

        $first = sanitize_text_field((string) wp_unslash($_POST['first_name'] ?? ''));
        $last  = sanitize_text_field((string) wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email((string) wp_unslash($_POST['email'] ?? ''));
        $title = ClergyTitles::resolve_from_post('title');

        if ($first === '' || $email === '' || ! is_email($email)) {
            SignupHandler::redirect_back('err', 'Please enter your name and a valid email address.');
            exit; // redirect_back() already exits; kept for clarity/safety
        }

        $phone_raw = sanitize_text_field((string) wp_unslash($_POST['phone'] ?? ''));
        if (trim($phone_raw) === '') {
            SignupHandler::redirect_back('err', 'Please enter a cell phone number — it\'s required so we can text you schedule updates.');
            exit;
        }
        $phone = self::normalize_phone_us($phone_raw);
        if ($phone === null) {
            SignupHandler::redirect_back('err', 'Please enter a valid 10-digit US cell phone number.');
            exit;
        }

        $email_norm = strtolower(trim($email));
        SignupHandler::rate_limit_by_email($email_norm);

        $repo = new PersonsRepository();
        $existing = $repo->find_by_email($email_norm);

        if ($existing) {
            $status = $repo->approval_status_of($existing);

            if ($status === PersonsRepository::STATUS_APPROVED) {
                SignupHandler::redirect_back(
                    'info',
                    'This email already has access — use "Email me a sign-in link" instead of requesting access again.'
                );
                exit;
            }

            if ($status === PersonsRepository::STATUS_PENDING) {
                SignupHandler::redirect_back(
                    'info',
                    'Your request is already awaiting review. You\'ll get access as soon as an admin approves it.'
                );
                exit;
            }

            // Previously rejected — allow a fresh resubmission. Also refresh
            // their name/title/phone in case it changed, but round-trip
            // parish through unchanged (update_person() overwrites every
            // field it manages, so an omitted key would otherwise wipe it).
            $repo->update_person((int)$existing['id'], [
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email_norm,
                'phone'      => $phone,
                'title'      => $title,
                'parish'     => (string)($existing['parish'] ?? ''),
            ]);
            $repo->set_approval_status((int)$existing['id'], PersonsRepository::STATUS_PENDING);
            self::notify_admin_new_request($repo->find((int)$existing['id']));

            SignupHandler::redirect_back('ok', 'Your request has been resubmitted for review.');
            exit;
        }

        $new_id = $repo->create_pending_person([
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email_norm,
            'phone'      => $phone,
            'title'      => $title,
        ]);

        if ($new_id <= 0) {
            error_log('[AdorationScheduler] AccessRequestHandler: create_pending_person failed for ' . $email_norm);
            SignupHandler::redirect_back('err', 'Something went wrong submitting your request. Please try again.');
            exit;
        }

        self::notify_admin_new_request($repo->find($new_id));

        SignupHandler::redirect_back('ok', 'Thanks! Your request has been submitted for review. You\'ll be notified once approved.');
        exit;
    }

    private static function normalize_phone_us(?string $raw): ?string
    {
        $raw = (string)($raw ?? '');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) return null;

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) return null;

        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }

    private static function notify_admin_new_request(?array $person): void
    {
        if (!$person) return;

        $admin_email = get_option('admin_email');
        if (!$admin_email || !is_email($admin_email)) return;

        $name = (new PersonsRepository())->full_name_with_title($person);
        if ($name === '—') $name = '(no name given)';

        $email = (string)($person['email'] ?? '');

        $approve_url = admin_url('admin.php?page=adoration_scheduler_people&approval_status=pending');

        try {
            \AdorationScheduler\Services\NotificationService::send_access_request_admin_notice([
                'to_email'        => $admin_email,
                'requester_name'  => $name,
                'requester_email' => $email,
                'review_url'      => $approve_url,
                'context'         => 'admin',
            ]);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] AccessRequestHandler: admin notify failed: ' . $e->getMessage());
        }
    }

    /**
     * ✅ Closes the loop on the approval gate: once an admin approves a
     * pending request, the requester previously had no way to find out —
     * they'd have to guess and try "Email me a sign-in link" again. Called
     * by PeopleAdminActionsService::handle_set_approval() (row action) and
     * PersonsListTable::process_bulk_action() (bulk approve), both of which
     * only invoke this on an actual pending/rejected -> approved transition,
     * never on a no-op re-save of an already-approved person.
     *
     * ✅ Now routed through NotificationService so it's editable from
     * Email Templates (Access Approved tab) — previously a plain wp_mail().
     */
    public static function notify_person_approved(?array $person): void
    {
        if (!$person) return;

        $email = trim((string)($person['email'] ?? ''));
        if ($email === '' || !is_email($email)) return;

        $title      = trim((string)($person['title'] ?? ''));
        $first_name = trim((string)($person['first_name'] ?? ''));
        $last_name  = trim((string)($person['last_name'] ?? ''));
        $greeting_name = trim($title . ' ' . $first_name);

        $sign_in_url = home_url('/my-adoration/');

        try {
            \AdorationScheduler\Services\NotificationService::send_access_approved([
                'to_email'     => $email,
                'title'        => $title,
                'first_name'   => $greeting_name !== '' ? $greeting_name : $first_name,
                'last_name'    => $last_name,
                'person_name'  => $greeting_name,
                'sign_in_url'  => $sign_in_url,
                'person_id'    => (int)($person['id'] ?? 0),
                'context'      => 'admin',
            ]);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] AccessRequestHandler: approval notify failed: ' . $e->getMessage());
        }
    }
}
