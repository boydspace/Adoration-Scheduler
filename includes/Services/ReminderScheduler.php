<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

class ReminderScheduler {

    /**
     * Hook name used for both Action Scheduler + legacy WP-Cron.
     */
    public const CRON_HOOK = 'adoration_scheduler_send_signup_reminder';

    /**
     * Group name in Action Scheduler (helps filter/manage jobs).
     */
    private const AS_GROUP = 'adoration-scheduler';

    public static function register(): void {
        add_action(self::CRON_HOOK, [self::class, 'send_reminder'], 10, 1);
    }

    /**
     * Schedule a 24h reminder for a confirmed signup.
     * Uses Action Scheduler when available; falls back to WP-Cron if not.
     */
    public function schedule_24h(int $signup_id): void {
        $signup_id = (int)$signup_id;

        $when = $this->compute_remind_timestamp($signup_id);
        if ($when === null) {
            return;
        }

        // Prefer Action Scheduler
        if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {

            // Avoid duplicates
            $already = as_has_scheduled_action(self::CRON_HOOK, [$signup_id], self::AS_GROUP);
            if ($already) return;

            as_schedule_single_action($when, self::CRON_HOOK, [$signup_id], self::AS_GROUP);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] ReminderScheduler scheduled (AS) signup_id=' . $signup_id . ' when=' . gmdate('c', $when) . ' (UTC)');
            }

