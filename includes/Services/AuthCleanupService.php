<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

/**
 * AuthCleanupService
 *
 * Uses Action Scheduler to periodically purge:
 * - expired sessions
 * - expired magic links
 * - used magic links older than retention window
 *
 * Schedule:
 * - runs every 12 hours (default), first run at next 03:17 local site time
 *
 * Notes:
 * - No WP-Cron fallback (per your requirement)
 * - Safe in dev: won’t explode if tables don’t exist yet
 * - IMPORTANT: scheduling is deferred until AFTER Action Scheduler datastore is initialized
 */
class AuthCleanupService
{
    /**
     * Hook name used for Action Scheduler.
     */
    public const CRON_HOOK = 'adoration_scheduler_auth_cleanup';

    /**
     * Group name in Action Scheduler (helps filter/manage jobs).
     */
    private const AS_GROUP = 'adoration-scheduler';

    /**
     * Default interval: every 12 hours.
     */
    private const DEFAULT_INTERVAL_SECONDS = 12 * HOUR_IN_SECONDS;

    /**
     * Keep USED magic links for audit (days).
     * Expired/unused links are deleted immediately.
     */
    private const USED_MAGIC_RETAIN_DAYS = 30;

    /**
     * Batch size safety (avoid huge deletes in one go).
     */
    private const DEFAULT_DELETE_BATCH_LIMIT = 1000;

    /**
     * Hard cap to prevent runaway loops.
     * Max deletions per table per run = batch_limit * max_batches
     */
    private const DEFAULT_MAX_BATCHES = 50;

    /**
     * Option used to request a one-time force reschedule on the next safe request.
     */
    private const OPT_FORCE_RESCHEDULE = 'adoration_scheduler_auth_cleanup_force_reschedule';

    public static function register(): void
    {
        // Action Scheduler executes WordPress actions; this is the job handler.
        add_action(self::CRON_HOOK, [self::class, 'run_cleanup']);

        /**
         * CRITICAL:
         * Do NOT call Action Scheduler scheduling APIs until AFTER AS datastore is initialized.
         *
         * wp_loaded is late enough that AS has completed init on normal requests.
         */
        add_action('wp_loaded', [self::class, 'maybe_schedule'], 20);
    }

    /**
     * Called from plugin activation.
     * We don't schedule immediately here because activation runs early and AS may not be ready.
     * Instead, set a flag to force-reschedule on the next normal request (wp_loaded).
     */
    public static function activate(): void
    {
        update_option(self::OPT_FORCE_RESCHEDULE, 1, false);
    }

    /**
     * Optional: call from Plugin::deactivate to remove scheduled jobs.
     */
    public static function deactivate(): void
    {
        self::unschedule_all();
        delete_option(self::OPT_FORCE_RESCHEDULE);
    }

    /**
     * Force-reschedule helper (useful during dev if you change interval/behavior).
     */
    public static function force_reschedule(): void
    {
        update_option(self::OPT_FORCE_RESCHEDULE, 1, false);
    }

    /**
     * Runs late (wp_loaded). Safe place to call AS scheduling functions.
     */
    public static function maybe_schedule(): void
    {
        static $ran = false;
        if ($ran) return;
        $ran = true;

        if (!self::action_scheduler_ready()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] AuthCleanupService: Action Scheduler not ready on wp_loaded; not scheduling.');
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

    /**
     * Ensure the recurring cleanup action is scheduled in Action Scheduler.
     *
     * @param bool $force If true, unschedules existing and schedules fresh
     */
    public static function ensure_recurring(bool $force = false): void
    {
        if (!self::action_scheduler_ready()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] AuthCleanupService: Action Scheduler not ready; ensure_recurring skipped.');
            }
            return;
        }

        $interval = (int) apply_filters(
            'adoration_scheduler_auth_cleanup_interval_seconds',
            self::DEFAULT_INTERVAL_SECONDS
        );
        if ($interval < HOUR_IN_SECONDS) $interval = HOUR_IN_SECONDS;

        if ($force) {
            self::unschedule_all();
        } else {
            // as_next_scheduled_action requires datastore initialized (now it is)
            $next = as_next_scheduled_action(self::CRON_HOOK, [], self::AS_GROUP);
            if (!empty($next)) {
                return; // already scheduled
            }
        }

        // First run: next 03:17 local site time (predictable)
        $first = self::next_run_timestamp_local(3, 17);

