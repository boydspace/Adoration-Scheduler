<?php
/**
 * Tab: Slots
 *
 * Expected variables in scope:
 * - $slots_total (int)
 * - $slots_active (int)
 * - $slots_inactive (int)
 * - $slots_rows (array) first 100 slots for the schedule
 * - $preview_slots (array) preview rows (optional)
 * - $has_signups (bool)  ✅ guardrail injected by EditSchedulePage
 * - $signups_total (int) ✅ guardrail injected by EditSchedulePage
 *
 * Also typically in scope from EditSchedulePage:
 * - $schedule (array)
 */

if ( ! defined('ABSPATH') ) exit;

// Explicit post target so generator buttons always hit EditSchedulePage::render() with correct args.
$current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$post_url = add_query_arg([
    'page'        => $current_page_slug,
    'action'      => 'edit',
    'schedule_id' => $schedule_id,
    'tab'         => 'slots',
], admin_url('admin.php'));

/**
 * ✅ Guardrail flags (safe defaults if not provided)
 */
$has_signups   = !empty($has_signups);
$signups_total = (int)($signups_total ?? 0);

/**
 * Overnight flag (informational only).
 */
$is_overnight = !empty($schedule['is_overnight'] ?? 0);

/**
 * Formatter helpers (closures) so we avoid redeclare fatals.
 */
$format_dt_date = function(\DateTimeInterface $dt): string {
    return date_i18n('l, F j, Y', $dt->getTimestamp());
};

$format_dt_time = function(\DateTimeInterface $dt): string {
    return date_i18n('g:i A', $dt->getTimestamp());
};

/**
 * Parse a DB datetime string into a local (site timezone) DateTimeImmutable.
 *
 * IMPORTANT FIX:
 * Our slots' start_at/end_at are effectively UTC timestamps in storage.
 * Treat them as UTC and convert to wp_timezone() for correct display.
 *
 * This fixes the "8:00 AM shows as 1:00 PM" (UTC displayed as local) bug.
 */
$parse_dt = function(string $ymd_his) {
    $ymd_his = trim($ymd_his);
    if ($ymd_his === '') return null;

    $site_tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

    try {
        $utc = new \DateTimeImmutable($ymd_his, new \DateTimeZone('UTC'));
        return $utc->setTimezone($site_tz);
    } catch (\Exception $e) {
        // Fallback: try strtotime
        $ts = strtotime($ymd_his);
        if ($ts === false) return null;

        try {
            $utc = new \DateTimeImmutable('@' . $ts); // @timestamp is UTC
            return $utc->setTimezone($site_tz);
        } catch (\Exception $e2) {
            return null;
        }
    }
};

$format_time_ampm = function(string $time): string {
    $time = trim($time);
    if ($time === '') return '—';
    if (strlen($time) === 5) $time .= ':00'; // HH:MM -> HH:MM:SS

    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) return $time;

    return date_i18n('g:i A', $ts);
};

$time_hhmm = function(string $time): string {
    $time = (string)$time;
    if ($time === '') return '';
    return substr($time, 0, 5);
};

$format_date_from_ymd = function(string $ymd): string {
    $ymd = trim($ymd);
    if ($ymd === '') return '—';
    $ts = strtotime($ymd);
    return ($ts !== false) ? date_i18n('l, F j, Y', $ts) : $ymd;
};

/**
 * ✅ Canonical display fields from a slot row.
 * Prefer start_at/end_at for DATE + TIME + chronology.
 *
 * Returns:
 * - display_date (Y-m-d) (used for modal read-only date)
 * - start_hhmm (HH:MM)
 * - end_hhmm (HH:MM)
 * - date_cell (formatted)
 * - time_label (formatted)
 */
$get_slot_display = function(array $row) use (
    $parse_dt,
    $format_dt_date,
    $format_dt_time,
    $format_time_ampm,
    $time_hhmm,
    $format_date_from_ymd
): array {
    $start_at = (string)($row['start_at'] ?? '');
    $end_at   = (string)($row['end_at'] ?? '');

    $start_dt = $start_at !== '' ? $parse_dt($start_at) : null;
    $end_dt   = $end_at   !== '' ? $parse_dt($end_at)   : null;

    if ($start_dt && $end_dt) {
        // Always show true local (site TZ) slot start date/time.
        $display_date = $start_dt->format('Y-m-d');

        return [
            'display_date' => $display_date,
            'start_hhmm'   => $start_dt->format('H:i'),
            'end_hhmm'     => $end_dt->format('H:i'),
            'date_cell'    => $format_dt_date($start_dt),
            'time_label'   => $format_dt_time($start_dt) . '–' . $format_dt_time($end_dt),
        ];
    }

    // Legacy fallback (no start_at/end_at present)
    $d = (string)($row['date'] ?? '');
    $date_cell = $format_date_from_ymd($d);

    $start_time = (string)($row['start_time'] ?? '');
    $end_time   = (string)($row['end_time'] ?? '');

    return [
        'display_date' => $d,
        'start_hhmm'   => $time_hhmm($start_time),
        'end_hhmm'     => $time_hhmm($end_time),
        'date_cell'    => $date_cell,
        'time_label'   => $format_time_ampm($start_time) . '–' . $format_time_ampm($end_time),
    ];
};
?>

