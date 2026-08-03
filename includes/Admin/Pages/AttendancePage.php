<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\AttendanceRepository;
use AdorationScheduler\Domain\Repositories\SignupAuditRepository;
use AdorationScheduler\Utils\ClergyTitles;

if ( ! defined('ABSPATH') ) exit;

/**
 * Attendance — review who actually checked in for a confirmed signup, and
 * manually mark present/absent for slots where nobody used the self-report
 * link or kiosk (e.g. the chapel has no kiosk set up, or someone forgot to
 * tap in). Read/write, but doesn't touch signup status itself — attendance
 * is a separate fact layered on top of a confirmed signup, following the
 * same "don't delete, annotate" spirit as SignupsRepository's audit log.
 */
class AttendancePage {

    private const PAGE_SLUG = 'adoration_scheduler_attendance';
    private const CAP_MANAGE_SETTINGS = 'adoration_manage_settings';

    /**
     * Runs BEFORE output (wired via Menu::load_attendance_page()).
     * Safe place for the mark-present/mark-absent POST handler.
     */
    public function handle_request(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.', 'adoration-scheduler'), 403 );
        }

        if ( ! isset($_POST['adoration_set_attendance']) ) {
            return;
        }

        check_admin_referer('adoration_set_attendance');

        $signup_id = isset($_POST['signup_id']) ? absint(wp_unslash($_POST['signup_id'])) : 0;
        $status = isset($_POST['attendance_status']) ? sanitize_key(wp_unslash($_POST['attendance_status'])) : '';
        $notes = isset($_POST['attendance_notes']) ? sanitize_textarea_field(wp_unslash($_POST['attendance_notes'])) : '';
        $saved = false;

        if ($signup_id > 0 && in_array($status, ['present', 'absent', 'excused'], true)) {
            $saved = (new AttendanceRepository())->set_signup_outcome($signup_id, $status, $notes, get_current_user_id());
        }

        [$schedule_id, $from, $to] = self::resolve_filters();

