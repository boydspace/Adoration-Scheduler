<?php
/**
 * Tab: Coverage Calendar (perpetual schedules)
 *
 * Expected variables in scope (from EditSchedulePage.php):
 * - $cal_year, $cal_month (int)
 * - $cal_date (string, 'Y-m-d' or '')
 * - $cal_weeks (array) of weeks, each an array of 7 day cells:
 *     ['ymd','day_num','in_month','slot_count','filled_count']
 * - $cal_day_slots (array) for the selected date: [ ['slot'=>row, 'signups'=>[rows]], ... ]
 * - $cal_prev_url, $cal_next_url (string)
 * - $schedule (array), $schedule_id (int)
 * - $closures_list (array) all closure rows for this schedule (id, start_at, end_at, reason)
 *   each $cal_weeks day cell also carries a 'closed_reason' (string|null)
 */

if ( ! defined('ABSPATH') ) exit;

$current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

$post_url = add_query_arg([
    'page'        => $current_page_slug,
    'action'      => 'edit',
    'schedule_id' => $schedule_id,
    'tab'         => 'coverage',
    'cal_year'    => $cal_year,
    'cal_month'   => $cal_month,
    'cal_date'    => $cal_date,
], admin_url('admin.php'));

$month_label = date_i18n('F Y', strtotime(sprintf('%04d-%02d-01', $cal_year, $cal_month)));

$format_time_ampm = function(string $time): string {
    $time = trim($time);
    if ($time === '') return '—';
    if (strlen($time) === 5) $time .= ':00';
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) return $time;
    return date_i18n('g:i A', $ts);
};

$day_of_week_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$today_ymd = current_time('Y-m-d');
?>

<h2><?php esc_html_e('Coverage Calendar', 'adoration-scheduler'); ?></h2>

<p class="description">
    <?php esc_html_e('Click any date to see who’s signed up for each hour that day. From there you can cancel just that date (the person’s standing weekly hour keeps going) or assign someone as a substitute for that one date.', 'adoration-scheduler'); ?>
</p>

<div style="display:flex; align-items:center; gap:12px; margin: 12px 0;">
    <a class="button" href="<?php echo esc_url($cal_prev_url); ?>">&larr; <?php esc_html_e('Prev', 'adoration-scheduler'); ?></a>
    <h3 style="margin:0;"><?php echo esc_html($month_label); ?></h3>
    <a class="button" href="<?php echo esc_url($cal_next_url); ?>"><?php esc_html_e('Next', 'adoration-scheduler'); ?> &rarr;</a>
</div>

