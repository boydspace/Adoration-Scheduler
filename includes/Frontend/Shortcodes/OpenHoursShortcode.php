<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Services\CalendarFeedService;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode: [adoration_open_hours slug="my-schedule" days="30" show_subscribe="1"]
 *
 * A public, read-only "advertise the hours" board — every upcoming slot for
 * one schedule with its fill status (Open / X of Y filled / Filled), and no
 * adorer names, ever. Deliberately does NOT check AccessGateService (the
 * site's optional approval gate): that gate controls who can sign up or see
 * the personal My Adoration portal, not whether the general public can see
 * that Adoration is happening. Pairs with CalendarFeedService's public .ics
 * feed for the same schedule, whose subscribe link this shortcode surfaces.
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
            'slug'           => '',
            'days'           => '30',
            'show_subscribe' => '1',
        ], (array)$atts, 'adoration_open_hours');

        $slug = sanitize_title((string)$atts['slug']);
        if ($slug === '') {
            return '<div class="adoration-open-hours"><em>' . esc_html__('Missing schedule slug.', 'adoration-scheduler') . '</em></div>';
        }

        $days = (int)$atts['days'];
        if ($days < 1)   $days = 30;
        if ($days > 180) $days = 180;

        $show_subscribe = !in_array(strtolower((string)$atts['show_subscribe']), ['0', 'false', 'no', ''], true);

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

        $slots_repo = new SlotsRepository();
        $rows = $slots_repo->list_upcoming_with_status($schedule_id, $days);

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

            <?php if ($show_subscribe): ?>
                <p class="uk-text-meta as-muted">
                    <a href="<?php echo esc_url(CalendarFeedService::public_feed_url($slug, true)); ?>">
                        📅 <?php esc_html_e('Subscribe to this calendar', 'adoration-scheduler'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if (empty($rows)): ?>
                <p class="uk-margin-remove-top"><?php esc_html_e('No upcoming hours are scheduled right now.', 'adoration-scheduler'); ?></p>
            <?php else: ?>
                <div class="uk-overflow-auto">
                    <table class="uk-table uk-table-divider uk-table-small adoration-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Date', 'adoration-scheduler'); ?></th>
                                <th><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                                <th><?php esc_html_e('Chapel', 'adoration-scheduler'); ?></th>
                                <th><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
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

                            $is_full = !empty($row['is_full']);
                            $max     = isset($row['max_adorers']) ? $row['max_adorers'] : null;
                            $count   = (int)($row['confirmed_count'] ?? 0);

                            if ($is_full) {
                                $status_lbl = __('Filled', 'adoration-scheduler');
                                $status_color = '#d63638';
                            } elseif ($max !== null) {
                                $status_lbl = sprintf(
                                    /* translators: 1: confirmed count, 2: max spots */
                                    __('%1$d of %2$d filled', 'adoration-scheduler'),
                                    $count,
                                    (int)$max
                                );
                                $status_color = ($count > 0) ? '#dba617' : '#00a32a';
                            } else {
                                $status_lbl = ($count > 0)
                                    ? __('Open', 'adoration-scheduler')
                                    : __('Open — nobody signed up yet', 'adoration-scheduler');
                                $status_color = '#00a32a';
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html($date_lbl); ?></td>
                                <td><?php echo esc_html($time_lbl); ?></td>
                                <td><?php echo esc_html($chapel_name); ?></td>
                                <td>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr($status_color); ?>;">
                                        <?php echo esc_html($status_lbl); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
