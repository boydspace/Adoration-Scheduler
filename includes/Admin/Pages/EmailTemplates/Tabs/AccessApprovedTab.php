<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class AccessApprovedTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Access Approved';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Access Request Approved</h2>';
        echo '<p class="description">Sent to a person once an admin approves their access request (row-action "Accept" or bulk-approve on the People page).</p>';
        echo '<p>Available tags: <code>{first_name}</code> <code>{last_name}</code> <code>{person_name}</code> <code>{sign_in_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="aa_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="aa_subject" name="templates[access_approved_subject]" value="' . esc_attr((string)($this->t['access_approved_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['access_approved_body'] ?? ''),
            'access_approved_body',
            [
                'textarea_name' => 'templates[access_approved_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Access Approved Notice');

        $this->open_test_form($tab_key, 'access_approved', __('Send Test Email', 'adoration-scheduler'));
    }
}
