<?php
namespace AdorationScheduler\Domain\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Utils\XlsxWriter;

/**
 * CSV/XLSX export of the Schedules list (export-only — unlike People,
 * schedules are structured/generated configs, not flat records someone
 * would hand-edit in a spreadsheet and re-upload, so there's no import
 * side here). Mirrors PeopleImportExportService's export half exactly:
 * same admin_post/nonce/streaming pattern, same XlsxWriter reuse.
 */
class ScheduleExportService
{
    public const ACTION_EXPORT_CSV  = 'adoration_schedules_export_csv';
    public const ACTION_EXPORT_XLSX = 'adoration_schedules_export_xlsx';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION_EXPORT_CSV,  [__CLASS__, 'handle_export_csv']);
        add_action('admin_post_' . self::ACTION_EXPORT_XLSX, [__CLASS__, 'handle_export_xlsx']);
    }

    private static function require_capability(): void
    {
        if (!current_user_can('adoration_manage_schedules') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }
    }

    private static function export_rows(): array
    {
        $repo = new SchedulesRepository();
        $schedules = $repo->export_rows();

        $header = [
            'Name', 'Slug', 'Type', 'Chapel', 'Status',
            'Start Date', 'End Date', 'Overnight',
            'Default Slot Length (min)', 'Default Min Adorers', 'Default Max Adorers',
            'Privacy Mode', 'Rolling Window (days)', 'Created At',
        ];
        $rows = [$header];

        foreach ($schedules as $s) {
            $rows[] = [
                (string)($s['name'] ?? ''),
                (string)($s['slug'] ?? ''),
                (string)($s['type'] ?? ''),
                (string)($s['chapel_name'] ?? ''),
                (string)($s['status'] ?? ''),
                (string)($s['start_date'] ?? ''),
                (string)($s['end_date'] ?? ''),
                !empty($s['is_overnight']) ? 'Yes' : 'No',
                (string)($s['default_slot_length'] ?? ''),
                (string)($s['default_min_adorers'] ?? ''),
                (string)($s['default_max_adorers'] ?? ''),
                (string)($s['privacy_mode'] ?? ''),
                (string)($s['rolling_window_days'] ?? ''),
                (string)($s['created_at'] ?? ''),
            ];
        }

        return $rows;
    }

    public static function handle_export_csv(): void
    {
        self::require_capability();
        check_admin_referer('adoration_schedules_export');

        $rows = self::export_rows();
        $filename = 'adoration-schedules-' . date('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public static function handle_export_xlsx(): void
    {
        self::require_capability();
        check_admin_referer('adoration_schedules_export');

        if (!XlsxWriter::is_available()) {
            wp_safe_redirect(add_query_arg([
                'page'          => 'adoration_scheduler_schedules',
                'as_toast'      => rawurlencode('XLSX export needs the PHP zip extension, which isn\'t available on this server. Try CSV export instead.'),
                'as_toast_type' => 'error',
            ], admin_url('admin.php')));
            exit;
        }

        $rows = self::export_rows();
        $writer = new XlsxWriter();
        foreach ($rows as $row) {
            $writer->add_row($row);
        }

        $filename = 'adoration-schedules-' . date('Y-m-d-His') . '.xlsx';
        $writer->output($filename);
    }
}
