<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;

/**
 * Self-service "Delete My Account" — orchestrates the full removal flow
 * for a signed-in person, in a specific order that matters:
 *
 *   1. Cancel every not-yet-occurred signup (frees those hours for
 *      someone else instead of leaving them "confirmed" under a name
 *      that no longer resolves to anyone).
 *   2. End every active standing/weekly commitment (stops future slots
 *      from auto-confirming under this person going forward).
 *   3. Clear any direct-to-person swap request that exclusively targets
 *      this person (nobody could ever claim it otherwise, once gone).
 *   4. Anonymize the person row — NOT a hard delete. Past signups and
 *      historical coverage counts still reference this id; deleting the
 *      row outright would either orphan that history or silently corrupt
 *      per-schedule reporting. See PersonsRepository::anonymize_person().
 *   5. Revoke every session and pending magic link, on every device.
 *   6. Best-effort confirmation email to the (about-to-be-replaced)
 *      original address.
 *
 * Deliberately uses MagicLinkService::current_person() (the strict,
 * real-session-only check) rather than current_person_or_admin_match().
 * Every other write handler in this plugin was extended to allow the
 * admin-preview fallback so a WP admin can exercise the self-service
 * flows as a smoke test — but destroying someone's data is the one
 * action that fallback should never be allowed to trigger by accident.
 */
class AccountDeletionService
{
    public const ACTION = 'adoration_delete_my_account';

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION,        [__CLASS__, 'handle']);
    }

    private static function redirect_with_toast(string $msg, string $type = 'success'): void
    {
        $url = add_query_arg([
            'as_toast'      => rawurlencode($msg),
            'as_toast_type' => $type,
        ], home_url('/'));

        wp_safe_redirect($url);
        exit;
    }

    public static function handle(): void
    {
        $person = MagicLinkService::current_person();
        $person_id = (int)($person['id'] ?? 0);

        if ($person_id <= 0) {
            self::redirect_with_toast('Please sign in again to delete your account.', 'error');
        }

        check_admin_referer(self::ACTION . '_' . $person_id);

        $persons_repo = new PersonsRepository();

        if ($persons_repo->is_anonymized($person)) {
            self::redirect_with_toast('Your account has already been deleted.', 'info');
        }

        if (!isset($_POST['confirm_delete']) || (string)$_POST['confirm_delete'] !== '1') {
            self::redirect_with_toast('Please check the confirmation box to delete your account.', 'error');
        }

        // Capture what we need for the goodbye email BEFORE anonymizing.
        $original_email = (string)($person['email'] ?? '');
        $original_name  = method_exists($persons_repo, 'display_name_for_person')
            ? $persons_repo->display_name_for_person($person)
            : trim((string)($person['first_name'] ?? '') . ' ' . (string)($person['last_name'] ?? ''));

        $today = current_time('Y-m-d');

        $signups_repo = new SignupsRepository();
        $signups_repo->cancel_all_future_for_person($person_id, $today);
        $signups_repo->clear_targets_pointing_at($person_id);

        if (class_exists(StandingCommitmentsRepository::class)) {
            $standing_repo = new StandingCommitmentsRepository();
            foreach ($standing_repo->list_for_person($person_id, true) as $commitment) {
                $commitment_id = (int)($commitment['id'] ?? 0);
                if ($commitment_id > 0) {
                    $standing_repo->end($commitment_id);
                }
            }
        }

        $ok = $persons_repo->anonymize_person($person_id);
        if (!$ok) {
            self::redirect_with_toast('Could not delete your account. Please try again, or contact the parish office.', 'error');
        }

        MagicLinkService::revoke_all_for_person($person_id);

        if ($original_email !== '' && is_email($original_email)) {
            try {
                NotificationService::send_account_deleted([
                    'to_email'    => $original_email,
                    'person_name' => $original_name !== '' ? $original_name : '',
                ]);
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] AccountDeletionService: goodbye email failed: ' . $e->getMessage());
            }
        }

        self::redirect_with_toast('Your account and personal data have been removed. Thank you for your time serving in Adoration.', 'success');
    }
}
