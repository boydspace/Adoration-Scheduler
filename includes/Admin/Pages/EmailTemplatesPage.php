<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Services\NotificationService;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\SenderTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\SignupConfirmationTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\Reminder24hTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\MagicLinkTab;

if ( ! defined('ABSPATH') ) exit;

class EmailTemplatesPage {

    public const OPTION_KEY = 'adoration_scheduler_email_templates';

    /**
     * Register admin-post handlers.
     * IMPORTANT: must be registered on every admin request (not just when page renders).
     */
    public static function register_actions(): void {
        add_action('admin_post_adoration_scheduler_save_email_templates', [__CLASS__, 'handle_save']);
        add_action('admin_post_adoration_scheduler_test_email_template', [__CLASS__, 'handle_send_test']);
    }

    public static function defaults(): array {
        return [
            'from_name'       => get_bloginfo('name'),
            'from_email'      => get_option('admin_email'),

            // ✅ NEW: Reply-To support (optional; can be blank)
            'reply_to_email'  => '',

            'signup_confirmation_subject' => 'Adoration Signup Confirmed: {slot_date} {slot_start}',
            'signup_confirmation_body'    =>
                "Hello {first_name},\n\n".
                "Thank you for signing up for Eucharistic Adoration.\n\n".
                "Schedule: {schedule_title}\n".
                "When: {slot_date} {slot_start}–{slot_end}\n\n".
                "If you have any questions, please contact the parish office.\n\n".
                "God bless,\n".
                "{church_name}\n",

            'reminder_24h_subject' => 'Reminder: Adoration Tomorrow ({slot_date} {slot_start})',
            'reminder_24h_body'    =>
                "Hello {first_name},\n\n".
                "This is a friendly reminder that you are scheduled for Eucharistic Adoration.\n\n".
                "Schedule: {schedule_title}\n".
                "When: {slot_date} {slot_start}–{slot_end}\n\n".
                "Thank you for your generosity in prayer.\n\n".
                "God bless,\n".
                "{church_name}\n",

            // ✅ NEW: Magic Link defaults
            'magic_link_subject' => 'Your Adoration Magic Link',
            'magic_link_body'    =>
                "Hello {first_name},\n\n".
                "Here is your secure link to manage your Eucharistic Adoration signup:\n\n".
                "{manage_url}\n\n".
                "If you did not request this link, you may ignore this email.\n\n".
                "God bless,\n".
                "{church_name}\n",
        ];
    }

    /**
     * Used for rendering the UI (guarantees defaults exist).
     */
    public static function get_templates(): array {
        $saved = get_option(self::OPTION_KEY);
        $saved = is_array($saved) ? $saved : [];
        return array_merge(self::defaults(), $saved);
    }

    /**
     * Sanitize the FULL templates array (expects it already contains any fields you want preserved).
     */
    private static function sanitize_templates(array $in): array {
        $out = [];

        // Sender
        $out['from_name']       = sanitize_text_field($in['from_name'] ?? '');
        $out['from_email']      = sanitize_email($in['from_email'] ?? '');

        // ✅ NEW: Reply-To (optional)
        $out['reply_to_email']  = sanitize_email($in['reply_to_email'] ?? '');

        // Subjects
        $out['signup_confirmation_subject'] = sanitize_text_field($in['signup_confirmation_subject'] ?? '');
        $out['reminder_24h_subject']        = sanitize_text_field($in['reminder_24h_subject'] ?? '');
        $out['magic_link_subject']          = sanitize_text_field($in['magic_link_subject'] ?? '');

        // Bodies
        $out['signup_confirmation_body'] = wp_kses_post($in['signup_confirmation_body'] ?? '');
        $out['reminder_24h_body']        = wp_kses_post($in['reminder_24h_body'] ?? '');
        $out['magic_link_body']          = wp_kses_post($in['magic_link_body'] ?? '');

        // Fallbacks for sender
        if ($out['from_name'] === '') {
            $out['from_name'] = get_bloginfo('name');
        }
        if (!is_email($out['from_email'])) {
            $out['from_email'] = get_option('admin_email');
        }

        // ✅ Reply-To can be blank; but if present, must be valid
        if ($out['reply_to_email'] !== '' && !is_email($out['reply_to_email'])) {
            $out['reply_to_email'] = '';
        }

        // Provide defaults if emptied
        $defaults = self::defaults();
        foreach ([
            'signup_confirmation_subject','signup_confirmation_body',
            'reminder_24h_subject','reminder_24h_body',
            'magic_link_subject','magic_link_body'
        ] as $k) {
            if (trim((string)($out[$k] ?? '')) === '') {
                $out[$k] = $defaults[$k];
            }
        }

        return $out;
    }

