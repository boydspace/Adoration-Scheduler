<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;

class ScheduleCreationService {

    /**
     * Register admin-post hooks.
     */
    public static function register(): void {
        add_action(
            'admin_post_adoration_create_schedule',
            [ __CLASS__, 'handle_create_schedule' ]
        );
    }

    /**
     * Handle schedule creation POST.
     *
     * IMPORTANT:
     * This runs BEFORE any output, so redirects are safe (as long as nothing has echoed earlier).
     */
    public static function handle_create_schedule(): void {

        if ( ! current_user_can('manage_options') ) {
            wp_die(
                esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'),
                403
            );
        }

        check_admin_referer('adoration_create_schedule');

        $repo       = new SchedulesRepository();
        $dateRepo   = new DatePatternsRepository();
        $chapelRepo = new ChapelsRepository();

        // Ensure at least one chapel exists so we can always save a valid chapel_id.
        $default_chapel_id = (int) $chapelRepo->ensure_default_chapel_exists();

        // --------------------
        // Sanitize input
        // --------------------
        $name         = sanitize_text_field($_POST['name'] ?? '');
        $type         = sanitize_text_field($_POST['type'] ?? 'event');
        $status       = sanitize_text_field($_POST['status'] ?? 'draft');
        $privacy_mode = sanitize_text_field($_POST['privacy_mode'] ?? 'counts_only');

        // ✅ NEW: Chapel selection
        $chapel_id = isset($_POST['chapel_id'])
            ? (int) wp_unslash($_POST['chapel_id'])
            : 0;

        // If chapel_id missing/invalid, fallback to default.
        if ($chapel_id <= 0) {
            $chapel_id = $default_chapel_id;
        } else {
            // If they posted a non-existent chapel, fallback to default.
            $found = $chapelRepo->find($chapel_id);
            if (!$found) {
                $chapel_id = $default_chapel_id;
            }
        }

        // NEW (schedule row fields)
        $start_date_raw = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date_raw   = sanitize_text_field($_POST['end_date'] ?? '');

        // ✅ NEW: Overnight flag (checkbox)
        // Checkbox sends "1" when checked, nothing when unchecked.
        $is_overnight = isset($_POST['is_overnight']) ? 1 : 0;

        // NEW (date list -> date_patterns rows)
        $event_dates_raw = (string)($_POST['event_dates'] ?? '');

        // ✅ DEFAULTS (schedule row fields)
        $default_slot_length = isset($_POST['default_slot_length'])
            ? (int) wp_unslash($_POST['default_slot_length'])
            : 60;
        if ($default_slot_length <= 0) $default_slot_length = 60;

        $default_min_adorers = isset($_POST['default_min_adorers'])
            ? (int) wp_unslash($_POST['default_min_adorers'])
            : 1;
        if ($default_min_adorers < 0) $default_min_adorers = 0;

        $default_max_raw = isset($_POST['default_max_adorers'])
            ? wp_unslash($_POST['default_max_adorers'])
            : '';
        $default_max_raw = is_string($default_max_raw) ? trim($default_max_raw) : $default_max_raw;

        // blank => NULL
        $default_max_adorers = ($default_max_raw === '' || $default_max_raw === null) ? null : (int) $default_max_raw;
        if ($default_max_adorers !== null && $default_max_adorers < 0) {
            $default_max_adorers = null;
        }

        // Optional sanity: if max < min, bump max to min
        if ($default_max_adorers !== null && $default_max_adorers < $default_min_adorers) {
            $default_max_adorers = $default_min_adorers;
        }

        // --------------------
        // Whitelists
        // --------------------
        $allowed_types   = ['event', 'perpetual', 'monthly'];
        $allowed_status  = ['draft', 'active'];
        $allowed_privacy = [
            'counts_only',
            'first_name_only',
            'first_last_initial',
            'names',
        ];

        if (!in_array($type, $allowed_types, true)) {
            $type = 'event';
        }

        if (!in_array($status, $allowed_status, true)) {
            $status = 'draft';
        }

        if (!in_array($privacy_mode, $allowed_privacy, true)) {
            $privacy_mode = 'counts_only';
        }

        // --------------------
        // Validation
        // --------------------
        if ($name === '') {
            self::redirect_back_with_toast(
                'Schedule name is required.',
                'error'
            );
        }

        // --------------------
        // Date helpers
        // --------------------
        $normalize_ymd = static function(string $v): ?string {
            $v = trim($v);
            if ($v === '') return null;

            // Must be YYYY-MM-DD
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;

            $dt = \DateTime::createFromFormat('Y-m-d', $v);
            if (!$dt) return null;

            $errors = \DateTime::getLastErrors();
            if (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']))) {
                return null;
            }

            return $dt->format('Y-m-d');
        };

        $start_date = $normalize_ymd($start_date_raw);
        $end_date   = $normalize_ymd($end_date_raw);

        // Parse event_dates textarea into unique, normalized Y-m-d array
        $event_dates = [];
        if (trim($event_dates_raw) !== '') {
            $lines = preg_split("/\r\n|\n|\r/", $event_dates_raw) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;

                $d = $normalize_ymd($line);
                if ($d !== null) {
                    $event_dates[$d] = true; // dedupe
                }
            }
        }
        $event_dates = array_keys($event_dates);
        sort($event_dates);

