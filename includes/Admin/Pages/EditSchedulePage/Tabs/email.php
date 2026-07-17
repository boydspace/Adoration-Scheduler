<?php
/**
 * Tab: Email (Schedule-specific)
 *
 * Expected variables in scope:
 * - $schedule (array)
 * - $schedule_id (int)
 */

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Services\EmailService;

$schedulesRepo = new SchedulesRepository();
$emailService  = new EmailService();

$notice = '';

// Build a self URL that keeps you on this schedule + this tab after POST.
$current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

$self_url = add_query_arg([
    'page'        => $current_page_slug,
    'action'      => 'edit',
    'schedule_id' => (int)$schedule_id,
    'tab'         => 'email',
], admin_url('admin.php'));

// Load schedule-specific templates/settings
$block = $schedulesRepo->get_email_templates((int)$schedule_id);
$enabled = !empty($block['enabled']);
$saved_templates = is_array($block['templates'] ?? null) ? $block['templates'] : [];

// Helper: get a field with fallback
$getv = function(string $key, string $default = '') use (&$saved_templates): string {
    $val = $saved_templates[$key] ?? $default;
    return is_string($val) ? $val : (string)$val;
};

/**
 * Light Reply-To validator:
 * Allow:
 *  - blank
 *  - email@domain.com
 *  - Name <email@domain.com>
 */
$reply_to_is_valid = function(string $value): bool {
    $value = trim($value);
    if ($value === '') return true;

    if (preg_match('/<([^>]+)>/', $value, $m)) {
        $inner = trim($m[1]);
        return is_email($inner);
    }

    if (strpos($value, '@') !== false) {
        return is_email($value);
    }

    return true;
};

/**
 * IMPORT GLOBAL templates into this schedule (does not force-enable).
 *
 * Global option key: adoration_scheduler_email_templates
 * - global reply_to_email => schedule reply_to
 */
if ( isset($_POST['adoration_import_schedule_email_templates']) ) {
    check_admin_referer('adoration_import_schedule_email_templates');

    $global = get_option('adoration_scheduler_email_templates', []);
    if (!is_array($global)) $global = [];

    $imported = [
        'from_name'  => (string)($global['from_name'] ?? ''),
        'from_email' => (string)($global['from_email'] ?? ''),
        'reply_to'   => (string)($global['reply_to'] ?? ($global['reply_to_email'] ?? '')),

        'signup_confirmation_subject' => (string)($global['signup_confirmation_subject'] ?? ''),
        'signup_confirmation_body'    => (string)($global['signup_confirmation_body'] ?? ''),

        'reminder_24h_subject' => (string)($global['reminder_24h_subject'] ?? ''),
        'reminder_24h_body'    => (string)($global['reminder_24h_body'] ?? ''),
    ];

    $from_email = trim((string)$imported['from_email']);
    if ($from_email !== '' && !is_email($from_email)) {
        $notice = '<div class="notice notice-error"><p>Global From Email is not a valid email address. Fix it in Global Email Templates, then import again.</p></div>';
    } elseif (!$reply_to_is_valid((string)$imported['reply_to'])) {
        $notice = '<div class="notice notice-error"><p>Global Reply-To is invalid. Fix it in Global Email Templates, then import again.</p></div>';
    } else {
        // Keep current enabled setting; overwrite templates.
        $ok = $schedulesRepo->save_email_templates((int)$schedule_id, $imported, (bool)$enabled);

        if ($ok) {
            $notice = '<div class="notice notice-success"><p>Imported global email templates into this schedule.</p></div>';

            $block = $schedulesRepo->get_email_templates((int)$schedule_id);
            $enabled = !empty($block['enabled']);
            $saved_templates = is_array($block['templates'] ?? null) ? $block['templates'] : [];
        } else {
            $notice = '<div class="notice notice-error"><p>Failed to import global templates into this schedule.</p></div>';
        }
    }
}

/**
 * SAVE schedule-specific templates
 */