        wp_safe_redirect(add_query_arg([
            'page'        => self::PAGE_SLUG,
            'schedule_id' => $schedule_id,
            'from'        => $from,
            'to'          => $to,
            'adoration_notice' => $saved ? 'attendance_saved' : 'attendance_failed',
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report filters and post-redirect notice.
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.', 'adoration-scheduler'), 403 );
        }

        if (class_exists('\\AdorationScheduler\\Admin\\Menu')) {
            \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_attendance');
        }

        [$schedule_id, $from, $to] = self::resolve_filters();

        $schedules_repo = new SchedulesRepository();
        $schedules = $schedules_repo->list_all(200, false);

        $signups_repo = new SignupsRepository();
        $rows = $signups_repo->list_for_attendance($from, $to, $schedule_id, 500);
        $histories = (new SignupAuditRepository())->get_attendance_events_for_signups(array_column($rows, 'id'));

        $nonce = wp_create_nonce('adoration_set_attendance');
        $now_ts = strtotime(current_time('mysql'));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Attendance', 'adoration-scheduler'); ?></h1>
            <hr class="wp-header-end" />

            <p class="description">
                <?php esc_html_e('Review check-ins, record present, absent, or excused outcomes, and retain a history of coordinator corrections.', 'adoration-scheduler'); ?>
            </p>

            <?php if (!empty($_GET['adoration_notice']) && $_GET['adoration_notice'] === 'attendance_saved'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Attendance updated.', 'adoration-scheduler'); ?></p></div>
            <?php elseif (!empty($_GET['adoration_notice']) && $_GET['adoration_notice'] === 'attendance_failed'): ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Attendance could not be updated. Please try again.', 'adoration-scheduler'); ?></p></div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
                  style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin: 16px 0; padding:12px 14px; background:#f6f7f7; border:1px solid #dcdcde;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">

                <div>
                    <label style="display:block; font-size:12px; color:#646970;" for="as_att_schedule"><?php esc_html_e('Schedule', 'adoration-scheduler'); ?></label>
                    <select id="as_att_schedule" name="schedule_id">
                        <option value="0" <?php selected($schedule_id, 0); ?>><?php esc_html_e('All Schedules', 'adoration-scheduler'); ?></option>
                        <?php foreach ($schedules as $s): ?>
                            <?php $sid = (int)($s['id'] ?? 0); ?>
                            <option value="<?php echo (int) $sid; ?>" <?php selected($schedule_id, $sid); ?>>
                                <?php echo esc_html((string)($s['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block; font-size:12px; color:#646970;" for="as_att_from"><?php esc_html_e('From', 'adoration-scheduler'); ?></label>
                    <input type="date" id="as_att_from" name="from" value="<?php echo esc_attr($from); ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#646970;" for="as_att_to"><?php esc_html_e('To', 'adoration-scheduler'); ?></label>
                    <input type="date" id="as_att_to" name="to" value="<?php echo esc_attr($to); ?>">
                </div>

                <button type="submit" class="button button-primary"><?php esc_html_e('Update', 'adoration-scheduler'); ?></button>
            </form>

            <?php if (empty($rows)): ?>
                <p><em><?php esc_html_e('No confirmed signups in this range.', 'adoration-scheduler'); ?></em></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Schedule / Chapel', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Person', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Attendance', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Check-in Details', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Coordinator Correction', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $signup_id  = (int)($r['id'] ?? 0);
                            $date       = (string)($r['date'] ?? '');
                            $start_time = (string)($r['start_time'] ?? '');
                            $end_time   = (string)($r['end_time'] ?? '');
                            $time_label = trim($start_time . ($end_time !== '' ? '–' . $end_time : ''));

                            $schedule_name = (string)($r['schedule_name'] ?? '');
                            $chapel_name   = (string)($r['chapel_name'] ?? '');
                            $where_label   = trim($schedule_name . ($chapel_name !== '' ? ' (' . $chapel_name . ')' : ''));

                            $name = trim((string)($r['person_first_name'] ?? '') . ' ' . (string)($r['person_last_name'] ?? ''));
                            $person_title = ClergyTitles::abbreviate((string)($r['person_title'] ?? ''));
                            if ($person_title !== '' && $name !== '') $name = $person_title . ' ' . $name;

                            $checked_in_at  = (string)($r['checked_in_at'] ?? '');
                            $checked_out_at = (string)($r['checked_out_at'] ?? '');
                            $method         = (string)($r['check_in_method'] ?? '');

                            $attendance_status = (string)($r['attendance_status'] ?? '');
                            if ($attendance_status === '') $attendance_status = $checked_in_at !== '' ? 'present' : 'unrecorded';
                            $attendance_notes = (string)($r['attendance_notes'] ?? '');
                            $history = (array)($histories[$signup_id] ?? []);

                            // Has this slot even started yet? Marking absent
                            // before it starts doesn't make sense.
                            $slot_start_ts = ($date !== '' && $start_time !== '')
                                ? strtotime($date . ' ' . $start_time)
                                : null;
                            $slot_started = ($slot_start_ts !== null && $now_ts >= $slot_start_ts);
                            ?>
                            <tr>
                                <td><?php echo esc_html($date); ?></td>
                                <td><?php echo esc_html($time_label); ?></td>
                                <td><?php echo esc_html($where_label !== '' ? $where_label : '—'); ?></td>
                                <td><?php echo esc_html($name !== '' ? $name : '—'); ?></td>
                                <td>
                                    <?php echo $this->status_badge($attendance_status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php if ($attendance_notes !== ''): ?><p class="description" style="margin:5px 0 0;"><?php echo esc_html($attendance_notes); ?></p><?php endif; ?>
                                    <?php $this->render_history($history); ?>
                                </td>
                                <td>
                                    <?php if ($checked_in_at !== ''): ?>
                                        <strong><?php echo esc_html($checked_in_at); ?></strong><br>
                                        <span class="description"><?php echo esc_html($method !== '' ? $method : __('Unknown method', 'adoration-scheduler')); ?></span>
                                        <?php if ($checked_out_at !== ''): ?><br><span class="description"><?php printf(esc_html__('Out: %s', 'adoration-scheduler'), esc_html($checked_out_at)); ?></span><?php endif; ?>
                                    <?php else: ?><span class="description">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($slot_started): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" style="min-width:220px;">
                                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="adoration_set_attendance" value="1">
                                            <input type="hidden" name="signup_id" value="<?php echo (int)$signup_id; ?>">
                                            <input type="hidden" name="schedule_id" value="<?php echo (int)$schedule_id; ?>">
                                            <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>">
                                            <input type="hidden" name="to" value="<?php echo esc_attr($to); ?>">
                                            <select name="attendance_status" aria-label="<?php esc_attr_e('Attendance outcome', 'adoration-scheduler'); ?>">
                                                <option value="present" <?php selected($attendance_status, 'present'); ?>><?php esc_html_e('Present', 'adoration-scheduler'); ?></option>
                                                <option value="absent" <?php selected($attendance_status, 'absent'); ?>><?php esc_html_e('Absent', 'adoration-scheduler'); ?></option>
                                                <option value="excused" <?php selected($attendance_status, 'excused'); ?>><?php esc_html_e('Excused', 'adoration-scheduler'); ?></option>
                                            </select>
                                            <textarea name="attendance_notes" rows="2" maxlength="1000" placeholder="<?php esc_attr_e('Optional coordinator note', 'adoration-scheduler'); ?>" style="display:block; width:100%; margin:5px 0;"><?php echo esc_textarea($attendance_notes); ?></textarea>
                                            <button type="submit" class="button button-small"><?php esc_html_e('Save Outcome', 'adoration-scheduler'); ?></button>
                                        </form>
                                    <?php else: ?><span class="description"><?php esc_html_e('Not started yet', 'adoration-scheduler'); ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    private function status_badge(string $status): string {
        $styles = [
            'present' => [__('Present', 'adoration-scheduler'), '#00a32a', '#edfaef'],
            'completed' => [__('Completed', 'adoration-scheduler'), '#2271b1', '#eef6fc'],
            'absent' => [__('Absent', 'adoration-scheduler'), '#d63638', '#fcf0f1'],
            'excused' => [__('Excused', 'adoration-scheduler'), '#996800', '#fff8e5'],
            'unrecorded' => [__('Unrecorded', 'adoration-scheduler'), '#646970', '#f0f0f1'],
        ];
        [$label, $color, $background] = $styles[$status] ?? $styles['unrecorded'];
        return '<span style="display:inline-block;border-radius:999px;padding:3px 9px;font-weight:600;color:' . esc_attr($color) . ';background:' . esc_attr($background) . ';">' . esc_html($label) . '</span>';
    }

    private function render_history(array $history): void {
        if (empty($history)) return;
        ?>
        <details style="margin-top:7px;">
            <summary class="description" style="cursor:pointer;"><?php
                printf(
                    /* translators: %d: number of recorded attendance corrections */
                    esc_html(_n('%d correction', '%d corrections', count($history), 'adoration-scheduler')),
                    count($history)
                );
            ?></summary>
            <ol style="margin:6px 0 0 18px; min-width:220px;">
                <?php foreach (array_slice($history, 0, 10) as $event): ?>
                    <?php $meta = (array)($event['meta'] ?? []); ?>
                    <li style="margin-bottom:6px;">
                        <strong><?php echo esc_html(ucfirst((string)($meta['to_status'] ?? __('Updated', 'adoration-scheduler')))); ?></strong>
                        <span class="description">— <?php echo esc_html((string)($event['actor_label'] ?? __('System', 'adoration-scheduler'))); ?>, <?php echo esc_html((string)($event['created_at'] ?? '')); ?></span>
                        <?php if (!empty($meta['notes'])): ?><br><span class="description"><?php echo esc_html((string)$meta['notes']); ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </details>
        <?php
    }

    /**
     * Same shape as CoverageReportPage::resolve_filters() — defaults to the
     * last 7 days through today, since attendance review is mostly about
     * "did anyone miss their hour recently," not a long historical window.
     */
    // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- These are display-filter reads only. Mutations are gated by check_admin_referer('adoration_set_attendance').
    private static function resolve_filters(): array {
        $schedule_id = absint(wp_unslash($_GET['schedule_id'] ?? ($_POST['schedule_id'] ?? 0)));

        $today = current_time('Y-m-d');
        $default_from = gmdate('Y-m-d', strtotime($today . ' -7 days'));

        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

        if ($from === '' && isset($_POST['from'])) $from = sanitize_text_field(wp_unslash($_POST['from']));
        if ($to === '' && isset($_POST['to']))     $to   = sanitize_text_field(wp_unslash($_POST['to']));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
        if ($to < $from) $to = $from;

        return [$schedule_id, $from, $to];
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
}
