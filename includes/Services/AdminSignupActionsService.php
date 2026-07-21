<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

/**
 * AdminSignupActionsService
 *
 * Handles admin-post actions from the Schedule Edit -> Signups tab AND Signups list page:
 * - adoration_admin_cancel_signup  (soft cancel: keeps record but frees slot)
 * - adoration_admin_delete_signup  (hard delete)
 * - adoration_admin_resend_confirm (optional helper)
 * - adoration_admin_send_reminder  (optional helper)
 *
 * Internal methods are exposed for bulk actions (no redirects).
 */
class AdminSignupActionsService
{
    /**
     * Granular capability (future-friendly).
     * If not yet added to roles, we fall back to manage_options.
     */
    private const CAP_MANAGE_SIGNUPS = 'adoration_manage_signups';

    /**
     * Basic rate limiting for admin actions (prevents accidental double-clicks and abuse).
     */
    private const RL_WINDOW_SECONDS = 60; // 1 minute
    private const RL_MAX_ATTEMPTS   = 20; // per user per signup per action per window

    public static function register(): void
    {
        add_action('admin_post_adoration_admin_cancel_signup',  [__CLASS__, 'handle_cancel']);
        add_action('admin_post_adoration_admin_delete_signup',  [__CLASS__, 'handle_delete']);
        add_action('admin_post_adoration_admin_resend_confirm', [__CLASS__, 'handle_resend_confirm']);
        add_action('admin_post_adoration_admin_send_reminder',  [__CLASS__, 'handle_send_reminder']);
    }

    public static function handle_cancel(): void
    {
        self::require_admin_cap(self::CAP_MANAGE_SIGNUPS);

        $signup_id = isset($_POST['signup_id']) ? (int) wp_unslash($_POST['signup_id']) : 0;
        $return    = self::get_return_url();

        $fail = self::add_toast($return, 'error', 'Could not cancel that signup.');

        if ($signup_id <= 0) {
            wp_safe_redirect($fail);
            exit;
        }

        if (!self::rate_limit_ok('cancel', $signup_id)) {
            wp_safe_redirect(self::add_toast($return, 'error', 'Too many attempts. Please wait a moment and try again.'));
            exit;
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adoration_admin_cancel_signup_' . $signup_id)) {
            wp_safe_redirect($fail);
            exit;
        }

        $ok = self::cancel_signup_internal($signup_id);

        if (!$ok) {
            wp_safe_redirect($fail);
            exit;
        }

        wp_safe_redirect(self::add_toast($return, 'success', 'Signup cancelled.'));
        exit;
    }

    public static function handle_delete(): void
    {
        self::require_admin_cap(self::CAP_MANAGE_SIGNUPS);

        $signup_id = isset($_POST['signup_id']) ? (int) wp_unslash($_POST['signup_id']) : 0;
        $return    = self::get_return_url();

        $fail = self::add_toast($return, 'error', 'Could not delete that signup.');

        if ($signup_id <= 0) {
            wp_safe_redirect($fail);
            exit;
        }

        if (!self::rate_limit_ok('delete', $signup_id)) {
            wp_safe_redirect(self::add_toast($return, 'error', 'Too many attempts. Please wait a moment and try again.'));
            exit;
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adoration_admin_delete_signup_' . $signup_id)) {
            wp_safe_redirect($fail);
            exit;
        }

        $ok = self::delete_signup_internal($signup_id);

        if (!$ok) {
            wp_safe_redirect($fail);
            exit;
        }

        wp_safe_redirect(self::add_toast($return, 'success', 'Signup deleted.'));
        exit;
    }