if ( isset($_POST['adoration_save_schedule_email_templates']) ) {
    check_admin_referer('adoration_save_schedule_email_templates');

    $enabled_post = !empty($_POST['enabled']);

    $templates = [
        'from_name'  => sanitize_text_field($_POST['from_name'] ?? ''),
        'from_email' => sanitize_email($_POST['from_email'] ?? ''),
        'reply_to'   => trim((string)($_POST['reply_to'] ?? '')),

        'signup_confirmation_subject' => sanitize_text_field($_POST['signup_confirmation_subject'] ?? ''),
        'reminder_24h_subject'        => sanitize_text_field($_POST['reminder_24h_subject'] ?? ''),
    ];

    $templates['signup_confirmation_body'] = wp_kses_post($_POST['signup_confirmation_body'] ?? '');
    $templates['reminder_24h_body']        = wp_kses_post($_POST['reminder_24h_body'] ?? '');

    if ($templates['from_email'] !== '' && !is_email($templates['from_email'])) {
        $notice = '<div class="notice notice-error"><p>From Email must be a valid email address (or blank).</p></div>';
    } elseif (!$reply_to_is_valid($templates['reply_to'])) {
        $notice = '<div class="notice notice-error"><p>Reply-To must be blank, a valid email, or Name &lt;email&gt;.</p></div>';
    } else {
        $ok = $schedulesRepo->save_email_templates((int)$schedule_id, $templates, (bool)$enabled_post);

        if ($ok) {
            $notice = '<div class="notice notice-success"><p>Schedule email templates saved.</p></div>';

            $block = $schedulesRepo->get_email_templates((int)$schedule_id);
            $enabled = !empty($block['enabled']);
            $saved_templates = is_array($block['templates'] ?? null) ? $block['templates'] : [];
        } else {
            $notice = '<div class="notice notice-error"><p>Failed to save schedule email templates.</p></div>';
        }
    }
}

/**
 * SEND TEST email (schedule-specific if enabled)
 */
if ( isset($_POST['adoration_send_schedule_test_email']) ) {
    check_admin_referer('adoration_send_schedule_test_email');

    $which = sanitize_text_field($_POST['which'] ?? 'signup_confirmation');
    $to    = sanitize_email($_POST['to'] ?? '');

    if ($to === '' || !is_email($to)) {
        $notice = '<div class="notice notice-error"><p>Please enter a valid test recipient email address.</p></div>';
    } else {
        $ok = $emailService->send_test_template($which, $to, (int)$schedule_id);

        $notice = $ok
            ? '<div class="notice notice-success"><p>Test email sent (check inbox/spam). Subject/body will reflect this schedule\'s templates if enabled.</p></div>'
            : '<div class="notice notice-error"><p>Test email failed to send. Check your mail configuration and debug logs.</p></div>';
    }
}
?>

<h2><?php esc_html_e('Email Notifications', 'adoration-scheduler'); ?></h2>

<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<p class="description">
    <?php esc_html_e('Customize the signup confirmation and reminder emails for this specific schedule.', 'adoration-scheduler'); ?>
</p>

