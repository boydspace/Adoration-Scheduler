<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\WaitlistRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;

/**
 * WaitlistService
 *
 * Three jobs:
 *  1. Self-service "leave the waitlist" (joining happens inline in
 *     SignupHandler, since it already has the person's contact info and
 *     the slot-capacity lock in hand).
 *  2. Admin "remove from waitlist" (Signups tab visibility).
 *  3. promote_next_for_slot() — the piece other services call, best-effort,
 *     right after a confirmed signup for a slot is freed up. Other services
 *     call this rather than duplicating the "who's waiting, is there room
 *     now" logic themselves.
 */
class WaitlistService
{
    public const ACTION_LEAVE         = 'adoration_leave_waitlist';
    public const ACTION_ADMIN_REMOVE  = 'adoration_admin_remove_waitlist';

    private const CAP_MANAGE_SIGNUPS = 'adoration_manage_signups';

    // AJAX conversion (2026-07-20): see ReplacementRequestService for the
    // same pattern - ajax_leave() sets this flag then calls the SAME
    // handle_leave() the full-page form uses.
    private static bool $is_ajax = false;

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION_LEAVE, [__CLASS__, 'handle_leave']);
        add_action('admin_post_' . self::ACTION_LEAVE,        [__CLASS__, 'handle_leave']);
        add_action('admin_post_' . self::ACTION_ADMIN_REMOVE, [__CLASS__, 'handle_admin_remove']);

        add_action('wp_ajax_' . self::ACTION_LEAVE,        [__CLASS__, 'ajax_leave']);
        add_action('wp_ajax_nopriv_' . self::ACTION_LEAVE, [__CLASS__, 'ajax_leave']);
    }

    public static function ajax_leave(): void
    {
        self::$is_ajax = true;
        self::handle_leave();
    }

    public static function handle_admin_remove(): void
    {
        if (!is_admin()) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
        }

        $allowed = current_user_can(self::CAP_MANAGE_SIGNUPS) || current_user_can('manage_options');
        if (!$allowed) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
        }

        $return_default = admin_url('admin.php?page=adoration_scheduler_schedules');
        $return = isset($_POST['return']) ? (string) wp_unslash($_POST['return']) : $return_default;

        $waitlist_id = (int)($_POST['waitlist_id'] ?? 0);
        if ($waitlist_id <= 0) {
            self::redirect_with_toast($return, 'Missing waitlist entry.', 'error');
        }

        check_admin_referer(self::ACTION_ADMIN_REMOVE . '_' . $waitlist_id);

        $repo = new WaitlistRepository();
        $ok = $repo->admin_remove($waitlist_id);

        self::redirect_with_toast(
            $return,
            $ok ? 'Removed from waitlist.' : 'Could not update that waitlist entry.',
            $ok ? 'success' : 'error'
        );
    }

    private static function redirect_with_toast(string $return_url, string $msg, string $type = 'success'): void
    {
        if (self::$is_ajax) {
            if ($type === 'error') {
                wp_send_json_error(['message' => $msg, 'type' => $type]);
            }
            wp_send_json_success(['message' => $msg, 'type' => $type]);
        }

        $url = add_query_arg([
            'as_toast'      => rawurlencode($msg),
            'as_toast_type' => $type,
        ], $return_url);

        wp_safe_redirect($url);
        exit;
    }

    public static function handle_leave(): void
    {
        $return = isset($_POST['return']) ? (string) wp_unslash($_POST['return']) : '';
        $return = ($return !== '' && strpos($return, '/') === 0) ? home_url($return) : home_url('/my-adoration/');

        $person = MagicLinkService::current_person_or_admin_match();
        $person_id = (int)($person['id'] ?? 0);

        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again to manage your waitlist entries.', 'error');
        }

        $waitlist_id = (int)($_POST['waitlist_id'] ?? 0);
        if ($waitlist_id <= 0) {
            self::redirect_with_toast($return, 'Missing waitlist entry.', 'error');
        }

        check_admin_referer(self::ACTION_LEAVE . '_' . $waitlist_id);

        $repo = new WaitlistRepository();
        $ok = $repo->leave($waitlist_id, $person_id);

        if (!$ok) {
            self::redirect_with_toast($return, 'Could not update that waitlist entry. Please try again.', 'error');
        }

        self::redirect_with_toast($return, "You've been removed from the waitlist.", 'success');
    }

    /**
     * Call after a confirmed signup for $slot_id is cancelled/deleted, so a
     * freed seat gets offered to whoever's been waiting longest. Swallows
     * its own errors — a promotion failure should never block or roll back
     * the cancellation that triggered it.
     *
     * Bounded to a handful of attempts: if promoting the person at the
     * front of the line unexpectedly fails (e.g. a stale duplicate), that
     * entry is cancelled and the next one in line is tried instead, rather
     * than looping forever or leaving the seat unfilled for a fixable reason.
     */
    public static function promote_next_for_slot(int $slot_id): void
    {
        try {
            $slot_id = (int)$slot_id;
            if ($slot_id <= 0) return;

            $slots_repo = new SlotsRepository();
            $slot = $slots_repo->find($slot_id);
            if (!$slot || (int)($slot['is_active'] ?? 0) !== 1) return;

            $schedule_id = (int)($slot['schedule_id'] ?? 0);
            $date        = trim((string)($slot['date'] ?? ''));
            if ($schedule_id <= 0 || $date === '') return;

            $max = ($slot['max_adorers'] ?? null);
            $max = ($max !== null && $max !== '') ? (int)$max : null;

            $waitlist_repo = new WaitlistRepository();
            $signups_repo  = new SignupsRepository();

            for ($attempt = 0; $attempt < 5; $attempt++) {
                if ($max !== null) {
                    $counts    = $signups_repo->counts_by_slot_ids([$slot_id]);
                    $confirmed = (int)($counts[$slot_id] ?? 0);
                    if ($confirmed >= $max) return; // still full, nothing to do
                }

                $next = $waitlist_repo->next_in_line($slot_id);
                if (!$next) return; // nobody waiting

                $waitlist_id = (int)$next['id'];
                $person_id   = (int)$next['person_id'];

                $signup_id = $signups_repo->create([
                    'person_id'   => $person_id,
                    'schedule_id' => $schedule_id,
                    'slot_id'     => $slot_id,
                    'date'        => $date,
                    'status'      => 'confirmed',
                    'type'        => 'one_time',
                    'created_via' => 'waitlist_promotion',
                ]);

                if ($signup_id <= 0) {
                    // Couldn't confirm this person (most likely: they already
                    // have a confirmed signup for this exact slot somehow).
                    // Drop their stale waitlist entry and try the next one.
                    $waitlist_repo->admin_remove($waitlist_id);
                    continue;
                }

                $waitlist_repo->mark_promoted($waitlist_id);

                self::send_promotion_email($person_id, $schedule_id, $slot, $date);

                return;
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] WaitlistService::promote_next_for_slot failed slot_id=' . $slot_id . ' err=' . $e->getMessage());
        }
    }

    private static function send_promotion_email(int $person_id, int $schedule_id, array $slot, string $date): void
    {
        try {
            $persons_repo = new PersonsRepository();
            $person = $persons_repo->find($person_id);
            if (!$person) return;

            $email = trim((string)($person['email'] ?? ''));
            if ($email === '' || !is_email($email)) return;

            $schedules_repo = new SchedulesRepository();
            $schedule = $schedules_repo->find($schedule_id);
            $schedule_title = trim((string)($schedule['name'] ?? $schedule['title'] ?? 'Adoration'));

            $start = trim((string)($slot['start_time'] ?? $slot['start'] ?? ''));
            $end   = trim((string)($slot['end_time'] ?? $slot['end'] ?? ''));
            $slot_label = trim($date . ' ' . $start);
            if ($end !== '') $slot_label .= '–' . $end;

            $first = trim((string)($person['first_name'] ?? ''));
            $last  = trim((string)($person['last_name'] ?? ''));
            $title = trim((string)($person['title'] ?? ''));

            NotificationService::send_waitlist_promoted([
                'to_email'       => $email,
                'title'          => $title,
                'first_name'     => $first,
                'last_name'      => $last,
                'person_name'    => trim($first . ' ' . $last),
                'schedule_title' => $schedule_title,
                'schedule_name'  => $schedule_title,
                'slot_date'      => $date,
                'slot_start'     => $start,
                'slot_end'       => $end,
                'slot_label'     => $slot_label,
                'manage_url'     => home_url('/my-adoration/'),
                'context'        => 'person',
                'send'           => true,
                'person_id'      => $person_id,
            ]);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Waitlist promotion email exception: ' . $e->getMessage());
        }
    }
}
