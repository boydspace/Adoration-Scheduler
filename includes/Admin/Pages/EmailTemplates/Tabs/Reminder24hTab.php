<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class Reminder24hTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return '24-Hour Reminder';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>24-Hour Reminder</h2>';
        echo '<p class="description">Sent about 24 hours before a scheduled adoration hour.</p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="rem_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="rem_subject" name="templates[reminder_24h_subject]" value="' . esc_attr((string)($this->t['reminder_24h_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['reminder_24h_body'] ?? ''),
            'reminder_24h_body',
            [
                'textarea_name' => 'templates[reminder_24h_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save 24-Hour Reminder');

        // ✅ Test: recipient is chosen in the test form (open_test_form renders the email field)
        $this->open_test_form($tab_key, 'reminder_24h', __('Send Test Email', 'adoration-scheduler'));
    }
}
