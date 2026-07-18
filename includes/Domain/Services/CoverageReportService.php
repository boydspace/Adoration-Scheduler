<?php
namespace AdorationScheduler\Domain\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;

/**
 * CoverageReportService
 *
 * CSV export for the two tables on CoverageReportPage — "Hours Served by
 * Person" and "Fill Rate by Month" — using the exact same filters (schedule,
 * date range) the on-screen report was rendered with, read straight from
 * $_GET the same way the page itself does. Mirrors ScheduleExportService's
 * streaming pattern.
 */
class CoverageReportService
{
    public const ACTION_EXPORT_HOURS_CSV    = 'adoration_coverage_export_hours_csv';
    public const ACTION_EXPORT_FILLRATE_CSV = 'adoration_coverage_export_fillrate_csv';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION_EXPORT_HOURS_CSV,    [__CLASS__, 'handle_export_hours_csv']);
        add_action('admin_post_' . self::ACTION_EXPORT_FILLRATE_CSV, [__CLASS__, 'handle_export_fillrate_csv']);
    }

    private static function require_capability(): void
    {
        if (!current_user_can('adoration_manage_settings') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }
    }

    /**
     * Shared GET-param parsing — same defaults/clamping as
     * CoverageReportPage::resolve_filters(), duplicated rather than shared
     * because these are two separate admin-post/page classes and the
     * logic is a handful of lines; see that method if the two ever drift.
     */
    private static function resolve_filters(): array
    {
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

    public static function handle_export_hours_csv(): void
    {
        self::require_capability();
        check_admin_referer('adoration_coverage_report');

        [$schedule_id, $from, $to] = self::resolve_filters();

        $repo = new SignupsRepository();
        $rows = $repo->hours_report_by_person($schedule_id, $from, $to);

        $filename = 'adoration-hours-' . $from . '-to-' . $to . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Name', 'Email', 'Signups', 'Total Hours']);
        foreach ($rows as $r) {
            $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
            $hours = round(((int)($r['total_minutes'] ?? 0)) / 60, 1);
            fputcsv($out, [$name, (string)($r['email'] ?? ''), (int)($r['signup_count'] ?? 0), $hours]);
        }
        fclose($out);
        exit;
    }

    public static function handle_export_fillrate_csv(): void
    {
        self::require_capability();
        check_admin_referer('adoration_coverage_report');

        [$schedule_id, $from, $to] = self::resolve_filters();

        $repo = new SlotsRepository();
        $by_month = $repo->fill_rate_by_month($schedule_id, $from, $to);

        $filename = 'adoration-fill-rate-' . $from . '-to-' . $to . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Month', 'Total Slots', 'Filled Slots', 'Fill %', 'Total Capacity', 'Total Confirmed', 'Capacity Fill %']);
        foreach ($by_month as $ym => $m) {
            $total_slots  = (int)($m['total_slots'] ?? 0);
            $filled_slots = (int)($m['filled_slots'] ?? 0);
            $capacity     = (int)($m['total_capacity'] ?? 0);
            $confirmed    = (int)($m['total_confirmed'] ?? 0);

            $fill_pct     = $total_slots > 0 ? round(($filled_slots / $total_slots) * 100, 1) : 0;
            $capacity_pct = $capacity > 0 ? round(($confirmed / $capacity) * 100, 1) : 0;

            fputcsv($out, [$ym, $total_slots, $filled_slots, $fill_pct, $capacity, $confirmed, $capacity_pct]);
        }
        fclose($out);
        exit;
    }
}
