<?php
/**
 * Tab: Weekly Hours (perpetual schedules)
 *
 * Expected variables in scope:
 * - $weekday_templates (array) list of date_patterns rows with day_of_week set
 * - $segments_by_weekday_template (array) keyed by date_pattern_id => segments array
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
    'tab'         => 'weekly_hours',
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

$day_short_keys = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

$used_days = [];
foreach ($weekday_templates as $wt) {
    $used_days[(int)($wt['day_of_week'] ?? -1)] = true;
}

$format_time_ampm = function(string $time): string {
    $time = trim($time);
    if ($time === '') return '—';
    if (strlen($time) === 5) $time .= ':00';
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) return $time;
    return date_i18n('g:i A', $ts);
};
?>

<h2><?php esc_html_e('Weekly Hours', 'adoration-scheduler'); ?></h2>

<p class="description">
    <?php esc_html_e('Set the hours adoration runs on each day of the week. This repeats every week indefinitely — a daily background job keeps future dates generated automatically based on the rolling window set on the Basic Info tab.', 'adoration-scheduler'); ?>
</p>

<div style="background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:16px; max-width:720px; margin-bottom:24px;">
    <h3 style="margin-top:0;"><?php esc_html_e('Quick Setup', 'adoration-scheduler'); ?></h3>
    <p class="description">
        <?php esc_html_e('For a 24/7 chapel, the defaults below already do it: every day, all 24 hours. Just click Apply.', 'adoration-scheduler'); ?>
    </p>

    <form method="post" action="<?php echo esc_url($post_url); ?>">
        <?php wp_nonce_field('adoration_quick_setup_weekly_hours'); ?>

        <p><strong><?php esc_html_e('Days', 'adoration-scheduler'); ?></strong>
            <a href="#" id="qs_select_all" style="margin-left:8px;"><?php esc_html_e('Select all', 'adoration-scheduler'); ?></a>
            · <a href="#" id="qs_select_none"><?php esc_html_e('Select none', 'adoration-scheduler'); ?></a>
        </p>
        <p>
            <?php foreach ($day_labels as $dow => $label): ?>
                <label style="display:inline-block; margin-right:14px;">
                    <input type="checkbox" class="qs_day_checkbox" name="qs_day_<?php echo esc_attr($day_short_keys[$dow]); ?>" value="1" checked>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </p>

        <p>
            <label>
                <input type="checkbox" name="qs_full_day" id="qs_full_day" value="1" checked>
                <strong><?php esc_html_e('Open 24 hours (12:00 AM – 12:00 AM)', 'adoration-scheduler'); ?></strong>
            </label>
        </p>

        <div id="qs_custom_hours" style="display:none; margin: 8px 0;">
            <label>
                <?php esc_html_e('Start:', 'adoration-scheduler'); ?>
                <input type="time" name="qs_start_time" id="qs_start_time">
            </label>
            <label style="margin-left:12px;">
                <?php esc_html_e('End:', 'adoration-scheduler'); ?>
                <input type="time" name="qs_end_time" id="qs_end_time">
            </label>
        </div>

        <p>
            <label>
                <?php esc_html_e('Slot length (minutes):', 'adoration-scheduler'); ?>
                <input type="number" name="qs_slot_length" min="5" step="5" style="width:90px;" value="<?php echo esc_attr((string)$default_slot_length); ?>">
            </label>
        </p>

        <p>
            <button type="submit" name="adoration_quick_setup_weekly_hours" class="button button-primary">
                <?php esc_html_e('Apply to Selected Days', 'adoration-scheduler'); ?>
            </button>
        </p>

        <p class="description">
            <?php esc_html_e('This replaces the hours for each selected day (it won’t stack duplicates if you run it again), and turns on Overnight automatically if needed. It never changes slots that were already generated — only new ones going forward.', 'adoration-scheduler'); ?>
        </p>
    </form>
</div>

<script>
(function(){
    var fullDay = document.getElementById('qs_full_day');
    var customWrap = document.getElementById('qs_custom_hours');
    var startInput = document.getElementById('qs_start_time');
    var endInput = document.getElementById('qs_end_time');

    function toggleCustom(){
        var show = fullDay && !fullDay.checked;
        if (customWrap) customWrap.style.display = show ? '' : 'none';
        if (startInput) startInput.required = !!show;
        if (endInput) endInput.required = !!show;
    }

    if (fullDay) {
        fullDay.addEventListener('change', toggleCustom);
        toggleCustom();
    }

    var selectAll = document.getElementById('qs_select_all');
    var selectNone = document.getElementById('qs_select_none');
    var checkboxes = document.querySelectorAll('.qs_day_checkbox');

    if (selectAll) {
        selectAll.addEventListener('click', function(e){
            e.preventDefault();
            checkboxes.forEach(function(cb){ cb.checked = true; });
        });
    }
    if (selectNone) {
        selectNone.addEventListener('click', function(e){
            e.preventDefault();
            checkboxes.forEach(function(cb){ cb.checked = false; });
        });
    }
})();
</script>

<?php if ($is_overnight): ?>
    <div class="notice notice-info" style="margin: 12px 0; max-width:720px;">
        <p style="margin: 8px 0;">
            <?php esc_html_e('Overnight is enabled for this schedule. If an End time is earlier than the Start time, it will be treated as ending the next day.', 'adoration-scheduler'); ?>
        </p>
    </div>
<?php endif; ?>

<hr>

<h3><?php esc_html_e('Customize a Single Day', 'adoration-scheduler'); ?></h3>
<p class="description">
    <?php esc_html_e('Use this if a specific day needs different hours than the rest of the week (e.g. shorter hours on Sundays), or to add more than one time block to a day.', 'adoration-scheduler'); ?>
</p>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-bottom: 16px;">
    <?php wp_nonce_field('adoration_add_weekday'); ?>
    <label>
        <?php esc_html_e('Add day of week:', 'adoration-scheduler'); ?>
        <select name="day_of_week" required>
            <option value=""><?php esc_html_e('— choose —', 'adoration-scheduler'); ?></option>
            <?php foreach ($day_labels as $dow => $label): ?>
                <?php if (isset($used_days[$dow])) continue; ?>
                <option value="<?php echo esc_attr((string)$dow); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" name="adoration_add_weekday" class="button button-secondary">
        <?php esc_html_e('Add Day', 'adoration-scheduler'); ?>
    </button>
</form>

<?php if (empty($weekday_templates)): ?>
    <p><?php esc_html_e('No days configured yet. Use Quick Setup above, or add a single day here.', 'adoration-scheduler'); ?></p>
<?php else: ?>
    <?php
    // Display in Sunday..Saturday order regardless of insertion order.
    $sorted = $weekday_templates;
    usort($sorted, function($a, $b) {
        return (int)($a['day_of_week'] ?? 0) <=> (int)($b['day_of_week'] ?? 0);
    });
    ?>
    <?php foreach ($sorted as $wt): ?>
        <?php
        $date_pattern_id = (int)($wt['id'] ?? 0);
        $dow = (int)($wt['day_of_week'] ?? 0);
        $segments = $segments_by_weekday_template[$date_pattern_id] ?? [];
        ?>
        <div style="padding:12px; border:1px solid #ccd0d4; background:#fff; margin-bottom:12px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                <h3 style="margin:0;"><?php echo esc_html($day_labels[$dow] ?? ('Day ' . $dow)); ?></h3>

                <?php if (!empty($segments)): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin:0;">
                        <?php wp_nonce_field('adoration_clear_segments'); ?>
                        <input type="hidden" name="date_pattern_id" value="<?php echo (int)$date_pattern_id; ?>">
                        <button
                            type="submit"
                            name="adoration_clear_segments"
                            class="button button-link-delete"
                            onclick="return confirm('<?php echo esc_js(__('Clear ALL hours for this day? This cannot be undone.', 'adoration-scheduler')); ?>');"
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
                        <?php esc_html_e('Tip: End must be after Start, or enable Overnight on the Basic Info tab (needed for a full 24-hour day, where Start and End are both 00:00).', 'adoration-scheduler'); ?>
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
    <?php wp_nonce_field('adoration_sync_perpetual_now'); ?>
    <input type="hidden" name="return_tab" value="weekly_hours">
    <button type="submit" name="adoration_sync_perpetual_now" class="button button-primary">
        <?php esc_html_e('Sync Slots Now', 'adoration-scheduler'); ?>
    </button>
    <p class="description">
        <?php esc_html_e('Materializes the rolling window of dated slots immediately from the hours above, instead of waiting for the nightly background job. Safe to run any time — it only ever adds missing slots, never removes existing ones.', 'adoration-scheduler'); ?>
    </p>
</form>
