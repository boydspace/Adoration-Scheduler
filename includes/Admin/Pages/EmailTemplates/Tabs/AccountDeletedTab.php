<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class AccountDeletedTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Account Deleted';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Account Deleted (Self-Service)</h2>';
        echo '<p class="description">Sent to a person\'s original email address right after they use "Delete My Account" on their profile card, confirming the removal.</p>';
        echo '<p>Available tags: <code>{first_name}</code> <code>{last_name}</code> <code>{person_name}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="ad_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="ad_subject" name="templates[account_deleted_subject]" value="' . esc_attr((string)($this->t['account_deleted_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['account_deleted_body'] ?? ''),
            'account_deleted_body',
            [
                'textarea_name' => 'templates[account_deleted_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Account Deleted Notice');

        $this->open_test_form($tab_key, 'account_deleted', __('Send Test Email', 'adoration-scheduler'));
    }
}
