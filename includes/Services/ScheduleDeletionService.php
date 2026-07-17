<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;

class ScheduleDeletionService {

    public static function register(): void {
        add_action('admin_post_adoration_delete_schedule', [__CLASS__, 'handle_trash']);
        add_action('admin_post_adoration_restore_schedule', [__CLASS__, 'handle_restore']);
        add_action('admin_post_adoration_delete_schedule_permanently', [__CLASS__, 'handle_delete_permanently']);
    }

    /**
     * Move to Trash (soft delete) — ALWAYS allowed (even if signups exist).
     */
    public static function handle_trash(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403 );
        }

        $schedule_id = (int)($_REQUEST['schedule_id'] ?? 0);
        if ($schedule_id <= 0) {
            self::redirect_list_with_toast('Missing schedule id.', 'error');
        }

        check_admin_referer('adoration_trash_schedule_' . $schedule_id);

        $repo = new SchedulesRepository();
        $ok = method_exists($repo, 'soft_delete') ? (bool)$repo->soft_delete($schedule_id) : false;

        if ($ok) {
            self::redirect_list_with_toast('Schedule moved to Trash.', 'success');
        }

        self::redirect_list_with_toast('Could not move schedule to Trash.', 'error');
    }

    /**
     * Restore from Trash
     */
    public static function handle_restore(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403 );
        }

        $schedule_id = (int)($_REQUEST['schedule_id'] ?? 0);
        if ($schedule_id <= 0) {
            self::redirect_list_with_toast(
                'Missing schedule id.',
                'error',
                ['status' => 'trash']
            );
        }

        check_admin_referer('adoration_restore_schedule_' . $schedule_id);

        $repo = new SchedulesRepository();
        $ok = method_exists($repo, 'restore') ? (bool)$repo->restore($schedule_id, 'draft') : false;

        // stay on Trash view after restore action
        if ($ok) {
            self::redirect_list_with_toast(
                'Schedule restored.',
                'success',
                ['status' => 'trash']
            );
        }

        self::redirect_list_with_toast(
            'Could not restore schedule.',
            'error',
            ['status' => 'trash']
        );
    }

    /**
     * Delete Permanently (hard delete) — ONLY from Trash, like Posts.
     *
     * ✅ Allowed even if signups exist.
     * ✅ Also deletes dependent rows to prevent orphan data.
     */
    public static function handle_delete_permanently(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403 );
        }

        $schedule_id = (int)($_REQUEST['schedule_id'] ?? 0);
        if ($schedule_id <= 0) {
            self::redirect_list_with_toast(
                'Missing schedule id.',
                'error',
                ['status' => 'trash']
            );
        }

        check_admin_referer('adoration_delete_schedule_permanently_' . $schedule_id);

        $repo = new SchedulesRepository();

        // Posts-like rule: only allow hard delete if it is currently trashed
        $row = method_exists($repo, 'find_by_id') ? $repo->find_by_id($schedule_id, true) : null;
        $status = strtolower((string)($row['status'] ?? ''));

        if (!$row || !in_array($status, ['trash','trashed'], true)) {
            self::redirect_list_with_toast(
                'Permanent delete is only allowed from Trash.',
                'warning'
            );
        }

        // ✅ Hard-delete dependents first (so nothing orphaned remains)
        $deleted_children = self::delete_dependents_for_schedule($schedule_id);

        // ✅ Then delete the schedule row (repo enforces “must be trashed”)
        $ok = method_exists($repo, 'delete_permanently') ? (bool)$repo->delete_permanently($schedule_id) : false;

        if ($ok) {
            $msg = 'Schedule deleted permanently.';
            if ($deleted_children > 0) {
                $msg .= ' (Also removed ' . (int)$deleted_children . ' related record(s).)';
            }
            self::redirect_list_with_toast(
                $msg,
                'success',
                ['status' => 'trash']
            );
        }

        self::redirect_list_with_toast(
            'Could not delete schedule permanently.',
            'error',
            ['status' => 'trash']
        );
    }

    /**
     * Delete dependent rows for a schedule to prevent orphaned data.
     *
     * Returns total rows deleted across tables (best-effort).
     */
    private static function delete_dependents_for_schedule(int $schedule_id): int {
        global $wpdb;
        if ($schedule_id <= 0) return 0;

        $total = 0;

        // These table names match your repo naming style.
        $t_signups = $wpdb->prefix . 'adoration_signups';
        $t_slots   = $wpdb->prefix . 'adoration_slots';
        $t_dates   = $wpdb->prefix . 'adoration_date_patterns';
        $t_segs    = $wpdb->prefix . 'adoration_segments';

        // Delete signups first (FK-like relationship to slots/schedule)
        try {
            $n = $wpdb->delete($t_signups, ['schedule_id' => (int)$schedule_id], ['%d']);
            if ($n !== false) $total += (int)$n;
        } catch (\Throwable $e) {}

        // Delete slots
        try {
            $n = $wpdb->delete($t_slots, ['schedule_id' => (int)$schedule_id], ['%d']);
            if ($n !== false) $total += (int)$n;
        } catch (\Throwable $e) {}

        // Delete segments (hours) and date patterns (dates)
        try {
            $n = $wpdb->delete($t_segs, ['schedule_id' => (int)$schedule_id], ['%d']);
            if ($n !== false) $total += (int)$n;
        } catch (\Throwable $e) {}

        try {
            $n = $wpdb->delete($t_dates, ['schedule_id' => (int)$schedule_id], ['%d']);
            if ($n !== false) $total += (int)$n;
        } catch (\Throwable $e) {}

        return $total;
    }

    /**
     * Redirect back to list, preserving state, and include toast trigger params.
     */
    private static function redirect_list_with_toast(string $message, string $type = 'info', array $args = [], bool $sticky = false): void {
        $allowed = ['success','error','warning','info'];
        $type = sanitize_key($type);
        if (!in_array($type, $allowed, true)) $type = 'info';

        $args['as_toast'] = rawurlencode($message);
        $args['as_toast_type'] = $type;
        $args['as_toast_sticky'] = $sticky ? '1' : '0';

        self::redirect_list($args);
    }

    private static function redirect_list(array $args = []): void {
        // Preserve list screen state (status/search/sort/paging/date filters)
        $preserve = [
            'status','s','paged','orderby','order',
            'start_from','start_to','end_from','end_to'
        ];

        $keep = [];
        foreach ($preserve as $k) {
            if (!isset($_REQUEST[$k]) || $_REQUEST[$k] === '') continue;

            $v = wp_unslash($_REQUEST[$k]);

            if (in_array($k, ['status','orderby','order'], true)) {
                $v = sanitize_key($v);
            } elseif ($k === 'paged') {
                $v = (string)max(1, (int)$v);
            } else {
                $v = sanitize_text_field($v);
            }

            $keep[$k] = $v;
        }

        $url = add_query_arg(
            array_merge(['page' => 'adoration_scheduler_schedules'], $keep, $args),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
