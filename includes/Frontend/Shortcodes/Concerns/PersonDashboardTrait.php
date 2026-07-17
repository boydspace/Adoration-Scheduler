<?php
namespace AdorationScheduler\Frontend\Shortcodes\Concerns;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Services\AccessGateService;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;

/**
 * Shared helpers for the modular "My Adoration" front-end shortcodes
 * (MyScheduleShortcode, NeededReplacementsShortcode, ProfileCardShortcode,
 * AccountStatusShortcode, MyReplacementRequestsShortcode,
 * NextAdorationHourShortcode). Extracted from the original monolithic
 * MyAdorationShortcode (now retired) so each piece can be placed
 * independently on a page while sharing one gate/auth check, one set of
 * formatting helpers, and one set of data-fetch queries.
 */
trait PersonDashboardTrait
{
    /**
     * Central gate + sign-in check, shared by every shortcode in this family.
     *
     * Returns:
     *  - ['person' => null, 'html' => string, ...] when the caller should
     *    return $html immediately (gate denied, or not signed in — $html is
     *    already a complete rendered fallback).
     *  - ['person' => array, 'html' => null, 'viewing_as_admin_match' => bool,
     *    'admin_email_for_notice' => string] when it's safe to render the
     *    real content using $person.
     *
     * Preserves the original admin-preview behavior: a WP admin without a
     * parishioner session sees their own matching person record (by email)
     * if one exists, so they can preview these widgets without a magic-link
     * session.
     */
    protected static function guard_and_get_person(string $redirect_path = '/my-adoration/'): array
    {
        if (!AccessGateService::visitor_is_allowed()) {
            return [
                'person'                  => null,
                'html'                    => do_shortcode('[adoration_request_access]'),
                'viewing_as_admin_match'  => false,
                'admin_email_for_notice' => '',
            ];
        }

        $person = MagicLinkService::current_person();
        $viewing_as_admin_match = false;
        $admin_email_for_notice = '';

        if (!$person && is_user_logged_in() && current_user_can('manage_options')) {
            $wp_user = wp_get_current_user();
            $admin_email_for_notice = (string)($wp_user->user_email ?? '');

            if ($admin_email_for_notice !== '' && class_exists(PersonsRepository::class)) {
                try {
                    $repo = new PersonsRepository();
                    $matched = $repo->find_by_email($admin_email_for_notice);
                    if ($matched) {
                        $person = $matched;
                        $viewing_as_admin_match = true;
                    }
                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] Admin->person email match failed: ' . $e->getMessage());
                }
            }
        }

        if (!$person) {
            $redirect_attr = esc_attr($redirect_path);

            ob_start();
            if (is_user_logged_in() && current_user_can('manage_options')) {
                ?>
                <div class="uk-alert uk-alert-warning" role="status" uk-alert>
                    <p class="uk-margin-remove">
                        No Adoration profile is linked to this WordPress account<?php echo $admin_email_for_notice !== '' ? ' (' . esc_html($admin_email_for_notice) . ')' : ''; ?>.
                        Add a person with this email under
                        <a href="<?php echo esc_url(admin_url('admin.php?page=adoration_scheduler_people_add')); ?>">People &rarr; Add Person</a>
                        to preview it here, or manage everyone's signups from the
                        <a href="<?php echo esc_url(admin_url('admin.php?page=adoration_scheduler_signups')); ?>">Signups</a> admin page.
                    </p>
                </div>
                <?php
            } else {
                ?>
                <div class="uk-alert uk-alert-primary" role="status" uk-alert>
                    <p class="uk-margin-remove">Please sign in to view this.</p>
                </div>
                <?php
                echo do_shortcode('[adoration_magic_link redirect="' . $redirect_attr . '"]');
            }
            $html = (string) ob_get_clean();

            return [
                'person'                 => null,
                'html'                   => $html,
                'viewing_as_admin_match' => false,
                'admin_email_for_notice' => $admin_email_for_notice,
            ];
        }

