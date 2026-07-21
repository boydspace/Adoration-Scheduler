<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Utils\IcsBuilder;

/**
 * Two read-only, unauthenticated-by-design iCal (.ics) endpoints:
 *
 * - Personal feed: a person's own upcoming confirmed hours, gated by a
 *   long-lived bearer token embedded in the URL (see
 *   PersonsRepository::get_or_create_calendar_token()) rather than a
 *   WordPress session, since calendar apps poll a plain URL and can't
 *   carry cookies or nonces.
 * - Public feed: every upcoming hour for one schedule, with counts/open
 *   status only — deliberately never touches adorer names, unlike the
 *   privacy_mode-dependent name pills ScheduleShortcode can show.
 *
 * Both follow this plugin's established admin-post pattern (paired
 * admin_post_nopriv_ and admin_post_ registration), but skip the usual
 * check_admin_referer() nonce check — a calendar app can't submit one —
 * and output raw `text/calendar` instead of redirecting, the same way
 * MagicLinkService::handle_consume() is GET-only/token-gated instead of
 * nonce-gated.
 */
class CalendarFeedService
{
    public const ACTION_PERSONAL   = 'adoration_calendar_feed';
    public const ACTION_PUBLIC     = 'adoration_calendar_feed_public';
    public const ACTION_REGENERATE = 'adoration_regenerate_calendar_token';

    use PersonDashboardTrait;