<h2><?php esc_html_e('Slots', 'adoration-scheduler'); ?></h2>

<p>
    <?php esc_html_e('Slots in DB:', 'adoration-scheduler'); ?>
    <strong><?php echo (int)$slots_total; ?></strong>
    &nbsp;—&nbsp;
    <?php esc_html_e('Active:', 'adoration-scheduler'); ?> <strong><?php echo (int)$slots_active; ?></strong>
    &nbsp;|&nbsp;
    <?php esc_html_e('Inactive:', 'adoration-scheduler'); ?> <strong><?php echo (int)$slots_inactive; ?></strong>
</p>

<?php if ($is_overnight): ?>
    <div class="notice notice-info" style="margin: 12px 0;">
        <p>
            <strong><?php esc_html_e('Overnight schedule:', 'adoration-scheduler'); ?></strong>
            <?php esc_html_e('Slots are shown in true chronological order and dates correctly roll over after midnight.', 'adoration-scheduler'); ?>
        </p>
    </div>
<?php endif; ?>

<?php if ($has_signups): ?>
    <div class="notice notice-warning" style="margin: 12px 0;">
        <p>
            <strong><?php esc_html_e('Guardrail:', 'adoration-scheduler'); ?></strong>
            <?php
            printf(
                esc_html__('This schedule has %d signup(s). “Rebuild Slots (Delete + Recreate)” is disabled to prevent orphaning signups. Use “Safe Sync” instead.', 'adoration-scheduler'),
                (int) number_format_i18n($signups_total)
            );
            ?>
        </p>
    </div>
<?php endif; ?>

<h3><?php esc_html_e('Slots (first 100)', 'adoration-scheduler'); ?></h3>

<?php if (empty($slots_rows)): ?>
    <p><em><?php esc_html_e('No slots found yet. Use Safe Sync or Rebuild to generate slots.', 'adoration-scheduler'); ?></em></p>
<?php else: ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Date', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Min', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Max', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($slots_rows as $r): ?>
                <?php
                $slot_id   = (int)($r['id'] ?? 0);
                $is_active_row = (int)($r['is_active'] ?? 0) === 1;
                $status_label = $is_active_row ? __('Active', 'adoration-scheduler') : __('Inactive', 'adoration-scheduler');

                $disp = $get_slot_display($r);

                $display_date = $disp['display_date']; // Y-m-d
                $start_5 = $disp['start_hhmm'];        // HH:MM
                $end_5   = $disp['end_hhmm'];          // HH:MM

                $time_label = $disp['time_label'];
                $date_cell  = $disp['date_cell'];

                $max_val = ($r['max_adorers'] !== null && $r['max_adorers'] !== '') ? (string)$r['max_adorers'] : '';
                $public_note = (string)($r['public_note'] ?? '');
                ?>
                <tr>
                    <td><?php echo esc_html($date_cell); ?></td>
                    <td><?php echo esc_html($time_label); ?></td>
                    <td><?php echo (int)($r['min_adorers'] ?? 0); ?></td>
                    <td><?php echo ($max_val !== '') ? (int)$max_val : '—'; ?></td>
                    <td>
                        <?php if ($is_active_row): ?>
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e7f7ed;border:1px solid #b7e3c3;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        <?php else: ?>
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f6f7f7;border:1px solid #c3c4c7;color:#646970;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button
                            type="button"
                            class="button button-small adoration-edit-slot-btn"
                            data-slot-id="<?php echo (int)$slot_id; ?>"
                            data-date="<?php echo esc_attr($display_date); ?>"
                            data-start="<?php echo esc_attr($start_5); ?>"
                            data-end="<?php echo esc_attr($end_5); ?>"
                            data-min="<?php echo esc_attr((string)($r['min_adorers'] ?? 0)); ?>"
                            data-max="<?php echo esc_attr($max_val); ?>"
                            data-active="<?php echo $is_active_row ? '1' : '0'; ?>"
                            data-note="<?php echo esc_attr($public_note); ?>"
                        >
                            <?php esc_html_e('Edit', 'adoration-scheduler'); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="description" style="margin-top:8px;">
        <?php esc_html_e('Showing up to 100 slots. Use Edit to change Min/Max/Active only.', 'adoration-scheduler'); ?>
    </p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-bottom: 12px;">
    <?php wp_nonce_field('adoration_preview_slots'); ?>
    <button type="submit" name="adoration_preview_slots" class="button button-secondary">
        <?php esc_html_e('Preview Slots (No Changes)', 'adoration-scheduler'); ?>
    </button>
    <p class="description">
        <?php esc_html_e('Shows the slots that would be generated from your Dates & Hours, without modifying the database.', 'adoration-scheduler'); ?>
    </p>
