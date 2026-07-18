<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

/**
 * Backwards-compatible facade.
 *
 * Legacy code calls:
 *   EmailService::send_signup_confirmation($signup_id)
 *
 * New code calls:
 *   NotificationService::send_signup_confirmation([ ...args... ])
 *
 * This class supports BOTH.
 */
class EmailService
{
    public static function register(): void
    {
        // No hooks. Exists for consistency if Plugin.php calls it.
    }

    /**
     * Legacy + new:
     * - If called with int $signup_id: loads signup/person/schedule/slot and forwards to NotificationService
     * - If called with array $args: forwards as-is
     *
     * @param mixed $args_or_signup_id
     */
    public static function send_signup_confirmation($args_or_signup_id): bool
    {
        if (is_array($args_or_signup_id)) {
            return NotificationService::send_signup_confirmation($args_or_signup_id);
        }

        $signup_id = (int) $args_or_signup_id;
        if ($signup_id <= 0) return false;

        $args = self::build_args_from_signup_id($signup_id);
        if (!$args) return false;

        return NotificationService::send_signup_confirmation($args);
    }

    /**
     * Reminder can be legacy (signup_id) or new (args array) too.
     *
     * @param mixed $args_or_signup_id
     */
    public static function send_reminder_24h($args_or_signup_id): bool
    {
        if (is_array($args_or_signup_id)) {
            return NotificationService::send_reminder_24h($args_or_signup_id);
        }

        $signup_id = (int) $args_or_signup_id;
        if ($signup_id <= 0) return false;

        $args = self::build_args_from_signup_id($signup_id);
        if (!$args) return false;

        return NotificationService::send_reminder_24h($args);
    }

    public static function send_magic_link(array $args): bool
    {
        return NotificationService::send_magic_link($args);
    }

    public static function send_test_template(string $template_key, string $to, int $schedule_id = 0): bool
    {
        return NotificationService::send_test_template($template_key, $to, $schedule_id);
    }

    /**
     * Build NotificationService-compatible args from a signup_id.
     *
     * Requires these tables to exist:
     *  - {$wpdb->prefix}adoration_signups
     *  - {$wpdb->prefix}adoration_persons
     *  - {$wpdb->prefix}adoration_slots
     *  - {$wpdb->prefix}adoration_schedules
     *
     * If your schema differs, tell me your column names and I’ll adjust.
     */
    private static function build_args_from_signup_id(int $signup_id): ?array
    {
        global $wpdb;

        $signups   = $wpdb->prefix . 'adoration_signups';
        $persons   = $wpdb->prefix . 'adoration_persons';
        $slots     = $wpdb->prefix . 'adoration_slots';
        $schedules = $wpdb->prefix . 'adoration_schedules';

        // Pull signup row
        $signup = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$signups} WHERE id = %d LIMIT 1", $signup_id),
            ARRAY_A
        );
        if (!is_array($signup)) {
            error_log('[AdorationScheduler] EmailService build_args: missing signup id=' . $signup_id);
            return null;
        }

        $person_id   = (int)($signup['person_id'] ?? 0);
        $schedule_id = (int)($signup['schedule_id'] ?? 0);
        $slot_id     = (int)($signup['slot_id'] ?? 0);

        if ($person_id <= 0) {
            error_log('[AdorationScheduler] EmailService build_args: signup missing person_id id=' . $signup_id);
            return null;
        }

        // Person
        $person = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$persons} WHERE id = %d LIMIT 1", $person_id),
            ARRAY_A
        );
        if (!is_array($person)) {
            error_log('[AdorationScheduler] EmailService build_args: missing person id=' . $person_id);
            return null;
        }

        // Schedule (optional but strongly preferred)
        $schedule = null;
        if ($schedule_id > 0) {
            $schedule = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$schedules} WHERE id = %d LIMIT 1", $schedule_id),
                ARRAY_A
            );
        }

        // Slot (optional but strongly preferred)
        $slot = null;
        if ($slot_id > 0) {
            $slot = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$slots} WHERE id = %d LIMIT 1", $slot_id),
                ARRAY_A
            );
        }

        // Email + name fields (try multiple possible column names)
        $email = (string)($person['email'] ?? '');
        $first = (string)($person['first_name'] ?? '');
        $last  = (string)($person['last_name'] ?? '');
        $name  = trim($first . ' ' . $last);
        if ($name === '' && !empty($person['name'])) {
            $name = (string)$person['name'];
        }

        // Schedule title/name (column names can vary)
        $schedule_title = '';
        if (is_array($schedule)) {
            $schedule_title = (string)($schedule['title'] ?? $schedule['name'] ?? '');
        }

        // Slot date/time (column names can vary; keep resilient)
        $slot_date  = '';
        $slot_start = '';
        $slot_end   = '';

        if (is_array($slot)) {
            // Common column possibilities:
            // date / slot_date, start_time / slot_start, end_time / slot_end
            $slot_date  = (string)($slot['slot_date'] ?? $slot['date'] ?? $slot['day'] ?? '');
            $slot_start = (string)($slot['slot_start'] ?? $slot['start_time'] ?? $slot['start'] ?? '');
            $slot_end   = (string)($slot['slot_end'] ?? $slot['end_time'] ?? $slot['end'] ?? '');
        }

        // Human label if we can
        $slot_label = trim($slot_date . ' ' . $slot_start . '–' . $slot_end);
        if ($slot_label === '–') $slot_label = '';

        // ✅ Check-in (2026-07-18): a no-login "I'm here" link for the
        // confirmation/reminder emails. Best-effort — if CheckInService or
        // the checkin_token column somehow isn't available yet (e.g. an
        // upgrade mid-flight), the email still sends, just without the link.
        $checkin_url = '';
        try {
            if (class_exists(\AdorationScheduler\Services\CheckInService::class)) {
                $checkin_url = (string) (\AdorationScheduler\Services\CheckInService::build_checkin_url($signup_id, 'in') ?? '');
            }
        } catch (\Throwable $e) {
            $checkin_url = '';
        }

        $args = [
            'signup_id'      => $signup_id,
            'to_email'       => $email,
            'first_name'     => $first,
            'last_name'      => $last,
            'person_name'    => $name,
            'person_id'      => $person_id,
            'schedule_title' => $schedule_title,
            'schedule_name'  => $schedule_title,
            'slot_date'      => $slot_date,
            'slot_start'     => $slot_start,
            'slot_end'       => $slot_end,
            'slot_label'     => $slot_label,
            'church_name'    => get_bloginfo('name'),
            'manage_url'     => home_url('/my-adoration/'),
            'checkin_url'    => $checkin_url,
            'context'        => 'frontend',
            'send'           => true,
        ];

        // Minimal sanity check
        if ($args['to_email'] === '' || !is_email($args['to_email'])) {
            error_log('[AdorationScheduler] EmailService build_args: invalid to_email for person_id=' . $person_id);
            return null;
        }

        error_log('[AdorationScheduler] EmailService build_args OK signup_id=' . $signup_id . ' to=' . $args['to_email']);

        return $args;
    }
}