    public static function handle_resend_confirm(): void
    {
        self::require_admin_cap(self::CAP_MANAGE_SIGNUPS);

        $signup_id = isset($_POST['signup_id']) ? (int) wp_unslash($_POST['signup_id']) : 0;
        $return    = self::get_return_url();

        $fail = self::add_toast($return, 'error', 'Could not resend confirmation.');

        if ($signup_id <= 0) {
            wp_safe_redirect($fail);
            exit;
        }

        if (!self::rate_limit_ok('resend_confirm', $signup_id)) {
            wp_safe_redirect(self::add_toast($return, 'error', 'Too many attempts. Please wait a moment and try again.'));
            exit;
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adoration_admin_resend_confirm_' . $signup_id)) {
            wp_safe_redirect($fail);
            exit;
        }

        try {
            $args = self::build_notification_args_from_signup($signup_id);

            if ($args === null || !class_exists('\\AdorationScheduler\\Services\\NotificationService')) {
                wp_safe_redirect($fail);
                exit;
            }

            $ok = \AdorationScheduler\Services\NotificationService::send_signup_confirmation($args);

            if (!$ok) {
                wp_safe_redirect($fail);
                exit;
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Admin resend confirm failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            wp_safe_redirect($fail);
            exit;
        }

        wp_safe_redirect(self::add_toast($return, 'success', 'Confirmation sent (if configured).'));
        exit;
    }

    public static function handle_send_reminder(): void
    {
        self::require_admin_cap(self::CAP_MANAGE_SIGNUPS);

        $signup_id = isset($_POST['signup_id']) ? (int) wp_unslash($_POST['signup_id']) : 0;
        $return    = self::get_return_url();

        $fail = self::add_toast($return, 'error', 'Could not send reminder.');

        if ($signup_id <= 0) {
            wp_safe_redirect($fail);
            exit;
        }

        if (!self::rate_limit_ok('send_reminder', $signup_id)) {
            wp_safe_redirect(self::add_toast($return, 'error', 'Too many attempts. Please wait a moment and try again.'));
            exit;
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adoration_admin_send_reminder_' . $signup_id)) {
            wp_safe_redirect($fail);
            exit;
        }

        try {
            if (!class_exists('\\AdorationScheduler\\Services\\ReminderScheduler')
                || !method_exists('\\AdorationScheduler\\Services\\ReminderScheduler', 'send_reminder')) {
                wp_safe_redirect($fail);
                exit;
            }

            // send_reminder() sends immediately using the signup's stored data; it doesn't
            // return a status, so we just trust it (it logs its own failures).
            \AdorationScheduler\Services\ReminderScheduler::send_reminder($signup_id);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Admin send reminder failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            wp_safe_redirect($fail);
            exit;
        }

        wp_safe_redirect(self::add_toast($return, 'success', 'Reminder sent (if configured).'));
        exit;
    }

    /**
     * Build NotificationService-compatible args from a signup_id (used for admin resend).
     */
    private static function build_notification_args_from_signup(int $signup_id): ?array
    {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return null;

        $t_signups   = $wpdb->prefix . 'adoration_signups';
        $t_persons   = $wpdb->prefix . 'adoration_persons';
        $t_slots     = $wpdb->prefix . 'adoration_slots';
        $t_schedules = $wpdb->prefix . 'adoration_schedules';

        $sql = "
            SELECT
                su.id,
                su.person_id,
                su.slot_id,
                su.schedule_id,
                TRIM(CONCAT(TRIM(COALESCE(p.first_name,'')), ' ', TRIM(COALESCE(p.last_name,'')))) AS person_name,
                p.title AS title,
                p.first_name AS first_name,
                p.last_name AS last_name,
                p.email AS person_email,
                sc.name AS schedule_name,
                sl.date AS slot_date,
                sl.start_time AS slot_start,
                sl.end_time AS slot_end
            FROM {$t_signups} su
            LEFT JOIN {$t_persons} p ON p.id = su.person_id
            LEFT JOIN {$t_schedules} sc ON sc.id = su.schedule_id
            LEFT JOIN {$t_slots} sl ON sl.id = su.slot_id
            WHERE su.id = %d
            LIMIT 1
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row($wpdb->prepare($sql, $signup_id), ARRAY_A);
        if (!$row) return null;

        $to = trim((string)($row['person_email'] ?? ''));
        if ($to === '' || !is_email($to)) return null;

        $first = trim((string)($row['first_name'] ?? ''));
        $last  = trim((string)($row['last_name'] ?? ''));
        $title = trim((string)($row['title'] ?? ''));
        $person_name = trim((string)($row['person_name'] ?? ''));
        if ($person_name === '') $person_name = trim($first . ' ' . $last);

        $schedule_name = trim((string)($row['schedule_name'] ?? 'Adoration'));
        $slot_date  = trim((string)($row['slot_date'] ?? ''));
        $slot_start = trim((string)($row['slot_start'] ?? ''));
        $slot_end   = trim((string)($row['slot_end'] ?? ''));

        $slot_label = trim($slot_date . ' ' . $slot_start);
        if ($slot_end !== '') $slot_label .= '–' . $slot_end;

        return [
            'to_email'       => $to,
            'title'          => $title,
            'first_name'     => $first,
            'last_name'      => $last,
            'person_name'    => $person_name,
            'schedule_title' => $schedule_name,
            'schedule_name'  => $schedule_name,
            'slot_date'      => $slot_date,
            'slot_start'     => $slot_start,
            'slot_end'       => $slot_end,
            'slot_label'     => $slot_label,
            'context'        => 'admin',
            'send'           => true,
            'signup_id'      => (int)($row['id'] ?? 0),
            'person_id'      => (int)($row['person_id'] ?? 0),
            'schedule_id'    => (int)($row['schedule_id'] ?? 0),
            'slot_id'        => (int)($row['slot_id'] ?? 0),
            'dedupe_key'     => 'admin_resend:signup_confirmation:' . $signup_id . ':' . microtime(true),
            'dedupe_ttl'     => 0,
        ];
    }

    // ---------------------------------------------------------------------
    // ✅ Internal workhorses (used by bulk actions; no redirects; no nonce)
    // ---------------------------------------------------------------------

    /**
     * Cancel a signup using the same hardened logic as handle_cancel(), but:
     * - no nonce
     * - no redirects
     * - returns bool success
     */
    public static function cancel_signup_internal(int $signup_id): bool
    {
        if ($signup_id <= 0) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'adoration_signups';

        $has_is_active  = self::table_has_column($table, 'is_active');
        $has_updated_at = self::table_has_column($table, 'updated_at');

        // Load row (need person_id + slot_id for the unique-index cleanup)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, person_id, slot_id, status" . ($has_is_active ? ", is_active" : "") . "
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                $signup_id
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['id'])) {
            return false;
        }

