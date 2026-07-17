<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Services\NotificationService;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\SenderTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\SignupConfirmationTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\Reminder24hTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\MagicLinkTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\AccessRequestAdminTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\AccessApprovedTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\CoverageDigestTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\ReplacementNeededTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\AccountDeletedTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\WaitlistJoinedTab;
use AdorationScheduler\Admin\Pages\EmailTemplates\Tabs\WaitlistPromotedTab;

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

            // ✅ NEW: Admin notice — new access request submitted
            'access_request_admin_subject' => '[{church_name}] New Adoration access request: {requester_name}',
            'access_request_admin_body'    =>
                "A new access request was submitted:\n\n".
                "Name: {requester_name}\n".
                "Email: {requester_email}\n\n".
                "Review pending requests:\n".
                "{review_url}\n",

            // ✅ NEW: Person notice — access request approved
            'access_approved_subject' => '[{church_name}] Your Adoration access request was approved',
            'access_approved_body'    =>
                "Hello {first_name},\n\n".
                "Good news — your access request has been approved. You can now sign in to view the schedule and manage your Adoration commitments.\n\n".
                "Sign in here:\n".
                "{sign_in_url}\n\n".
                "You'll get a one-time sign-in link by email each time (no password required, unless you set one from your profile once signed in).\n",

            // ✅ NEW: Admin coverage-gap digest (daily cron)
            'coverage_digest_subject' => '[{church_name}] {gap_count} Adoration hour(s) need coverage',
            'coverage_digest_body'    =>
                "The following {gap_count} Adoration hour(s) have nobody signed up, and each starts within the next {window_hours} hours:\n\n".
                "{gap_list}\n\n".
                "View the Coverage Calendar or Signups page to assign someone, or share the schedule with parishioners so they can claim it themselves.\n\n".
                "{signups_url}\n",

            // ✅ NEW: Replacement/coverage-needed notice
            'replacement_needed_subject' => '[{church_name}] Coverage needed: {slot_label}',
            'replacement_needed_body'    =>
                "{requester_name} requested a replacement for their Adoration commitment:\n\n".
                "When: {slot_label}\n".
                "Schedule: {schedule_title}\n".
                "Note: {note}\n\n".
                "Sign in to view or claim it:\n".
                "{claim_url}\n",

            // ✅ NEW: Self-service account deletion confirmation
            'account_deleted_subject' => '[{church_name}] Your account has been deleted',
            'account_deleted_body'    =>
                "Hello {first_name},\n\n".
                "As you requested, your Adoration Scheduler account and personal information have been removed from our system.\n\n".
                "Any upcoming hours you were signed up for have been cancelled and are now open for someone else to cover. Your past participation history is kept in anonymized form so schedule and coverage records stay accurate — it's no longer linked to your name, email, or phone number.\n\n".
                "If this wasn't you, or you'd like to sign up again in the future, please contact the parish office.\n\n".
                "Thank you for your time serving in Adoration.\n",

            // ✅ NEW: Waitlist joined (slot was full)
            'waitlist_joined_subject' => "[{church_name}] You're on the waitlist",
            'waitlist_joined_body'    =>
                "Hello {first_name},\n\n".
                "That Adoration hour is currently full, so we've added you to the waitlist instead.\n\n".
                "Schedule: {schedule_title}\n".
                "When: {slot_date} {slot_start}–{slot_end}\n".
                "Your position: #{position}\n\n".
                "If someone cancels, we'll automatically move you into the open spot and email you right away — no action needed from you.\n\n".
                "You can view or leave the waitlist here:\n".
                "{manage_url}\n",

            // ✅ NEW: Waitlist promoted (a spot opened up)
            'waitlist_promoted_subject' => "[{church_name}] A spot opened up — you're confirmed!",
            'waitlist_promoted_body'    =>
                "Hello {first_name},\n\n".
                "Good news — a spot opened up, and you've been moved from the waitlist to a confirmed Adoration signup.\n\n".
                "Schedule: {schedule_title}\n".
                "When: {slot_date} {slot_start}–{slot_end}\n\n".
                "Manage your commitment here:\n".
                "{manage_url}\n\n".
                "Thank you for your faithful presence in prayer.\n",
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
        $out['access_request_admin_subject'] = sanitize_text_field($in['access_request_admin_subject'] ?? '');
        $out['access_approved_subject']      = sanitize_text_field($in['access_approved_subject'] ?? '');
        $out['coverage_digest_subject']      = sanitize_text_field($in['coverage_digest_subject'] ?? '');
        $out['replacement_needed_subject']   = sanitize_text_field($in['replacement_needed_subject'] ?? '');
        $out['account_deleted_subject']      = sanitize_text_field($in['account_deleted_subject'] ?? '');
        $out['waitlist_joined_subject']      = sanitize_text_field($in['waitlist_joined_subject'] ?? '');
        $out['waitlist_promoted_subject']    = sanitize_text_field($in['waitlist_promoted_subject'] ?? '');

        // Bodies
        $out['signup_confirmation_body'] = wp_kses_post($in['signup_confirmation_body'] ?? '');
        $out['reminder_24h_body']        = wp_kses_post($in['reminder_24h_body'] ?? '');
        $out['magic_link_body']          = wp_kses_post($in['magic_link_body'] ?? '');
        $out['access_request_admin_body'] = wp_kses_post($in['access_request_admin_body'] ?? '');
        $out['access_approved_body']      = wp_kses_post($in['access_approved_body'] ?? '');
        $out['coverage_digest_body']      = wp_kses_post($in['coverage_digest_body'] ?? '');
        $out['replacement_needed_body']   = wp_kses_post($in['replacement_needed_body'] ?? '');
        $out['account_deleted_body']      = wp_kses_post($in['account_deleted_body'] ?? '');
        $out['waitlist_joined_body']      = wp_kses_post($in['waitlist_joined_body'] ?? '');
        $out['waitlist_promoted_body']    = wp_kses_post($in['waitlist_promoted_body'] ?? '');

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
            'magic_link_subject','magic_link_body',
            'access_request_admin_subject','access_request_admin_body',
            'access_approved_subject','access_approved_body',
            'coverage_digest_subject','coverage_digest_body',
            'replacement_needed_subject','replacement_needed_body',
            'account_deleted_subject','account_deleted_body',
            'waitlist_joined_subject','waitlist_joined_body',
            'waitlist_promoted_subject','waitlist_promoted_body',
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
            'access_request_admin' => AccessRequestAdminTab::class,
            'access_approved'      => AccessApprovedTab::class,
            'coverage_digest'      => CoverageDigestTab::class,
            'replacement_needed'   => ReplacementNeededTab::class,
            'account_deleted'      => AccountDeletedTab::class,
            'waitlist_joined'       => WaitlistJoinedTab::class,
            'waitlist_promoted'     => WaitlistPromotedTab::class,
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
