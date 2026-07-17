<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

abstract class AbstractEmailTemplatesTab {

    /** @var array<string,mixed> */
    protected $t;

    public function __construct(array $templates) {
        $this->t = $templates;
    }

    /**
     * Human label for the nav tab.
     */
    public static function label(): string {
        return 'Tab';
    }

    /**
     * Render the tab content.
     *
     * @param string $tab_key Current tab key (used for hidden input + redirects)
     */
    abstract public function render(string $tab_key): void;

    protected function open_save_form(string $tab_key): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('adoration_scheduler_email_templates_save');
        echo '<input type="hidden" name="action" value="adoration_scheduler_save_email_templates" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab_key) . '" />';
    }

    protected function close_save_form(string $button_text = 'Save'): void {
        submit_button($button_text);
        echo '</form>';
    }

    /**
     * Test form with customizable recipient.
     *
     * Expects EmailTemplatesPage::handle_send_test() to accept POST[to_email].
     */
    protected function open_test_form(string $tab_key, string $which, string $button_label = ''): void {

        // Prefill priority:
        // 1) querystring ?test_to=... (after redirect)
        // 2) current user email
        $prefill = '';
        if (isset($_GET['test_to'])) {
            $prefill = sanitize_email((string) $_GET['test_to']);
        }
        if ($prefill === '' || !is_email($prefill)) {
            $user = wp_get_current_user();
            $prefill = isset($user->user_email) ? (string)$user->user_email : '';
        }

        if ($button_label === '') {
            $button_label = __('Send Test Email', 'adoration-scheduler');
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top: 16px;">';
        wp_nonce_field('adoration_scheduler_email_templates_test');

        echo '<input type="hidden" name="action" value="adoration_scheduler_test_email_template" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab_key) . '" />';
        echo '<input type="hidden" name="which" value="' . esc_attr($which) . '" />';

        echo '<table class="form-table" style="max-width: 800px;">';
        echo '  <tr>';
        echo '    <th scope="row"><label for="adoration_test_to">' . esc_html__('Send test to', 'adoration-scheduler') . '</label></th>';
        echo '    <td>';
        echo '      <input type="email" id="adoration_test_to" name="to_email" class="regular-text" value="' . esc_attr($prefill) . '" />';
        echo '      <p class="description">' . esc_html__('Enter the recipient address for this test email.', 'adoration-scheduler') . '</p>';
        echo '    </td>';
        echo '  </tr>';
        echo '</table>';

        echo '<p><button class="button button-secondary">' . esc_html($button_label) . '</button></p>';
        echo '</form>';
    }
}
