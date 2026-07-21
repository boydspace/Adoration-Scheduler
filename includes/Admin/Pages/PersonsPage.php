<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Admin\Tables\PersonsListTable;
use AdorationScheduler\Admin\Support\RowActionForm;
use AdorationScheduler\Utils\ClergyTitles;

if ( ! defined('ABSPATH') ) exit;

class PersonsPage {

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

    private function people_list_url(string $page_slug): string {
        $base = admin_url('admin.php?page=' . $page_slug);
        $args = $this->preserved_args_from_request();
        return !empty($args) ? add_query_arg($args, $base) : $base;
    }

    private function format_time_of_day(string $t): string {
        $t = trim($t);
        if ($t === '') return '';

        if (preg_match('/^\d{1,2}:\d{2}$/', $t)) $t .= ':00';

        $ts = strtotime('2000-01-01 ' . $t);
        if (!$ts) return '';

        $fmt = (string) get_option('time_format');
        if ($fmt === '') $fmt = 'g:i a';

        return date_i18n($fmt, $ts);
    }

    private function day_of_week_label($dow): string {
        $dow_raw = is_scalar($dow) ? trim((string)$dow) : '';
        if ($dow_raw === '') return '';

        if (!is_numeric($dow_raw)) return $dow_raw;

        $n = (int)$dow_raw;

        $names = [
            0 => __('Sunday', 'adoration-scheduler'),
            1 => __('Monday', 'adoration-scheduler'),
            2 => __('Tuesday', 'adoration-scheduler'),
            3 => __('Wednesday', 'adoration-scheduler'),
            4 => __('Thursday', 'adoration-scheduler'),
            5 => __('Friday', 'adoration-scheduler'),
            6 => __('Saturday', 'adoration-scheduler'),
        ];

        if ($n >= 0 && $n <= 6) return $names[$n] ?? '';
        if ($n >= 1 && $n <= 7) return $names[$n - 1] ?? '';

        return '';
    }

