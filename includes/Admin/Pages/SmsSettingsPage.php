<?php
namespace AdorationScheduler\Admin\Pages;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Services\SmsService;
use AdorationScheduler\Utils\PhoneNumberFormatter;

/**
 * "SMS Reminders" settings — Twilio credentials + the 24h reminder message
 * template. Built on the same WordPress Settings API pattern as
 * CoverageAlertsSettingsPage (one options array, defaults()/sanitize_options()/
 * get_options()), and the same unmasked-password-field precedent as
 * AntiSpamSettingsPage's Turnstile secret key — no encryption, no
 * "leave blank to keep existing" dance, consistent with how this plugin
 * already stores its one other third-party secret.
 *
 * Read by SmsService, which is what ReminderScheduler::send_reminder()
 * actually calls.
 */
class SmsSettingsPage {

    private const OPTION_GROUP = 'adoration_scheduler_sms';
    public const OPTION_NAME   = 'adoration_scheduler_sms_options';
    private const PAGE_SLUG    = 'adoration_scheduler_sms';

    private static bool $did_register_settings = false;

    public static function register(): void {
        add_action('admin_post_adoration_scheduler_send_test_sms', [__CLASS__, 'handle_send_test']);

        if (did_action('admin_init') > 0) {
            self::register_settings();
            return;
        }

        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_settings(): void {
        if (self::$did_register_settings) return;
        self::$did_register_settings = true;

        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
            'default'           => self::defaults(),
        ]);

        add_settings_section(
            'adoration_sms_twilio',
            __('Twilio', 'adoration-scheduler'),
            [__CLASS__, 'section_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'enabled',
            __('Enable SMS Reminders', 'adoration-scheduler'),
            [__CLASS__, 'field_enabled'],
            self::PAGE_SLUG,
            'adoration_sms_twilio'
        );

        add_settings_field(
            'twilio_account_sid',
            __('Account SID', 'adoration-scheduler'),
            [__CLASS__, 'field_account_sid'],
            self::PAGE_SLUG,
            'adoration_sms_twilio'
        );

        add_settings_field(
            'twilio_auth_token',
            __('Auth Token', 'adoration-scheduler'),
            [__CLASS__, 'field_auth_token'],
            self::PAGE_SLUG,
            'adoration_sms_twilio'
        );

        add_settings_field(
            'twilio_from_number',
            __('From Number', 'adoration-scheduler'),
            [__CLASS__, 'field_from_number'],
            self::PAGE_SLUG,
            'adoration_sms_twilio'
        );

        add_settings_field(
            'reminder_sms_body',
            __('Reminder Message', 'adoration-scheduler'),
            [__CLASS__, 'field_reminder_sms_body'],
            self::PAGE_SLUG,
            'adoration_sms_twilio'
        );
    }

    public static function defaults(): array {
        return [
            'enabled'            => 0,
            'twilio_account_sid' => '',
            'twilio_auth_token'  => '',
            'twilio_from_number' => '',
            'reminder_sms_body'  => "Reminder: you're signed up for {schedule_title} tomorrow, {slot_date} {slot_start}. — {title_first_name}",
        ];
    }

    public static function get_options(): array {
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    /**
     * True only when SMS is turned on AND all three Twilio credentials are
     * present — this is what actually gates a send attempt, so a
     * half-filled-in settings page (enabled, but no Auth Token yet) never
     * causes a broken API call instead of just quietly not sending.
     */
    public static function is_configured(): bool {
        $o = self::get_options();
        return !empty($o['enabled'])
            && trim((string)$o['twilio_account_sid']) !== ''
            && trim((string)$o['twilio_auth_token']) !== ''
            && trim((string)$o['twilio_from_number']) !== '';
    }

    public static function sanitize_options($opts): array {
        $opts = is_array($opts) ? $opts : [];
        $defaults = self::defaults();

        $from_number = trim((string)($opts['twilio_from_number'] ?? ''));
        if ($from_number !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $from_number)) {
            // Invalid input is dropped rather than saved malformed — same
            // posture as CoverageAlertsSettingsPage's alert_email handling.
            $from_number = '';
        }

        return [
            'enabled'            => !empty($opts['enabled']) ? 1 : 0,
            'twilio_account_sid' => sanitize_text_field((string)($opts['twilio_account_sid'] ?? '')),
            'twilio_auth_token'  => sanitize_text_field((string)($opts['twilio_auth_token'] ?? '')),
            'twilio_from_number' => $from_number,
            'reminder_sms_body'  => sanitize_textarea_field((string)($opts['reminder_sms_body'] ?? $defaults['reminder_sms_body'])),
        ];
    }