<!-- SAVE FORM (only SAVE nonce lives here) -->
<form method="post" action="<?php echo esc_url($self_url); ?>" style="max-width: 980px;">
    <?php wp_nonce_field('adoration_save_schedule_email_templates'); ?>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('Enable schedule-specific emails', 'adoration-scheduler'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>>
                    <?php esc_html_e('Use these templates for this schedule (otherwise global templates are used)', 'adoration-scheduler'); ?>
                </label>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="from_name"><?php esc_html_e('From Name', 'adoration-scheduler'); ?></label></th>
            <td>
                <input type="text" id="from_name" name="from_name" class="regular-text"
                       value="<?php echo esc_attr($getv('from_name')); ?>"
                       placeholder="<?php echo esc_attr(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)); ?>">
                <p class="description"><?php esc_html_e('Optional. Leave blank to use the site name.', 'adoration-scheduler'); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="from_email"><?php esc_html_e('From Email', 'adoration-scheduler'); ?></label></th>
            <td>
                <input type="email" id="from_email" name="from_email" class="regular-text"
                       value="<?php echo esc_attr($getv('from_email')); ?>"
                       placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                <p class="description"><?php esc_html_e('Optional. Leave blank to use the site admin email.', 'adoration-scheduler'); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="reply_to"><?php esc_html_e('Reply-To', 'adoration-scheduler'); ?></label></th>
            <td>
                <input type="text" id="reply_to" name="reply_to" class="regular-text"
                       value="<?php echo esc_attr($getv('reply_to')); ?>"
                       placeholder="<?php echo esc_attr__('Optional: email or Name <email>', 'adoration-scheduler'); ?>">
            </td>
        </tr>
    </table>

    <hr />

    <h3 style="margin-top: 18px;"><?php esc_html_e('Signup Confirmation Email', 'adoration-scheduler'); ?></h3>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="signup_confirmation_subject"><?php esc_html_e('Subject', 'adoration-scheduler'); ?></label></th>
            <td>
                <input type="text" id="signup_confirmation_subject" name="signup_confirmation_subject" class="large-text"
                       value="<?php echo esc_attr($getv('signup_confirmation_subject')); ?>">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="signup_confirmation_body"><?php esc_html_e('Body', 'adoration-scheduler'); ?></label></th>
            <td>
                <textarea id="signup_confirmation_body" name="signup_confirmation_body" class="large-text code" rows="10"><?php
                    echo esc_textarea($getv('signup_confirmation_body'));
                ?></textarea>
            </td>
        </tr>
    </table>

    <hr />

    <h3 style="margin-top: 18px;"><?php esc_html_e('Reminder Email (24 hours)', 'adoration-scheduler'); ?></h3>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="reminder_24h_subject"><?php esc_html_e('Subject', 'adoration-scheduler'); ?></label></th>
            <td>
                <input type="text" id="reminder_24h_subject" name="reminder_24h_subject" class="large-text"
                       value="<?php echo esc_attr($getv('reminder_24h_subject')); ?>">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="reminder_24h_body"><?php esc_html_e('Body', 'adoration-scheduler'); ?></label></th>
            <td>
                <textarea id="reminder_24h_body" name="reminder_24h_body" class="large-text code" rows="10"><?php
                    echo esc_textarea($getv('reminder_24h_body'));
                ?></textarea>
            </td>
        </tr>
    </table>

    <p style="display:flex; gap:10px; align-items:center;">
        <button type="submit" name="adoration_save_schedule_email_templates" class="button button-primary">
            <?php esc_html_e('Save Schedule Email Templates', 'adoration-scheduler'); ?>
        </button>
    </p>
</form>

<!-- IMPORT FORM (separate form, separate nonce, no fields) -->
<form method="post" action="<?php echo esc_url($self_url); ?>" style="max-width: 980px; margin-top: 10px;">
    <?php wp_nonce_field('adoration_import_schedule_email_templates'); ?>
    <p>
        <button type="submit"
                name="adoration_import_schedule_email_templates"
                class="button"
                onclick="return confirm('Import global templates into this schedule? This will overwrite the schedule’s current subject/body fields.');">
            <?php esc_html_e('Import Global Defaults', 'adoration-scheduler'); ?>
        </button>
        <span class="description" style="margin-left:8px;">
            <?php esc_html_e('Copies the global email templates into this schedule so you can tweak them.', 'adoration-scheduler'); ?>
        </span>
    </p>
</form>

<hr />

<h3><?php esc_html_e('Send a Test Email (this schedule)', 'adoration-scheduler'); ?></h3>

<form method="post" action="<?php echo esc_url($self_url); ?>" style="max-width: 980px;">
    <?php wp_nonce_field('adoration_send_schedule_test_email'); ?>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="which"><?php esc_html_e('Template', 'adoration-scheduler'); ?></label></th>
            <td>
                <select name="which" id="which">
                    <option value="signup_confirmation"><?php esc_html_e('Signup Confirmation', 'adoration-scheduler'); ?></option>
                    <option value="reminder_24h"><?php esc_html_e('Reminder (24 hours)', 'adoration-scheduler'); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="to"><?php esc_html_e('Send to', 'adoration-scheduler'); ?></label></th>
            <td>
                <input type="email" name="to" id="to" class="regular-text" required
                       placeholder="<?php echo esc_attr__('you@example.com', 'adoration-scheduler'); ?>">
                <p class="description">
                    <?php
                    echo $enabled
                        ? esc_html__('Schedule-specific templates are ENABLED, so this test will use the templates on this tab.', 'adoration-scheduler')
                        : esc_html__('Schedule-specific templates are DISABLED, so this test will fall back to the global templates.', 'adoration-scheduler');
                    ?>
                </p>
            </td>
        </tr>
    </table>

    <p>
        <button type="submit" name="adoration_send_schedule_test_email" class="button">
            <?php esc_html_e('Send Test Email', 'adoration-scheduler'); ?>
        </button>
    </p>
</form>

<p class="description" style="color:#646970;">
    <?php printf(esc_html__('Schedule ID: %d', 'adoration-scheduler'), (int)$schedule_id); ?>
</p>
