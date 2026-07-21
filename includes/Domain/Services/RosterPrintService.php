<?php
namespace AdorationScheduler\Domain\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Utils\ClergyTitles;

/**
 * RosterPrintService
 *
 * Staff-only "Print Roster" view: a clean, chapel-binder-friendly page for
 * a schedule over a date range, showing who's confirmed for each hour with
 * a phone number to call if someone's a no-show. Deliberately plain HTML
 * with a print stylesheet rather than a generated PDF — the plugin avoids
 * pulling in third-party libraries (see XlsxWriter/IcsBuilder for the same
 * reasoning), and a PDF format hand-rolled from scratch would be a lot of
 * work for something the browser's own print-to-PDF already covers.
 */
class RosterPrintService
{
    public const ACTION = 'adoration_print_roster';

    private const CAP_MANAGE_SIGNUPS = 'adoration_manage_signups';

    /**
     * Widest allowed range, to keep the query and the printed page sane.
     */
    private const MAX_RANGE_DAYS = 180;

    public static function register(): void
    {
        // Staff-only: no admin_post_nopriv_ hook registered on purpose.
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        if (!is_admin()) {
            wp_die(esc_html__('Invalid context.', 'adoration-scheduler'), 400);
        }

        $allowed = current_user_can(self::CAP_MANAGE_SIGNUPS) || current_user_can('manage_options');
        if (!$allowed) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }

        $schedule_id = (int)($_GET['schedule_id'] ?? 0);
        if ($schedule_id <= 0) {
            wp_die(esc_html__('Missing schedule.', 'adoration-scheduler'), 400);
        }

        check_admin_referer(self::ACTION . '_' . $schedule_id);

        $schedules_repo = new SchedulesRepository();
        $schedule = $schedules_repo->find($schedule_id);
        if (!$schedule) {
            wp_die(esc_html__('Schedule not found.', 'adoration-scheduler'), 404);
        }

        [$from_ymd, $to_ymd] = self::resolve_range();

        $slots_repo = new SlotsRepository();
        $slots = $slots_repo->list_for_roster($schedule_id, $from_ymd, $to_ymd);

        $signups_by_slot = [];
        if (!empty($slots)) {
            $signups_repo = new SignupsRepository();
            $all_confirmed = $signups_repo->list_for_schedule($schedule_id, true);
            foreach ($all_confirmed as $su) {
                $sid = (int)($su['slot_id'] ?? 0);
                if ($sid <= 0) continue;
                if (!isset($signups_by_slot[$sid])) $signups_by_slot[$sid] = [];
                $signups_by_slot[$sid][] = $su;
            }
        }