<table class="widefat" style="max-width:900px; table-layout:fixed;">
    <thead>
        <tr>
            <?php foreach ($day_of_week_labels as $lbl): ?>
                <th style="text-align:center;"><?php echo esc_html($lbl); ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cal_weeks as $week): ?>
            <tr>
                <?php foreach ($week as $day): ?>
                    <?php
                    $is_today = ($day['ymd'] === $today_ymd);
                    $is_selected = ($day['ymd'] === $cal_date);
                    $has_slots = $day['slot_count'] > 0;

                    $cell_url = add_query_arg([
                        'page'        => $current_page_slug,
                        'action'      => 'edit',
                        'schedule_id' => $schedule_id,
                        'tab'         => 'coverage',
                        'cal_year'    => $cal_year,
                        'cal_month'   => $cal_month,
                        'cal_date'    => $day['ymd'],
                    ], admin_url('admin.php'));

                    $closed_reason = $day['closed_reason'] ?? null;

                    $bg = '#fff';
                    if (!$day['in_month']) $bg = '#f6f7f7';
                    elseif ($closed_reason) $bg = '#e2e3e4';
                    elseif ($is_selected) $bg = '#dceefb';
                    elseif ($has_slots && $day['filled_count'] < $day['slot_count']) $bg = '#fcf0f1';
                    elseif ($has_slots) $bg = '#edfaef';
                    ?>
                    <td style="vertical-align:top; padding:4px; background:<?php echo esc_attr($bg); ?>; <?php echo $is_today ? 'border:2px solid #2271b1;' : 'border:1px solid #e0e0e0;'; ?>" <?php echo $closed_reason ? 'title="' . esc_attr($closed_reason) . '"' : ''; ?>>
                        <a href="<?php echo esc_url($cell_url); ?>" style="display:block; text-decoration:none; color:inherit;">
                            <div style="font-weight:<?php echo $day['in_month'] ? '600' : '400'; ?>; color:<?php echo $day['in_month'] ? '#1d2327' : '#a7aaad'; ?>;">
                                <?php echo (int)$day['day_num']; ?>
                            </div>
                            <?php if ($closed_reason): ?>
                                <div style="font-size:11px; color:#646970; font-style:italic;">
                                    <?php esc_html_e('Closed', 'adoration-scheduler'); ?>
                                </div>
                            <?php elseif ($has_slots): ?>
                                <div style="font-size:11px; color:#50575e;">
                                    <?php echo (int)$day['filled_count']; ?>/<?php echo (int)$day['slot_count']; ?> <?php esc_html_e('filled', 'adoration-scheduler'); ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top:24px; max-width:900px;">
    <h3><?php esc_html_e('Closures / Blackout Periods', 'adoration-scheduler'); ?></h3>
    <p class="description">
        <?php esc_html_e('Block out a date/time range (e.g. Christmas: Dec 24, 4:00 PM through Dec 26, 4:00 PM). This cancels signups and closes slots for that window without touching the weekly hours template or standing commitments — everything resumes automatically once the window ends. Admin-only.', 'adoration-scheduler'); ?>
    </p>

    <?php if (!empty($closures_list)): ?>
        <table class="widefat striped" style="margin-bottom:16px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Reason', 'adoration-scheduler'); ?></th>
                    <th><?php esc_html_e('From', 'adoration-scheduler'); ?></th>
                    <th><?php esc_html_e('Through', 'adoration-scheduler'); ?></th>
                    <th style="width:100px;"><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($closures_list as $c): ?>
                    <?php
                    $c_id     = (int)($c['id'] ?? 0);
                    $c_start  = strtotime((string)($c['start_at'] ?? ''));
                    $c_end    = strtotime((string)($c['end_at'] ?? ''));
                    $c_reason = trim((string)($c['reason'] ?? ''));
                    ?>
                    <tr>
                        <td><?php echo esc_html($c_reason !== '' ? $c_reason : '—'); ?></td>
                        <td><?php echo $c_start ? esc_html(date_i18n('M j, Y g:i A', $c_start)) : '—'; ?></td>
                        <td><?php echo $c_end ? esc_html(date_i18n('M j, Y g:i A', $c_end)) : '—'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url($post_url); ?>">
                                <?php wp_nonce_field('adoration_remove_closure'); ?>
                                <input type="hidden" name="closure_id" value="<?php echo $c_id; ?>">
                                <button
                                    type="submit"
                                    name="adoration_remove_closure"
                                    class="button button-small button-link-delete"
                                    onclick="return confirm('<?php echo esc_js(__('Remove this closure? Any slots it closed will reopen (cancelled signups are not automatically restored).', 'adoration-scheduler')); ?>');"
                                >
                                    <?php esc_html_e('Remove', 'adoration-scheduler'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p><em><?php esc_html_e('No closures on record.', 'adoration-scheduler'); ?></em></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($post_url); ?>" style="padding:12px; border:1px solid #ccd0d4; background:#fafafa; max-width:520px;">
        <?php wp_nonce_field('adoration_add_closure'); ?>
        <p style="margin:4px 0;">
            <label style="display:block; font-weight:600; margin-bottom:2px;"><?php esc_html_e('Reason (optional)', 'adoration-scheduler'); ?></label>
            <input type="text" name="closure_reason" placeholder="<?php esc_attr_e('e.g. Christmas', 'adoration-scheduler'); ?>" style="width:100%;">
        </p>
        <div style="display:flex; gap:16px;">
            <p style="margin:4px 0; flex:1;">
                <label style="display:block; font-weight:600; margin-bottom:2px;"><?php esc_html_e('Closed from', 'adoration-scheduler'); ?></label>
                <input type="date" name="closure_start_date" required style="width:100%;">
                <input type="time" name="closure_start_time" value="16:00" required style="width:100%; margin-top:4px;">
            </p>
            <p style="margin:4px 0; flex:1;">
                <label style="display:block; font-weight:600; margin-bottom:2px;"><?php esc_html_e('Through', 'adoration-scheduler'); ?></label>
                <input type="date" name="closure_end_date" required style="width:100%;">
                <input type="time" name="closure_end_time" value="16:00" required style="width:100%; margin-top:4px;">
            </p>
        </div>
        <p style="margin:8px 0 0;">
            <button type="submit" name="adoration_add_closure" class="button button-primary">
                <?php esc_html_e('Add Closure', 'adoration-scheduler'); ?>
            </button>
        </p>
    </form>
</div>

<?php if ($cal_date === ''): ?>
    <p style="margin-top:16px;"><?php esc_html_e('Click a date above to see its hours.', 'adoration-scheduler'); ?></p>
<?php else: ?>
    <h3 style="margin-top:24px;">
        <?php echo esc_html(date_i18n('l, F j, Y', strtotime($cal_date))); ?>
    </h3>

    <?php if (empty($cal_day_slots)): ?>
        <p><?php esc_html_e('No slots generated for this date yet.', 'adoration-scheduler'); ?></p>
    <?php else: ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:140px;"><?php esc_html_e('Hour', 'adoration-scheduler'); ?></th>
                    <th><?php esc_html_e('Signed Up', 'adoration-scheduler'); ?></th>
                    <th style="width:280px;"><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cal_day_slots as $row): ?>
                    <?php
                    $slot = $row['slot'];
                    $slot_id = (int)($slot['id'] ?? 0);
                    $confirmed = array_values(array_filter($row['signups'], function($s) {
                        return (string)($s['status'] ?? '') === 'confirmed';
                    }));
                    ?>
                    <tr>
                        <td><?php echo esc_html($format_time_ampm((string)($slot['start_time'] ?? '')) . ' – ' . $format_time_ampm((string)($slot['end_time'] ?? ''))); ?></td>
                        <td>
                            <?php if (empty($confirmed)): ?>
                                <span style="color:#996800;"><?php esc_html_e('Open', 'adoration-scheduler'); ?></span>
                            <?php else: ?>
                                <?php foreach ($confirmed as $su): ?>
                                    <?php
                                    $name = trim((string)($su['first_name'] ?? '') . ' ' . (string)($su['last_name'] ?? ''));
                                    $is_standing = ((string)($su['type'] ?? '') === 'standing');
                                    ?>
                                    <div>
                                        <?php echo esc_html($name !== '' ? $name : '—'); ?>
                                        <?php if ($is_standing): ?>
                                            <span class="uk-text-meta" style="font-size:11px;">(<?php esc_html_e('standing', 'adoration-scheduler'); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($confirmed as $su): ?>
                                <?php $signup_id = (int)($su['id'] ?? 0); ?>
                                <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline-block; margin:0 4px 4px 0;">
                                    <?php wp_nonce_field('adoration_coverage_cancel_signup'); ?>
                                    <input type="hidden" name="signup_id" value="<?php echo (int)$signup_id; ?>">
                                    <input type="hidden" name="cal_date" value="<?php echo esc_attr($cal_date); ?>">
                                    <button
                                        type="submit"
                                        name="adoration_coverage_cancel_signup"
                                        class="button button-small button-link-delete"
                                        onclick="return confirm('<?php echo esc_js(__('Cancel just this date? Their standing weekly hour (if any) will continue next week.', 'adoration-scheduler')); ?>');"
                                    >
                                        <?php esc_html_e('Cancel this date', 'adoration-scheduler'); ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>

                            <button
                                type="button"
                                class="button button-small as-toggle-sub"
                                data-target="sub_form_<?php echo (int)$slot_id; ?>"
                            >
                                <?php esc_html_e('Assign substitute', 'adoration-scheduler'); ?>
                            </button>

                            <div id="sub_form_<?php echo (int)$slot_id; ?>" style="display:none; margin-top:8px; padding:10px; border:1px solid #ccd0d4; background:#fafafa;">
                                <form method="post" action="<?php echo esc_url($post_url); ?>">
                                    <?php wp_nonce_field('adoration_admin_add_signup'); ?>
                                    <input type="hidden" name="slot_id" value="<?php echo (int)$slot_id; ?>">
                                    <input type="hidden" name="return_tab" value="coverage">
                                    <input type="hidden" name="cal_date" value="<?php echo esc_attr($cal_date); ?>">

                                    <p style="margin:4px 0;"><input type="text" name="first_name" placeholder="<?php esc_attr_e('First name', 'adoration-scheduler'); ?>" style="width:100%;" required></p>
                                    <p style="margin:4px 0;"><input type="text" name="last_name" placeholder="<?php esc_attr_e('Last name', 'adoration-scheduler'); ?>" style="width:100%;" required></p>
                                    <p style="margin:4px 0;"><input type="email" name="email" placeholder="<?php esc_attr_e('Email', 'adoration-scheduler'); ?>" style="width:100%;" required></p>
                                    <p style="margin:4px 0;"><input type="text" name="phone" placeholder="(555) 555-5555" style="width:100%;" required></p>

                                    <button type="submit" name="adoration_admin_add_signup" class="button button-primary button-small">
                                        <?php esc_html_e('Save Substitute', 'adoration-scheduler'); ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<script>
(function(){
    document.querySelectorAll('.as-toggle-sub').forEach(function(btn){
        btn.addEventListener('click', function(){
            var id = btn.getAttribute('data-target');
            var el = document.getElementById(id);
            if (!el) return;
            el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
        });
    });
})();
</script>
