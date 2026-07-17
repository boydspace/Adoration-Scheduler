<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\EmailLogRepository;

class EmailLogRetentionService
{
    /**
     * Action Scheduler hook name.
     */
    private const AS_HOOK = 'adoration_scheduler_email_log_retention_purge';

    /**
     * WP-Cron hook name (fallback).
     */
    private const CRON_HOOK = 'adoration_scheduler_email_log_retention_purge_cron';

    /**
     * Default retention (days).
     */
    private const DEFAULT_RETENTION_DAYS = 365;

    /**
     * Default run interval (seconds): every 7 days.
     */
    private const DEFAULT_INTERVAL_SECONDS = 7 * DAY_IN_SECONDS;

    public static function register(): void
    {
        // The job callbacks
        add_action(self::AS_HOOK,   [__CLASS__, 'run_purge']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_purge']);

        // Ensure scheduled
        add_action('init', [__CLASS__, 'ensure_scheduled']);
    }

    /**
     * Make sure we have a recurring job.
     * Prefers Action Scheduler, falls back to WP-Cron.
     */
    public static function ensure_scheduled(): void
    {
        // Allow disabling entirely
        $enabled = apply_filters('adoration_scheduler_email_log_retention_enabled', true);
        if (!$enabled) {
            self::unschedule_all();
            return;
        }

        // 7-day interval (seconds)
        $interval = (int) apply_filters('adoration_scheduler_email_log_retention_interval', self::DEFAULT_INTERVAL_SECONDS);
        if ($interval < DAY_IN_SECONDS) $interval = DAY_IN_SECONDS; // guard

        // Prefer Action Scheduler if present
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            $next = as_next_scheduled_action(self::AS_HOOK);
            if (!$next) {
                // First run: tomorrow at ~2:15am site time (nice + quiet), then repeat every 7 days
                $start = self::next_run_timestamp();
                as_schedule_recurring_action($start, $interval, self::AS_HOOK, [], 'adoration-scheduler');
            }
            return;
        }

        // Fallback: WP-Cron weekly-ish schedule
        self::ensure_wp_cron_schedule($interval);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(self::next_run_timestamp(), 'adoration_scheduler_every_7_days', self::CRON_HOOK);
        }
    }

    /**
     * Purge logs older than retention days.
     */
    public static function run_purge(): void
    {
        // Allow disabling on the fly
        $enabled = apply_filters('adoration_scheduler_email_log_retention_enabled', true);
        if (!$enabled) return;

        $days = (int) apply_filters('adoration_scheduler_email_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        $days = max(1, min(3650, $days)); // cap 10 years

        try {
            if (!class_exists(EmailLogRepository::class)) return;
            $repo = new EmailLogRepository();
            if (method_exists($repo, 'delete_older_than_days')) {
                $repo->delete_older_than_days($days);
            }
        } catch (\Throwable $e) {
            // Never throw from cron/AS callbacks
        }
    }

    /**
     * Unschedule everything if disabled.
     */
    public static function unschedule_all(): void
    {
        // Action Scheduler
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AS_HOOK, [], 'adoration-scheduler');
        }

        // WP-Cron
        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Compute next run: tomorrow 2:15am site time.
     */
    private static function next_run_timestamp(): int
    {
        // Site-local time to timestamp
        $dt = new \DateTime('now', wp_timezone());
        $dt->modify('+1 day');
        $dt->setTime(2, 15, 0);
        return $dt->getTimestamp();
    }

    /**
     * Register a custom WP-Cron interval that matches our desired seconds.
     * WordPress only knows built-ins unless we add it.
     */
    private static function ensure_wp_cron_schedule(int $interval_seconds): void
    {
        add_filter('cron_schedules', function($schedules) use ($interval_seconds) {
            if (!is_array($schedules)) $schedules = [];

            $schedules['adoration_scheduler_every_7_days'] = [
                'interval' => $interval_seconds,
                'display'  => __('Every 7 days (Adoration Scheduler)', 'adoration-scheduler'),
            ];

            return $schedules;
        });
    }
}