        as_schedule_recurring_action(
            $first,
            $interval,
            self::CRON_HOOK,
            [],
            self::AS_GROUP
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                '[AdorationScheduler] AuthCleanupService scheduled: first=' .
                gmdate('c', $first) .
                ' interval=' . $interval . 's group=' . self::AS_GROUP
            );
        }
    }

    /**
     * Remove all scheduled cleanup actions for this hook/group.
     */
    public static function unschedule_all(): void
    {
        if (!self::action_scheduler_ready()) {
            return;
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_HOOK, [], self::AS_GROUP);
        }
    }

    /**
     * Perform the cleanup in batches.
     */
    public static function run_cleanup(): void
    {
        global $wpdb;

        $sessions = $wpdb->prefix . 'adoration_sessions';
        $magic    = $wpdb->prefix . 'adoration_magic_links';

        $now_gmt = gmdate('Y-m-d H:i:s');

        $retain_days = (int) apply_filters(
            'adoration_scheduler_used_magic_retain_days',
            self::USED_MAGIC_RETAIN_DAYS
        );
        if ($retain_days < 0) $retain_days = 0;

        $batch_limit = (int) apply_filters(
            'adoration_scheduler_auth_cleanup_batch_limit',
            self::DEFAULT_DELETE_BATCH_LIMIT
        );
        if ($batch_limit < 100) $batch_limit = 100;

        $max_batches = (int) apply_filters(
            'adoration_scheduler_auth_cleanup_max_batches',
            self::DEFAULT_MAX_BATCHES
        );
        if ($max_batches < 1) $max_batches = 1;

        $used_cutoff_gmt = gmdate('Y-m-d H:i:s', time() - ($retain_days * DAY_IN_SECONDS));

        $deleted_sessions = 0;
        $deleted_magic    = 0;

        // 1) Expired sessions
        $deleted_sessions += self::delete_in_batches(
            $sessions,
            "expires_at <= %s",
            [$now_gmt],
            $batch_limit,
            $max_batches
        );

        // 2) Expired magic links (used or unused)
        $deleted_magic += self::delete_in_batches(
            $magic,
            "expires_at <= %s",
            [$now_gmt],
            $batch_limit,
            $max_batches
        );

        // 3) Used magic links older than retention window
        if ($retain_days === 0) {
            $deleted_magic += self::delete_in_batches(
                $magic,
                "used_at IS NOT NULL",
                [],
                $batch_limit,
                $max_batches
            );
        } else {
            $deleted_magic += self::delete_in_batches(
                $magic,
                "used_at IS NOT NULL AND used_at <= %s",
                [$used_cutoff_gmt],
                $batch_limit,
                $max_batches
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[AdorationScheduler] AuthCleanupService ran: deleted_sessions=%d deleted_magic_links=%d retain_days=%d batch_limit=%d max_batches=%d',
                $deleted_sessions,
                $deleted_magic,
                $retain_days,
                $batch_limit,
                $max_batches
            ));
        }
    }

    /**
     * Delete rows matching a WHERE clause in batches (MySQL supports DELETE ... LIMIT).
     *
     * @return int total rows deleted
     */
    private static function delete_in_batches(
        string $table,
        string $where_sql,
        array $where_params,
        int $batch_limit,
        int $max_batches
    ): int {
        global $wpdb;

        // If table doesn't exist (early dev), just no-op.
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return 0;
        }

        $deleted_total = 0;

        for ($i = 0; $i < $max_batches; $i++) {
            $sql = "DELETE FROM {$table} WHERE {$where_sql} LIMIT " . (int)$batch_limit;

            $prepared = !empty($where_params)
                ? $wpdb->prepare($sql, ...$where_params)
                : $sql;

            $deleted = $wpdb->query($prepared);

            if ($deleted === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[AdorationScheduler] AuthCleanupService delete failed table=' . $table . ' err=' . $wpdb->last_error);
                }
                break;
            }

            $deleted_total += (int)$deleted;

            if ((int)$deleted < (int)$batch_limit) {
                break;
            }
        }

        return $deleted_total;
    }

    /**
     * Returns the next run timestamp based on the site's local timezone.
     */
    private static function next_run_timestamp_local(int $hour, int $minute): int
    {
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

    /**
     * “Ready” check: functions exist AND Action Scheduler store can be instantiated.
     */
    private static function action_scheduler_ready(): bool
    {
        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_next_scheduled_action')) {
            return false;
        }

        // Avoid “called incorrectly” warnings by ensuring the store exists now.
        if (class_exists('\\ActionScheduler_Store') && method_exists('\\ActionScheduler_Store', 'instance')) {
            try {
                $store = \ActionScheduler_Store::instance();
                return (bool) $store;
            } catch (\Throwable $e) {
                return false;
            }
        }

        // If store class isn’t visible, play it safe.
        return false;
    }
}