        $person_id = (int)($row['person_id'] ?? 0);
        $slot_id   = (int)($row['slot_id'] ?? 0);

        $now = gmdate('Y-m-d H:i:s');

        /**
         * ✅ CRITICAL FIX
         * If a previous cancelled row already exists for (person_id, slot_id, is_active=0),
         * then updating this row to is_active=0 can violate uniq_person_slot_active.
         * So: delete any OTHER inactive rows for same person+slot before we set this one inactive.
         */
        if ($has_is_active && $person_id > 0 && $slot_id > 0) {
            try {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table}
                     WHERE person_id = %d
                       AND slot_id = %d
                       AND is_active = 0
                       AND id <> %d",
                    $person_id,
                    $slot_id,
                    $signup_id
                ));
            } catch (\Throwable $e) {
                // best-effort cleanup only
                error_log('[AdorationScheduler] Admin cancel cleanup failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            }
        }

        $data = [
            'status' => 'cancelled',
        ];
        $fmt = ['%s'];

        if ($has_is_active) {
            $data['is_active'] = 0;
            $fmt[] = '%d';
        }

        if ($has_updated_at) {
            $data['updated_at'] = $now;
            $fmt[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $updated = $wpdb->update(
            $table,
            $data,
            ['id' => $signup_id],
            $fmt,
            ['%d']
        );

        if ($updated === false) {
            error_log('[AdorationScheduler] Admin cancel failed signup_id=' . $signup_id . ' err=' . $wpdb->last_error);
            return false;
        }

        // A confirmed seat was just freed — offer it to whoever's been
        // waiting longest for this slot (best-effort; never blocks cancel).
        if ($slot_id > 0
            && class_exists('\\AdorationScheduler\\Services\\WaitlistService')
            && method_exists('\\AdorationScheduler\\Services\\WaitlistService', 'promote_next_for_slot')) {
            try {
                \AdorationScheduler\Services\WaitlistService::promote_next_for_slot($slot_id);
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] Admin cancel waitlist promotion failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            }
        }

        // best-effort: unschedule reminder
        try {
            if (class_exists('\\AdorationScheduler\\Services\\ReminderScheduler')
                && method_exists('\\AdorationScheduler\\Services\\ReminderScheduler', 'unschedule_for_signup')) {
                \AdorationScheduler\Services\ReminderScheduler::unschedule_for_signup($signup_id);
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Admin cancel unschedule failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
        }

        return true;
    }

    /**
     * Delete a signup using the same hardened logic as handle_delete(), but:
     * - no nonce
     * - no redirects
     * - returns bool success
     */
    public static function delete_signup_internal(int $signup_id): bool
    {
        if ($signup_id <= 0) return false;

        // best-effort: unschedule reminder before delete
        try {
            if (class_exists('\\AdorationScheduler\\Services\\ReminderScheduler')
                && method_exists('\\AdorationScheduler\\Services\\ReminderScheduler', 'unschedule_for_signup')) {
                \AdorationScheduler\Services\ReminderScheduler::unschedule_for_signup($signup_id);
            }
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Admin delete unschedule failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
        }

        global $wpdb;
        $table = $wpdb->prefix . 'adoration_signups';

        // Capture slot_id + status BEFORE deleting so we know whether this
        // removal actually freed a confirmed seat worth offering to the
        // waitlist (deleting an already-cancelled row frees nothing new).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT slot_id, status FROM {$table} WHERE id = %d LIMIT 1", $signup_id),
            ARRAY_A
        );
        $slot_id     = is_array($row) ? (int)($row['slot_id'] ?? 0) : 0;
        $was_confirmed = is_array($row) && (string)($row['status'] ?? '') === 'confirmed';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $deleted = $wpdb->delete($table, ['id' => $signup_id], ['%d']);

        if ($deleted === false) {
            error_log('[AdorationScheduler] Admin delete failed signup_id=' . $signup_id . ' err=' . $wpdb->last_error);
            return false;
        }

        if ($was_confirmed && $slot_id > 0
            && class_exists('\\AdorationScheduler\\Services\\WaitlistService')
            && method_exists('\\AdorationScheduler\\Services\\WaitlistService', 'promote_next_for_slot')) {
            try {
                \AdorationScheduler\Services\WaitlistService::promote_next_for_slot($slot_id);
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] Admin delete waitlist promotion failed signup_id=' . $signup_id . ' err=' . $e->getMessage());
            }
        }

        return true;
    }

    // ----------------- helpers -----------------

    private static function require_admin_cap(string $capability): void
    {
        if (!is_admin()) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
        }

        // Capability: allow granular cap if present; otherwise fall back to manage_options.
        $capability = sanitize_key($capability);
        $allowed = false;

        if ($capability !== '' && current_user_can($capability)) {
            $allowed = true;
        } elseif (current_user_can('manage_options')) {
            $allowed = true;
        }

        if (!$allowed) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.'), 403);
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            wp_safe_redirect(admin_url('admin.php'));
            exit;
        }
    }

    private static function rate_limit_ok(string $action, int $signup_id): bool
    {
        $action = sanitize_key($action);
        if ($action === '') $action = 'action';

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            // In wp-admin, this should never happen; but if it does, deny.
            return false;
        }

        $key = 'as_rl_admin_' . md5($action . '|' . $signup_id . '|' . $user_id);

        $data = get_transient($key);
        if (!is_array($data)) {
            $data = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $now   = time();
        $start = (int)($data['start'] ?? $now);
        $count = (int)($data['count'] ?? 0);

        if (($now - $start) >= self::RL_WINDOW_SECONDS) {
            $start = $now;
            $count = 0;
        }

        $count++;
        $data['count'] = $count;
        $data['start'] = $start;

        $ttl = max(5, self::RL_WINDOW_SECONDS - ($now - $start)) + 5;
        set_transient($key, $data, $ttl);

        return ($count <= self::RL_MAX_ATTEMPTS);
    }

    private static function get_return_url(): string
    {
        $return = isset($_POST['return']) ? (string) wp_unslash($_POST['return']) : '';
        $return = $return ? self::safe_redirect_url($return) : '';

        if (!$return) $return = wp_get_referer();
        if (!$return) $return = admin_url('admin.php');

        return $return;
    }

    private static function add_toast(string $url, string $type, string $text, bool $sticky = false): string
    {
        $url = remove_query_arg(['as_toast','as_toast_type','as_toast_sticky'], $url);

        $type = sanitize_key($type);
        $allowed = ['success','error','warning','info'];
        if (!in_array($type, $allowed, true)) $type = 'info';

        $text = sanitize_text_field($text);
        $text = trim($text);
        if ($text === '') $text = ($type === 'error') ? 'Action failed.' : 'Done.';
        if (strlen($text) > 300) $text = substr($text, 0, 300);

        return add_query_arg([
            'as_toast'        => rawurlencode($text),
            'as_toast_type'   => $type,
            'as_toast_sticky' => $sticky ? '1' : '0',
        ], $url);
    }

    private static function safe_redirect_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';

        if (strpos($url, '/') === 0) {
            return home_url($url);
        }

        $safe = wp_validate_redirect($url, '');
        if (!$safe) return '';

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $safe_host = wp_parse_url($safe, PHP_URL_HOST);

        if ($home_host && $safe_host && strtolower((string)$home_host) !== strtolower((string)$safe_host)) {
            return '';
        }

        return $safe;
    }

    private static function table_has_column(string $table, string $column): bool
    {
        global $wpdb;

        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($column === '') return false;

        try {
            $like = $wpdb->esc_like($column);
            $sql  = "SHOW COLUMNS FROM `{$table}` LIKE %s";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_var($wpdb->prepare($sql, $like));
            return !empty($found);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