            return;
        }

        // Fallback: WP-Cron
        $already = wp_next_scheduled(self::CRON_HOOK, [$signup_id]);
        if ($already) return;

        wp_schedule_single_event($when, self::CRON_HOOK, [$signup_id]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] ReminderScheduler scheduled (WP-Cron) signup_id=' . $signup_id . ' when=' . gmdate('c', $when) . ' (UTC)');
        }
    }

    /**
     * Unschedule any pending reminders for a signup.
     */
    public static function unschedule_for_signup(int $signup_id): void {
        $signup_id = (int)$signup_id;

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_HOOK, [$signup_id], self::AS_GROUP);
        }

        wp_clear_scheduled_hook(self::CRON_HOOK, [$signup_id]);
        wp_clear_scheduled_hook(self::CRON_HOOK, [(string)$signup_id]);
    }

    /**
     * Compute the reminder timestamp (UTC epoch seconds) = slot_start - 24 hours.
     *
     * Key fixes:
     * - Prefer the SLOT occurrence date (not signup['date'] which may be "created date" or otherwise not the occurrence).
     * - Parse using WP timezone via DateTimeImmutable (no strtotime guessing).
     * - Support common stored formats.
     */
    private function compute_remind_timestamp(int $signup_id): ?int {
        $signups = new SignupsRepository();
        $slots   = new SlotsRepository();

        $signup = $signups->find($signup_id);
        if (!$signup || ($signup['status'] ?? '') !== 'confirmed') return null;

        $slot_id = (int)($signup['slot_id'] ?? 0);
        if ($slot_id <= 0) return null;

        $slot = $slots->find($slot_id);
        if (!$slot) return null;

        // ✅ Prefer the slot occurrence date/time (that’s what the person is actually signed up for)
        $date  = trim((string)($slot['date'] ?? ''));
        $start = trim((string)($slot['start_time'] ?? $slot['start'] ?? ''));

        // Fallback ONLY if slot date is missing (should be rare)
        if ($date === '') {
            $date = trim((string)($signup['date'] ?? ''));
        }

        if ($date === '' || $start === '') return null;

        $slot_ts = self::parse_local_datetime_to_timestamp($date, $start);
        if ($slot_ts === null) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] ReminderScheduler compute_remind_timestamp FAILED parse signup_id=' . (int)$signup_id . ' date=' . $date . ' start=' . $start);
            }
            return null;
        }

        $remind_ts = $slot_ts - DAY_IN_SECONDS;

        // Don’t schedule in the past (or within 60 seconds)
        if ($remind_ts <= (time() + 60)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[AdorationScheduler] ReminderScheduler not scheduling (past) signup_id=' . (int)$signup_id
                    . ' slot_utc=' . gmdate('c', $slot_ts)
                    . ' remind_utc=' . gmdate('c', $remind_ts));
            }
            return null;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] ReminderScheduler computed signup_id=' . (int)$signup_id
                . ' slot_date=' . $date
                . ' slot_start=' . $start
                . ' slot_utc=' . gmdate('c', $slot_ts)
                . ' remind_utc=' . gmdate('c', $remind_ts));
        }

        return $remind_ts;
    }

    /**
     * Parse a (date, time) that represent LOCAL parish time (WP timezone) into a UTC epoch timestamp.
     *
     * Supports:
     * - Date: Y-m-d (preferred), m/d/Y
     * - Time: H:i:s, H:i, g:i A, g:i a
     */
    private static function parse_local_datetime_to_timestamp(string $date, string $time): ?int {
        $date = trim($date);
        $time = trim($time);

        if ($date === '' || $time === '') return null;

        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        // Build candidate formats in order of likelihood
        $date_formats = ['Y-m-d', 'm/d/Y', 'n/j/Y'];
        $time_formats = ['H:i:s', 'H:i', 'g:i A', 'g:i a'];

        foreach ($date_formats as $df) {
            foreach ($time_formats as $tf) {
                $fmt = $df . ' ' . $tf;
                $dt = \DateTimeImmutable::createFromFormat($fmt, $date . ' ' . $time, $tz);
                if ($dt instanceof \DateTimeImmutable) {
                    $errors = \DateTimeImmutable::getLastErrors();
                    if (is_array($errors) && (($errors['warning_count'] ?? 0) === 0) && (($errors['error_count'] ?? 0) === 0)) {
                        return $dt->getTimestamp();
                    }
                }
            }
        }

        // Last resort: try DateTime parser in WP TZ (still better than strtotime in server TZ)
        try {
            $dt = new \DateTimeImmutable($date . ' ' . $time, $tz);
            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function send_reminder(int $signup_id): void {
        $signup_id = (int)$signup_id;

        $signups   = new SignupsRepository();
        $slots     = new SlotsRepository();
        $schedules = new SchedulesRepository();
        $persons   = new PersonsRepository();

        $signup = $signups->find($signup_id);

        // If it was cancelled/deleted since scheduling, do nothing
        if (!$signup || ($signup['status'] ?? '') !== 'confirmed') return;

        $person_id   = (int)($signup['person_id'] ?? 0);
        $slot_id     = (int)($signup['slot_id'] ?? 0);
        $schedule_id = (int)($signup['schedule_id'] ?? 0);

        $person = $person_id > 0 ? $persons->find($person_id) : null;
        $slot   = $slot_id > 0 ? $slots->find($slot_id) : null;
        $sched  = $schedule_id > 0 ? $schedules->find($schedule_id) : null;

        $to = trim((string)($person['email'] ?? $signup['email'] ?? ''));
        if ($to === '' || !is_email($to)) return;

        $first = trim((string)($person['first_name'] ?? $person['first'] ?? ''));
        $last  = trim((string)($person['last_name'] ?? $person['last'] ?? ''));

        $person_name = trim((string)($person['name'] ?? ''));
        if ($person_name === '') {
            $person_name = trim($first . ' ' . $last);
        }

        $schedule_title = trim((string)($sched['name'] ?? $sched['title'] ?? $signup['schedule_name'] ?? 'Adoration'));

        // ✅ Use slot occurrence date primarily (fallback to signup date if slot missing)
        $date  = trim((string)($slot['date'] ?? $signup['date'] ?? ''));
        $start = trim((string)($slot['start_time'] ?? $slot['start'] ?? ''));
        $end   = trim((string)($slot['end_time'] ?? $slot['end'] ?? ''));

        $slot_label = trim($date . ' ' . $start);
        if ($end !== '') $slot_label .= '–' . $end;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] ReminderScheduler send_reminder signup_id=' . $signup_id . ' to=' . $to . ' date=' . $date . ' start=' . $start);
        }

        NotificationService::send_reminder_24h([
            'to_email'       => $to,
            'first_name'     => $first,
            'last_name'      => $last,
            'person_name'    => $person_name,
            'schedule_title' => $schedule_title,
            'schedule_name'  => $schedule_title,
            'slot_date'      => $date,
            'slot_start'     => $start,
            'slot_end'       => $end,
            'slot_label'     => $slot_label,
            'context'        => 'system',
            'send'           => true,
            'signup_id'      => $signup_id, // deterministic dedupe
        ]);
    }
}
