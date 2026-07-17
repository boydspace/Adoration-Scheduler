<?php
namespace AdorationScheduler\Admin\Pages;

if (!defined('ABSPATH')) exit;

/**
 * "Coverage Alerts" settings — admin email digest for unfilled adoration
 * hours coming up soon, across every active schedule (event or perpetual).
 *
 * On by default: unlike the approval gate (which changes visitor-facing
 * behavior), this is a pure admin-side notification with no effect on the
 * public site, so there's no reason to make it opt-in.
 *
 * Read by CoverageAlertService's daily cron job.
 */
class CoverageAlertsSettingsPage {

    private const OPTION_GROUP = 'adoration_scheduler_coverage_alerts';
    public const OPTION_NAME   = 'adoration_scheduler_coverage_alerts_options';
    private const PAGE_SLUG    = 'adoration_scheduler_coverage_alerts';

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
            'adoration_coverage_alerts_main',
            __('Open Hour Alerts', 'adoration-scheduler'),
            [__CLASS__, 'section_intro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'enabled',
            __('Enable Alerts', 'adoration-scheduler'),
            [__CLASS__, 'field_enabled'],
            self::PAGE_SLUG,
            'adoration_coverage_alerts_main'
        );

        add_settings_field(
            'alert_email',
            __('Send To', 'adoration-scheduler'),
            [__CLASS__, 'field_alert_email'],
            self::PAGE_SLUG,
            'adoration_coverage_alerts_main'
        );

        add_settings_field(
            'window_hours',
            __('Alert Window', 'adoration-scheduler'),
            [__CLASS__, 'field_window_hours'],
            self::PAGE_SLUG,
            'adoration_coverage_alerts_main'
        );

        add_settings_field(
            'repeat_mode',
            __('Repeat Behavior', 'adoration-scheduler'),
            [__CLASS__, 'field_repeat_mode'],
            self::PAGE_SLUG,
            'adoration_coverage_alerts_main'
        );
    }

    public static function defaults(): array {
        return [
            'enabled'      => 1,
            'alert_email'  => '', // empty = fall back to admin_email
            'window_hours' => 48,
            'repeat_mode'  => 'once', // 'once' | 'daily'
        ];
    }

    /**
     * The email address the digest actually goes to: the configured
     * override if set and valid, otherwise the site's admin_email.
     * Used by both this settings page (to show what will be used) and
     * CoverageAlertService (to actually send).
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
            esc_html__('Sends a single digest email to the site admin address whenever an active schedule has an hour coming up with nobody signed up for it — the moment that hour first enters the alert window below. Covers every active schedule (perpetual or event), including ones created later; no per-schedule setup needed.', 'adoration-scheduler') .
        '</p>';
    }

    public static function sanitize_options($opts): array {
        $opts = is_array($opts) ? $opts : [];
        $defaults = self::defaults();

        $window_hours = (int)($opts['window_hours'] ?? $defaults['window_hours']);
        if ($window_hours < 1) $window_hours = 1;
        if ($window_hours > 24 * 30) $window_hours = 24 * 30; // sanity cap: 30 days

        $repeat_mode = (string)($opts['repeat_mode'] ?? $defaults['repeat_mode']);
        if (!in_array($repeat_mode, ['once', 'daily'], true)) {
            $repeat_mode = $defaults['repeat_mode'];
        }

        $alert_email = sanitize_email((string)($opts['alert_email'] ?? ''));
        if ($alert_email !== '' && !is_email($alert_email)) {
            $alert_email = ''; // invalid input silently falls back to admin_email, not saved
        }

        return [
            'enabled'      => !empty($opts['enabled']) ? 1 : 0,
            'alert_email'  => $alert_email,
            'window_hours' => $window_hours,
            'repeat_mode'  => $repeat_mode,
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
            <?php esc_html_e('Email a designated address when an hour is about to go uncovered', 'adoration-scheduler'); ?>
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

    public static function field_window_hours(): void {
        $o = self::get_options();
        ?>
        <input type="number" min="1" max="720" step="1" class="small-text"
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[window_hours]"
               value="<?php echo esc_attr((string)(int)$o['window_hours']); ?>">
        <?php esc_html_e('hours ahead', 'adoration-scheduler'); ?>
        <p class="description">
            <?php esc_html_e('An unfilled hour starting within this many hours from now counts as "urgent." 48 hours gives enough lead time to actually rearrange a schedule.', 'adoration-scheduler'); ?>
        </p>
        <?php
    }

    public static function field_repeat_mode(): void {
        $o = self::get_options();
        $mode = (string)$o['repeat_mode'];
        ?>
        <label style="display:block;margin-bottom:6px;">
            <input type="radio"
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[repeat_mode]"
                   value="once" <?php checked('once', $mode); ?>>
            <?php esc_html_e('Alert once per gap', 'adoration-scheduler'); ?>
            <span class="description">&mdash; <?php esc_html_e('one email when a slot first enters the window; no repeat nagging.', 'adoration-scheduler'); ?></span>
        </label>
        <label style="display:block;">
            <input type="radio"
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[repeat_mode]"
                   value="daily" <?php checked('daily', $mode); ?>>
            <?php esc_html_e('Re-include daily until filled', 'adoration-scheduler'); ?>
            <span class="description">&mdash; <?php esc_html_e('the same open hour shows up in every day\'s digest until someone signs up.', 'adoration-scheduler'); ?></span>
        </label>
        <?php
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'), 403);
        }

        self::register_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Coverage Alerts', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_coverage_alerts'); ?>

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
