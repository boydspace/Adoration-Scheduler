<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class AccessRequestAdminTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'New Access Request (Admin)';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>New Access Request (Admin Notice)</h2>';
        echo '<p class="description">Sent to the site admin email whenever someone submits (or resubmits) the "Request Access" form.</p>';
        echo '<p>Available tags: <code>{requester_name}</code> <code>{requester_email}</code> <code>{review_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="ara_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="ara_subject" name="templates[access_request_admin_subject]" value="' . esc_attr((string)($this->t['access_request_admin_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['access_request_admin_body'] ?? ''),
            'access_request_admin_body',
            [
                'textarea_name' => 'templates[access_request_admin_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save New Access Request Notice');

        $this->open_test_form($tab_key, 'access_request_admin', __('Send Test Email', 'adoration-scheduler'));
    }
}
