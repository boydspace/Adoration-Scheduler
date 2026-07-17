<?php
/**
 * Tab: Monthly Occurrence (monthly schedules)
 *
 * Expected variables in scope:
 * - $monthly_templates (array) list of date_patterns rows with day_of_week + week_of_month set
 * - $segments_by_monthly_template (array) keyed by date_pattern_id => segments array
 * - $schedule (array) (for is_overnight flag + rolling_window_days + default_slot_length)
 */

if ( ! defined('ABSPATH') ) exit;

$current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$post_url = add_query_arg([
    'page'        => $current_page_slug,
    'action'      => 'edit',
    'schedule_id' => $schedule_id,
    'tab'         => 'monthly_occurrence',
], admin_url('admin.php'));

$is_overnight = !empty($schedule['is_overnight']);
$default_slot_length = (int)($schedule['default_slot_length'] ?? 60);
if ($default_slot_length <= 0) $default_slot_length = 60;

$day_labels = [
    0 => __('Sunday', 'adoration-scheduler'),
    1 => __('Monday', 'adoration-scheduler'),
    2 => __('Tuesday', 'adoration-scheduler'),
    3 => __('Wednesday', 'adoration-scheduler'),
    4 => __('Thursday', 'adoration-scheduler'),
    5 => __('Friday', 'adoration-scheduler'),
    6 => __('Saturday', 'adoration-scheduler'),
];

$week_of_month_labels = [
    1 => __('1st', 'adoration-scheduler'),
    2 => __('2nd', 'adoration-scheduler'),
    3 => __('3rd', 'adoration-scheduler'),
    4 => __('4th', 'adoration-scheduler'),
    5 => __('5th', 'adoration-scheduler'),
    6 => __('Last', 'adoration-scheduler'),
];

$format_time_ampm = function(string $time): string {
    $time = trim($time);
    if ($time === '') return '—';
    if (strlen($time) === 5) $time .= ':00';
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) return $time;
    return date_i18n('g:i A', $ts);
};

$pattern_label = function(int $week_of_month, int $day_of_week) use ($week_of_month_labels, $day_labels): string {
    $w = $week_of_month_labels[$week_of_month] ?? ('#' . $week_of_month);
    $d = $day_labels[$day_of_week] ?? ('Day ' . $day_of_week);
    return $w . ' ' . $d;
};
?>

<h2><?php esc_html_e('Monthly Occurrence', 'adoration-scheduler'); ?></h2>

<p class="description">
    <?php esc_html_e('Set which weekday-of-month this schedule runs on (e.g. the 1st Friday, or the last Sunday) and its hours. This repeats every month indefinitely — a daily background job keeps future occurrences generated automatically based on the rolling window set on the Basic Info tab. Unlike Perpetual schedules, each month\'s occurrence is its own one-time signup — nobody is automatically re-enrolled every month.', 'adoration-scheduler'); ?>
</p>

<?php if ($is_overnight): ?>
    <div class="notice notice-info" style="margin: 12px 0; max-width:720px;">
        <p style="margin: 8px 0;">
            <?php esc_html_e('Overnight is enabled for this schedule. If an End time is earlier than the Start time, it will be treated as ending the next day.', 'adoration-scheduler'); ?>
        </p>
    </div>
<?php endif; ?>

<h3><?php esc_html_e('Add a Pattern', 'adoration-scheduler'); ?></h3>
<p class="description">
    <?php esc_html_e('You can add more than one pattern to the same schedule (e.g. both "1st Friday" and "3rd Sunday").', 'adoration-scheduler'); ?>
</p>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-bottom: 16px;">
    <?php wp_nonce_field('adoration_add_monthly_pattern'); ?>
    <label>
        <?php esc_html_e('Occurrence:', 'adoration-scheduler'); ?>
        <select name="week_of_month" required>
            <option value=""><?php esc_html_e('— choose —', 'adoration-scheduler'); ?></option>
            <?php foreach ($week_of_month_labels as $wom => $label): ?>
                <option value="<?php echo esc_attr((string)$wom); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="margin-left:10px;">
        <?php esc_html_e('Day of week:', 'adoration-scheduler'); ?>
        <select name="day_of_week" required>
            <option value=""><?php esc_html_e('— choose —', 'adoration-scheduler'); ?></option>
            <?php foreach ($day_labels as $dow => $label): ?>
                <option value="<?php echo esc_attr((string)$dow); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" name="adoration_add_monthly_pattern" class="button button-secondary">
        <?php esc_html_e('Add Pattern', 'adoration-scheduler'); ?>
    </button>
</form>

<?php if (empty($monthly_templates)): ?>
    <p><?php esc_html_e('No patterns configured yet. Add one above (e.g. "1st" + "Friday" for First Friday devotions).', 'adoration-scheduler'); ?></p>