    public static function section_intro(): void {
        echo '<p style="max-width:900px;">' .
            esc_html__('Makes text-message reminders (via Twilio) available at this parish, alongside the existing email reminder. This only turns SMS ON as an option — each person still separately chooses whether they want it on their own "Reminder Preferences" dashboard widget; enabling this does not start texting anyone by itself.', 'adoration-scheduler') .
        '</p>';
    }

    public static function field_enabled(): void {
        $o = self::get_options();
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[enabled]"
                   value="1" <?php checked(1, (int)$o['enabled']); ?>>
            <?php esc_html_e('Make SMS reminders available (people still choose it themselves)', 'adoration-scheduler'); ?>
        </label>
        <?php
    }

    public static function field_account_sid(): void {
        $o = self::get_options();
        ?>
        <input type="text" class="regular-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[twilio_account_sid]"
               value="<?php echo esc_attr((string)$o['twilio_account_sid']); ?>"
               placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
        <?php
    }

    public static function field_auth_token(): void {
        $o = self::get_options();
        ?>
        <input type="password" class="regular-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[twilio_auth_token]"
               value="<?php echo esc_attr((string)$o['twilio_auth_token']); ?>"
               autocomplete="off">
        <p class="description">
            <?php esc_html_e('Keep this private. Found on your Twilio Console dashboard.', 'adoration-scheduler'); ?>
        </p>
        <?php
    }

    public static function field_from_number(): void {
        $o = self::get_options();
        ?>
        <input type="text" class="regular-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[twilio_from_number]"
               value="<?php echo esc_attr((string)$o['twilio_from_number']); ?>"
               placeholder="+15551234567" autocomplete="off">
        <p class="description">
            <?php esc_html_e('Your Twilio phone number, in E.164 format (a leading + and country code, e.g. +15551234567).', 'adoration-scheduler'); ?>
        </p>
        <?php
    }

    public static function field_reminder_sms_body(): void {
        $o = self::get_options();
        ?>
        <textarea class="large-text" rows="3"
                  name="<?php echo esc_attr(self::OPTION_NAME); ?>[reminder_sms_body]"
        ><?php echo esc_textarea((string)$o['reminder_sms_body']); ?></textarea>
        <p class="description">
            <?php echo wp_kses(
                __('Available tags: <code>{title}</code> <code>{title_first_name}</code> <code>{first_name}</code> <code>{last_name}</code> <code>{schedule_title}</code> <code>{slot_date}</code> <code>{slot_start}</code> <code>{slot_end}</code>. Keep it short — messages over about 160 characters send as multiple SMS segments (extra cost per segment).', 'adoration-scheduler'),
                ['code' => []]
            ); ?>
        </p>
        <?php
    }

    /**
     * Handles the "Send Test SMS" form below the settings form — its own
     * small admin-post.php action, separate from the Settings API form
     * (which posts to options.php), mirroring EmailTemplatesPage's
     * send-a-test-email feature so Twilio credentials can be verified
     * without waiting up to 24h for a real reminder to fire.
     */
    public static function handle_send_test(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
        }

        check_admin_referer('adoration_scheduler_sms_test');

        $raw_phone = (string)($_POST['test_phone'] ?? '');
        $to_e164   = PhoneNumberFormatter::to_e164($raw_phone);

        $result = $to_e164 !== null
            ? SmsService::send_test($to_e164)
            : ['success' => false, 'error' => 'Please enter a valid 10-digit US phone number.'];

        $url = add_query_arg([
            'page'     => self::PAGE_SLUG,
            'sms_test' => !empty($result['success']) ? 'sent' : 'fail',
            'sms_err'  => rawurlencode((string)($result['error'] ?? '')),
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
        }

        self::register_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SMS Reminders', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_settings_tabs(self::PAGE_SLUG); ?>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'adoration-scheduler'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['sms_test'])):
                $sent  = ((string)$_GET['sms_test'] === 'sent');
                $err   = isset($_GET['sms_err']) ? sanitize_text_field(rawurldecode((string)$_GET['sms_err'])) : '';
                $class = $sent ? 'notice-success' : 'notice-error';
                $msg   = $sent ? __('Test text sent.', 'adoration-scheduler') : (__('Test text failed: ', 'adoration-scheduler') . $err);
                ?>
                <div class="notice <?php echo esc_attr($class); ?> is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Send Test SMS', 'adoration-scheduler'); ?></h2>
            <p class="description"><?php esc_html_e('Sends a short test message using the settings currently saved above.', 'adoration-scheduler'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('adoration_scheduler_sms_test'); ?>
                <input type="hidden" name="action" value="adoration_scheduler_send_test_sms">
                <input type="tel" class="regular-text" name="test_phone" placeholder="(555) 123-4567" required>
                <?php submit_button(__('Send Test', 'adoration-scheduler'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
