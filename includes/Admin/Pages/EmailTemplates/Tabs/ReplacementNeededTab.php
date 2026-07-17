<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class ReplacementNeededTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Replacement Needed';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Replacement / Coverage Needed</h2>';
        echo '<p class="description">Sent when someone requests a replacement for their Adoration commitment — to the admin, plus either the one person it was directly asked of, or every opted-in substitute.</p>';
        echo '<p>Available tags: <code>{requester_name}</code> <code>{slot_label}</code> <code>{schedule_title}</code> <code>{note}</code> <code>{target_name}</code> <code>{claim_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="rn_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="rn_subject" name="templates[replacement_needed_subject]" value="' . esc_attr((string)($this->t['replacement_needed_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['replacement_needed_body'] ?? ''),
            'replacement_needed_body',
            [
                'textarea_name' => 'templates[replacement_needed_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Replacement Needed Notice');

        $this->open_test_form($tab_key, 'replacement_needed', __('Send Test Email', 'adoration-scheduler'));
    }
}