    // AJAX conversion (2026-07-20): see ReplacementRequestService for the
    // same pattern. Only applies to the regenerate-token action - the two
    // .ics feed endpoints stay untouched (they're not forms, and aren't
    // part of this AJAX conversion's scope).
    private static bool $is_ajax = false;

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION_PERSONAL, [__CLASS__, 'handle_personal_feed']);
        add_action('admin_post_' . self::ACTION_PERSONAL,        [__CLASS__, 'handle_personal_feed']);

        add_action('admin_post_nopriv_' . self::ACTION_PUBLIC, [__CLASS__, 'handle_public_feed']);
        add_action('admin_post_' . self::ACTION_PUBLIC,        [__CLASS__, 'handle_public_feed']);

        // Regenerating is a signed-in self-service action (not public), but
        // still needs the nopriv hook registered — a "signed in" adorer
        // here means an authenticated *person* session (magic link/
        // password), which WordPress's own admin_post routing doesn't know
        // about, so it always looks "nopriv" to WP itself.
        add_action('admin_post_nopriv_' . self::ACTION_REGENERATE, [__CLASS__, 'handle_regenerate_token']);
        add_action('admin_post_' . self::ACTION_REGENERATE,        [__CLASS__, 'handle_regenerate_token']);

        add_action('wp_ajax_' . self::ACTION_REGENERATE,        [__CLASS__, 'ajax_regenerate_token']);
        add_action('wp_ajax_nopriv_' . self::ACTION_REGENERATE, [__CLASS__, 'ajax_regenerate_token']);
    }

    public static function ajax_regenerate_token(): void
    {
        self::$is_ajax = true;
        self::handle_regenerate_token();
    }

    /**
     * Build the subscribe URL for a person's personal feed. Uses the
     * webcal:// scheme so tapping/clicking the link offers to add it as a
     * subscription in the OS's default calendar app; https:// is also
     * accepted by anything that wants a plain downloadable URL instead.
     */
    public static function personal_feed_url(string $token, bool $webcal = true): string {
        $url = add_query_arg([
            'action' => self::ACTION_PERSONAL,
            'token'  => $token,
        ], admin_url('admin-post.php'));

        return $webcal ? self::to_webcal($url) : $url;
    }

    public static function public_feed_url(string $slug, bool $webcal = true): string {
        $url = add_query_arg([
            'action' => self::ACTION_PUBLIC,
            'slug'   => $slug,
        ], admin_url('admin-post.php'));

        return $webcal ? self::to_webcal($url) : $url;
    }

    private static function to_webcal(string $url): string {
        if (strpos($url, 'https://') === 0) return 'webcal://' . substr($url, 8);
        if (strpos($url, 'http://') === 0)  return 'webcal://' . substr($url, 7);
        return $url;
    }

    // -------------------------------------------------------------------------
    // PERSONAL FEED
    // -------------------------------------------------------------------------

    public static function handle_personal_feed(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== self::ACTION_PERSONAL) {
            return;
        }

        $token = isset($_GET['token']) ? trim((string) wp_unslash($_GET['token'])) : '';
        if ($token === '') {
            self::output_error_ics('Missing calendar token.');
        }

        $persons_repo = new PersonsRepository();
        $person = $persons_repo->find_by_calendar_token($token);
        if (!$person) {
            self::output_error_ics('This calendar link is no longer valid. Sign in to My Adoration and re-copy your subscribe link.');
        }

        $person_id = (int)($person['id'] ?? 0);
        $rows = self::get_person_signups_upcoming($person_id);

        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'adoration-scheduler.local';

        $ics = new IcsBuilder(sprintf(__('My Adoration Hours — %s', 'adoration-scheduler'), get_bloginfo('name')));

        foreach ($rows as $row) {
            [$start, $end] = self::parse_slot_datetimes($row, $tz);
            if (!$start || !$end) continue;

            $schedule_name = trim((string)($row['schedule_name'] ?? ''));
            $chapel_name   = trim((string)($row['chapel_name'] ?? ''));

            $summary = $schedule_name !== '' ? $schedule_name : __('Adoration', 'adoration-scheduler');
            if (!empty($row['needs_replacement'])) {
                $summary .= ' ' . __('(needs coverage)', 'adoration-scheduler');
            }

            $ics->add_event(
                'signup-' . (int)($row['id'] ?? 0) . '@' . $host,
                $start,
                $end,
                $summary,
                $chapel_name
            );
        }

        self::output_ics($ics->build(), 'my-adoration.ics');
    }

    // -------------------------------------------------------------------------
    // PUBLIC FEED (no names, no auth — same data ScheduleShortcode already
    // shows publicly, just reshaped as .ics instead of an HTML grid)
    // -------------------------------------------------------------------------

    public static function handle_public_feed(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== self::ACTION_PUBLIC) {
            return;
        }

        $slug = isset($_GET['slug']) ? sanitize_title((string) wp_unslash($_GET['slug'])) : '';
        if ($slug === '') {
            self::output_error_ics('Missing schedule.');
        }

        $schedules_repo = new SchedulesRepository();
        $schedule = $schedules_repo->find_by_slug($slug);

        if (!$schedule || (string)($schedule['status'] ?? '') !== 'active') {
            self::output_error_ics('This schedule is not currently available.');
        }

        $schedule_id   = (int)($schedule['id'] ?? 0);
        $schedule_name = trim((string)($schedule['name'] ?? ''));

        $slots_repo = new SlotsRepository();
        $rows = $slots_repo->list_upcoming_with_status($schedule_id);

        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'adoration-scheduler.local';

        $calendar_name = $schedule_name !== ''
            ? sprintf(__('%s — %s', 'adoration-scheduler'), get_bloginfo('name'), $schedule_name)
            : get_bloginfo('name');

        $ics = new IcsBuilder($calendar_name);

        foreach ($rows as $row) {
            [$start, $end] = self::parse_slot_datetimes($row, $tz);
            if (!$start || !$end) continue;

            $chapel_name = trim((string)($row['chapel_name'] ?? ''));
            $is_full     = !empty($row['is_full']);
            $max         = isset($row['max_adorers']) ? $row['max_adorers'] : null;

            // ✅ Status only — never a name, never a count of who specifically.
            if ($is_full) {
                $status_label = __('Filled', 'adoration-scheduler');
            } elseif ($max !== null) {
                $status_label = sprintf(
                    /* translators: 1: confirmed count, 2: max spots */
                    __('%1$d of %2$d filled', 'adoration-scheduler'),
                    (int)($row['confirmed_count'] ?? 0),
                    (int)$max
                );
            } else {
                $status_label = ((int)($row['confirmed_count'] ?? 0) > 0)
                    ? __('Open', 'adoration-scheduler')
                    : __('Open — nobody signed up yet', 'adoration-scheduler');
            }

            $summary = ($schedule_name !== '' ? $schedule_name : __('Adoration', 'adoration-scheduler'))
                . ' — ' . $status_label;

            $ics->add_event(
                'slot-' . (int)($row['id'] ?? 0) . '@' . $host,
                $start,
                $end,
                $summary,
                $chapel_name
            );
        }

        self::output_ics($ics->build(), sanitize_title($schedule_name !== '' ? $schedule_name : 'adoration-schedule') . '.ics');
    }

    // -------------------------------------------------------------------------
    // REGENERATE (self-service, signed-in person only)
    // -------------------------------------------------------------------------

    public static function handle_regenerate_token(): void
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        if ($action !== self::ACTION_REGENERATE) {
            return;
        }

        $return = isset($_POST['return']) ? esc_url_raw((string) $_POST['return']) : home_url('/my-adoration/');

        $person = MagicLinkService::current_person_or_admin_match();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            self::redirect_with_toast($return, 'Please sign in again to manage your calendar link.', 'error');
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_REGENERATE . '_' . $person_id)) {
            self::redirect_with_toast($return, 'Security check failed. Please try again.', 'error');
        }

        $persons_repo = new PersonsRepository();
        $new_token = $persons_repo->regenerate_calendar_token($person_id);

        if ($new_token === null) {
            self::redirect_with_toast($return, 'Could not generate a new calendar link. Please try again.', 'error');
        }

        self::redirect_with_toast($return, 'Your calendar link was reset. Any old link will stop updating — re-subscribe with the new one.', 'success');
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

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Build start/end DateTimeImmutable from a row's date+start_time/end_time
     * (site-local wall clock), handling hours that roll past midnight
     * (end_time < start_time -> end is the next day), matching the same
     * assumption SlotGenerator/ScheduleShortcode already make elsewhere.
     *
     * @return array{0:?\DateTimeImmutable,1:?\DateTimeImmutable}
     */
    private static function parse_slot_datetimes(array $row, \DateTimeZone $tz): array {
        $date  = trim((string)($row['date'] ?? ''));
        $start = trim((string)($row['start_time'] ?? ''));
        $end   = trim((string)($row['end_time'] ?? ''));

        if ($date === '' || $start === '' || $end === '') {
            return [null, null];
        }

        try {
            $start_dt = new \DateTimeImmutable($date . ' ' . $start, $tz);
            $end_dt   = new \DateTimeImmutable($date . ' ' . $end, $tz);

            if ($end_dt <= $start_dt) {
                $end_dt = $end_dt->modify('+1 day');
            }
        } catch (\Throwable $e) {
            return [null, null];
        }

        return [$start_dt, $end_dt];
    }

    private static function output_ics(string $content, string $filename): void {
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw ICS body, not HTML
        exit;
    }

    /**
     * A calendar app expecting text/calendar has no good way to show an
     * HTML error page — return a minimal valid-but-empty .ics with the
     * problem in X-WR-CALNAME/X-WR-CALDESC so at least it's visible if the
     * person opens the URL directly in a browser or inspects the feed.
     */
    private static function output_error_ics(string $message): void {
        $ics = new IcsBuilder(__('Adoration Calendar — Unavailable', 'adoration-scheduler'));
        $ics->set_description($message);
        self::output_ics($ics->build(), 'error.ics');
    }
}
