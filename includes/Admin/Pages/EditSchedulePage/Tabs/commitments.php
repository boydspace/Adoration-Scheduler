<?php
/**
 * Tab: Standing Commitments (perpetual schedules)
 *
 * Expected variables in scope:
 * - $weekday_templates (array)
 * - $weekday_slot_starts (array) [day_of_week] => [['start_time','end_time','label'], ...]
 * - $commitments_grid (array) [day_of_week][start_time] => [commitment rows]
 * - $commitments_list (array) flat list of active commitments (joined w/ person)
 * - $schedule (array)
 */

if ( ! defined('ABSPATH') ) exit;

$current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$post_url = add_query_arg([
    'page'        => $current_page_slug,
    'action'      => 'edit',
    'schedule_id' => $schedule_id,
    'tab'         => 'commitments',
], admin_url('admin.php'));

$day_labels = [
    0 => __('Sunday', 'adoration-scheduler'),
    1 => __('Monday', 'adoration-scheduler'),
    2 => __('Tuesday', 'adoration-scheduler'),
    3 => __('Wednesday', 'adoration-scheduler'),
    4 => __('Thursday', 'adoration-scheduler'),
    5 => __('Friday', 'adoration-scheduler'),
    6 => __('Saturday', 'adoration-scheduler'),
];

$format_time_ampm = function(string $time): string {
    $time = trim($time);
    if ($time === '') return '—';
    if (strlen($time) === 5) $time .= ':00';
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) return $time;
    return date_i18n('g:i A', $ts);
};

$has_any_hours = false;
foreach ($weekday_slot_starts as $opts) {
    if (!empty($opts)) { $has_any_hours = true; break; }
}
?>

<h2><?php esc_html_e('Standing Commitments', 'adoration-scheduler'); ?></h2>

<p class="description">
    <?php esc_html_e('A standing commitment is a parishioner’s permanent weekly hour — e.g. "Tuesday 3:00 AM – 4:00 AM, every week." Once assigned, the background sync automatically signs that person up each time it generates a new week’s slot for that hour, so they never have to re-signup. Cancelling one week from the Signups tab only skips that date; it does not end the standing commitment.', 'adoration-scheduler'); ?>
</p>

<?php if (!$has_any_hours): ?>
    <div class="notice notice-warning" style="margin: 12px 0;">
        <p><?php esc_html_e('Set hours on the Weekly Hours tab first, then come back here to assign adorers to them.', 'adoration-scheduler'); ?></p>
    </div>
