<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Services\PerpetualSlotGenerator;

/**
 * PerpetualScheduleGeneratorService
 *
 * Uses Action Scheduler to keep every active perpetual schedule's rolling window
 * of dated slots topped up (default: next `rolling_window_days` days, per-schedule
 * setting), and to auto-create signups for active standing commitments as new
 * slots come into the window.
 *
 * Schedule:
 * - runs once daily, first run at next 03:47 local site time (offset from
 *   AuthCleanupService's 03:17 so the two jobs don't collide)
 *
 * Mirrors AuthCleanupService's registration pattern:
 * - No WP-Cron fallback
 * - Scheduling deferred until AFTER Action Scheduler datastore is initialized (wp_loaded)
 */
class PerpetualScheduleGeneratorService
{
    public const CRON_HOOK = 'adoration_scheduler_perpetual_sync';

    private const AS_GROUP = 'adoration-scheduler';

    private const DEFAULT_INTERVAL_SECONDS = 24 * HOUR_IN_SECONDS;

    private const OPT_FORCE_RESCHEDULE = 'adoration_scheduler_perpetual_sync_force_reschedule';

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
                error_log('[AdorationScheduler] PerpetualScheduleGeneratorService: Action Scheduler not ready on wp_loaded; not scheduling.');
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
                error_log('[AdorationScheduler] PerpetualScheduleGeneratorService: Action Scheduler not ready; ensure_recurring skipped.');
            }
            return;
        }

        $interval = (int) apply_filters(
            'adoration_scheduler_perpetual_sync_interval_seconds',
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

        $first = self::next_run_timestamp_local(3, 47);

        as_schedule_recurring_action(
            $first,
            $interval,
            self::CRON_HOOK,
            [],
            self::AS_GROUP
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                '[AdorationScheduler] PerpetualScheduleGeneratorService scheduled: first=' .
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
     * Run the rolling-window sync for every active perpetual schedule.
     * Also callable directly (e.g. from an admin "Sync Now" button) — safe to run
     * more often than the daily cron since it's purely additive/idempotent.
     */
    public static function run_sync(): void {
        $schedulesRepo   = new SchedulesRepository();
        $dateRepo        = new DatePatternsRepository();
        $segmentsRepo    = new SegmentsRepository();
        $slotsRepo       = new SlotsRepository();
        $commitmentsRepo = new StandingCommitmentsRepository();
        $signupsRepo     = new SignupsRepository();

        $generator = new PerpetualSlotGenerator($dateRepo, $segmentsRepo, $slotsRepo, $commitmentsRepo, $signupsRepo);

        $schedules = $schedulesRepo->list_active_perpetual();

        foreach ($schedules as $schedule) {
            $schedule_id = (int)($schedule['id'] ?? 0);
            if ($schedule_id <= 0) continue;

            $days_ahead = (int)($schedule['rolling_window_days'] ?? 60);
            if ($days_ahead <= 0) $days_ahead = 60;

            try {
                $result = $generator->sync_window($schedule, $days_ahead);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[AdorationScheduler] Perpetual sync schedule_id=%d window_days=%d generated=%d inserted=%d signups_created=%d',
                        $schedule_id,
                        $days_ahead,
                        (int)($result['generated'] ?? 0),
                        (int)($result['inserted'] ?? 0),
                        (int)($result['signups_created'] ?? 0)
                    ));
                }
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] Perpetual sync failed for schedule_id=' . $schedule_id . ': ' . $e->getMessage());
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
