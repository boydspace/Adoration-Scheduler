<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Admin\Pages\CoverageAlertsSettingsPage;

/**
 * CoverageAlertService
 *
 * Daily digest to the site admin listing every active schedule's slots
 * (event or perpetual — any active schedule, including ones created after
 * this shipped) that are starting soon with zero confirmed signups.
 *
 * Settings (Admin > Settings > Coverage Alerts, CoverageAlertsSettingsPage):
 * - enabled (default on)
 * - window_hours: how far ahead counts as "urgent" (default 48)
 * - repeat_mode: 'once' (default, alert once per gap) or 'daily' (re-include
 *   the same gap in every day's digest until it's filled)
 *
 * Mirrors PerpetualScheduleGeneratorService's registration pattern:
 * - No WP-Cron fallback
 * - Scheduling deferred until AFTER Action Scheduler datastore is
 *   initialized (wp_loaded)
 * - Runs once daily, first run at next 04:15 local site time (offset from
 *   AuthCleanupService's 03:17 and PerpetualScheduleGeneratorService's
 *   03:47 so the three jobs don't collide)
 */
class CoverageAlertService
{
    public const CRON_HOOK = 'adoration_scheduler_coverage_alert_check';

    private const AS_GROUP = 'adoration-scheduler';

    private const DEFAULT_INTERVAL_SECONDS = 24 * HOUR_IN_SECONDS;

    private const OPT_FORCE_RESCHEDULE = 'adoration_scheduler_coverage_alert_force_reschedule';

    public static function register(): void {
        add_action(self::CRON_HOOK, [self::class, 'run_check']);
        add_action('wp_loaded', [self::class, 'maybe_schedule'], 20);
    }

    public static function activate(): void {
        update_option(self::OPT_FORCE_RESCHEDULE, 1, false);
    }

    public static function deactivate(): void {
        self::unschedule_all();
        delete_option(self::OPT_FORCE_RESCHEDULE);
    }

