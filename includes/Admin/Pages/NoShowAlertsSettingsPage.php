<?php
namespace AdorationScheduler\Admin\Pages;

if (!defined('ABSPATH')) exit;

/**
 * "No-Show Alerts" settings — admin email digest for confirmed Adoration
 * hours that started a while ago with nobody checked in.
 *
 * Off by default: unlike Coverage Alerts (a pure planning nudge), this
 * digest is only useful once a parish has actually set up check-in links
 * or a chapel kiosk — turning it on with nobody using check-in yet would
 * just report every confirmed hour as a "no-show," so it stays opt-in
 * until the admin has that infrastructure in place.
 *
 * Read by NoShowAlertService's daily cron job.
 */
class NoShowAlertsSettingsPage {

    private const OPTION_GROUP = 'adoration_scheduler_no_show_alerts';
    public const OPTION_NAME   = 'adoration_scheduler_no_show_alerts_options';
    private const PAGE_SLUG    = 'adoration_scheduler_no_show_alerts';

    private static bool $did_register_settings = false;

    public static function register(): void {
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
            'adoration_no_show_alerts_main',
            __('No-Show Alerts', 'adoration-scheduler'),
            [__CLASS__, 'section_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'enabled',
            __('Enable Alerts', 'adoration-scheduler'),
            [__CLASS__, 'field_enabled'],
            self::PAGE_SLUG,
            'adoration_no_show_alerts_main'
        );

        add_settings_field(
            'alert_email',
            __('Send To', 'adoration-scheduler'),
            [__CLASS__, 'field_alert_email'],
            self::PAGE_SLUG,
            'adoration_no_show_alerts_main'
        );

        add_settings_field(
            'grace_minutes',
            __('Grace Period', 'adoration-scheduler'),
            [__CLASS__, 'field_grace_minutes'],
            self::PAGE_SLUG,
            'adoration_no_show_alerts_main'
        );
    }

    public static function defaults(): array {
        return [
            'enabled'       => 0, // opt-in — see class docblock
            'alert_email'   => '', // empty = fall back to admin_email
            'grace_minutes' => 30,
        ];
    }

    /**
     * Same fallback pattern as CoverageAlertsSettingsPage::get_recipient_email().
     */
    public static function get_recipient_email(): string {
        $o = self::get_options();
        $override = trim((string)($o['alert_email'] ?? ''));

        if ($override !== '' && is_email($override)) {
            return $override;
        }

        return (string) get_option('admin_email');
    }

    public static function section_intro(): void {
        echo '<p style="max-width:900px;">' .
            esc_html__('Sends a digest email to the site admin address when a confirmed Adoration hour started a while ago and nobody has checked in — either via the self-report link, a chapel kiosk, or an admin marking them present. Off by default until your parish has one of those check-in methods actually in use, since otherwise every confirmed hour would be flagged.', 'adoration-scheduler') .
        '</p>';
    }

    public static function sanitize_options($opts): array {
        $opts = is_array($opts) ? $opts : [];
        $defaults = self::defaults();

        $grace_minutes = (int)($opts['grace_minutes'] ?? $defaults['grace_minutes']);
        if ($grace_minutes < 5) $grace_minutes = 5;
        if ($grace_minutes > 240) $grace_minutes = 240; // sanity cap: 4 hours

        $alert_email = sanitize_email((string)($opts['alert_email'] ?? ''));
        if ($alert_email !== '' && !is_email($alert_email)) {
            $alert_email = ''; // invalid input silently falls back to admin_email, not saved
        }

        return [
            'enabled'       => !empty($opts['enabled']) ? 1 : 0,
            'alert_email'   => $alert_email,
            'grace_minutes' => $grace_minutes,
        ];
    }

    public static function get_options(): array {
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function field_enabled(): void {
        $o = self::get_options();
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[enabled]"
                   value="1" <?php checked(1, (int)$o['enabled']); ?>>
            <?php esc_html_e('Email a designated address when a confirmed hour looks unattended', 'adoration-scheduler'); ?>
        </label>
        <?php
    }

    public static function field_alert_email(): void {
        $o = self::get_options();
        $configured = trim((string)($o['alert_email'] ?? ''));
        ?>
        <input type="email" class="regular-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[alert_email]"
               value="<?php echo esc_attr($configured); ?>"
               placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
               autocomplete="off">
        <p class="description">
            <?php esc_html_e('Where the digest is sent. Leave blank to use your WordPress admin email instead.', 'adoration-scheduler'); ?>
            <br>
            <?php echo wp_kses(
                sprintf(
                    /* translators: %s: the email address alerts will actually go to right now */
                    __('Alerts currently go to: %s', 'adoration-scheduler'),
                    '<strong>' . esc_html(self::get_recipient_email()) . '</strong>'
                ),
                ['strong' => []]
            ); ?>
        </p>
        <?php
    }

    public static function field_grace_minutes(): void {
        $o = self::get_options();
        ?>
        <input type="number" min="5" max="240" step="5" class="small-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[grace_minutes]"
               value="<?php echo esc_attr((string)(int)$o['grace_minutes']); ?>">
        <?php esc_html_e('minutes after the hour starts', 'adoration-scheduler'); ?>
        <p class="description">
            <?php esc_html_e('How long to wait past the start time before flagging a confirmed hour as a possible no-show. 30 minutes gives someone running a little late time to still check in.', 'adoration-scheduler'); ?>
        </p>
        <?php
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
        }

        self::register_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('No-Show Alerts', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_no_show_alerts'); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
