<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class CoverageDigestTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return 'Coverage Digest';
    }

    public function render(string $tab_key): void {

        $this->open_save_form($tab_key);

        echo '<h2>Coverage-Gap Digest</h2>';
        echo '<p class="description">Daily admin digest listing unfilled Adoration hours coming up soon. Configure whether it\'s on, the lookahead window, and the recipient under Settings &rsaquo; Coverage Alerts.</p>';
        echo '<p>Available tags: <code>{gap_count}</code> <code>{window_hours}</code> <code>{gap_list}</code> <code>{signups_url}</code> <code>{church_name}</code></p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="cd_subject">Subject</label></th><td>';
        echo '<input type="text" class="large-text" id="cd_subject" name="templates[coverage_digest_subject]" value="' . esc_attr((string)($this->t['coverage_digest_subject'] ?? '')) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label>Body</label></th><td>';
        wp_editor(
            (string)($this->t['coverage_digest_body'] ?? ''),
            'coverage_digest_body',
            [
                'textarea_name' => 'templates[coverage_digest_body]',
                'textarea_rows' => 10,
                'media_buttons' => false,
            ]
        );
        echo '</td></tr>';

        echo '</tbody></table>';

        $this->close_save_form('Save Coverage Digest');

        $this->open_test_form($tab_key, 'coverage_digest', __('Send Test Email', 'adoration-scheduler'));
    }
}
