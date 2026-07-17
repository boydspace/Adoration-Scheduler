<?php
/**
 * Tab: Signups
 *
 * Expected variables in scope:
 * - $slots_rows (array) slots for the schedule (already ordered in SQL!)
 * - $signup_counts (array) [slot_id => confirmed_count]
 * - $signups_by_slot (array) [slot_id => [signup_rows...]] (joined with persons)
 * - $schedule (array)
 */

if ( ! defined('ABSPATH') ) exit;

// Stable return URL (so admin-post actions can redirect cleanly)
$page_slug   = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
$schedule_id = (int)($_GET['schedule_id'] ?? 0);

$return_url = add_query_arg([
    'page'        => $page_slug,
    'action'      => 'edit',
    'schedule_id' => (int) $schedule_id,
    'tab'         => 'signups',
], admin_url('admin.php'));

$GLOBALS['as_return_url'] = $return_url;

/**
 * ✅ Nonce for AJAX resend buttons (matches AdminResendEmailAjaxService)
 * Must match: check_ajax_referer('as_signup_resend')
 */
$nonce_resend = wp_create_nonce('as_signup_resend');

/**
 * Overnight flag (informational only).
 */
$is_overnight = !empty($schedule['is_overnight'] ?? 0);

// ---- Local helpers (NO globals, avoids redeclare fatals) ----

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
 */
