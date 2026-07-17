<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Services\MonthlySlotGenerator;

/**
 * MonthlyScheduleGeneratorService
 *
 * Uses Action Scheduler to keep every active monthly schedule's rolling
 * window of dated slots topped up (default: next `rolling_window_days` days,
 * per-schedule setting, same field perpetual schedules use — set a larger
 * value on Basic Info for a monthly schedule since occurrences are sparse).
 *
 * Schedule:
 * - runs once daily, first run at next 05:20 local site time (offset from
 *   AuthCleanup's 03:17, PerpetualSync's 03:47, and CoverageAlert's 04:15 so
 *   none of the daily jobs collide)
 *
 * Mirrors PerpetualScheduleGeneratorService's registration pattern exactly:
 * - No WP-Cron fallback
 * - Scheduling deferred until AFTER Action Scheduler datastore is
 *   initialized (wp_loaded)
 */
class MonthlyScheduleGeneratorService
{
    public const CRON_HOOK = 'adoration_scheduler_monthly_sync';

    private const AS_GROUP = 'adoration-scheduler';

    private const DEFAULT_INTERVAL_SECONDS = 24 * HOUR_IN_SECONDS;

    private const OPT_FORCE_RESCHEDULE = 'adoration_scheduler_monthly_sync_force_reschedule';

    public static function register(): void {
        add_action(self::CRON_HOOK, [self::class, 'run_sync']);
        add_action('wp_loaded', [self::class, 'maybe_schedule'], 20);
    }

    public static function activate(): void {
        update_option(self::OPT_FORCE_RESCHEDULE, 1, false);
    }

    public static function deactivate(): void {
        self::unschedule_all();
        delete_option(self::OPT_FORCE_RESCHEDULE);
    }

    public static function force_reschedule(): void {
        update_option(self::OPT_FORCE_RESCHEDULE, 1, false);
    }

    public static function maybe_schedule(): void {
        static $ran = false;
        if ($ran) return;
        $ran = true;

        if (!self::action_scheduler_ready()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] MonthlyScheduleGeneratorService: Action Scheduler not ready on wp_loaded; not scheduling.');
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
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] MonthlyScheduleGeneratorService: Action Scheduler not ready; ensure_recurring skipped.');
            }
            return;
        }

        $interval = (int) apply_filters(
            'adoration_scheduler_monthly_sync_interval_seconds',
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

        $first = self::next_run_timestamp_local(5, 20);

        as_schedule_recurring_action(
            $first,
            $interval,
            self::CRON_HOOK,
            [],
            self::AS_GROUP
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                '[AdorationScheduler] MonthlyScheduleGeneratorService scheduled: first=' .
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
     * Run the rolling-window sync for every active monthly schedule.
     * Also callable directly (e.g. the admin "Sync Now" button on the
     * Monthly Occurrence tab) — safe to run more often than the daily cron
     * since it's purely additive/idempotent.
     */
    public static function run_sync(): void {
        $schedulesRepo = new SchedulesRepository();
        $dateRepo      = new DatePatternsRepository();
        $segmentsRepo  = new SegmentsRepository();
        $slotsRepo     = new SlotsRepository();

        $generator = new MonthlySlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);

        $schedules = $schedulesRepo->list_active_monthly();

        foreach ($schedules as $schedule) {
            $schedule_id = (int)($schedule['id'] ?? 0);
            if ($schedule_id <= 0) continue;

            $days_ahead = (int)($schedule['rolling_window_days'] ?? 60);
            if ($days_ahead <= 0) $days_ahead = 60;

            try {
                $result = $generator->sync_window($schedule, $days_ahead);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[AdorationScheduler] Monthly sync schedule_id=%d window_days=%d generated=%d inserted=%d',
                        $schedule_id,
                        $days_ahead,
                        (int)($result['generated'] ?? 0),
                        (int)($result['inserted'] ?? 0)
                    ));
                }
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] Monthly sync failed for schedule_id=' . $schedule_id . ': ' . $e->getMessage());
            }
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
