<?php
namespace AdorationScheduler\Admin\Pages;

if (!defined('ABSPATH')) exit;

class AntiSpamSettingsPage {

    private const OPTION_GROUP = 'adoration_scheduler_antispam';
    private const OPTION_NAME  = 'adoration_scheduler_antispam_options';
    private const PAGE_SLUG    = 'adoration_scheduler_antispam';

    /** prevent double registration in a single request */
    private static bool $did_register_settings = false;

    public static function register(): void {
        // If admin_init already fired, register immediately (otherwise the page will be empty)
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
            'default'           => [
                'turnstile_enabled'    => 0,
                'turnstile_site_key'   => '',
                'turnstile_secret_key' => '',
            ],
        ]);

        add_settings_section(
            'adoration_antispam_turnstile',
            __('Cloudflare Turnstile', 'adoration-scheduler'),
            [__CLASS__, 'section_turnstile_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'turnstile_enabled',
            __('Enable Turnstile', 'adoration-scheduler'),
            [__CLASS__, 'field_enabled'],
            self::PAGE_SLUG,
            'adoration_antispam_turnstile'
        );

        add_settings_field(
            'turnstile_site_key',
            __('Site Key', 'adoration-scheduler'),
            [__CLASS__, 'field_site_key'],
            self::PAGE_SLUG,
            'adoration_antispam_turnstile'
        );

        add_settings_field(
            'turnstile_secret_key',
            __('Secret Key', 'adoration-scheduler'),
            [__CLASS__, 'field_secret_key'],
            self::PAGE_SLUG,
            'adoration_antispam_turnstile'
        );
    }

    public static function section_turnstile_intro(): void {
        echo '<p style="max-width:900px;">' .
            esc_html__('Turnstile protects your public signup form. When enabled, the widget appears on the signup modal and submissions are verified server-side.', 'adoration-scheduler') .
        '</p>';
    }

    public static function sanitize_options($opts): array {
        $opts = is_array($opts) ? $opts : [];

        return [
            'turnstile_enabled'    => !empty($opts['turnstile_enabled']) ? 1 : 0,
            'turnstile_site_key'   => sanitize_text_field($opts['turnstile_site_key'] ?? ''),
            'turnstile_secret_key' => sanitize_text_field($opts['turnstile_secret_key'] ?? ''),
        ];
    }

    private static function get_options(): array {
        $defaults = [
            'turnstile_enabled'    => 0,
            'turnstile_site_key'   => '',
            'turnstile_secret_key' => '',
        ];
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public static function field_enabled(): void {
        $o = self::get_options();
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[turnstile_enabled]"
                   value="1" <?php checked(1, (int)$o['turnstile_enabled']); ?>>
            <?php esc_html_e('Require Turnstile on public signup submissions', 'adoration-scheduler'); ?>
        </label>
        <?php
    }

    public static function field_site_key(): void {
        $o = self::get_options();
        ?>
        <input type="text" class="regular-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[turnstile_site_key]"
               value="<?php echo esc_attr($o['turnstile_site_key']); ?>"
               placeholder="0x4AAAAAA..." autocomplete="off">
        <?php
    }

    public static function field_secret_key(): void {
        $o = self::get_options();
        ?>
        <input type="password" class="regular-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[turnstile_secret_key]"
               value="<?php echo esc_attr($o['turnstile_secret_key']); ?>"
               placeholder="0x4AAAAAA..." autocomplete="off">
        <p class="description">
            <?php esc_html_e('Keep this private. It is used for server-side verification.', 'adoration-scheduler'); ?>
        </p>
        <?php
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
        }

        // Safety: if settings didn’t register for whatever reason, force it now.
        self::register_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Anti-Spam Settings', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_antispam'); ?>

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