    public static function maybe_schedule(): void {
        static $ran = false;
        if ($ran) return;
        $ran = true;

        if (!self::action_scheduler_ready()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] CoverageAlertService: Action Scheduler not ready on wp_loaded; not scheduling.');
            }
            return;
        }

        $force = ((int) get_option(self::OPT_FORCE_RESCHEDULE, 0) === 1);
        if ($force) {
            delete_option(self::OPT_FORCE_RESCHEDULE);
            self::ensure_recurring(true);
            return;
        }

        self::ensure_recurring(false);
    }

    public static function ensure_recurring(bool $force = false): void {
        if (!self::action_scheduler_ready()) {
            return;
        }

        $interval = (int) apply_filters(
            'adoration_scheduler_coverage_alert_interval_seconds',
            self::DEFAULT_INTERVAL_SECONDS
        );
        if ($interval < HOUR_IN_SECONDS) $interval = HOUR_IN_SECONDS;

        if ($force) {
            self::unschedule_all();
        } else {
            $next = as_next_scheduled_action(self::CRON_HOOK, [], self::AS_GROUP);
            if (!empty($next)) {
                return; // already scheduled
            }
        }

        $first = self::next_run_timestamp_local(4, 15);

        as_schedule_recurring_action(
            $first,
            $interval,
            self::CRON_HOOK,
            [],
            self::AS_GROUP
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                '[AdorationScheduler] CoverageAlertService scheduled: first=' .
                gmdate('c', $first) .
                ' interval=' . $interval . 's group=' . self::AS_GROUP
            );
        }
    }

    public static function unschedule_all(): void {
        if (!self::action_scheduler_ready()) {
            return;
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_HOOK, [], self::AS_GROUP);
        }
    }

    /**
     * Build and send the digest, if enabled and there's anything to report.
     * Safe to call directly (e.g. a future "Send Test Digest" admin button)
     * since it's purely additive: it only reads open slots and stamps
     * coverage_alert_sent_at on the ones it includes.
     */
    public static function run_check(): void {
        $options = class_exists(CoverageAlertsSettingsPage::class)
            ? CoverageAlertsSettingsPage::get_options()
            : ['enabled' => 1, 'window_hours' => 48, 'repeat_mode' => 'once'];

        if (empty($options['enabled'])) {
            return;
        }

        $window_hours = (int)($options['window_hours'] ?? 48);
        if ($window_hours < 1) $window_hours = 48;

        $repeat_mode = (string)($options['repeat_mode'] ?? 'once');
        $only_unalerted = ($repeat_mode !== 'daily');

        $slots_repo = new SlotsRepository();

        try {
            $gaps = $slots_repo->find_open_urgent_slots($window_hours, $only_unalerted);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] CoverageAlertService::run_check query failed: ' . $e->getMessage());
            return;
        }

        if (empty($gaps)) {
            return;
        }

        self::send_digest($gaps, $window_hours);

        $slot_ids = array_map(static fn($g) => (int)($g['id'] ?? 0), $gaps);
        $slots_repo->mark_coverage_alert_sent($slot_ids);
    }

    /**
     * ✅ Now routed through NotificationService so it's editable from
     * Email Templates (Coverage Digest tab) — previously a plain wp_mail().
     */
    private static function send_digest(array $gaps, int $window_hours): void {
        // Configurable recipient (CoverageAlertsSettingsPage's "Send To" field),
        // falling back to admin_email if unset/invalid.
        $recipient = class_exists(CoverageAlertsSettingsPage::class)
            ? CoverageAlertsSettingsPage::get_recipient_email()
            : (string) get_option('admin_email');

        if (!$recipient || !is_email($recipient)) return;

        $count = count($gaps);

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        $gap_lines = [];
        foreach ($gaps as $g) {
            $date  = (string)($g['date'] ?? '');
            $start = (string)($g['start_time'] ?? '');
            $end   = (string)($g['end_time'] ?? '');

            $date_lbl = $date !== '' ? date_i18n($date_format, strtotime($date . ' 00:00:00')) : '';
            $time_lbl = '';
            if ($start !== '' && $end !== '') {
                $time_lbl = date_i18n($time_format, strtotime('1970-01-01 ' . $start))
                    . ' – ' . date_i18n($time_format, strtotime('1970-01-01 ' . $end));
            }

            $schedule = (string)($g['schedule_name'] ?? '');
            $chapel   = (string)($g['chapel_name'] ?? '');

            $line = trim($date_lbl . ' ' . $time_lbl);
            if ($schedule !== '') $line .= " — {$schedule}";
            if ($chapel !== '')   $line .= " ({$chapel})";

            $gap_lines[] = "• {$line}";
        }

        try {
            \AdorationScheduler\Services\NotificationService::send_coverage_digest([
                'to_email'     => $recipient,
                'gap_count'    => $count,
                'window_hours' => $window_hours,
                'gap_list'     => implode("\n", $gap_lines),
                'signups_url'  => admin_url('admin.php?page=adoration_scheduler_signups'),
                'context'      => 'admin',
                'dedupe_key'   => '', // digest already dedupes via coverage_alert_sent_at per slot
            ]);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] CoverageAlertService::send_digest failed: ' . $e->getMessage());
        }
    }

    private static function next_run_timestamp_local(int $hour, int $minute): int {
        try {
            $tz = wp_timezone();
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = new \DateTime('now', $tz);
        $run = new \DateTime('now', $tz);
        $run->setTime($hour, $minute, 0);

        if ($run <= $now) {
            $run->modify('+1 day');
        }

        return $run->getTimestamp();
    }

    private static function action_scheduler_ready(): bool {
        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_next_scheduled_action')) {
            return false;
        }

        if (class_exists('\\ActionScheduler_Store') && method_exists('\\ActionScheduler_Store', 'instance')) {
            try {
                $store = \ActionScheduler_Store::instance();
                return (bool) $store;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }
}
