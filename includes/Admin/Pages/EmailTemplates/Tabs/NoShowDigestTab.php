<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class NoShowDigestTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'No-Show Digest';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>No-Show Digest</h2>';
        echo '<p class="description">Admin digest listing confirmed Adoration hours that started a while ago with nobody checked in — the safety-alerting half of check-in tracking. Configure whether it\'s on, the grace period, and the recipient under Settings &rsaquo; No-Show Alerts.</p>';
        echo '<p>Available tags: <code>{no_show_count}</code> <code>{grace_minutes}</code> <code>{no_show_list}</code> <code>{attendance_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="nsd_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="nsd_subject" name="templates[no_show_digest_subject]" value="' . esc_attr((string)($this->t['no_show_digest_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['no_show_digest_body'] ?? ''),
            'no_show_digest_body',
            [
                'textarea_name' => 'templates[no_show_digest_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save No-Show Digest');

        $this->open_test_form($tab_key, 'no_show_digest', __('Send Test Email', 'adoration-scheduler'));
    }
}