<?php else: ?>
    <?php
    // Display in week-of-month then weekday order (already ordered by the repo query).
    $sorted = $monthly_templates;
    ?>
    <?php foreach ($sorted as $mt): ?>
        <?php
        $date_pattern_id = (int)($mt['id'] ?? 0);
        $wom = (int)($mt['week_of_month'] ?? 0);
        $dow = (int)($mt['day_of_week'] ?? 0);
        $segments = $segments_by_monthly_template[$date_pattern_id] ?? [];
        $label = $pattern_label($wom, $dow);
        ?>
        <div style="padding:12px; border:1px solid #ccd0d4; background:#fff; margin-bottom:12px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                <h3 style="margin:0;"><?php echo esc_html($label); ?></h3>

                <?php if (!empty($segments)): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin:0;">
                        <?php wp_nonce_field('adoration_clear_segments'); ?>
                        <input type="hidden" name="date_pattern_id" value="<?php echo (int)$date_pattern_id; ?>">
                        <button
                            type="submit"
                            name="adoration_clear_segments"
                            class="button button-link-delete"
                            onclick="return confirm('<?php echo esc_js(__('Clear ALL hours for this pattern? This cannot be undone.', 'adoration-scheduler')); ?>');"
                        >
                            <?php esc_html_e('Clear Hours', 'adoration-scheduler'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <h4 style="margin-top:12px;"><?php esc_html_e('Hours', 'adoration-scheduler'); ?></h4>

            <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-bottom:10px;">
                <?php wp_nonce_field('adoration_add_segment'); ?>
                <input type="hidden" name="date_pattern_id" value="<?php echo (int)$date_pattern_id; ?>">

                <label>
                    <?php esc_html_e('Start:', 'adoration-scheduler'); ?>
                    <input type="time" name="start_time" required>
                </label>

                <label>
                    <?php esc_html_e('End:', 'adoration-scheduler'); ?>
                    <input type="time" name="end_time" required>
                </label>

                <label>
                    <?php esc_html_e('Slot length (min, optional):', 'adoration-scheduler'); ?>
                    <input type="number" name="slot_length" min="5" step="5" style="width:90px;">
                </label>

                <button type="submit" name="adoration_add_segment" class="button">
                    <?php esc_html_e('Add Hours', 'adoration-scheduler'); ?>
                </button>

                <p class="description" style="margin:8px 0 0;">
                    <?php if ($is_overnight): ?>
                        <?php esc_html_e('Tip: If End is earlier than (or equal to) Start, it’s treated as ending the next day.', 'adoration-scheduler'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Tip: End must be after Start, or enable Overnight on the Basic Info tab.', 'adoration-scheduler'); ?>
                    <?php endif; ?>
                </p>
            </form>

            <?php if (empty($segments)): ?>
                <p><em><?php esc_html_e('No hours yet.', 'adoration-scheduler'); ?></em></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Start', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('End', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Slot Length', 'adoration-scheduler'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($segments as $seg): ?>
                            <?php $seg_id = (int)($seg['id'] ?? 0); ?>
                            <tr>
                                <td><?php echo esc_html($format_time_ampm((string)($seg['start_time'] ?? ''))); ?></td>
                                <td><?php echo esc_html($format_time_ampm((string)($seg['end_time'] ?? ''))); ?></td>
                                <td><?php echo esc_html(($seg['slot_length'] ?? '') !== '' ? (string)$seg['slot_length'] : 'default'); ?></td>
                                <td>
                                    <?php if ($seg_id > 0): ?>
                                        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline; margin:0;">
                                            <?php wp_nonce_field('adoration_delete_segment'); ?>
                                            <input type="hidden" name="segment_id" value="<?php echo (int)$seg_id; ?>">
                                            <input type="hidden" name="date_pattern_id" value="<?php echo (int)$date_pattern_id; ?>">
                                            <button
                                                type="submit"
                                                name="adoration_delete_segment"
                                                class="button button-link-delete"
                                                onclick="return confirm('<?php echo esc_js(__('Delete these hours? This cannot be undone.', 'adoration-scheduler')); ?>');"
                                            >
                                                <?php esc_html_e('Delete', 'adoration-scheduler'); ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<hr>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top:16px;">
    <?php wp_nonce_field('adoration_sync_monthly_now'); ?>
    <button type="submit" name="adoration_sync_monthly_now" class="button button-primary">
        <?php esc_html_e('Sync Slots Now', 'adoration-scheduler'); ?>
    </button>
    <p class="description">
        <?php esc_html_e('Materializes the rolling window of dated occurrences immediately from the patterns above, instead of waiting for the nightly background job. Safe to run any time — it only ever adds missing occurrences, never removes existing ones.', 'adoration-scheduler'); ?>
    </p>
</form>