    /**
     * Tabs registry: add new tab classes here as you create them.
     */
    private static function tabs(): array {
        return [
            'sender'              => SenderTab::class,
            'signup_confirmation' => SignupConfirmationTab::class,
            'reminder_24h'        => Reminder24hTab::class,
            'magic_link'          => MagicLinkTab::class,
        ];
    }

    private static function default_tab(): string {
        return 'sender';
    }

    private static function current_tab(): string {
        $tab = sanitize_key((string)($_GET['tab'] ?? ''));
        if ($tab === '') $tab = self::default_tab();

        $tabs = self::tabs();
        if (!isset($tabs[$tab])) {
            $tab = self::default_tab();
        }
        return $tab;
    }

    private static function admin_page_url(array $args = []): string {
        $url = admin_url('admin.php?page=adoration_scheduler_email_templates');
        if (!empty($args)) $url = add_query_arg($args, $url);
        return $url;
    }

    /**
     * ✅ FIXED SAVE LOGIC
     */
    public static function handle_save(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        check_admin_referer('adoration_scheduler_email_templates_save');

        $tab = sanitize_key((string)($_POST['tab'] ?? ''));
        if ($tab === '') $tab = self::default_tab();

        $posted = $_POST['templates'] ?? [];
        if (!is_array($posted)) $posted = [];

        // ✅ IMPORTANT: use raw saved option (not get_templates()) so we preserve previous saved values
        $raw_saved = get_option(self::OPTION_KEY, []);
        $raw_saved = is_array($raw_saved) ? $raw_saved : [];

        $merged = array_merge($raw_saved, $posted);
        $templates = self::sanitize_templates($merged);

        update_option(self::OPTION_KEY, $templates, false);

        // Optional debug (safe)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AdorationScheduler] EmailTemplates SAVE tab=' . $tab);
            error_log('[AdorationScheduler] EmailTemplates POST keys=' . implode(',', array_keys($posted)));
            error_log('[AdorationScheduler] EmailTemplates SAVED from_name=' . (string)($templates['from_name'] ?? ''));
            error_log('[AdorationScheduler] EmailTemplates SAVED from_email=' . (string)($templates['from_email'] ?? ''));
            error_log('[AdorationScheduler] EmailTemplates SAVED reply_to_email=' . (string)($templates['reply_to_email'] ?? ''));
        }

        $url = self::admin_page_url(['updated' => 1, 'tab' => $tab]);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * ✅ UPDATED: allow test emails to go to a custom address.
     *
     * Reads POST field: to_email
     * Falls back to current user's email if blank/invalid.
     * Preserves the chosen email via ?test_to=... on redirect.
     */
    public static function handle_send_test(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        check_admin_referer('adoration_scheduler_email_templates_test');

        $which = sanitize_text_field((string)($_POST['which'] ?? 'signup_confirmation'));
        $tab   = sanitize_key((string)($_POST['tab'] ?? ''));
        if ($tab === '') $tab = self::default_tab();

        // ✅ accept a custom test recipient
        $to = sanitize_email((string)($_POST['to_email'] ?? ''));
        if ($to === '' || !is_email($to)) {
            $to = (string) (wp_get_current_user()->user_email ?? '');
        }

        $ok = false;
        try {
            $ok = ($to !== '' && is_email($to)) ? NotificationService::send_test_template($which, $to) : false;
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Test email failed: ' . $e->getMessage());
            $ok = false;
        }

        $url = self::admin_page_url([
            'test'    => ($ok ? 'sent' : 'fail'),
            'which'   => $which,
            'tab'     => $tab,
            'test_to' => $to, // so tabs can prefill the field
        ]);
        wp_safe_redirect($url);
        exit;
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $t = self::get_templates();
        $tab_key = self::current_tab();
        $tabs = self::tabs();
        $tab_class = $tabs[$tab_key];

        $tab = new $tab_class($t);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Email Templates', 'adoration-scheduler') . '</h1>';
        \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_email_templates');

        // Notices
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Templates saved.</p></div>';
        }
        if (isset($_GET['test'])) {
            $msg = ((string)$_GET['test'] === 'sent') ? 'Test email sent.' : 'Test email failed (see debug.log).';
            $class = ((string)$_GET['test'] === 'sent') ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        // Tabs UI (WP-native)
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $cls) {
            $active = ($key === $tab_key) ? ' nav-tab-active' : '';
            $label = method_exists($cls, 'label') ? $cls::label() : $key;
            echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url(self::admin_page_url(['tab' => $key])) . '">'
                . esc_html($label)
                . '</a>';
        }
        echo '</h2>';

        echo '<p>Available tags: <code>{first_name}</code> <code>{last_name}</code> <code>{schedule_title}</code> <code>{slot_date}</code> <code>{slot_start}</code> <code>{slot_end}</code> <code>{church_name}</code> <code>{manage_url}</code></p>';

        // Render the active tab content
        $tab->render($tab_key);

        echo '</div>';
    }
}