    /**
     * Fetch signup rows safely without any joins.
     */
    private function get_signups_for_person(int $person_id): array {
        global $wpdb;

        $signups_table = $wpdb->prefix . 'adoration_signups';
        $person_id = (int)$person_id;

        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$signups_table}
             WHERE person_id = %d
             ORDER BY date DESC, schedule_id ASC, slot_id ASC, id ASC",
            $person_id
        );

        return (array)$wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get schedule display label via SELECT * (schema-safe).
     */
    private function schedule_label_for(int $schedule_id): string {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return __('(Unknown schedule)', 'adoration-scheduler');

        $table = $wpdb->prefix . 'adoration_schedules';

        static $cache = [];
        if (isset($cache[$schedule_id])) return $cache[$schedule_id];

        $label = sprintf(__('Schedule #%d', 'adoration-scheduler'), $schedule_id);

        try {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $schedule_id),
                ARRAY_A
            );

            if (is_array($row) && !empty($row)) {
                $name = '';
                foreach (['name','title','schedule_name'] as $k) {
                    if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $name = trim((string)$row[$k]); break; }
                }

                $slug = '';
                foreach (['slug','schedule_slug'] as $k) {
                    if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $slug = trim((string)$row[$k]); break; }
                }

                if ($name !== '') $label = $name;
                if ($slug !== '') $label .= ' ' . sprintf('<span style="color:#646970;">(%s)</span>', esc_html($slug));
            }
        } catch (\Throwable $e) {
            // best-effort only
        }

        $cache[$schedule_id] = $label;
        return $label;
    }

    /**
     * Get slot time label via SELECT * (schema-safe).
     */
    private function slot_time_label_for(int $slot_id): string {
        global $wpdb;

        $slot_id = (int)$slot_id;
        if ($slot_id <= 0) return '—';

        $table = $wpdb->prefix . 'adoration_slots';

        static $cache = [];
        if (isset($cache[$slot_id])) return $cache[$slot_id];

        $label = '—';

        try {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $slot_id),
                ARRAY_A
            );

            if (!is_array($row) || empty($row)) {
                $cache[$slot_id] = $label;
                return $label;
            }

            $dow = '';
            foreach (['day_of_week','dow','day','weekday'] as $k) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $dow = $row[$k]; break; }
            }

            $start = '';
            foreach (['start_time','start','time_start','begins_at','from_time'] as $k) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $start = (string)$row[$k]; break; }
            }

            $end = '';
            foreach (['end_time','end','time_end','ends_at','to_time'] as $k) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $end = (string)$row[$k]; break; }
            }

            $dow_label   = $this->day_of_week_label($dow);
            $start_label = $this->format_time_of_day($start);
            $end_label   = $this->format_time_of_day($end);

            $parts = [];

            if ($dow_label !== '') $parts[] = $dow_label;

            if ($start_label !== '' && $end_label !== '') {
                $parts[] = $start_label . '–' . $end_label;
            } elseif ($start_label !== '') {
                $parts[] = $start_label;
            } elseif ($end_label !== '') {
                $parts[] = $end_label;
            }

            if (!empty($parts)) {
                $label = implode(' — ', $parts);
            } else {
                $label = sprintf(__('Slot #%d', 'adoration-scheduler'), $slot_id);
            }
        } catch (\Throwable $e) {
            // best-effort only
        }

        $cache[$slot_id] = $label;
        return $label;
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $repo = new PersonsRepository();

        $page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_people');
        $action    = sanitize_key($_GET['action'] ?? '');
        $person_id = (int)($_GET['person_id'] ?? 0);

        /**
         * ✅ VIEW PAGE
         */
        if ($action === 'view' && $person_id > 0) {
            $person = $repo->find($person_id);
            if (!$person) {
                echo '<div class="wrap"><h1>' . esc_html__('People', 'adoration-scheduler') . '</h1><p>' . esc_html__('Person not found.', 'adoration-scheduler') . '</p></div>';
                return;
            }

            $back_url = $this->people_list_url($page_slug);

            $first = trim((string)($person['first_name'] ?? ''));
            $last  = trim((string)($person['last_name'] ?? ''));
            $name  = trim($first . ' ' . $last);
            if ($name === '') $name = __('(No name)', 'adoration-scheduler');

            $email = trim((string)($person['email'] ?? ''));
            $phone = trim((string)($person['phone'] ?? ''));

            $edit_url = add_query_arg(array_merge($this->preserved_args_from_request(), [
                'page'      => $page_slug,
                'action'    => 'edit',
                'person_id' => $person_id,
            ]), admin_url('admin.php'));

            $signups = $this->get_signups_for_person($person_id);
            ?>
            <div class="wrap">
                <div style="display:flex; align-items:baseline; gap:10px; flex-wrap:wrap;">
                    <h1 style="margin:0;"><?php echo esc_html($name); ?></h1>

                    <span style="color:#646970;">
                        <?php echo esc_html__('ID:', 'adoration-scheduler'); ?>
                        <span style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;">
                            <?php echo (int)$person_id; ?>
                        </span>
                    </span>

                    <span style="margin-left:auto;">
                        <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>">
                            <?php echo esc_html__('Edit Person', 'adoration-scheduler'); ?>
                        </a>
                    </span>
                </div>

                <p>
                    <a class="button" href="<?php echo esc_url($back_url); ?>">
                        ← <?php echo esc_html__('Back to People', 'adoration-scheduler'); ?>
                    </a>
                </p>

                <?php
                $title_val  = trim((string)($person['title'] ?? ''));
                $parish_val = trim((string)($person['parish'] ?? ''));
                ?>
                <table class="widefat striped" style="max-width: 980px;">
                    <tbody>
                        <tr>
                            <th style="width:220px;"><?php echo esc_html__('Title', 'adoration-scheduler'); ?></th>
                            <td><?php echo esc_html($title_val !== '' ? $title_val : '—'); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Parish', 'adoration-scheduler'); ?></th>
                            <td><?php echo esc_html($parish_val !== '' ? $parish_val : '—'); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Email', 'adoration-scheduler'); ?></th>
                            <td><?php echo esc_html($email !== '' ? $email : '—'); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Phone', 'adoration-scheduler'); ?></th>
                            <td><?php echo esc_html($phone !== '' ? $phone : '—'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="postbox" style="margin-top:16px; max-width: 980px;">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php echo esc_html__('Adoration Signups', 'adoration-scheduler'); ?></h2>
                    </div>
                    <div class="inside">

                        <?php if (empty($signups)): ?>
                            <p style="margin-top:0;">
                                <?php echo esc_html__('No signups found for this person.', 'adoration-scheduler'); ?>
                            </p>
                        <?php else: ?>
                            <p style="margin-top:0; color:#646970;">
                                <?php
                                printf(
                                    esc_html(_n('Showing %d signup.', 'Showing %d signups.', count($signups), 'adoration-scheduler')),
                                    (int)count($signups)
                                );
                                ?>
                            </p>

                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Schedule', 'adoration-scheduler'); ?></th>
                                        <th style="width:140px;"><?php echo esc_html__('Date', 'adoration-scheduler'); ?></th>
                                        <th style="width:240px;"><?php echo esc_html__('Slot Time', 'adoration-scheduler'); ?></th>
                                        <th style="width:140px;"><?php echo esc_html__('Status', 'adoration-scheduler'); ?></th>
                                        <th style="width:140px;"><?php echo esc_html__('Type', 'adoration-scheduler'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($signups as $s): ?>
                                    <?php
                                        $schedule_id = (int)($s['schedule_id'] ?? 0);
                                        $slot_id     = (int)($s['slot_id'] ?? 0);

                                        $schedule_label = $this->schedule_label_for($schedule_id);

                                        $date_raw = trim((string)($s['date'] ?? ''));
                                        $date_fmt = '—';
                                        if ($date_raw !== '') {
                                            $ts = strtotime($date_raw);
                                            $date_fmt = $ts ? date_i18n('M j, Y', $ts) : $date_raw;
                                        }

                                        $slot_time = $this->slot_time_label_for($slot_id);

                                        $status = trim((string)($s['status'] ?? ''));
                                        $type   = trim((string)($s['type'] ?? ''));
                                        if ($status === '') $status = '—';
                                        if ($type === '') $type = '—';
                                    ?>
                                    <tr>
                                        <td><?php echo wp_kses_post($schedule_label); ?></td>
                                        <td><?php echo esc_html($date_fmt); ?></td>
                                        <td><?php echo esc_html($slot_time); ?></td>
                                        <td><?php echo esc_html($status); ?></td>
                                        <td><?php echo esc_html($type); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
            <?php
            return;
        }

        /**
         * ✅ EDIT PAGE
         */
        if ($action === 'edit' && $person_id > 0) {
            $person = $repo->find($person_id);
            if (!$person) {
                echo '<div class="wrap"><h1>' . esc_html__('People', 'adoration-scheduler') . '</h1><p>' . esc_html__('Person not found.', 'adoration-scheduler') . '</p></div>';
                return;
            }

            $preserve  = $this->preserved_args_from_request();
            $back_url  = add_query_arg(array_merge($preserve, [
                'page'      => $page_slug,
                'action'    => 'view',
                'person_id' => $person_id,
            ]), admin_url('admin.php'));

            $err = isset($_GET['person_save_error']) ? sanitize_key((string)$_GET['person_save_error']) : '';
            $err_map = [
                'invalid'        => __('Invalid person.', 'adoration-scheduler'),
                'first_required' => __('First name is required.', 'adoration-scheduler'),
                'bad_email'      => __('Please enter a valid email address.', 'adoration-scheduler'),
                'email_in_use'   => __('That email address is already used by another person.', 'adoration-scheduler'),
                'bad_phone'      => __('Phone must be a valid US number (10 digits).', 'adoration-scheduler'),
                'repo_missing'   => __('Update not available: repository method missing.', 'adoration-scheduler'),
                'failed'         => __('Failed to update person.', 'adoration-scheduler'),
            ];
            $err_msg = ($err !== '') ? ($err_map[$err] ?? __('Could not save changes. Please check the form and try again.', 'adoration-scheduler')) : '';
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php echo esc_html__('Edit Person', 'adoration-scheduler'); ?></h1>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php echo esc_html__('Back to Person', 'adoration-scheduler'); ?>
                </a>
                <hr class="wp-header-end" />

                <?php if ($err_msg !== ''): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html($err_msg); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 820px;">
                    <?php wp_nonce_field('adoration_save_person'); ?>
                    <input type="hidden" name="action" value="adoration_save_person" />
                    <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
                    <input type="hidden" name="person_id" value="<?php echo esc_attr((string)$person_id); ?>" />

                    <?php foreach ($preserve as $k => $v): ?>
                        <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" />
                    <?php endforeach; ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="title"><?php echo esc_html__('Title', 'adoration-scheduler'); ?></label>
                            </th>
                            <td>
                                <?php ClergyTitles::render_field_html('title', 'title', (string)($person['title'] ?? '')); ?>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="first_name"><?php echo esc_html__('First name', 'adoration-scheduler'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="first_name" name="first_name" class="regular-text" required value="<?php echo esc_attr((string)($person['first_name'] ?? '')); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="last_name"><?php echo esc_html__('Last name', 'adoration-scheduler'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="last_name" name="last_name" class="regular-text" value="<?php echo esc_attr((string)($person['last_name'] ?? '')); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="parish"><?php echo esc_html__('Parish', 'adoration-scheduler'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="parish" name="parish" class="regular-text" value="<?php echo esc_attr((string)($person['parish'] ?? '')); ?>" placeholder="Immaculate Heart of Mary Parish" />
                                <p class="description"><?php echo esc_html__('Optional.', 'adoration-scheduler'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="email"><?php echo esc_html__('Email', 'adoration-scheduler'); ?></label>
                            </th>
                            <td>
                                <input type="email" id="email" name="email" class="regular-text" value="<?php echo esc_attr((string)($person['email'] ?? '')); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="phone"><?php echo esc_html__('Phone', 'adoration-scheduler'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="phone" name="phone" class="regular-text" value="<?php echo esc_attr((string)($person['phone'] ?? '')); ?>" placeholder="(555) 123-4567" />
                                <p class="description"><?php echo esc_html__('Optional.', 'adoration-scheduler'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Changes', 'adoration-scheduler')); ?>
                </form>
            </div>
            <?php
            return;
        }

        /**
         * LIST VIEW (WP_List_Table)
         */
        if ( ! class_exists(PersonsListTable::class) ) {
            $maybe = plugin_dir_path(__FILE__) . '/../Tables/PersonsListTable.php';
            if (file_exists($maybe)) {
                require_once $maybe;
            }
        }

        $search = sanitize_text_field($_GET['s'] ?? '');
        $table  = new PersonsListTable($page_slug, $search);

        $table->process_bulk_action();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('People', 'adoration-scheduler'); ?></h1>

            <a href="<?php echo esc_url( admin_url('admin.php?page=adoration_scheduler_people_add') ); ?>"
               class="page-title-action">
                <?php echo esc_html__('Add New', 'adoration-scheduler'); ?>
            </a>

            <hr class="wp-header-end" />
            <?php \AdorationScheduler\Admin\Menu::render_people_tabs('adoration_scheduler_people'); ?>

            <form method="post">
                <?php
                    echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '" />';

                    if ($search !== '') {
                        echo '<input type="hidden" name="s" value="' . esc_attr($search) . '" />';
                    }

                    $orderby = sanitize_key($_GET['orderby'] ?? '');
                    $order   = sanitize_key($_GET['order'] ?? '');
                    $paged   = isset($_GET['paged']) ? (int)$_GET['paged'] : 0;

                    if ($orderby !== '') echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '" />';
                    if ($order   !== '') echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
                    if ($paged   > 0)    echo '<input type="hidden" name="paged" value="' . (int)$paged . '" />';

                    $table->search_box(__('Search People', 'adoration-scheduler'), 'person-search-input');
                    $table->display();
                ?>
            </form>

            <?php
                // ✅ Shared out-of-band form for row-action buttons
                // (Accept/Reject/Delete). Must be printed OUTSIDE the
                // bulk-action <form> above — see RowActionForm's docblock.
                RowActionForm::render_once();
            ?>
        </div>
        <?php
    }
}