        // If start/end are blank, and they provided event dates, derive range from those
        if ($start_date === null && !empty($event_dates)) {
            $start_date = $event_dates[0];
        }
        if ($end_date === null && !empty($event_dates)) {
            $end_date = $event_dates[count($event_dates) - 1];
        }

        // If both provided, ensure end >= start
        if ($start_date !== null && $end_date !== null && $end_date < $start_date) {
            // swap to be forgiving
            $tmp = $start_date;
            $start_date = $end_date;
            $end_date = $tmp;
        }

        // --------------------
        // Slug generation
        // --------------------
        $slug = sanitize_title($name);
        if ($slug === '') {
            $slug = 'schedule';
        }

        if (method_exists($repo, 'exists_slug') && $repo->exists_slug($slug)) {
            $base = $slug;
            $i = 2;
            while ($repo->exists_slug($slug)) {
                $slug = $base . '-' . $i;
                $i++;
            }
        }

        // --------------------
        // Create schedule
        // --------------------
        $new_id = (int) $repo->create([
            // ✅ NEW: persist chapel selection
            'chapel_id'            => $chapel_id,

            'name'                 => $name,
            'slug'                 => $slug,
            'type'                 => $type,
            'status'               => $status,
            'privacy_mode'         => $privacy_mode,
            'start_date'           => $start_date,
            'end_date'             => $end_date,

            // ✅ persist overnight flag
            'is_overnight'         => $is_overnight,

            // ✅ include defaults on create
            'default_slot_length'  => $default_slot_length,
            'default_min_adorers'  => $default_min_adorers,
            'default_max_adorers'  => $default_max_adorers, // null allowed
        ]);

        if ($new_id <= 0) {
            self::redirect_back_with_toast(
                'Could not create schedule. Please try again.',
                'error'
            );
        }

        // --------------------
        // Create DatePatterns rows for Event Dates (optional)
        // --------------------
        if ($type === 'event' && !empty($event_dates)) {
            foreach ($event_dates as $d) {
                $dateRepo->create($new_id, $d);
            }
        }

        // --------------------
        // Seed email templates from global (DISABLED by default)
        // --------------------
        if (method_exists($repo, 'save_email_templates')) {
            $global = get_option('adoration_scheduler_email_templates', []);
            if (!is_array($global)) {
                $global = [];
            }

            // Normalize global → schedule keys
            if (isset($global['confirm_subject']) && !isset($global['signup_confirmation_subject'])) {
                $global['signup_confirmation_subject'] = (string)$global['confirm_subject'];
            }
            if (isset($global['confirm_body']) && !isset($global['signup_confirmation_body'])) {
                $global['signup_confirmation_body'] = (string)$global['confirm_body'];
            }
            if (isset($global['reminder_subject']) && !isset($global['reminder_24h_subject'])) {
                $global['reminder_24h_subject'] = (string)$global['reminder_subject'];
            }
            if (isset($global['reminder_body']) && !isset($global['reminder_24h_body'])) {
                $global['reminder_24h_body'] = (string)$global['reminder_body'];
            }

            $repo->save_email_templates(
                $new_id,
                [
                    'from_name' => (string)($global['from_name'] ?? ''),
                    'from_email' => (string)($global['from_email'] ?? ''),
                    'reply_to' => (string)($global['reply_to'] ?? ''),

                    'signup_confirmation_subject' => (string)($global['signup_confirmation_subject'] ?? ''),
                    'signup_confirmation_body'    => (string)($global['signup_confirmation_body'] ?? ''),
                    'reminder_24h_subject'        => (string)($global['reminder_24h_subject'] ?? ''),
                    'reminder_24h_body'           => (string)($global['reminder_24h_body'] ?? ''),
                ],
                false
            );
        }

        // --------------------
        // Redirect to edit page (with toast)
        // --------------------
        $edit_url = add_query_arg([
            'page'        => 'adoration_scheduler_schedules',
            'action'      => 'edit',
            'schedule_id' => $new_id,
            'tab'         => 'overview',

            'as_toast'      => 'Schedule created.',
            'as_toast_type' => 'success',
        ], admin_url('admin.php'));

        wp_safe_redirect($edit_url);
        exit;
    }

    /**
     * Redirect back to wherever the form came from (Add New Schedule page),
     * with a toast message.
     */
    private static function redirect_back_with_toast(string $message, string $type = 'info', bool $sticky = false): void {
        $ref = wp_get_referer();

        if (!$ref) {
            $ref = add_query_arg(['page' => 'adoration_scheduler_schedules'], admin_url('admin.php'));
        }

        $allowed = ['success','error','warning','info'];
        $type = sanitize_key($type);
        if (!in_array($type, $allowed, true)) $type = 'info';

        $url = add_query_arg([
            'created'         => '0',
            'as_toast'        => $message,
            'as_toast_type'   => $type,
            'as_toast_sticky' => $sticky ? '1' : '0',
        ], $ref);

        wp_safe_redirect($url);
        exit;
    }
}
