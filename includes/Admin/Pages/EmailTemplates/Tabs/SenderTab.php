<?php
namespace AdorationScheduler\Admin\Pages\EmailTemplates\Tabs;

if ( ! defined('ABSPATH') ) exit;

class SenderTab extends AbstractEmailTemplatesTab {

    public static function label(): string {
        return __('Sender', 'adoration-scheduler');
    }

    public function render(string $active_tab): void {

        // IMPORTANT: this base class stores templates in $this->t
        $from_name       = esc_attr($this->t['from_name'] ?? get_bloginfo('name'));
        $from_email      = esc_attr($this->t['from_email'] ?? get_option('admin_email'));
        $reply_to_email  = esc_attr($this->t['reply_to_email'] ?? '');

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('adoration_scheduler_email_templates_save'); ?>

            <input type="hidden" name="action" value="adoration_scheduler_save_email_templates">
            <input type="hidden" name="tab" value="sender">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="from_name"><?php _e('From Name', 'adoration-scheduler'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="from_name"
                            name="templates[from_name]"
                            class="regular-text"
                            value="<?php echo $from_name; ?>"
                        >
                        <p class="description">
                            <?php _e('The name emails will appear to come from.', 'adoration-scheduler'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="from_email"><?php _e('From Email', 'adoration-scheduler'); ?></label>
                    </th>
                    <td>
                        <input
                            type="email"
                            id="from_email"
                            name="templates[from_email]"
                            class="regular-text"
                            value="<?php echo $from_email; ?>"
                        >
                        <p class="description">
                            <?php _e('The email address emails will be sent from.', 'adoration-scheduler'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="reply_to_email"><?php _e('Reply-To Email', 'adoration-scheduler'); ?></label>
                    </th>
                    <td>
                        <input
                            type="email"
                            id="reply_to_email"
                            name="templates[reply_to_email]"
                            class="regular-text"
                            value="<?php echo $reply_to_email; ?>"
                            placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                        >
                        <p class="description">
                            <?php _e('If set, replies will go to this address instead of the From Email.', 'adoration-scheduler'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save Sender Settings', 'adoration-scheduler')); ?>
        </form>
        <?php
    }
}
