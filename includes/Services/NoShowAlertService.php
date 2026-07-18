<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Admin\Pages\NoShowAlertsSettingsPage;

/**
 * NoShowAlertService
 *
 * Daily-ish digest to the site admin listing confirmed signups whose slot
 * started more than a grace period ago with nobody checked in (self-report
 * link, kiosk, or admin override — any of the three counts). The
 * safety-alerting half of the check-in/attendance feature: CoverageAlertService
 * warns about hours nobody signed up for; this warns about hours someone
 * DID sign up for but nobody actually seems to be present at.
 *
 * Settings (Admin > Settings > No-Show Alerts, NoShowAlertsSettingsPage):
 * - enabled (default OFF — see that class's docblock for why)
 * - grace_minutes: how long past start time before flagging (default 30)
 *
 * Mirrors CoverageAlertService's registration pattern exactly:
 * - No WP-Cron fallback
 * - Scheduling deferred until AFTER Action Scheduler datastore is
 *   initialized (wp_loaded)
 * - Runs every 30 minutes (much shorter interval than the once-daily
 *   coverage digest, since "flag within the grace window" only works if
 *   the check runs more often than the grace period itself)
 * - First run offset from the other three wp_loaded-deferred jobs
 *   (AuthCleanupService 03:17, PerpetualScheduleGeneratorService 03:47,
 *   CoverageAlertService 04:15) so none of them collide
 */
class NoShowAlertService
{
    public const CRON_HOOK = 'adoration_scheduler_no_show_alert_check';

    private const AS_GROUP = 'adoration-scheduler';

    private const DEFAULT_INTERVAL_SECONDS = 30 * MINUTE_IN_SECONDS;

    private const OPT_FORCE_RESCHEDULE = 'adoration_scheduler_no_show_alert_force_reschedule';

    public static function register(): void {
        add_action(self::CRON_HOOK, [self::class, 'run_check']);
        add_action('wp_loaded', [self::class, 'maybe_schedule'], 21);
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
                error_log('[AdorationScheduler] NoShowAlertService: Action Scheduler not ready on wp_loaded; not scheduling.');
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
            'adoration_scheduler_no_show_alert_interval_seconds',
            self::DEFAULT_INTERVAL_SECONDS
        );
        if ($interval < 5 * MINUTE_IN_SECONDS) $interval = 5 * MINUTE_IN_SECONDS;

        if ($force) {
            self::unschedule_all();
        } else {
            $next = as_next_scheduled_action(self::CRON_HOOK, [], self::AS_GROUP);
            if (!empty($next)) {
                return; // already scheduled
            }
        }

        $first = time() + 5 * MINUTE_IN_SECONDS;

        as_schedule_recurring_action(
            $first,
            $interval,
            self::CRON_HOOK,
            [],
            self::AS_GROUP
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                '[AdorationScheduler] NoShowAlertService scheduled: first=' .
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
     * since it only reads unchecked-in signups and stamps
     * no_show_alert_sent_at on the ones it includes.
     */
    public static function run_check(): void {
        $options = class_exists(NoShowAlertsSettingsPage::class)
            ? NoShowAlertsSettingsPage::get_options()
            : ['enabled' => 0, 'grace_minutes' => 30];

        if (empty($options['enabled'])) {
            return;
        }

        $grace_minutes = (int)($options['grace_minutes'] ?? 30);
        if ($grace_minutes < 1) $grace_minutes = 30;

        $signups_repo = new SignupsRepository();

        try {
            $gaps = $signups_repo->find_unchecked_in_past_grace($grace_minutes, 100);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] NoShowAlertService::run_check query failed: ' . $e->getMessage());
            return;
        }

        if (empty($gaps)) {
            return;
        }

        self::send_digest($gaps, $grace_minutes);

        $signup_ids = array_map(static fn($g) => (int)($g['id'] ?? 0), $gaps);
        $signups_repo->mark_no_show_alert_sent($signup_ids);
    }

    private static function send_digest(array $gaps, int $grace_minutes): void {
        $recipient = class_exists(NoShowAlertsSettingsPage::class)
            ? NoShowAlertsSettingsPage::get_recipient_email()
            : (string) get_option('admin_email');

        if (!$recipient || !is_email($recipient)) return;

        $count = count($gaps);

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        $lines = [];
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
            $name     = trim((string)($g['person_first_name'] ?? '') . ' ' . (string)($g['person_last_name'] ?? ''));

            $line = trim($date_lbl . ' ' . $time_lbl);
            if ($schedule !== '') $line .= " — {$schedule}";
            if ($chapel !== '')   $line .= " ({$chapel})";
            if ($name !== '')     $line .= " — {$name}";

            $lines[] = "• {$line}";
        }

        try {
            \AdorationScheduler\Services\NotificationService::send_no_show_digest([
                'to_email'       => $recipient,
                'no_show_count'  => $count,
                'grace_minutes'  => $grace_minutes,
                'no_show_list'   => implode("\n", $lines),
                'attendance_url' => admin_url('admin.php?page=adoration_scheduler_attendance'),
                'context'        => 'admin',
                'dedupe_key'     => '', // digest already dedupes via no_show_alert_sent_at per signup
            ]);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] NoShowAlertService::send_digest failed: ' . $e->getMessage());
        }
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
