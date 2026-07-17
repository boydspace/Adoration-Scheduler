<?php
namespace AdorationScheduler\Admin\Pages;

if (!defined('ABSPATH')) exit;

/**
 * "Access & Privacy" settings — optional approval gate for the plugin's
 * public scheduling pages (weekly grid + My Adoration dashboard).
 *
 * Off by default: the plugin behaves exactly as it always has (anyone can
 * self-serve a magic link and sign up). When turned on, new visitors get a
 * "Request Access" form instead of the sign-up UI, and their person record
 * sits in 'pending' status until an admin approves them from the People
 * page. This is deliberately site-scoped (not per-schedule) and does NOT
 * touch WordPress's own front-end visibility for other pages/content —
 * only this plugin's own shortcodes check it.
 */
class AccessSettingsPage {

    private const OPTION_GROUP = 'adoration_scheduler_access';
    private const OPTION_NAME  = 'adoration_scheduler_access_options';
    private const PAGE_SLUG    = 'adoration_scheduler_access';

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
            'default'           => [
                'require_approval' => 0,
            ],
        ]);

        add_settings_section(
            'adoration_access_gate',
            __('Approval Gate', 'adoration-scheduler'),
            [__CLASS__, 'section_gate_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'require_approval',
            __('Require Approval', 'adoration-scheduler'),
            [__CLASS__, 'field_require_approval'],
            self::PAGE_SLUG,
            'adoration_access_gate'
        );
    }

    public static function section_gate_intro(): void {
        echo '<p style="max-width:900px;">' .
            esc_html__('When enabled, visitors must have an approved account before they can view the weekly schedule or the My Adoration dashboard. New visitors see a "Request Access" form instead — their request sits in People (filtered to Pending) until an admin approves it. This only affects this plugin\'s own pages; it does not change visibility of any other page on your site.', 'adoration-scheduler') .
        '</p>';
    }

    public static function sanitize_options($opts): array {
        $opts = is_array($opts) ? $opts : [];

        return [
            'require_approval' => !empty($opts['require_approval']) ? 1 : 0,
        ];
    }

    public static function get_options(): array {
        $defaults = [
            'require_approval' => 0,
        ];
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public static function field_require_approval(): void {
        $o = self::get_options();
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[require_approval]"
                   value="1" <?php checked(1, (int)$o['require_approval']); ?>>
            <?php esc_html_e('Require an approved account to view scheduling pages', 'adoration-scheduler'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Off by default. Turn this on if this site should work like a members-only group (e.g. replacing a private Facebook group) rather than an open public sign-up page.', 'adoration-scheduler'); ?>
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
            <h1><?php esc_html_e('Access & Privacy', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_access'); ?>

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