$parse_dt = function(string $ymd_his) {
    $ymd_his = trim($ymd_his);
    if ($ymd_his === '') return null;

    $site_tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

    try {
        $utc = new \DateTimeImmutable($ymd_his, new \DateTimeZone('UTC'));
        return $utc->setTimezone($site_tz);
    } catch (\Exception $e) {
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

$format_date_from_ymd = function(string $ymd): string {
    $ymd = trim($ymd);
    if ($ymd === '') return '—';
    $ts = strtotime($ymd);
    return ($ts !== false) ? date_i18n('l, F j, Y', $ts) : $ymd;
};

/**
 * ✅ Canonical display fields from a slot row.
 * Prefer start_at/end_at for DATE + TIME.
 *
 * Returns:
 * - display_date (Y-m-d) (used for modal label)
 * - date_cell (formatted)
 * - time_cell (formatted)
 * - slot_label (for modal header)
 */
$get_slot_display = function(array $row) use (
    $parse_dt,
    $format_dt_date,
    $format_dt_time,
    $format_time_ampm,
    $format_date_from_ymd
): array {
    $start_at = (string)($row['start_at'] ?? '');
    $end_at   = (string)($row['end_at'] ?? '');

    $start_dt = $start_at !== '' ? $parse_dt($start_at) : null;
    $end_dt   = $end_at   !== '' ? $parse_dt($end_at)   : null;

    if ($start_dt && $end_dt) {
        $display_date = $start_dt->format('Y-m-d');
        $date_cell    = $format_dt_date($start_dt);
        $time_cell    = $format_dt_time($start_dt) . '–' . $format_dt_time($end_dt);

        return [
            'display_date' => $display_date,
            'date_cell'    => $date_cell,
            'time_cell'    => $time_cell,
            'slot_label'   => $date_cell . ' ' . $time_cell,
        ];
    }

    // Legacy fallback (no start_at/end_at columns)
    $d = (string)($row['date'] ?? '');
    $date_cell = $format_date_from_ymd($d);

    $start_raw = (string)($row['start_time'] ?? '');
    $end_raw   = (string)($row['end_time'] ?? '');

    $time_cell = $format_time_ampm($start_raw) . '–' . $format_time_ampm($end_raw);

    return [
        'display_date' => $d,
        'date_cell'    => $date_cell,
        'time_cell'    => $time_cell,
        'slot_label'   => $date_cell . ' ' . $time_cell,
    ];
};

/**
 * ✅ Admin-post action form (POST) with nonce + return.
 *
 * Cancel/Delete are admin-post handlers owned by AdminSignupActionsService.
 */
$as_admin_post_form = function(
    string $action,
    int $signup_id,
    string $nonce_action,
    string $label,
    string $class = 'button button-small',
    string $confirm = ''
) use ($return_url): void {
    $action_url = admin_url('admin-post.php');
    ?>
    <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline-block; margin:0 6px 6px 0;">
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
        <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>">
        <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>">
        <?php wp_nonce_field($nonce_action); ?>
        <button
            type="submit"
            class="<?php echo esc_attr($class); ?>"
            <?php if ($confirm !== ''): ?>
                onclick="return confirm(<?php echo wp_json_encode($confirm); ?>);"
            <?php endif; ?>
        >
            <?php echo esc_html($label); ?>
        </button>
    </form>
    <?php
};

?>

<h2><?php esc_html_e('Signups', 'adoration-scheduler'); ?></h2>

<?php if ($is_overnight): ?>
    <div class="notice notice-info" style="margin: 12px 0;">
        <p>
            <strong><?php esc_html_e('Overnight schedule:', 'adoration-scheduler'); ?></strong>
            <?php esc_html_e('Slots are shown in true chronological order and dates correctly roll over after midnight.', 'adoration-scheduler'); ?>
        </p>
    </div>
<?php endif; ?>

<p class="description">
    <?php esc_html_e('Click a TIME to add a signup for that specific slot.', 'adoration-scheduler'); ?>
</p>

<?php if (empty($slots_rows)): ?>
    <p><em><?php esc_html_e('No slots found. Generate slots first.', 'adoration-scheduler'); ?></em></p>
<?php else: ?>

<table class="widefat striped">
    <thead>
        <tr>
            <th><?php esc_html_e('Date', 'adoration-scheduler'); ?></th>
            <th><?php esc_html_e('Time (click to add)', 'adoration-scheduler'); ?></th>
            <th><?php esc_html_e('Confirmed', 'adoration-scheduler'); ?></th>
            <th><?php esc_html_e('Details', 'adoration-scheduler'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($slots_rows as $r): ?>
            <?php
            $slot_id = (int)($r['id'] ?? 0);

            $disp = $get_slot_display((array)$r);

            $date_cell  = $disp['date_cell'];
            $time_cell  = $disp['time_cell'];
            $slot_label = $disp['slot_label'];

            $confirmed_count = (int)($signup_counts[$slot_id] ?? 0);
            $signups_here    = $signups_by_slot[$slot_id] ?? [];
            ?>
            <tr>
                <td><?php echo esc_html($date_cell); ?></td>

                <td>
                    <button
                        type="button"
                        class="button-link adoration-signup-time"
                        data-slot-id="<?php echo (int)$slot_id; ?>"
                        data-slot-label="<?php echo esc_attr($slot_label); ?>"
                        style="cursor:pointer; text-decoration:underline; padding:0; border:0; background:none;"
                    >
                        <?php echo esc_html($time_cell); ?>
                    </button>
                </td>

                <td><strong><?php echo (int)$confirmed_count; ?></strong></td>

                <td>
                    <details>
                        <summary><?php esc_html_e('View', 'adoration-scheduler'); ?></summary>

                        <?php if (empty($signups_here)): ?>
                            <p style="margin:8px 0;"><em><?php esc_html_e('No confirmed signups for this slot.', 'adoration-scheduler'); ?></em></p>
                        <?php else: ?>
                            <table class="widefat striped" style="margin:8px 0;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Name', 'adoration-scheduler'); ?></th>
                                        <th><?php esc_html_e('Email', 'adoration-scheduler'); ?></th>
                                        <th><?php esc_html_e('Phone', 'adoration-scheduler'); ?></th>
                                        <th><?php esc_html_e('Actions', 'adoration-scheduler'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($signups_here as $su): ?>
                                        <?php
                                        $signup_id = (int)($su['id'] ?? 0);
                                        $name  = trim((string)($su['first_name'] ?? '') . ' ' . (string)($su['last_name'] ?? ''));
                                        $email = (string)($su['email'] ?? '');
                                        $phone = (string)($su['phone'] ?? '');
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html($name !== '' ? $name : '—'); ?></td>
                                            <td><?php echo esc_html($email !== '' ? $email : '—'); ?></td>
                                            <td><?php echo esc_html($phone !== '' ? $phone : '—'); ?></td>
                                            <td>
                                                <?php if ($signup_id > 0): ?>
                                                    <?php
                                                    $as_admin_post_form(
                                                        'adoration_admin_cancel_signup',
                                                        $signup_id,
                                                        'adoration_admin_cancel_signup_' . $signup_id,
                                                        __('Cancel', 'adoration-scheduler'),
                                                        'button button-small',
                                                        __('Cancel this signup (keeps record, stops reminders)?', 'adoration-scheduler')
                                                    );

                                                    $as_admin_post_form(
                                                        'adoration_admin_delete_signup',
                                                        $signup_id,
                                                        'adoration_admin_delete_signup_' . $signup_id,
                                                        __('Delete', 'adoration-scheduler'),
                                                        'button button-small button-link-delete',
                                                        __('Delete this signup permanently? This cannot be undone.', 'adoration-scheduler')
                                                    );
                                                    ?>

                                                    <!-- ✅ Resend actions are now AJAX (AdminResendEmailAjaxService) -->
                                                    <button type="button"
                                                            class="button button-small as-inline-resend"
                                                            data-signup-id="<?php echo (int)$signup_id; ?>"
                                                            data-email-type="signup_confirmation">
                                                        <?php esc_html_e('Resend confirmation', 'adoration-scheduler'); ?>
                                                    </button>

                                                    <button type="button"
                                                            class="button button-small as-inline-resend"
                                                            data-signup-id="<?php echo (int)$signup_id; ?>"
                                                            data-email-type="reminder_24h">
                                                        <?php esc_html_e('Send reminder now', 'adoration-scheduler'); ?>
                                                    </button>

                                                    <?php if (!empty($email) && is_email($email)): ?>
                                                        <button type="button"
                                                                class="button button-small as-inline-resend"
                                                                data-signup-id="<?php echo (int)$signup_id; ?>"
                                                                data-email-type="magic_link">
                                                            <?php esc_html_e('Send magic link', 'adoration-scheduler'); ?>
                                                        </button>
                                                    <?php endif; ?>

                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

<!-- Add Signup modal (unchanged) -->
<div id="adoration-signup-modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:999999;"></div>

<div id="adoration-signup-modal" style="display:none; position:fixed; top:8%; left:50%; transform:translateX(-50%); width:min(680px, 92vw); background:#fff; border:1px solid #c3c4c7; box-shadow:0 10px 30px rgba(0,0,0,.25); border-radius:8px; z-index:1000000;">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid #dcdcde;">
        <strong style="font-size:14px;"><?php esc_html_e('Add Signup', 'adoration-scheduler'); ?></strong>
        <button type="button" class="button" id="adoration-signup-modal-close"><?php esc_html_e('Close', 'adoration-scheduler'); ?></button>
    </div>

    <div style="padding:14px;">
        <p style="margin-top:0;">
            <span style="color:#646970;"><?php esc_html_e('Slot:', 'adoration-scheduler'); ?></span>
            <strong id="adoration-signup-slot-label">—</strong>
        </p>

        <form method="post" id="adoration-signup-form">
            <?php wp_nonce_field('adoration_admin_add_signup'); ?>
            <input type="hidden" name="adoration_admin_add_signup" value="1">
            <input type="hidden" name="slot_id" id="adoration_signup_slot_id" value="">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="adoration_signup_first"><?php esc_html_e('First name', 'adoration-scheduler'); ?></label></th>
                    <td><input type="text" name="first_name" id="adoration_signup_first" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="adoration_signup_last"><?php esc_html_e('Last name', 'adoration-scheduler'); ?></label></th>
                    <td><input type="text" name="last_name" id="adoration_signup_last" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="adoration_signup_email"><?php esc_html_e('Email', 'adoration-scheduler'); ?></label></th>
                    <td><input type="email" name="email" id="adoration_signup_email" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="adoration_signup_phone"><?php esc_html_e('Phone', 'adoration-scheduler'); ?></label></th>
                    <td>
                        <input type="text" name="phone" id="adoration_signup_phone" class="regular-text" required
                               placeholder="(555) 123-4567">
                        <p class="description"><?php esc_html_e('US numbers only; will be normalized to (###) ###-####.', 'adoration-scheduler'); ?></p>
                    </td>
                </tr>
            </table>

            <p style="margin-top:10px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Add Confirmed Signup', 'adoration-scheduler'); ?></button>
                <button type="button" class="button" id="adoration-signup-modal-cancel"><?php esc_html_e('Cancel', 'adoration-scheduler'); ?></button>
            </p>
        </form>
    </div>
</div>

<script>
(function() {
    const backdrop = document.getElementById('adoration-signup-modal-backdrop');
    const modal = document.getElementById('adoration-signup-modal');
    const closeBtn = document.getElementById('adoration-signup-modal-close');
    const cancelBtn = document.getElementById('adoration-signup-modal-cancel');

    const slotIdEl = document.getElementById('adoration_signup_slot_id');
    const slotLabelEl = document.getElementById('adoration-signup-slot-label');

    const firstEl = document.getElementById('adoration_signup_first');
    const phoneEl = document.getElementById('adoration_signup_phone');

    function normalizePhoneToDigits(raw) {
        return (raw || '').replace(/\D+/g, '');
    }

    function formatPhoneUS(raw) {
        let d = normalizePhoneToDigits(raw);
        if (d.length === 11 && d[0] === '1') d = d.slice(1);
        if (d.length !== 10) return null;
        return '(' + d.slice(0,3) + ') ' + d.slice(3,6) + '-' + d.slice(6);
    }

    function openModal(slotId, label) {
        slotIdEl.value = slotId || '';
        slotLabelEl.textContent = label || '—';

        document.getElementById('adoration-signup-form').reset();
        slotIdEl.value = slotId || '';

        backdrop.style.display = 'block';
        modal.style.display = 'block';
        setTimeout(() => { try { firstEl.focus(); } catch(e) {} }, 10);
    }

    function closeModal() {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
    }

    document.querySelectorAll('.adoration-signup-time').forEach(btn => {
        btn.addEventListener('click', function() {
            const slotId = btn.getAttribute('data-slot-id') || '';
            const label  = btn.getAttribute('data-slot-label') || '—';
            openModal(slotId, label);
        });
    });

    if (phoneEl) {
        phoneEl.addEventListener('blur', function() {
            const f = formatPhoneUS(phoneEl.value);
            if (f) phoneEl.value = f;
        });
    }

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

<!-- ✅ Inline resend handler (uses ajaxurl + AdminResendEmailAjaxService) -->
<script>
(function($){
    if (typeof ajaxurl === 'undefined') return;

    function asToast(message, type, sticky){
        message = (message || '').toString();
        type = (type || 'info').toString();
        sticky = !!sticky;

        // ✅ Preferred: ToastService bridge
        if (window.AdorationScheduler && typeof window.AdorationScheduler.toast === 'function') {
            window.AdorationScheduler.toast({ message: message, type: type, sticky: sticky });
            return;
        }

        // UIkit fallback (unlikely in wp-admin, but harmless)
        if (window.UIkit && typeof window.UIkit.notification === 'function') {
            var status = (type === 'success') ? 'success' :
                         (type === 'error')   ? 'danger'  :
                         (type === 'warning') ? 'warning' :
                         'primary';
            window.UIkit.notification({ message: message, status: status, timeout: sticky ? 0 : 3500 });
            return;
        }

        // WP notice fallback injected into page
        var cls = (type === 'success') ? 'notice-success' :
                  (type === 'error')   ? 'notice-error' :
                  (type === 'warning') ? 'notice-warning' :
                  'notice-info';

        var $wrap = $('#as-signups-tab-notices');
        if (!$wrap.length) {
            $wrap = $('<div id="as-signups-tab-notices"></div>');
            $wrap.insertBefore($('table.widefat').first());
        }

        var $n = $('<div class="notice '+cls+' is-dismissible"><p></p></div>');
        $n.find('p').text(message);
        $wrap.prepend($n);

        if (!sticky) {
            setTimeout(function(){
                $n.fadeOut(250, function(){ $(this).remove(); });
            }, 4000);
        }
    }

    $(document).on('click', '.as-inline-resend', function(e){
        e.preventDefault();

        var $btn = $(this);
        var signupId = parseInt($btn.data('signupId'), 10) || 0;
        var emailType = ($btn.data('emailType') || '').toString();

        if (!signupId || !emailType) return;

        var original = $btn.text();
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Sending…', 'adoration-scheduler')); ?>');

        $.post(ajaxurl, {
            action: 'adoration_signup_resend',
            signup_id: signupId,
            email_type: emailType,
            _ajax_nonce: <?php echo wp_json_encode($nonce_resend); ?>
        }).done(function(resp){
            $btn.prop('disabled', false).text(original);

            var ok = !!(resp && resp.success);
            var msg = (resp && resp.data && resp.data.message) ? resp.data.message :
                      (ok ? '<?php echo esc_js(__('Email sent.', 'adoration-scheduler')); ?>'
                          : '<?php echo esc_js(__('Send failed.', 'adoration-scheduler')); ?>');

            asToast(msg, ok ? 'success' : 'error', false);
        }).fail(function(){
            $btn.prop('disabled', false).text(original);
            asToast('<?php echo esc_js(__('Send failed.', 'adoration-scheduler')); ?>', 'error', false);
        });
    });

})(jQuery);
</script>
