<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Services\CoverageReportService;

if ( ! defined('ABSPATH') ) exit;

/**
 * Coverage Report — hours served per person and month-by-month fill rate,
 * for stewardship recognition and year-end reports. Read-only (plus CSV
 * export); doesn't change any plugin behavior, unlike the daily Coverage
 * Alerts digest which is about upcoming gaps rather than historical data.
 */
class CoverageReportPage {

    private const CAP_MANAGE_SETTINGS = 'adoration_manage_settings';

    public function render(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        if (class_exists('\\AdorationScheduler\\Admin\\Menu')) {
            \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_coverage_report');
        }

        [$schedule_id, $from, $to] = self::resolve_filters();

        $schedules_repo = new SchedulesRepository();
        $schedules = $schedules_repo->list_all(200, false);

        $signups_repo = new SignupsRepository();
        $hours_rows = $signups_repo->hours_report_by_person($schedule_id, $from, $to);

        $slots_repo = new SlotsRepository();
        $by_month = $slots_repo->fill_rate_by_month($schedule_id, $from, $to);

        $nonce = wp_create_nonce('adoration_coverage_report');
        $page_url = admin_url('admin.php?page=adoration_scheduler_coverage_report');

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Coverage Report', 'adoration-scheduler'); ?></h1>
            <hr class="wp-header-end" />

            <p class="description">
                <?php esc_html_e('Hours served per person and how full each month has been, for stewardship recognition or a year-end report.', 'adoration-scheduler'); ?>
            </p>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
                  style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin: 16px 0; padding:12px 14px; background:#f6f7f7; border:1px solid #dcdcde;">
                <input type="hidden" name="page" value="adoration_scheduler_coverage_report">

                <div>
                    <label style="display:block; font-size:12px; color:#646970;" for="as_cr_schedule"><?php esc_html_e('Schedule', 'adoration-scheduler'); ?></label>
                    <select id="as_cr_schedule" name="schedule_id">
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
                    <label style="display:block; font-size:12px; color:#646970;" for="as_cr_from"><?php esc_html_e('From', 'adoration-scheduler'); ?></label>
                    <input type="date" id="as_cr_from" name="from" value="<?php echo esc_attr($from); ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#646970;" for="as_cr_to"><?php esc_html_e('To', 'adoration-scheduler'); ?></label>
                    <input type="date" id="as_cr_to" name="to" value="<?php echo esc_attr($to); ?>">
                </div>

                <button type="submit" class="button button-primary"><?php esc_html_e('Update Report', 'adoration-scheduler'); ?></button>
            </form>

            <h2><?php esc_html_e('Hours Served by Person', 'adoration-scheduler'); ?></h2>

            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin: 0 0 10px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(CoverageReportService::ACTION_EXPORT_HOURS_CSV); ?>">
                <input type="hidden" name="schedule_id" value="<?php echo (int)$schedule_id; ?>">
                <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>">
                <input type="hidden" name="to" value="<?php echo esc_attr($to); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="button"><?php esc_html_e('Export CSV', 'adoration-scheduler'); ?></button>
            </form>

            <?php if (empty($hours_rows)): ?>
                <p><em><?php esc_html_e('No confirmed signups in this range.', 'adoration-scheduler'); ?></em></p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Email', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Signups', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Total Hours', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hours_rows as $r): ?>
                            <?php
                            $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                            $hours = round(((int)($r['total_minutes'] ?? 0)) / 60, 1);
                            ?>
                            <tr>
                                <td><?php echo esc_html($name !== '' ? $name : '—'); ?></td>
                                <td><?php echo esc_html((string)($r['email'] ?? '')); ?></td>
                                <td><?php echo (int)($r['signup_count'] ?? 0); ?></td>
                                <td><?php echo esc_html((string)$hours); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:32px;"><?php esc_html_e('Fill Rate by Month', 'adoration-scheduler'); ?></h2>

            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin: 0 0 10px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(CoverageReportService::ACTION_EXPORT_FILLRATE_CSV); ?>">
                <input type="hidden" name="schedule_id" value="<?php echo (int)$schedule_id; ?>">
                <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>">
                <input type="hidden" name="to" value="<?php echo esc_attr($to); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="button"><?php esc_html_e('Export CSV', 'adoration-scheduler'); ?></button>
            </form>

            <?php if (empty($by_month)): ?>
                <p><em><?php esc_html_e('No active hours in this range.', 'adoration-scheduler'); ?></em></p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Month', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Total Slots', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Filled Slots', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Fill %', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Capacity Fill %', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_month as $ym => $m): ?>
                            <?php
                            $total_slots  = (int)($m['total_slots'] ?? 0);
                            $filled_slots = (int)($m['filled_slots'] ?? 0);
                            $capacity     = (int)($m['total_capacity'] ?? 0);
                            $confirmed    = (int)($m['total_confirmed'] ?? 0);

                            $fill_pct     = $total_slots > 0 ? round(($filled_slots / $total_slots) * 100, 1) : 0;
                            $capacity_pct = $capacity > 0 ? round(($confirmed / $capacity) * 100, 1) : 0;

                            $month_label = date_i18n('F Y', strtotime($ym . '-01'));
                            ?>
                            <tr>
                                <td><?php echo esc_html($month_label); ?></td>
                                <td><?php echo $total_slots; ?></td>
                                <td><?php echo $filled_slots; ?></td>
                                <td><?php echo esc_html((string)$fill_pct); ?>%</td>
                                <td><?php echo esc_html((string)$capacity_pct); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    <?php esc_html_e('"Fill %" counts a slot as filled if at least one person signed up. "Capacity Fill %" weighs it by how many spots each slot actually had.', 'adoration-scheduler'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Same defaults/clamping as CoverageReportService::resolve_filters();
     * see that method's docblock for why this is duplicated rather than
     * shared.
     */
    private static function resolve_filters(): array {
        $schedule_id = (int)($_GET['schedule_id'] ?? 0);

        $today = current_time('Y-m-d');
        $default_from = date('Y-m-01', strtotime($today . ' -11 months'));

        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
        if ($to < $from) $to = $from;

        return [$schedule_id, $from, $to];
    }
}