        self::render($schedule, $slots, $signups_by_slot, $from_ymd, $to_ymd);
        exit;
    }

    /**
     * From/to GET params, sanitized and clamped to a sane window. Defaults
     * to today through +30 days when not supplied or malformed.
     */
    private static function resolve_range(): array
    {
        $today = current_time('Y-m-d');
        $default_to = date('Y-m-d', strtotime($today . ' +30 days'));

        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $default_to;

        if ($to < $from) {
            $to = $from;
        }

        $from_ts = strtotime($from);
        $to_ts   = strtotime($to);
        if ($from_ts !== false && $to_ts !== false) {
            $days = (int) round(($to_ts - $from_ts) / DAY_IN_SECONDS);
            if ($days > self::MAX_RANGE_DAYS) {
                $to = date('Y-m-d', strtotime($from . ' +' . self::MAX_RANGE_DAYS . ' days'));
            }
        }

        return [$from, $to];
    }

    private static function fmt_date_ymd(string $ymd): string
    {
        $ts = strtotime($ymd);
        return ($ts !== false) ? date_i18n('l, F j, Y', $ts) : $ymd;
    }

    private static function fmt_time(string $time): string
    {
        $time = trim($time);
        if ($time === '') return '';
        if (strlen($time) === 5) $time .= ':00';
        $ts = strtotime('1970-01-01 ' . $time);
        return ($ts === false) ? $time : date_i18n('g:i A', $ts);
    }

    private static function render(array $schedule, array $slots, array $signups_by_slot, string $from_ymd, string $to_ymd): void
    {
        $schedule_name = trim((string)($schedule['name'] ?? $schedule['title'] ?? 'Adoration'));
        $range_label = self::fmt_date_ymd($from_ymd) . ' – ' . self::fmt_date_ymd($to_ymd);
        $church_name = get_bloginfo('name');

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>' . esc_html($schedule_name . ' Roster — ' . $range_label) . '</title>';
        ?>
        <style>
            body { font-family: -apple-system, Segoe UI, Arial, sans-serif; color: #1d2327; margin: 24px; }
            h1 { font-size: 20px; margin: 0 0 2px; }
            .as-roster-meta { color: #646970; margin: 0 0 18px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 24px; page-break-inside: avoid; }
            th, td { border: 1px solid #c3c4c7; padding: 6px 8px; text-align: left; font-size: 13px; vertical-align: top; }
            th { background: #f0f0f1; }
            h2 { font-size: 15px; margin: 22px 0 6px; border-bottom: 2px solid #1d2327; padding-bottom: 3px; }
            .as-empty { color: #646970; font-style: italic; }
            .as-toolbar { margin-bottom: 18px; }
            .as-toolbar button { font-size: 14px; padding: 6px 14px; cursor: pointer; }
            @media print {
                .as-toolbar { display: none; }
                body { margin: 0.4in; }
            }
        </style>
        </head><body>

        <div class="as-toolbar">
            <button type="button" onclick="window.print();"><?php esc_html_e('Print', 'adoration-scheduler'); ?></button>
        </div>

        <h1><?php echo esc_html($schedule_name); ?></h1>
        <p class="as-roster-meta">
            <?php echo esc_html($range_label); ?>
            <?php if ($church_name !== ''): ?> &middot; <?php echo esc_html($church_name); ?><?php endif; ?>
        </p>

        <?php if (empty($slots)): ?>
            <p class="as-empty"><?php esc_html_e('No active hours in this date range.', 'adoration-scheduler'); ?></p>
        <?php else: ?>
            <?php
            $current_date = null;
            foreach ($slots as $slot):
                $slot_id = (int)($slot['id'] ?? 0);
                $slot_date = (string)($slot['date'] ?? '');

                if ($slot_date !== $current_date) {
                    if ($current_date !== null) echo '</table>';
                    echo '<h2>' . esc_html(self::fmt_date_ymd($slot_date)) . '</h2>';
                    echo '<table><thead><tr>'
                        . '<th style="width:110px;">' . esc_html__('Time', 'adoration-scheduler') . '</th>'
                        . '<th style="width:130px;">' . esc_html__('Chapel', 'adoration-scheduler') . '</th>'
                        . '<th>' . esc_html__('Confirmed Adorers', 'adoration-scheduler') . '</th>'
                        . '</tr></thead><tbody>';
                    $current_date = $slot_date;
                }

                $time_label = self::fmt_time((string)($slot['start_time'] ?? ''));
                $end_label  = self::fmt_time((string)($slot['end_time'] ?? ''));
                if ($end_label !== '') $time_label .= '–' . $end_label;

                $chapel = trim((string)($slot['chapel_name'] ?? ''));

                $people = $signups_by_slot[$slot_id] ?? [];
                ?>
                <tr>
                    <td><?php echo esc_html($time_label !== '' ? $time_label : '—'); ?></td>
                    <td><?php echo esc_html($chapel !== '' ? $chapel : '—'); ?></td>
                    <td>
                        <?php if (empty($people)): ?>
                            <span class="as-empty"><?php esc_html_e('No one signed up', 'adoration-scheduler'); ?></span>
                        <?php else: ?>
                            <?php foreach ($people as $p): ?>
                                <?php
                                $name  = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
                                $p_title = ClergyTitles::abbreviate((string)($p['title'] ?? ''));
                                if ($p_title !== '' && $name !== '') $name = $p_title . ' ' . $name;
                                $phone = trim((string)($p['phone'] ?? ''));
                                ?>
                                <div><?php echo esc_html($name !== '' ? $name : '—'); ?><?php if ($phone !== ''): ?> — <?php echo esc_html($phone); ?><?php endif; ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            endforeach;
            echo '</table>';
            ?>
        <?php endif; ?>

        </body></html>
        <?php
    }
}