</form>

<?php if (!empty($preview_slots)): ?>
    <h3><?php esc_html_e('Preview', 'adoration-scheduler'); ?></h3>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Date', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Min', 'adoration-scheduler'); ?></th>
                <th><?php esc_html_e('Max', 'adoration-scheduler'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($preview_slots as $ps): ?>
                <?php
                $disp = $get_slot_display($ps);

                $max_display = (($ps['max_adorers'] ?? null) !== null && ($ps['max_adorers'] ?? '') !== '')
                    ? (int)$ps['max_adorers']
                    : '—';
                ?>
                <tr>
                    <td><?php echo esc_html($disp['date_cell']); ?></td>
                    <td><?php echo esc_html($disp['time_label']); ?></td>
                    <td><?php echo (int)($ps['min_adorers'] ?? 0); ?></td>
                    <td><?php echo esc_html($max_display); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin: 14px 0;">
    <?php wp_nonce_field('adoration_sync_slots'); ?>
    <button type="submit" name="adoration_sync_slots" class="button button-primary">
        <?php esc_html_e('Safe Sync Slots (No Nuking)', 'adoration-scheduler'); ?>
    </button>
    <p class="description">
        <?php esc_html_e('Adds missing slots and deactivates removed slots without deleting existing rows.', 'adoration-scheduler'); ?>
    </p>
</form>

<hr style="margin: 18px 0;">

<form method="post" action="<?php echo esc_url($post_url); ?>">
    <?php wp_nonce_field('adoration_generate_slots'); ?>

    <button
        type="submit"
        name="adoration_generate_slots"
        class="button"
        <?php echo $has_signups ? 'disabled aria-disabled="true"' : ''; ?>
        <?php echo $has_signups ? 'title="' . esc_attr__('Disabled because signups exist for this schedule.', 'adoration-scheduler') . '"' : ''; ?>
        <?php
            echo $has_signups
                ? ''
                : 'onclick="' . esc_attr("return confirm('This deletes existing slots for this schedule and recreates them from segments. Continue?');") . '"';
        ?>
    >
        <?php esc_html_e('Rebuild Slots From Segments (Delete + Recreate)', 'adoration-scheduler'); ?>
    </button>

    <p class="description">
        <?php
        if ($has_signups) {
            esc_html_e('Disabled because signups exist for this schedule. Use Safe Sync instead.', 'adoration-scheduler');
        } else {
            esc_html_e('This deletes existing slots for this schedule and recreates them from segments. Use only if needed.', 'adoration-scheduler');
        }
        ?>
    </p>
</form>

<!-- Slot Edit Modal -->
<div id="adoration-slot-modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:999999;"></div>

<div id="adoration-slot-modal" style="display:none; position:fixed; top:8%; left:50%; transform:translateX(-50%); width:min(640px, 92vw); background:#fff; border:1px solid #c3c4c7; box-shadow:0 10px 30px rgba(0,0,0,.25); border-radius:8px; z-index:1000000;">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid #dcdcde;">
        <strong style="font-size:14px;"><?php esc_html_e('Edit Slot', 'adoration-scheduler'); ?></strong>
        <button type="button" class="button" id="adoration-slot-modal-close"><?php esc_html_e('Close', 'adoration-scheduler'); ?></button>
    </div>

    <div style="padding:14px;">
        <form method="post" id="adoration-slot-edit-form" action="<?php echo esc_url($post_url); ?>">
            <?php wp_nonce_field('adoration_update_slot'); ?>
            <input type="hidden" name="adoration_update_slot" value="1">
            <input type="hidden" name="slot_id" id="adoration_slot_id" value="">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="adoration_slot_date_display"><?php esc_html_e('Date', 'adoration-scheduler'); ?></label></th>
                    <td><input type="date" id="adoration_slot_date_display" readonly></td>
                </tr>

                <tr>
                    <th><label for="adoration_slot_start_display"><?php esc_html_e('Start', 'adoration-scheduler'); ?></label></th>
                    <td><input type="time" id="adoration_slot_start_display" readonly></td>
                </tr>

                <tr>
                    <th><label for="adoration_slot_end_display"><?php esc_html_e('End', 'adoration-scheduler'); ?></label></th>
                    <td><input type="time" id="adoration_slot_end_display" readonly></td>
                </tr>

                <tr>
                    <th><label for="adoration_slot_min"><?php esc_html_e('Min adorers', 'adoration-scheduler'); ?></label></th>
                    <td><input type="number" name="min_adorers" id="adoration_slot_min" min="0" step="1"></td>
                </tr>

                <tr>
                    <th><label for="adoration_slot_max"><?php esc_html_e('Max adorers', 'adoration-scheduler'); ?></label></th>
                    <td>
                        <input type="number" name="max_adorers" id="adoration_slot_max" min="0" step="1">
                        <p class="description"><?php esc_html_e('Leave this blank for unlimited signups per hour.', 'adoration-scheduler'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="adoration_slot_public_note"><?php esc_html_e('Public note (when inactive)', 'adoration-scheduler'); ?></label></th>
                    <td>
                        <input
                            type="text"
                            name="public_note"
                            id="adoration_slot_public_note"
                            class="regular-text"
                            maxlength="255"
                            placeholder="<?php echo esc_attr__('e.g., Praise and Worship Adoration', 'adoration-scheduler'); ?>"
                        >
                        <p class="description">
                            <?php esc_html_e('Shown on the public schedule when this slot is inactive/disabled. Leave blank for no message.', 'adoration-scheduler'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><label for="adoration_slot_active"><?php esc_html_e('Active', 'adoration-scheduler'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" id="adoration_slot_active" value="1">
                            <?php esc_html_e('This slot is active', 'adoration-scheduler'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p style="margin-top:10px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Slot', 'adoration-scheduler'); ?></button>
                <button type="button" class="button" id="adoration-slot-modal-cancel"><?php esc_html_e('Cancel', 'adoration-scheduler'); ?></button>
            </p>
        </form>
    </div>
</div>

<script>
(function() {
    const backdrop = document.getElementById('adoration-slot-modal-backdrop');
    const modal = document.getElementById('adoration-slot-modal');
    const closeBtn = document.getElementById('adoration-slot-modal-close');
    const cancelBtn = document.getElementById('adoration-slot-modal-cancel');

    const slotIdEl = document.getElementById('adoration_slot_id');

    const dateDisplayEl  = document.getElementById('adoration_slot_date_display');
    const startDisplayEl = document.getElementById('adoration_slot_start_display');
    const endDisplayEl   = document.getElementById('adoration_slot_end_display');

    const minEl    = document.getElementById('adoration_slot_min');
    const maxEl    = document.getElementById('adoration_slot_max');
    const activeEl = document.getElementById('adoration_slot_active');
    const noteEl   = document.getElementById('adoration_slot_public_note');

    function openModal() {
        backdrop.style.display = 'block';
        modal.style.display = 'block';
        setTimeout(() => { try { minEl.focus(); } catch(e) {} }, 10);
    }

    function closeModal() {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
    }

    function onEditClick(e) {
        const btn = e.currentTarget;

        slotIdEl.value = btn.getAttribute('data-slot-id') || '';

        dateDisplayEl.value  = btn.getAttribute('data-date') || '';
        startDisplayEl.value = btn.getAttribute('data-start') || '';
        endDisplayEl.value   = btn.getAttribute('data-end') || '';

        minEl.value = btn.getAttribute('data-min') || '0';

        const maxVal = btn.getAttribute('data-max');
        maxEl.value = (maxVal === null || maxVal === undefined) ? '' : maxVal;

        const active = btn.getAttribute('data-active') || '0';
        activeEl.checked = (active === '1');

        if (noteEl) noteEl.value = btn.getAttribute('data-note') || '';

        openModal();
    }

    document.querySelectorAll('.adoration-edit-slot-btn').forEach(btn => {
        btn.addEventListener('click', onEditClick);
    });

    backdrop.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', function(e){ e.preventDefault(); closeModal(); });
    cancelBtn.addEventListener('click', function(e){ e.preventDefault(); closeModal(); });

    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape' && modal.style.display === 'block') {
            closeModal();
        }
    });
})();
</script>
