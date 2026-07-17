<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SignupAuditRepository;
use AdorationScheduler\Core\Plugin;

class SignupsPage {

    /**
     * Nonces for modal + save + resend.
     */
    private const NONCE_MODAL  = 'as_signup_modal';
    private const NONCE_SAVE   = 'as_signup_save';
    private const NONCE_RESEND = 'as_signup_resend';

    public static function register_actions(): void {

        /**
         * IMPORTANT:
         * - Cancel/Delete admin-post actions are now owned by:
         *   \AdorationScheduler\Services\AdminSignupActionsService
         *
         * - Resend AJAX is now owned by:
         *   \AdorationScheduler\Services\AdminResendEmailAjaxService
         *
         * So SignupsPage ONLY owns modal + save.
         */

        // ✅ Modal (admin-ajax)
        add_action('wp_ajax_adoration_signup_modal',  [__CLASS__, 'ajax_modal']);
        add_action('wp_ajax_adoration_signup_save',   [__CLASS__, 'ajax_save']);

        // ❌ DO NOT register resend here (avoid duplicates)
        // add_action('wp_ajax_adoration_signup_resend', [__CLASS__, 'ajax_resend_email']);
    }

    // ---------------------------------------------------------------------
    // Redirect helpers (support "return" param from other admin pages/tabs)
    // ---------------------------------------------------------------------

    /**
     * Resolve a safe return URL from request param "return".
     * Falls back to the main Signups page.
     */
    private static function resolve_return_url(): string {
        $fallback = admin_url('admin.php?page=adoration_scheduler_signups');

        $raw = $_REQUEST['return'] ?? '';
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        $raw = trim($raw);

        if ($raw === '') {
            return $fallback;
        }

        // Sanitize, then validate redirect target (prevents external redirects).
        $candidate = esc_url_raw($raw);
        $safe = wp_validate_redirect($candidate, $fallback);

        // Extra hardening: only allow admin URLs (keeps it inside wp-admin).
        $admin_base = admin_url();
        if (strpos($safe, $admin_base) !== 0) {
            return $fallback;
        }

        return $safe;
    }

