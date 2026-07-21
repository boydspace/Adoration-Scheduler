<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Utils\ClergyTitles;

if ( ! defined('ABSPATH') ) exit;

class AddPersonPage {

    private function preserved_args_from_request(): array {
        $out = [];
        $keys = ['s','paged','orderby','order'];

        foreach ($keys as $k) {
            if (!isset($_REQUEST[$k])) continue;

            $v = $_REQUEST[$k];
            if (is_array($v)) continue;

            $v = wp_unslash($v);

            if ($k === 'paged') {
                $v = (string) max(1, (int) $v);
            } elseif ($k === 'orderby' || $k === 'order') {
                $v = sanitize_key($v);
            } else {
                $v = sanitize_text_field($v);
            }

            if ($v !== '') $out[$k] = $v;
        }

        return $out;
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_people_add');
        $preserve  = $this->preserved_args_from_request();

        $back_url = add_query_arg(
            array_merge(['page' => 'adoration_scheduler_people'], $preserve),
            admin_url('admin.php')
        );

        $err = isset($_GET['person_create_error']) ? sanitize_key((string)$_GET['person_create_error']) : '';
        $err_msg = '';
        if ($err !== '') {
            $map = [
                'first_required' => __('First name is required.', 'adoration-scheduler'),
                'bad_email'      => __('Please enter a valid email address.', 'adoration-scheduler'),
                'email_in_use'   => __('That email address is already used by another person.', 'adoration-scheduler'),
                'bad_phone'      => __('Phone must be a valid US number (10 digits).', 'adoration-scheduler'),
                'repo_missing'   => __('Create not available: repository method missing.', 'adoration-scheduler'),
                'failed'         => __('Failed to create person.', 'adoration-scheduler'),
            ];
            $err_msg = $map[$err] ?? __('Could not create person. Please check the form and try again.', 'adoration-scheduler');
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Add Person', 'adoration-scheduler'); ?></h1>
            <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                <?php echo esc_html__('Back to People', 'adoration-scheduler'); ?>
            </a>
            <hr class="wp-header-end" />
            <?php \AdorationScheduler\Admin\Menu::render_people_tabs('adoration_scheduler_people_add'); ?>

            <?php if ($err_msg !== ''): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($err_msg); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 820px;">
                <?php wp_nonce_field('adoration_create_person'); ?>
                <input type="hidden" name="action" value="adoration_create_person" />
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />

                <?php
                foreach ($preserve as $k => $v) {
                    echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '" />';
                }
                ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="title"><?php echo esc_html__('Title', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <?php ClergyTitles::render_field_html('title', 'title', ''); ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="first_name"><?php echo esc_html__('First name', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="first_name" name="first_name" class="regular-text" required value="" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="last_name"><?php echo esc_html__('Last name', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="last_name" name="last_name" class="regular-text" value="" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="parish"><?php echo esc_html__('Parish', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="parish" name="parish" class="regular-text" value="" placeholder="Immaculate Heart of Mary Parish" />
                            <p class="description"><?php echo esc_html__('Optional.', 'adoration-scheduler'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="email"><?php echo esc_html__('Email', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="email" id="email" name="email" class="regular-text" value="" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="phone"><?php echo esc_html__('Phone', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="phone" name="phone" class="regular-text" value="" placeholder="(555) 123-4567" />
                            <p class="description"><?php echo esc_html__('Optional. Formats as you type.', 'adoration-scheduler'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Create Person', 'adoration-scheduler')); ?>
            </form>
        </div>

        <script>
        (function(){
            function digitsOnly(s){ return (s || '').replace(/\D+/g, ''); }

            function formatDigitsProgressive(d) {
                // allow leading 1, but don't show it in display
                if (d.length > 10 && d[0] === '1') d = d.slice(1);

                if (d.length === 0) return '';
                if (d.length <= 3) return '(' + d;
                if (d.length <= 6) return '(' + d.slice(0,3) + ') ' + d.slice(3);
                return '(' + d.slice(0,3) + ') ' + d.slice(3,6) + '-' + d.slice(6,10);
            }

            function countDigitsBeforeCaret(str, caretPos) {
                var sub = str.slice(0, caretPos);
                return digitsOnly(sub).length;
            }

            function caretPosForDigitIndex(formatted, digitIndex) {
                if (digitIndex <= 0) return 0;

                var seen = 0;
                for (var i = 0; i < formatted.length; i++) {
                    if (/\d/.test(formatted[i])) {
                        seen++;
                        if (seen >= digitIndex) return i + 1;
                    }
                }
                return formatted.length;
            }

            document.addEventListener('DOMContentLoaded', function(){
                var el = document.getElementById('phone');
                if (!el) return;

                el.addEventListener('input', function(){
                    var raw = el.value || '';
                    var caret = el.selectionStart || 0;

                    var digitIndex = countDigitsBeforeCaret(raw, caret);

                    var d = digitsOnly(raw);
                    // if they pasted 11 digits starting with 1, treat as US and ignore 1
                    if (d.length === 11 && d[0] === '1') d = d.slice(1);
                    if (d.length > 10) d = d.slice(0, 10);

                    var formatted = formatDigitsProgressive(d);
                    el.value = formatted;

                    if (document.activeElement === el) {
                        var newCaret = caretPosForDigitIndex(formatted, digitIndex);
                        try { el.setSelectionRange(newCaret, newCaret); } catch(e) {}
                    }
                });

                // On blur, if only "(" or "(123" etc., normalize to either full or leave digits
                el.addEventListener('blur', function(){
                    var d = digitsOnly(el.value || '');
                    if (d.length === 0) { el.value = ''; return; }
                    if (d.length === 11 && d[0] === '1') d = d.slice(1);
                    if (d.length > 10) d = d.slice(0,10);
                    el.value = formatDigitsProgressive(d);
                });
            });
        })();
        </script>
        <?php
    }
}