        return [
            'person'                 => $person,
            'html'                   => null,
            'viewing_as_admin_match' => $viewing_as_admin_match,
            'admin_email_for_notice' => $admin_email_for_notice,
        ];
    }

    /**
     * UIkit JS detection (controls uk-modal vs .as-modal fallback behavior).
     */
    protected static function has_uikit_js(): bool
    {
        if (!function_exists('wp_script_is')) return false;

        $handles = [
            'uikit',
            'uikit-js',
            'uikit-icons',
        ];

        foreach ($handles as $h) {
            if (wp_script_is($h, 'enqueued') || wp_script_is($h, 'done')) {
                return true;
            }
        }

        return (bool) apply_filters('adoration_scheduler_has_uikit_js', false);
    }

    protected static function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

        $url = $scheme . $host . $uri;

        // Ensure we don't preserve toast args in return URLs
        $url = remove_query_arg(['as_toast', 'as_toast_type', 'as_toast_sticky'], $url);

        return (string) $url;
    }

    protected static function fmt_date(string $ymd): string
    {
        $ts = strtotime($ymd . ' 00:00:00');
        if (!$ts) return $ymd;
        return date_i18n(get_option('date_format'), $ts);
    }

    protected static function fmt_time_range(string $start, string $end): string
    {
        $s = self::fmt_time($start);
        $e = self::fmt_time($end);
        if ($s && $e) return "{$s} – {$e}";
        return trim($start . ' - ' . $end);
    }

    protected static function fmt_time(string $t): string
    {
        $ts = strtotime('1970-01-01 ' . $t);
        if (!$ts) return '';
        return date_i18n(get_option('time_format'), $ts);
    }

    protected static function pretty_status(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') return '—';
        if ($status === 'confirmed') return 'Confirmed';
        if ($status === 'pending') return 'Pending';
        if ($status === 'cancelled') return 'Cancelled';
        return ucfirst($status);
    }

    protected static function get_person_standing_hours(int $person_id): array
    {
        if ($person_id <= 0) return [];
        if (!class_exists(\AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository::class)) return [];

        try {
            $repo = new \AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository();
            return (array) $repo->list_for_person($person_id, true);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_person_standing_hours failed: ' . $e->getMessage());
            return [];
        }
    }

    protected static function fmt_day_of_week(int $dow): string
    {
        $labels = [
            0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
        ];
        return $labels[$dow] ?? '';
    }

    protected static function get_person_signups_upcoming(int $person_id): array
    {
        if ($person_id <= 0) return [];

        global $wpdb;

        $signups  = $wpdb->prefix . 'adoration_signups';
        $slots    = $wpdb->prefix . 'adoration_slots';
        $sched    = $wpdb->prefix . 'adoration_schedules';
        $chapels  = $wpdb->prefix . 'adoration_chapels';

        $today = wp_date('Y-m-d'); // site timezone

        $sql = "
            SELECT
                su.id,
                su.date,
                su.status,
                su.type,
                su.needs_replacement,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name
            FROM {$signups} su
            INNER JOIN {$slots} sl ON sl.id = su.slot_id
            INNER JOIN {$sched} sc ON sc.id = su.schedule_id
            INNER JOIN {$chapels} ch ON ch.id = sc.chapel_id
            WHERE su.person_id = %d
              AND su.status <> 'cancelled'
              AND su.date >= %s
            ORDER BY su.date ASC, sl.start_time ASC
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $person_id, $today), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Just the single next upcoming commitment (or null), for the compact
     * "Next Adoration Hour" widget.
     */
    protected static function get_person_next_signup(int $person_id): ?array
    {
        $rows = self::get_person_signups_upcoming($person_id);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * "Coverage Needed": open replacement requests from other people.
     */
    protected static function get_open_replacement_requests(int $exclude_person_id, int $limit = 25): array
    {
        try {
            $repo = new SignupsRepository();
            return $repo->list_open_replacement_requests($exclude_person_id, $limit);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_open_replacement_requests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * "Asked of You" (Direct-to-person swap requests): open requests
     * exclusively targeted at this person — distinct from the general
     * "Coverage Needed" pool (get_open_replacement_requests()), which now
     * excludes targeted rows entirely.
     */
    protected static function get_my_targeted_replacement_requests(int $person_id, int $limit = 25): array
    {
        try {
            $repo = new SignupsRepository();
            return $repo->list_requests_targeted_at($person_id, $limit);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_my_targeted_replacement_requests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * "Recently Fulfilled": claimed replacement requests, for transparency.
     */
    protected static function get_fulfilled_replacement_requests(int $limit = 10): array
    {
        try {
            $repo = new SignupsRepository();
            return $repo->list_fulfilled_replacement_requests($limit);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_fulfilled_replacement_requests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * A signed-in person's OWN open (unclaimed) replacement requests.
     */
    protected static function get_my_open_replacement_requests(int $person_id): array
    {
        if ($person_id <= 0) return [];

        global $wpdb;

        $signups = $wpdb->prefix . 'adoration_signups';
        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';
        $persons = $wpdb->prefix . 'adoration_persons';

        $sql = "
            SELECT
                s.id,
                s.date,
                s.replacement_requested_at,
                s.replacement_note,
                s.replacement_target_person_id,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name,
                tgt.first_name AS target_first_name,
                tgt.last_name  AS target_last_name
            FROM {$signups} s
            INNER JOIN {$slots} sl ON sl.id = s.slot_id
            INNER JOIN {$sched} sc ON sc.id = s.schedule_id
            INNER JOIN {$chapels} ch ON ch.id = sc.chapel_id
            LEFT JOIN {$persons} tgt ON tgt.id = s.replacement_target_person_id
            WHERE s.person_id = %d
              AND s.needs_replacement = 1
              AND s.replacement_claimed_by IS NULL
              AND s.status = 'confirmed'
              AND s.is_active = 1
            ORDER BY s.date ASC, sl.start_time ASC
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $person_id), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    protected static function new_uid(string $prefix): string
    {
        return $prefix . '_' . substr(wp_hash(uniqid('', true)), 0, 10);
    }

    /**
     * Whether a shortcode's `card` attribute requests the boxed UIkit
     * "uk-card" look around its main content. Defaults to OFF: these
     * widgets are meant to be dropped into a theme's own UIkit layout
     * (grids, cards, panels the theme/page-builder already controls), not
     * force their own box every time. Pass card="1" on any instance that
     * should keep the boxed look.
     */
    protected static function wants_card($val): bool
    {
        if (is_bool($val)) return $val;
        $val = strtolower(trim((string)$val));
        return in_array($val, ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * Class string for the main-content wrapper div: the boxed UIkit card
     * when card="1" was requested, or a plain (unstyled) content class
     * otherwise, so the theme's own CSS/UIkit grid controls the look.
     */
    protected static function card_class(bool $card): string
    {
        return $card ? 'uk-card uk-card-default uk-card-body uk-width-1-1' : 'adoration-content';
    }
}