    /**
     * Redirect back to return URL (if provided) with toast args, otherwise Signups page.
     */
    private static function redirect_with_toast(string $msg, string $type = 'success'): void {
        $base = self::resolve_return_url();

        $url = add_query_arg([
            'as_toast'      => rawurlencode($msg),
            'as_toast_type' => $type,
        ], $base);

        wp_safe_redirect($url);
        exit;
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    /**
     * Best-effort audit logger (must never affect primary behavior).
     */
    private static function audit_log(int $signup_id, string $event_type, array $meta = []): void {
        if ($signup_id <= 0) return;
        if (!class_exists(SignupAuditRepository::class)) return;

        try {
            $repo = new SignupAuditRepository();

            $actor_user_id = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
            if ($actor_user_id <= 0) $actor_user_id = null;

            $actor_label = null;
            if ($actor_user_id && method_exists($repo, 'build_actor_label')) {
                $actor_label = $repo->build_actor_label((int)$actor_user_id);
            }

            $repo->log((int)$signup_id, (string)$event_type, is_array($meta) ? $meta : [], $actor_user_id, $actor_label);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Audit log failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Page render
    // ---------------------------------------------------------------------

    public function render(): void {
        // ✅ Use granular cap with fallback so Editors can access if granted.
        if ( ! Plugin::current_user_can_with_fallback('adoration_manage_signups') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        // ✅ Thickbox for modal UI
        wp_enqueue_script('jquery');
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');

        // Load table class
        $table_file = plugin_dir_path(__FILE__) . '../Tables/SignupsListTable.php';
        if (file_exists($table_file)) {
            require_once $table_file;
        }

        $class = '\\AdorationScheduler\\Admin\\Tables\\SignupsListTable';
        if (!class_exists($class)) {
            wp_die('Missing table class: ' . esc_html($class), 500);
        }

        /** @var \WP_List_Table $table */
        $table = new $class();
        $table->prepare_items();

        // Nonces for JS
        $nonce_modal  = wp_create_nonce(self::NONCE_MODAL);
        $nonce_save   = wp_create_nonce(self::NONCE_SAVE);
        $nonce_resend = wp_create_nonce(self::NONCE_RESEND);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Signups', 'adoration-scheduler') . '</h1>';

        // ✅ Use POST so bulk actions are safe + nonce-protected (WP_List_Table expects this)
        echo '<form method="post">';
        echo '<input type="hidden" name="page" value="adoration_scheduler_signups" />';

        // Preserve current GET params (sorting/search/filters) when bulk posting
        $preserve = ['s','orderby','order','status','schedule_id','paged'];
        foreach ($preserve as $k) {
            if (isset($_GET[$k])) {
                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string)wp_unslash($_GET[$k])) . '" />';
            }
        }

        $table->search_box(__('Search', 'adoration-scheduler'), 'adoration-signups-search');
        $table->display();
        echo '</form>';

        /**
         * ✅ Hidden Thickbox inline modal container
         * We fill #as-signup-modal-body via AJAX.
         */
        echo '<div id="as-signup-modal" style="display:none;">';
        echo '  <div id="as-signup-modal-body" style="padding:14px;">' . esc_html__('Loading…', 'adoration-scheduler') . '</div>';
        echo '</div>';

        // ✅ Inline JS for opening + saving + resending from modal
        ?>
        <script>
        (function($){

            function asToast(message, type, sticky){
                message = (message || '').toString().trim();
                type = (type || 'info').toString().trim();
                sticky = !!sticky;

                if (!message) return;

                // Preferred: ToastService bridge
                if (window.AdorationScheduler && typeof window.AdorationScheduler.toast === 'function') {
                    window.AdorationScheduler.toast({ message: message, type: type, sticky: sticky });
                    return;
                }

                // Fallback: inline notice inside the modal
                var cls = (type === 'success') ? 'notice-success' :
                          (type === 'error')   ? 'notice-error' :
                          (type === 'warning') ? 'notice-warning' :
                          'notice-info';

                var safe = $('<div/>').text(message).html();
                $('#as-signup-resend-msg').html('<div class="notice '+cls+' inline"><p>' + safe + '</p></div>');
            }

            function openSignupModal(signupId){
                tb_show(
                    <?php echo json_encode(__('View / Edit Signup', 'adoration-scheduler')); ?>,
                    '#TB_inline?width=760&height=560&inlineId=as-signup-modal'
                );

                $('#as-signup-modal-body').html('<?php echo esc_js(__('Loading…', 'adoration-scheduler')); ?>');

                $.post(ajaxurl, {
                    action: 'adoration_signup_modal',
                    signup_id: signupId,
                    _ajax_nonce: <?php echo json_encode($nonce_modal); ?>
                }).done(function(resp){
                    if(resp && resp.success && resp.data && resp.data.html){
                        $('#as-signup-modal-body').html(resp.data.html);
                    } else {
                        $('#as-signup-modal-body').html('<?php echo esc_js(__('Failed to load signup.', 'adoration-scheduler')); ?>');
                    }
                }).fail(function(){
                    $('#as-signup-modal-body').html('<?php echo esc_js(__('Failed to load signup.', 'adoration-scheduler')); ?>');
                });
            }

            // Click handler on "View/Edit"
            $(document).on('click', 'a.as-signup-edit', function(e){
                e.preventDefault();
                var id = parseInt($(this).data('signupId'), 10) || 0;
                if(id > 0) openSignupModal(id);
            });

            // Save handler inside modal
            $(document).on('submit', '#as-signup-edit-form', function(e){
                e.preventDefault();

                var $form = $(this);
                var $btn  = $form.find('button[type="submit"]');
                var data  = $form.serializeArray();

                data.push({ name: 'action', value: 'adoration_signup_save' });
                data.push({ name: '_ajax_nonce', value: <?php echo json_encode($nonce_save); ?> });

                $btn.prop('disabled', true);

                $.post(ajaxurl, $.param(data)).done(function(resp){
                    $btn.prop('disabled', false);

                    if(resp && resp.success){
                        var msg = (resp.data && resp.data.message) ? resp.data.message : 'Saved.';
                        $('#as-signup-save-msg').html('<div class="notice notice-success inline"><p>' + msg + '</p></div>');
                        window.location.reload();
                        return;
                    }

                    var msg2 = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Save failed.', 'adoration-scheduler')); ?>';
                    $('#as-signup-save-msg').html('<div class="notice notice-error inline"><p>' + msg2 + '</p></div>');
                }).fail(function(){
                    $btn.prop('disabled', false);
                    $('#as-signup-save-msg').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Save failed.', 'adoration-scheduler')); ?></p></div>');
                });
            });

            // Resend handler (buttons inside modal)
            $(document).on('click', '.as-resend-email', function(e){
                e.preventDefault();

                var $btn = $(this);

                /**
                 * ✅ IMPORTANT FIX:
                 * Thickbox injects HTML dynamically; jQuery .data() can be unreliable
                 * depending on caching timing. Reading raw attributes is consistent.
                 */
                var signupId  = parseInt($btn.attr('data-signup-id'), 10) || 0;
                var emailType = ($btn.attr('data-email-type') || '').toString().trim();

                if(!signupId || !emailType){
                    asToast('<?php echo esc_js(__('Invalid resend request (missing data attributes).', 'adoration-scheduler')); ?>', 'error', false);
                    return;
                }

                // UI lock
                var original = $btn.text();
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Sending…', 'adoration-scheduler')); ?>');

                $('#as-signup-resend-msg').html('');

                $.post(ajaxurl, {
                    action: 'adoration_signup_resend',
                    signup_id: signupId,
                    email_type: emailType,
                    _ajax_nonce: <?php echo json_encode($nonce_resend); ?>
                }).done(function(resp){
                    $btn.prop('disabled', false).text(original);

                    if(resp && resp.success){
                        var msg = (resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Email sent.', 'adoration-scheduler')); ?>';
                        asToast(msg, 'success', false);
                        return;
                    }

                    var msg2 = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Send failed.', 'adoration-scheduler')); ?>';
                    asToast(msg2, 'error', false);
                }).fail(function(){
                    $btn.prop('disabled', false).text(original);
                    asToast('<?php echo esc_js(__('Send failed.', 'adoration-scheduler')); ?>', 'error', false);
                });
            });

        })(jQuery);
        </script>
        <?php

        echo '</div>';
    }

    // ---------------------------------------------------------------------
    // AJAX: modal
    // ---------------------------------------------------------------------

    public static function ajax_modal(): void {
        if ( ! Plugin::current_user_can_with_fallback('adoration_manage_signups') ) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer(self::NONCE_MODAL);

        $signup_id = isset($_POST['signup_id']) ? (int) $_POST['signup_id'] : 0;
        if ($signup_id <= 0) {
            wp_send_json_error(['message' => 'Invalid signup_id'], 400);
        }

        global $wpdb;

        $t_signups   = $wpdb->prefix . 'adoration_signups';
        $t_persons   = $wpdb->prefix . 'adoration_persons';
        $t_slots     = $wpdb->prefix . 'adoration_slots';
        $t_schedules = $wpdb->prefix . 'adoration_schedules';

        $sql = "
            SELECT
                su.id,
                su.status,
                su.is_active,
                su.created_at,
                su.updated_at,
                su.person_id,
                su.slot_id,
                su.schedule_id,
                TRIM(CONCAT(TRIM(COALESCE(p.first_name,'')), ' ', TRIM(COALESCE(p.last_name,'')))) AS person_name,
                p.first_name AS first_name,
                p.last_name AS last_name,
                p.email AS person_email,
                sc.name AS schedule_name,
                CASE
                    WHEN sl.start_at IS NOT NULL AND sl.start_at <> '0000-00-00 00:00:00'
                        THEN DATE_FORMAT(sl.start_at, '%%Y-%%m-%%d %%H:%%i')
                    ELSE CONCAT(sl.date, ' ', LEFT(sl.start_time,5))
                END AS slot_label,
                sl.date AS slot_date,
                sl.start_time AS slot_start,
                sl.end_time AS slot_end
            FROM {$t_signups} su
            LEFT JOIN {$t_persons} p ON p.id = su.person_id
            LEFT JOIN {$t_schedules} sc ON sc.id = su.schedule_id
            LEFT JOIN {$t_slots} sl ON sl.id = su.slot_id
            WHERE su.id = %d
            LIMIT 1
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row($wpdb->prepare($sql, $signup_id), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Signup not found'], 404);
        }

        // ✅ Fetch audit trail (best-effort)
        $audit = [];
        if (class_exists(SignupAuditRepository::class)) {
            try {
                $audit = (new SignupAuditRepository())->get_for_signup($signup_id, 50);
            } catch (\Throwable $e) {
                $audit = [];
            }
        }

        $person_name = trim((string)($row['person_name'] ?? ''));
        if ($person_name === '') $person_name = __('(Unknown)', 'adoration-scheduler');

        $person_email  = (string)($row['person_email'] ?? '');
        $schedule_name = (string)($row['schedule_name'] ?? '');
        $slot_label    = (string)($row['slot_label'] ?? '');

        $status = sanitize_key((string)($row['status'] ?? ''));
        if ($status === '') $status = 'confirmed';

        $is_active = (int)($row['is_active'] ?? 0) ? 1 : 0;

        $status_options = [
            'confirmed' => __('Confirmed', 'adoration-scheduler'),
            'pending'   => __('Pending', 'adoration-scheduler'),
            'cancelled' => __('Cancelled', 'adoration-scheduler'),
        ];

        ob_start();
        ?>
        <div id="as-signup-save-msg"></div>
        <div id="as-signup-resend-msg"></div>

        <h2 style="margin-top:0;"><?php echo esc_html__('Signup Details', 'adoration-scheduler'); ?></h2>

        <table class="widefat striped" style="margin-bottom:12px;">
            <tbody>
                <tr>
                    <th style="width:180px;"><?php echo esc_html__('Person', 'adoration-scheduler'); ?></th>
                    <td>
                        <?php echo esc_html($person_name); ?>
                        <?php if ($person_email !== ''): ?>
                            <br><a href="mailto:<?php echo esc_attr($person_email); ?>"><?php echo esc_html($person_email); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php echo esc_html__('Schedule', 'adoration-scheduler'); ?></th>
                    <td><?php echo esc_html($schedule_name); ?></td>
                </tr>
                <tr>
                    <th><?php echo esc_html__('Slot', 'adoration-scheduler'); ?></th>
                    <td><?php echo esc_html($slot_label); ?></td>
                </tr>
                <tr>
                    <th><?php echo esc_html__('Created', 'adoration-scheduler'); ?></th>
                    <td><?php echo esc_html((string)($row['created_at'] ?? '')); ?></td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin:12px 0 8px;"><?php echo esc_html__('Audit Trail', 'adoration-scheduler'); ?></h2>
        <table class="widefat striped" style="margin-bottom:12px;">
            <tbody>
                <?php if (empty($audit)): ?>
                    <tr>
                        <td><?php echo esc_html__('No audit events yet.', 'adoration-scheduler'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($audit as $ev): ?>
                        <tr>
                            <td style="width:170px;"><?php echo esc_html((string)($ev['created_at'] ?? '')); ?></td>
                            <td style="width:180px;"><?php echo esc_html((string)($ev['event_type'] ?? '')); ?></td>
                            <td style="width:180px;"><?php echo esc_html((string)($ev['actor_label'] ?? '')); ?></td>
                            <td>
                                <?php
                                $meta = $ev['meta'] ?? [];
                                if (is_array($meta) && !empty($meta)) {
                                    echo '<code>' . esc_html(wp_json_encode($meta)) . '</code>';
                                } else {
                                    echo '&nbsp;';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 style="margin:0 0 8px;"><?php echo esc_html__('Edit', 'adoration-scheduler'); ?></h2>

        <form id="as-signup-edit-form">
            <input type="hidden" name="signup_id" value="<?php echo (int)$signup_id; ?>" />

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="as_status"><?php echo esc_html__('Status', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <select name="status" id="as_status">
                                <?php foreach ($status_options as $k => $label): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($status, $k); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html__('Changing to Cancelled will automatically deactivate the signup.', 'adoration-scheduler'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="as_is_active"><?php echo esc_html__('Active', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" id="as_is_active" value="1" <?php checked($is_active, 1); ?> />
                                <?php echo esc_html__('This signup counts as active.', 'adoration-scheduler'); ?>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:12px;">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Save Changes', 'adoration-scheduler'); ?></button>
                <button type="button" class="button" onclick="tb_remove();"><?php echo esc_html__('Close', 'adoration-scheduler'); ?></button>
            </p>
        </form>

        <hr style="margin:16px 0;">

        <h2 style="margin:0 0 8px;"><?php echo esc_html__('Resend Emails', 'adoration-scheduler'); ?></h2>
        <p class="description" style="margin-top:0;">
            <?php echo esc_html__('These actions send the email again and will appear in Email Log.', 'adoration-scheduler'); ?>
        </p>

        <p style="margin:10px 0 0;">
            <button type="button"
                    class="button as-resend-email"
                    data-signup-id="<?php echo (int)$signup_id; ?>"
                    data-email-type="signup_confirmation">
                <?php echo esc_html__('Resend Confirmation', 'adoration-scheduler'); ?>
            </button>

            <button type="button"
                    class="button as-resend-email"
                    data-signup-id="<?php echo (int)$signup_id; ?>"
                    data-email-type="reminder_24h">
                <?php echo esc_html__('Resend 24h Reminder', 'adoration-scheduler'); ?>
            </button>

            <?php if ($person_email !== '' && is_email($person_email)): ?>
                <button type="button"
                        class="button as-resend-email"
                        data-signup-id="<?php echo (int)$signup_id; ?>"
                        data-email-type="magic_link">
                    <?php echo esc_html__('Send Magic Link', 'adoration-scheduler'); ?>
                </button>
            <?php endif; ?>
        </p>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    // ---------------------------------------------------------------------
    // AJAX: save
    // ---------------------------------------------------------------------

    public static function ajax_save(): void {
        if ( ! Plugin::current_user_can_with_fallback('adoration_manage_signups') ) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer(self::NONCE_SAVE);

        $signup_id = isset($_POST['signup_id']) ? (int) $_POST['signup_id'] : 0;
        if ($signup_id <= 0) {
            wp_send_json_error(['message' => 'Invalid signup_id'], 400);
        }

        $status = isset($_POST['status']) ? sanitize_key((string)wp_unslash($_POST['status'])) : 'confirmed';
        if ($status === '') $status = 'confirmed';

        $allowed = ['confirmed','pending','cancelled'];
        if (!in_array($status, $allowed, true)) {
            wp_send_json_error(['message' => 'Invalid status'], 400);
        }

        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Business rule: cancelled => inactive
        if ($status === 'cancelled') {
            $is_active = 0;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'adoration_signups';

        // Old status for audit (best-effort)
        $old_status = '';
        try {
            $old_status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$t} WHERE id = %d", $signup_id));
        } catch (\Throwable $e) {
            $old_status = '';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r = $wpdb->update(
            $t,
            [
                'status'     => $status,
                'is_active'  => $is_active,
                'updated_at' => current_time('mysql'),
            ],
            [ 'id' => $signup_id ],
            [ '%s','%d','%s' ],
            [ '%d' ]
        );

        if ($r === false) {
            wp_send_json_error(['message' => 'Save failed: ' . $wpdb->last_error], 500);
        }

        // ✅ Audit: status changed (only if it actually changed)
        if ($old_status !== $status) {
            self::audit_log($signup_id, 'status_changed', [
                'from'      => ($old_status !== '' ? $old_status : null),
                'to'        => $status,
                'is_active' => (int)$is_active,
                'context'   => 'admin_modal_save',
            ]);
        }

        wp_send_json_success(['message' => __('Saved.', 'adoration-scheduler')]);
    }
}
