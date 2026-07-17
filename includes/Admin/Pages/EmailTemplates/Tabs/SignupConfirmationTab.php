<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class SignupConfirmationTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Signup Confirmation';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Signup Confirmation</h2>';
        echo '<p class="description">Sent when a signup is created (admin or frontend).</p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="signup_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="signup_subject" name="templates[signup_confirmation_subject]" value="' . esc_attr((string)($this->t['signup_confirmation_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['signup_confirmation_body'] ?? ''),
            'signup_confirmation_body',
            [
                'textarea_name' => 'templates[signup_confirmation_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Signup Confirmation');

        $this->open_test_form($tab_key, 'signup_confirmation', __('Send Test Email', 'adoration-scheduler'));

    }
}