<?php else: ?>

    <?php
    // ---------------------------------------------------------------
    // Weekly Calendar: one row per distinct hour used anywhere in the
    // week, one column per day — this is the "weekly calendar, not a
    // date calendar" view, since standing commitments repeat every
    // week on the same hour rather than living on one specific date.
    // ---------------------------------------------------------------
    $all_row_times = [];
    foreach ($weekday_slot_starts as $opts) {
        foreach ($opts as $opt) {
            $all_row_times[$opt['start_time']] = $opt['start_time'];
        }
    }
    ksort($all_row_times);
    ?>

    <h3><?php esc_html_e('Weekly Calendar', 'adoration-scheduler'); ?></h3>
    <p class="description">
        <?php esc_html_e('Every hour below repeats on the same day and time every week. Green = filled, gray = open. Click an open hour to jump to the assign form.', 'adoration-scheduler'); ?>
    </p>

    <div class="uk-overflow-auto" style="overflow-x:auto; margin-bottom:24px;">
        <table class="widefat striped" style="min-width:900px;">
            <thead>
                <tr>
                    <th style="width:100px;"><?php esc_html_e('Hour', 'adoration-scheduler'); ?></th>
                    <?php foreach ($day_labels as $dow => $label): ?>
                        <th><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_row_times as $row_start): ?>
                    <tr>
                        <td><strong><?php echo esc_html($format_time_ampm($row_start)); ?></strong></td>
                        <?php foreach ($day_labels as $dow => $label): ?>
                            <?php
                            $has_hour_this_day = false;
                            foreach (($weekday_slot_starts[$dow] ?? []) as $opt) {
                                if ($opt['start_time'] === $row_start) { $has_hour_this_day = true; break; }
                            }

                            if (!$has_hour_this_day):
                            ?>
                                <td style="background:#f0f0f1; color:#a7aaad; text-align:center;">—</td>
                            <?php else:
                                $occupants = $commitments_grid[$dow][$row_start] ?? [];
                            ?>
                                <td style="<?php echo empty($occupants) ? 'background:#fcf0f1;' : 'background:#edfaef;'; ?> text-align:center;">
                                    <?php if (empty($occupants)): ?>
                                        <a href="#assign_adorer_form" style="color:#996800;"><?php esc_html_e('Open', 'adoration-scheduler'); ?></a>
                                    <?php else: ?>
                                        <?php foreach ($occupants as $occ): ?>
                                            <div><?php echo esc_html(trim((string)($occ['first_name'] ?? '') . ' ' . (string)($occ['last_name'] ?? ''))); ?></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3 id="assign_adorer_form"><?php esc_html_e('Assign an Adorer to an Hour', 'adoration-scheduler'); ?></h3>

    <form method="post" action="<?php echo esc_url($post_url); ?>" style="max-width: 640px; background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:6px; margin-bottom:24px;">
        <?php wp_nonce_field('adoration_add_commitment'); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cd_day"><?php esc_html_e('Day', 'adoration-scheduler'); ?></label></th>
                <td>
                    <select name="day_of_week" id="cd_day" required>
                        <option value=""><?php esc_html_e('— choose —', 'adoration-scheduler'); ?></option>
                        <?php foreach ($day_labels as $dow => $label): ?>
                            <?php if (empty($weekday_slot_starts[$dow])) continue; ?>
                            <option value="<?php echo esc_attr((string)$dow); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <?php foreach ($weekday_slot_starts as $dow => $opts): ?>
                <?php if (empty($opts)) continue; ?>
                <tr class="cd_hour_row" data-day="<?php echo (int)$dow; ?>" style="display:none;">
                    <th scope="row"><?php esc_html_e('Hour', 'adoration-scheduler'); ?></th>
                    <td>
                        <?php foreach ($opts as $opt): ?>
                            <label style="display:block; margin-bottom:4px;">
                                <input
                                    type="radio"
                                    name="hour_choice_<?php echo (int)$dow; ?>"
                                    class="cd_hour_radio"
                                    data-day="<?php echo (int)$dow; ?>"
                                    value="<?php echo esc_attr($opt['start_time'] . '|' . $opt['end_time']); ?>"
                                >
                                <?php echo esc_html($opt['label']); ?>

                                <?php
                                $dow_grid = $commitments_grid[$dow][$opt['start_time']] ?? [];
                                if (!empty($dow_grid)):
                                    $names = [];
                                    foreach ($dow_grid as $c) {
                                        $names[] = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
                                    }
                                ?>
                                    <span style="color:#996800;"> — <?php echo esc_html__('already has:', 'adoration-scheduler'); ?> <?php echo esc_html(implode(', ', $names)); ?></span>
                                <?php else: ?>
                                    <span style="color:#2a7a2a;"> — <?php esc_html_e('open', 'adoration-scheduler'); ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <input type="hidden" name="start_time" id="cd_start_time">
            <input type="hidden" name="end_time" id="cd_end_time">

            <tr>
                <th scope="row"><label for="cd_first"><?php esc_html_e('First Name', 'adoration-scheduler'); ?></label></th>
                <td><input type="text" name="first_name" id="cd_first" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="cd_last"><?php esc_html_e('Last Name', 'adoration-scheduler'); ?></label></th>
                <td><input type="text" name="last_name" id="cd_last" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="cd_email"><?php esc_html_e('Email', 'adoration-scheduler'); ?></label></th>
                <td><input type="email" name="email" id="cd_email" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="cd_phone"><?php esc_html_e('Phone', 'adoration-scheduler'); ?></label></th>
                <td><input type="text" name="phone" id="cd_phone" class="regular-text" required placeholder="(555) 555-5555"></td>
            </tr>
        </table>

        <p>
            <button type="submit" name="adoration_add_commitment" class="button button-primary">
                <?php esc_html_e('Assign Adorer', 'adoration-scheduler'); ?>
            </button>
        </p>
    </form>

    <script>
    (function(){
        var daySel = document.getElementById('cd_day');
        var rows = document.querySelectorAll('.cd_hour_row');
        var startInput = document.getElementById('cd_start_time');
        var endInput = document.getElementById('cd_end_time');

        function showRowsForDay(){
            var day = daySel ? daySel.value : '';
            rows.forEach(function(row){
                row.style.display = (row.getAttribute('data-day') === day) ? '' : 'none';
            });
            startInput.value = '';
            endInput.value = '';
        }

        if (daySel) {
            daySel.addEventListener('change', showRowsForDay);
        }

        document.addEventListener('change', function(e){
            if (e.target && e.target.classList && e.target.classList.contains('cd_hour_radio')) {
                var parts = e.target.value.split('|');
                startInput.value = parts[0] || '';
                endInput.value = parts[1] || '';
            }
        });
    })();
    </script>
<?php endif; ?>

<h3><?php esc_html_e('Current Standing Commitments', 'adoration-scheduler'); ?></h3>

<?php if (empty($commitments_list)): ?>
    <p><?php esc_html_e('No standing commitments yet.', 'adoration-scheduler'); ?></p>
<?php else: ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Day', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Hour', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Adorer', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Contact', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Since', 'adoration-scheduler'); ?></th>
                <th style="width:120px;"><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($commitments_list as $c): ?>
                <?php
                $cid = (int)($c['id'] ?? 0);
                $dow = (int)($c['day_of_week'] ?? 0);
                $name = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
                ?>
                <tr>
                    <td><?php echo esc_html($day_labels[$dow] ?? ('Day ' . $dow)); ?></td>
                    <td><?php echo esc_html($format_time_ampm((string)($c['start_time'] ?? '')) . ' – ' . $format_time_ampm((string)($c['end_time'] ?? ''))); ?></td>
                    <td><?php echo esc_html($name !== '' ? $name : '—'); ?></td>
                    <td>
                        <?php echo esc_html((string)($c['email'] ?? '')); ?>
                        <?php if (!empty($c['phone'])): ?>
                            <br><?php echo esc_html((string)$c['phone']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string)($c['started_on'] ?? '')); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin:0;">
                            <?php wp_nonce_field('adoration_end_commitment'); ?>
                            <input type="hidden" name="commitment_id" value="<?php echo (int)$cid; ?>">
                            <button
                                type="submit"
                                name="adoration_end_commitment"
                                class="button button-link-delete"
                                onclick="return confirm('<?php echo esc_js(__('End this standing commitment? Future weeks will no longer auto-signup this person.', 'adoration-scheduler')); ?>');"
                            >
                                <?php esc_html_e('End', 'adoration-scheduler'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
