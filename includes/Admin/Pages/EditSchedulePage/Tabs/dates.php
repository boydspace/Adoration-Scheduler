<?php
/**
 * Tab: Dates & Hours
 *
 * Expected variables in scope:
 * - $dates (array) list of date patterns for this schedule
 * - $segments_by_date (array) keyed by date_pattern_id => segments array
 * - $schedule (array) (for is_overnight flag)
 */

if ( ! defined('ABSPATH') ) exit;

// Build an explicit post target so admin.php keeps the query args we need.
$current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$post_url = add_query_arg([
    'page'        => $current_page_slug,
    'action'      => 'edit',
    'schedule_id' => $schedule_id,
    'tab'         => 'dates',
], admin_url('admin.php'));

// Overnight flag (merged into $schedule by controller in Step 1)
$is_overnight = !empty($schedule['is_overnight']);

// Local formatter (closure) to avoid global function redeclare fatals.
$format_time_ampm = function(string $time): string {
    $time = trim($time);
    if ($time === '') return '—';

    // Normalize HH:MM or HH:MM:SS
    if (strlen($time) === 5) {
        $time .= ':00';
    }

    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) return $time;

    // g:i A => 12-hour with AM/PM, localized through WP
    return date_i18n('g:i A', $ts);
};
?>

<h2><?php esc_html_e('Dates & Hours', 'adoration-scheduler'); ?></h2>

<?php if ($is_overnight): ?>
    <div class="notice notice-info" style="margin: 12px 0;">
        <p style="margin: 8px 0;">
            <?php esc_html_e('Overnight is enabled for this schedule.', 'adoration-scheduler'); ?>
            <?php esc_html_e('If an End time is earlier than the Start time, it will be treated as ending the next day.', 'adoration-scheduler'); ?>
        </p>
        <p style="margin: 8px 0;">
            <strong><?php esc_html_e('Example:', 'adoration-scheduler'); ?></strong>
            <?php esc_html_e('Friday 8:00 AM → Saturday 8:00 AM (enter Start 08:00, End 08:00).', 'adoration-scheduler'); ?>
        </p>
    </div>
<?php else: ?>
    <div class="notice notice-warning" style="margin: 12px 0;">
        <p style="margin: 8px 0;">
            <?php esc_html_e('Overnight is disabled for this schedule.', 'adoration-scheduler'); ?>
            <?php esc_html_e('End time must be after Start time. If End is earlier than Start, that segment will not generate slots.', 'adoration-scheduler'); ?>
        </p>
        <p style="margin: 8px 0;">
            <?php esc_html_e('If you need a schedule like “Friday 8:00 AM → Saturday 8:00 AM”, enable Overnight on the Basic Info tab.', 'adoration-scheduler'); ?>
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-bottom: 16px;">
    <?php wp_nonce_field('adoration_add_date'); ?>
    <label>
        <?php esc_html_e('Add date:', 'adoration-scheduler'); ?>
        <input type="date" name="date" required>
    </label>
    <button type="submit" name="adoration_add_date" class="button button-secondary">
        <?php esc_html_e('Add Date', 'adoration-scheduler'); ?>
    </button>
</form>

<?php if (empty($dates)): ?>
    <p><?php esc_html_e('No dates yet. Add the event dates above.', 'adoration-scheduler'); ?></p>
<?php else: ?>
    <?php foreach ($dates as $d): ?>
        <?php
        $date_pattern_id = (int)($d['id'] ?? 0);
        $date_value      = (string)($d['date'] ?? '');
        $segments        = $segments_by_date[$date_pattern_id] ?? [];
        ?>
        <div style="padding:12px; border:1px solid #ccd0d4; background:#fff; margin-bottom:12px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                <h3 style="margin:0;">
                    <?php echo esc_html( $date_value ? date_i18n('l, F j, Y', strtotime($date_value)) : '—' ); ?>
                </h3>

                <?php if (!empty($segments)): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin:0;">
                        <?php wp_nonce_field('adoration_clear_segments'); ?>
                        <input type="hidden" name="date_pattern_id" value="<?php echo (int)$date_pattern_id; ?>">
                        <button
                            type="submit"
                            name="adoration_clear_segments"
                            class="button button-link-delete"
                            onclick="return confirm('<?php echo esc_js(__('Clear ALL segments for this date? This cannot be undone.', 'adoration-scheduler')); ?>');"
                        >
                            <?php esc_html_e('Clear Segments', 'adoration-scheduler'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <h4 style="margin-top:12px;"><?php esc_html_e('Segments', 'adoration-scheduler'); ?></h4>

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
                    <?php esc_html_e('Add Segment', 'adoration-scheduler'); ?>
                </button>

                <p class="description" style="margin:8px 0 0;">
                    <?php if ($is_overnight): ?>
                        <?php esc_html_e('Tip: If End is earlier than Start, it will be treated as ending the next day (overnight). Example: 8:00 PM → 8:00 AM.', 'adoration-scheduler'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Tip: End must be after Start. If you need an overnight segment, enable Overnight on the Basic Info tab.', 'adoration-scheduler'); ?>
                    <?php endif; ?>
                </p>
            </form>

            <?php if (empty($segments)): ?>
                <p><em><?php esc_html_e('No segments yet.', 'adoration-scheduler'); ?></em></p>
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
                                <td><?php echo esc_html( $format_time_ampm((string)($seg['start_time'] ?? '')) ); ?></td>
                                <td><?php echo esc_html( $format_time_ampm((string)($seg['end_time'] ?? '')) ); ?></td>
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
                                                onclick="return confirm('<?php echo esc_js(__('Delete this segment? This cannot be undone.', 'adoration-scheduler')); ?>');"
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
