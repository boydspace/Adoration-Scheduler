<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class MagicLinkTab {

    /** @var array */
    private $t;

    public function __construct(array $templates) {
        $this->t = $templates;
    }

    public static function label(): string {
        return __('Magic Link', 'adoration-scheduler');
    }

    public function render(string $tab_key): void {

        // Pre-fill test recipient from redirect (EmailTemplatesPage adds ?test_to=...)
        $test_to = isset($_GET['test_to']) ? sanitize_email((string)$_GET['test_to']) : '';
        if ($test_to === '') {
            $test_to = (string) (wp_get_current_user()->user_email ?? '');
        }

        $subject = (string)($this->t['magic_link_subject'] ?? '');
        $body    = (string)($this->t['magic_link_body'] ?? '');

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('adoration_scheduler_email_templates_save'); ?>
            <input type="hidden" name="action" value="adoration_scheduler_save_email_templates" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab_key); ?>" />

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="magic_link_subject"><?php echo esc_html__('Subject', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                id="magic_link_subject"
                                name="templates[magic_link_subject]"
                                value="<?php echo esc_attr($subject); ?>"
                            />
                            <p class="description">
                                <?php echo esc_html__('Use tags like {first_name}, {schedule_title}, {manage_url}.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="magic_link_body"><?php echo esc_html__('Body', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $body,
                                'magic_link_body',
                                [
                                    'textarea_name' => 'templates[magic_link_body]',
                                    'textarea_rows' => 12,
                                    'media_buttons' => false,
                                ]
                            );
                            ?>
                            <p class="description">
                                <?php echo esc_html__('HTML is allowed. Keep links on their own line for readability.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Save Templates', 'adoration-scheduler'); ?>
                </button>
            </p>
        </form>

        <hr />

        <h2><?php echo esc_html__('Send Test', 'adoration-scheduler'); ?></h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('adoration_scheduler_email_templates_test'); ?>
            <input type="hidden" name="action" value="adoration_scheduler_test_email_template" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab_key); ?>" />
            <input type="hidden" name="which" value="magic_link" />

            <p>
                <label for="adoration_test_to_email">
                    <?php echo esc_html__('Send test to:', 'adoration-scheduler'); ?>
                </label>
                <input
                    type="email"
                    class="regular-text"
                    id="adoration_test_to_email"
                    name="to_email"
                    value="<?php echo esc_attr($test_to); ?>"
                />
                <button type="submit" class="button">
                    <?php echo esc_html__('Send Test Email', 'adoration-scheduler'); ?>
                </button>
            </p>

            <p class="description">
                <?php echo esc_html__('Uses sample values for tags. Check debug.log if sending fails.', 'adoration-scheduler'); ?>
            </p>
        </form>
        <?php
    }
}
