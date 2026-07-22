<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class AccountReadyTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Account Ready';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Account Ready (No-Account Adorer Given an Email)</h2>';
        echo '<p class="description">Sent when an admin gives a previously no-account person (one added to the schedule with no email on file) a real email address for the first time, so they know an online account now exists and how to sign in.</p>';
        echo '<p>Available tags: <code>{title}</code> <code>{title_first_name}</code> <code>{first_name}</code> <code>{last_name}</code> <code>{person_name}</code> <code>{sign_in_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="ar_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="ar_subject" name="templates[account_ready_subject]" value="' . esc_attr((string)($this->t['account_ready_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['account_ready_body'] ?? ''),
            'account_ready_body',
            [
                'textarea_name' => 'templates[account_ready_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Account Ready Notice');

        $this->open_test_form($tab_key, 'account_ready', __('Send Test Email', 'adoration-scheduler'));
    }
}
