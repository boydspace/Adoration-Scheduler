<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Services\PerpetualSlotGenerator;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Utils\CapacityBadge;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode: [adoration_open_hours slug="my-schedule" days="30" layout="list|weekly"]
 *
 * A public, read-only "advertise the hours" board — status only (Open / X of
 * Y filled / Filled), and no adorer names, ever. Deliberately does NOT check
 * AccessGateService (the site's optional approval gate): that gate controls
 * who can sign up or see the personal My Adoration portal, not whether the
 * general public can see that Adoration is happening. No signup controls of
 * any kind — signing up happens on a separate page/shortcode entirely. No
 * calendar-subscribe link either, by design — that's a separate concern
 * ([adoration_calendar_subscribe], personal) from just showing the hours.
 *
 * Two layouts:
 * - "list" (default): every upcoming dated slot in the next N days, one row
 *   each. Works for any schedule type.
 * - "weekly": the recurring weekly pattern (one row per time, one column per
 *   day of week) instead of individual dates — only meaningful for a
 *   perpetual (round-the-clock) schedule, since that's the only type with a
 *   repeating weekly template rather than one-off dates. Falls back to
 *   "list" if the target schedule isn't perpetual.
 *
 * Contrast with [adoration_schedule], which is the full signup UI and can
 * optionally show name pills depending on the schedule's privacy_mode —
 * this shortcode ignores privacy_mode entirely and never shows names,
 * because it's meant to be safe to drop on a fully public page.
 */
class OpenHoursShortcode
{
    public static function register(): void
    {
        add_shortcode('adoration_open_hours', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'slug'   => '',
            'days'   => '30',
            'layout' => 'list',
        ], (array)$atts, 'adoration_open_hours');

        $slug = sanitize_title((string)$atts['slug']);
        if ($slug === '') {
            return '<div class="adoration-open-hours"><em>' . esc_html__('Missing schedule slug.', 'adoration-scheduler') . '</em></div>';
        }

        $days = (int)$atts['days'];
        if ($days < 1)   $days = 30;
        if ($days > 180) $days = 180;

        $layout = strtolower(trim((string)$atts['layout']));

        $schedules_repo = new SchedulesRepository();
        $schedule = $schedules_repo->find_by_slug($slug);

        if (!$schedule) {
            return '<div class="adoration-open-hours"><em>' . esc_html__('Schedule not found.', 'adoration-scheduler') . '</em></div>';
        }
        if ((string)($schedule['status'] ?? 'draft') !== 'active') {
            return '<div class="adoration-open-hours"><em>' . esc_html__('This schedule is not currently active.', 'adoration-scheduler') . '</em></div>';
        }

        $schedule_id   = (int)($schedule['id'] ?? 0);
        $schedule_name = trim((string)($schedule['name'] ?? ''));
        $is_perpetual  = ((string)($schedule['type'] ?? 'event') === 'perpetual');

        // "weekly" only makes sense for a perpetual schedule (repeating
        // weekly template, not individual dates) — silently use the list
        // layout otherwise rather than erroring on a simple attribute typo.
        $use_weekly = ($layout === 'weekly' && $is_perpetual);

        $date_format = (string) get_option('date_format');
        $time_format = (string) get_option('time_format');

        ob_start();
        ?>
        <div class="adoration-widget adoration-open-hours uk-width-1-1">
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php if ($schedule_name !== ''): ?>
                <h3 class="uk-margin-remove-top"><?php echo esc_html($schedule_name); ?></h3>
            <?php endif; ?>

            <?php if ($use_weekly): ?>
                <?php echo self::render_weekly_layout($schedule); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else: ?>
                <?php echo self::render_list_layout($schedule_id, $days, $date_format, $time_format); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Original per-date board: every upcoming dated slot in the next $days,
     * status only, no names.
     */
    private static function render_list_layout(int $schedule_id, int $days, string $date_format, string $time_format): string
    {
        $slots_repo = new SlotsRepository();
        $rows = $slots_repo->list_upcoming_with_status($schedule_id, $days);

        ob_start();
        ?>
        <?php if (empty($rows)): ?>
            <p class="uk-margin-remove-top"><?php esc_html_e('No upcoming hours are scheduled right now.', 'adoration-scheduler'); ?></p>
        <?php else: ?>
            <div class="uk-overflow-auto">
                <table class="uk-table uk-table-divider uk-table-small adoration-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Date', 'adoration-scheduler'); ?></th>
                            <th scope="col"><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                            <th scope="col"><?php esc_html_e('Chapel', 'adoration-scheduler'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $date_raw = (string)($row['date'] ?? '');
                        $ts = $date_raw ? strtotime($date_raw) : false;
                        $date_lbl = $ts ? date_i18n($date_format, $ts) : $date_raw;

                        $start_raw = (string)($row['start_time'] ?? '');
                        $end_raw   = (string)($row['end_time'] ?? '');
                        $start_ts  = $start_raw ? strtotime('1970-01-01 ' . $start_raw) : false;
                        $end_ts    = $end_raw ? strtotime('1970-01-01 ' . $end_raw) : false;
                        $time_lbl  = ($start_ts && $end_ts)
                            ? date_i18n($time_format, $start_ts) . '–' . date_i18n($time_format, $end_ts)
                            : trim($start_raw . '–' . $end_raw, '–');

                        $chapel_name = (string)($row['chapel_name'] ?? '—');

                        $max   = isset($row['max_adorers']) ? $row['max_adorers'] : null;
                        $count = (int)($row['confirmed_count'] ?? 0);
                        // list_upcoming_with_status() already computes is_full
                        // (it may factor in things this helper can't, e.g.
                        // closures), so prefer it when present.
                        $is_full = array_key_exists('is_full', $row)
                            ? !empty($row['is_full'])
                            : null;

                        ?>
                        <tr>
                            <td><?php echo esc_html($date_lbl); ?></td>
                            <td><?php echo esc_html($time_lbl); ?></td>
                            <td><?php echo esc_html($chapel_name); ?></td>
                            <td><?php echo CapacityBadge::html($count, $max, $is_full); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Recurring weekly pattern board for a perpetual schedule: one row per
     * distinct start time, one column per day of week, status only — the
     * same underlying data as [adoration_schedule]'s weekly grid
     * (PerpetualSlotGenerator::describe_weekly_pattern() +
     * StandingCommitmentsRepository::list_for_schedule()), but this never
     * pulls names (only counts) and never renders a Take/Cover button.
     */
    private static function render_weekly_layout(array $schedule): string
    {
        $schedule_id = (int)($schedule['id'] ?? 0);

        $dateRepo        = new DatePatternsRepository();
        $segmentsRepo    = new SegmentsRepository();
        $slotsRepo       = new SlotsRepository();
        $commitmentsRepo = new StandingCommitmentsRepository();
        $signupsRepo     = new SignupsRepository();
        $perpGenerator   = new PerpetualSlotGenerator($dateRepo, $segmentsRepo, $slotsRepo, $commitmentsRepo, $signupsRepo);

        $weekly_pattern = $perpGenerator->describe_weekly_pattern($schedule);

        ob_start();

        if (empty($weekly_pattern)) {
            ?>
            <p class="uk-margin-remove-top"><?php esc_html_e('No adoration times available.', 'adoration-scheduler'); ?></p>
            <?php
            return (string) ob_get_clean();
        }

        $default_max = ($schedule['default_max_adorers'] ?? '') !== '' && $schedule['default_max_adorers'] !== null
            ? (int)$schedule['default_max_adorers']
            : null;

        // Union of distinct start_times across all days, sorted chronologically.
        $seen_times = [];
        foreach ($weekly_pattern as $opts) {
            foreach ($opts as $opt) {
                $st = (string)($opt['start_time'] ?? '');
                if ($st === '' || isset($seen_times[$st])) continue;
                $seen_times[$st] = true;
            }
        }
        $row_times = array_keys($seen_times);
        sort($row_times);

        // Counts only — deliberately never collecting names here, unlike
        // ScheduleShortcode's equivalent grid.
        $commit_counts = [];
        foreach ($commitmentsRepo->list_for_schedule($schedule_id, true) as $row) {
            $dow = (int)($row['day_of_week'] ?? -1);
            $st  = substr((string)($row['start_time'] ?? ''), 0, 8);
            if ($dow < 0 || $st === '') continue;

            $key = $dow . '|' . $st;
            $commit_counts[$key] = ($commit_counts[$key] ?? 0) + 1;
        }

        $day_of_week_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $time_format = (string) get_option('time_format');
        ?>
        <div class="uk-overflow-auto">
            <table class="uk-table uk-table-divider uk-table-small adoration-table adoration-weekly-open-hours-table">
                <thead>
                    <tr>
                        <th scope="col" style="width:90px;"><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                        <?php foreach ($day_of_week_labels as $lbl): ?>
                            <th scope="col"><?php echo esc_html($lbl); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($row_times as $st): ?>
                        <?php
                        $row_ts = strtotime('1970-01-01 ' . $st);
                        $row_label = $row_ts !== false ? date_i18n($time_format, $row_ts) : $st;
                        ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($row_label); ?></th>
                            <?php for ($dow = 0; $dow <= 6; $dow++): ?>
                                <?php
                                $opt = null;
                                foreach (($weekly_pattern[$dow] ?? []) as $candidate) {
                                    if ((string)($candidate['start_time'] ?? '') === $st) { $opt = $candidate; break; }
                                }
                                ?>
                                <?php if ($opt === null): ?>
                                    <td>—</td>
                                <?php else: ?>
                                    <?php
                                    $key   = $dow . '|' . $st;
                                    $count = (int)($commit_counts[$key] ?? 0);
                                    ?>
                                    <td><?php echo CapacityBadge::html($count, $default_max); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // Status badge computation/rendering moved to
    // AdorationScheduler\Utils\CapacityBadge (2026-07-20) so ScheduleShortcode
    // can share the exact same coverage-status color scheme instead of
    // drifting into its own.
}
