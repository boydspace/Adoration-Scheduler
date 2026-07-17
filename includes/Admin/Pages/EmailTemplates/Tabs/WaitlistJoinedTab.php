<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class WaitlistJoinedTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Waitlist Joined';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Waitlist Joined</h2>';
        echo '<p class="description">Sent when someone signs up for an hour that\'s already full and is added to the waitlist instead.</p>';
        echo '<p>Available tags: <code>{first_name}</code> <code>{last_name}</code> <code>{person_name}</code> <code>{schedule_name}</code> <code>{slot_label}</code> <code>{slot_date}</code> <code>{slot_start}</code> <code>{slot_end}</code> <code>{position}</code> <code>{manage_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="wj_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="wj_subject" name="templates[waitlist_joined_subject]" value="' . esc_attr((string)($this->t['waitlist_joined_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['waitlist_joined_body'] ?? ''),
            'waitlist_joined_body',
            [
                'textarea_name' => 'templates[waitlist_joined_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Waitlist Joined Notice');

        $this->open_test_form($tab_key, 'waitlist_joined', __('Send Test Email', 'adoration-scheduler'));
    }
}
