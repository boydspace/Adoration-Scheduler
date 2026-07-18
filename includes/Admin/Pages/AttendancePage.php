<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;

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
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        if ( ! isset($_POST['adoration_set_attendance']) ) {
            return;
        }

        check_admin_referer('adoration_set_attendance');

        $signup_id = isset($_POST['signup_id']) ? (int)$_POST['signup_id'] : 0;
        $present   = isset($_POST['present']) ? sanitize_text_field((string)$_POST['present']) : '';

        if ($signup_id > 0 && ($present === '1' || $present === '0')) {
            $repo = new SignupsRepository();
            $repo->set_attendance_admin($signup_id, $present === '1');
        }

        [$schedule_id, $from, $to] = self::resolve_filters();

        wp_safe_redirect(add_query_arg([
            'page'        => self::PAGE_SLUG,
            'schedule_id' => $schedule_id,
            'from'        => $from,
            'to'          => $to,
            'adoration_notice' => 'attendance_saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        if (class_exists('\\AdorationScheduler\\Admin\\Menu')) {
            \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_attendance');
        }

        [$schedule_id, $from, $to] = self::resolve_filters();

        $schedules_repo = new SchedulesRepository();
        $schedules = $schedules_repo->list_all(200, false);

        $signups_repo = new SignupsRepository();
        $rows = $signups_repo->list_for_attendance($from, $to, $schedule_id, 500);

        $nonce = wp_create_nonce('adoration_set_attendance');
        $now_ts = strtotime(current_time('mysql'));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Attendance', 'adoration-scheduler'); ?></h1>
            <hr class="wp-header-end" />

            <p class="description">
                <?php esc_html_e('Who actually checked in for a confirmed slot, and a way to mark present/absent by hand for slots without a self check-in.', 'adoration-scheduler'); ?>
            </p>

            <?php if (!empty($_GET['adoration_notice']) && $_GET['adoration_notice'] === 'attendance_saved'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Attendance updated.', 'adoration-scheduler'); ?></p></div>
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
                            <option value="<?php echo $sid; ?>" <?php selected($schedule_id, $sid); ?>>
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
                            <th><?php esc_html_e('Checked In', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Checked Out', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Method', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
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

                            $checked_in_at  = (string)($r['checked_in_at'] ?? '');
                            $checked_out_at = (string)($r['checked_out_at'] ?? '');
                            $method         = (string)($r['check_in_method'] ?? '');

                            $is_present = $checked_in_at !== '';

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
                                <td><?php echo esc_html($checked_in_at !== '' ? $checked_in_at : '—'); ?></td>
                                <td><?php echo esc_html($checked_out_at !== '' ? $checked_out_at : '—'); ?></td>
                                <td><?php echo esc_html($method !== '' ? $method : '—'); ?></td>
                                <td>
                                    <?php if ($is_present): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" style="display:inline-block;">
                                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="adoration_set_attendance" value="1">
                                            <input type="hidden" name="signup_id" value="<?php echo $signup_id; ?>">
                                            <input type="hidden" name="present" value="0">
                                            <button type="submit" class="button button-small"><?php esc_html_e('Mark Absent', 'adoration-scheduler'); ?></button>
                                        </form>
                                    <?php elseif ($slot_started): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" style="display:inline-block;">
                                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="adoration_set_attendance" value="1">
                                            <input type="hidden" name="signup_id" value="<?php echo $signup_id; ?>">
                                            <input type="hidden" name="present" value="1">
                                            <button type="submit" class="button button-small"><?php esc_html_e('Mark Present', 'adoration-scheduler'); ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#646970;"><?php esc_html_e('Not started yet', 'adoration-scheduler'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Same shape as CoverageReportPage::resolve_filters() — defaults to the
     * last 7 days through today, since attendance review is mostly about
     * "did anyone miss their hour recently," not a long historical window.
     */
    private static function resolve_filters(): array {
        $schedule_id = (int)($_GET['schedule_id'] ?? ($_POST['schedule_id'] ?? 0));

        $today = current_time('Y-m-d');
        $default_from = date('Y-m-d', strtotime($today . ' -7 days'));

        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

        if ($from === '' && isset($_POST['from'])) $from = sanitize_text_field(wp_unslash($_POST['from']));
        if ($to === '' && isset($_POST['to']))     $to   = sanitize_text_field(wp_unslash($_POST['to']));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
        if ($to < $from) $to = $from;

        return [$schedule_id, $from, $to];
    }
}
