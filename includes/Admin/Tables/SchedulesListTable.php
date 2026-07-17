<?php
namespace AdorationScheduler\Admin\Tables;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SchedulesListTable extends \WP_List_Table {

    /** @var SchedulesRepository */
    private SchedulesRepository $repo;

    public function __construct() {
        parent::__construct([
            'singular' => 'schedule',
            'plural'   => 'schedules',
            'ajax'     => false,
        ]);

        $this->repo = new SchedulesRepository();
    }

    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox" />',
            'name'       => __('Name', 'adoration-scheduler'),
            'slug'       => __('Shortcode', 'adoration-scheduler'),
            'type'       => __('Type', 'adoration-scheduler'),
            'start_date' => __('Start Date', 'adoration-scheduler'),
            'end_date'   => __('End Date', 'adoration-scheduler'),
            'status'     => __('Status', 'adoration-scheduler'),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'name'       => ['name', false],
            'slug'       => ['slug', false],
            'type'       => ['type', false],
            'start_date' => ['start_date', false],
            'end_date'   => ['end_date', false],
            'status'     => ['status', false],
        ];
    }

    protected function column_cb($item): string {
        $id = (int)($item['id'] ?? 0);
        return sprintf('<input type="checkbox" name="schedule_ids[]" value="%d" />', $id);
    }

    protected function column_name($item): string {
        $id     = (int)($item['id'] ?? 0);
        $name   = (string)($item['name'] ?? '');
        $status = (string)($item['status'] ?? '');

        $edit_url = admin_url('admin.php?page=adoration_scheduler_schedules&action=edit&schedule_id=' . $id);

        /**
         * These admin-post actions are assumed to exist:
         * - adoration_delete_schedule              (trash)
         * - adoration_restore_schedule             (restore)
         * - adoration_delete_schedule_permanently  (hard delete cascade)
         * - adoration_duplicate_schedule           (duplicate)
         */
        $trash_url = wp_nonce_url(
            admin_url('admin-post.php?action=adoration_delete_schedule&schedule_id=' . $id),
            'adoration_trash_schedule_' . $id
        );

        $restore_url = wp_nonce_url(
            admin_url('admin-post.php?action=adoration_restore_schedule&schedule_id=' . $id),
            'adoration_restore_schedule_' . $id
        );

        $delete_perm_url = wp_nonce_url(
            admin_url('admin-post.php?action=adoration_delete_schedule_permanently&schedule_id=' . $id),
            'adoration_delete_schedule_permanently_' . $id
        );

        $duplicate_url = wp_nonce_url(
            admin_url('admin-post.php?action=adoration_duplicate_schedule&schedule_id=' . $id),
            'adoration_duplicate_schedule_' . $id
        );

        $is_trashed = in_array(strtolower($status), ['trash', 'trashed'], true);

        $actions = [];

        if (!$is_trashed) {
            $actions['edit'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_url),
                esc_html__('Edit', 'adoration-scheduler')
            );

            // ✅ NEW: Duplicate action (non-trashed only)
            $actions['duplicate'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($duplicate_url),
                esc_html__('Duplicate', 'adoration-scheduler')
            );

            $actions['trash'] = sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
                esc_url($trash_url),
                wp_json_encode(__('Move this schedule to Trash?', 'adoration-scheduler')),
                esc_html__('Trash', 'adoration-scheduler')
            );
        } else {
            $actions['restore'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($restore_url),
                esc_html__('Restore', 'adoration-scheduler')
            );

            $actions['delete'] = sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
                esc_url($delete_perm_url),
                wp_json_encode(__('Delete this schedule permanently? This will remove the schedule and ALL related data (slots, signups, etc.). This cannot be undone.', 'adoration-scheduler')),
                esc_html__('Delete Permanently', 'adoration-scheduler')
            );
        }

        return sprintf(
            '<strong><a class="row-title" href="%s">%s</a></strong> %s',
            esc_url($edit_url),
            esc_html($name),
            $this->row_actions($actions)
        );
    }

    protected function column_start_date($item): string {
        $val = (string)($item['start_date'] ?? '');
        if ($val === '' || $val === '0000-00-00') return '—';
        return esc_html($val);
    }

    protected function column_end_date($item): string {
        $val = (string)($item['end_date'] ?? '');
        if ($val === '' || $val === '0000-00-00') return '—';
        return esc_html($val);
    }

    protected function column_default($item, $column_name) {
        $val = $item[$column_name] ?? '';

        // Slug column renders click-to-copy shortcode
        if ($column_name === 'slug') {
            $slug = sanitize_title((string)$val);
            if ($slug === '') return '—';

            $shortcode = '[adoration_schedule slug="' . $slug . '"]';

            return '<button type="button"
                        class="button button-small as-copy-shortcode"
                        data-shortcode="' . esc_attr($shortcode) . '"
                        style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;"
                    >'
                    . esc_html($shortcode)
                    . '</button>'
                    . '<p class="description" style="margin:6px 0 0 0;">'
                    . esc_html__('Click to copy', 'adoration-scheduler')
                    . '</p>';
        }

        return esc_html((string)$val);
    }

    public function get_bulk_actions(): array {
        $status = $this->get_current_status();

        if ($status === 'trash') {
            return [
                'bulk-restore' => __('Restore', 'adoration-scheduler'),
                'bulk-delete'  => __('Delete Permanently', 'adoration-scheduler'),
            ];
        }

        return [
            'bulk-trash' => __('Move to Trash', 'adoration-scheduler'),
        ];
    }

    /**
     * ---------- helpers ----------
     */
    private function table_exists(string $table): bool {
        global $wpdb;
        $table = trim($table);
        if ($table === '') return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $like = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return !empty($like);
    }

    /**
     * HARD DELETE CASCADE (for when repo blocks deletes due to signups).
     * - Only allowed if schedule is currently in trash (WP-like UX).
     * - Deletes known related rows if the tables exist.
     */
    private function cascade_delete_schedule(int $schedule_id): bool {
        global $wpdb;
        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return false;

        // Must be trashed first (Posts-like rule).
        $row = method_exists($this->repo, 'find_by_id') ? $this->repo->find_by_id($schedule_id, true) : null;
        $status = strtolower((string)($row['status'] ?? ''));
        if (!$row || !in_array($status, ['trash','trashed'], true)) {
            return false;
        }

        $tables = [
            'signups'       => $wpdb->prefix . 'adoration_signups',
            'slots'         => $wpdb->prefix . 'adoration_slots',
            'segments'      => $wpdb->prefix . 'adoration_segments',
            'date_patterns' => $wpdb->prefix . 'adoration_date_patterns',
        ];

        // Delete dependents first (if table exists). Ignore missing tables safely.
        foreach (['signups','slots','segments','date_patterns'] as $key) {
            $t = $tables[$key];
            if (!$this->table_exists($t)) continue;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->delete($t, ['schedule_id' => $schedule_id], ['%d']);
        }

        // Finally delete schedule itself.
        if ($this->table_exists($wpdb->prefix . 'adoration_schedules')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $deleted = $wpdb->delete($wpdb->prefix . 'adoration_schedules', ['id' => $schedule_id], ['%d']);
            return ($deleted !== false && $deleted > 0);
        }

        return false;
    }

    public function process_bulk_action(): void {
        $action = $this->current_action();
        if ( ! $action ) return;

        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }

        // WP_List_Table bulk nonce action is "bulk-{$plural}"
        check_admin_referer('bulk-' . $this->_args['plural']);

        $ids = array_map('intval', (array)($_REQUEST['schedule_ids'] ?? []));
        $ids = array_values(array_filter($ids));
        if (empty($ids)) return;

        $trashed = 0;
        $restored = 0;
        $deleted_perm = 0;

        foreach ($ids as $id) {
            if ($action === 'bulk-trash') {
                // ✅ Trashing MUST be allowed even if signups exist.
                // Prefer repository soft_delete; fallback to raw status update.
                $ok = false;

                if (method_exists($this->repo, 'soft_delete')) {
                    $ok = (bool)$this->repo->soft_delete($id);
                } else {
                    global $wpdb;
                    $table = $wpdb->prefix . 'adoration_schedules';
                    if ($this->table_exists($table)) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $r = $wpdb->update($table, ['status' => 'trash'], ['id' => (int)$id], ['%s'], ['%d']);
                        $ok = ($r !== false);
                    }
                }

                if ($ok) $trashed++;

            } elseif ($action === 'bulk-restore') {
                if (method_exists($this->repo, 'restore')) {
                    try {
                        $ok = $this->repo->restore($id, 'draft');
                    } catch (\Throwable $e) {
                        $ok = $this->repo->restore($id);
                    }
                    if ($ok) $restored++;
                }

            } elseif ($action === 'bulk-delete') {
                // ✅ Permanent delete should be allowed even if signups exist.
                // Repo may refuse; if it does, we cascade-delete here (ONLY if schedule is trashed).
                $ok = false;

                if (method_exists($this->repo, 'delete_permanently')) {
                    $ok = (bool)$this->repo->delete_permanently($id);
                }

                if (!$ok) {
                    $ok = $this->cascade_delete_schedule($id);
                }

                if ($ok) $deleted_perm++;
            }
        }

        // Redirect back preserving filters so the change is visible
        $base = admin_url('admin.php?page=adoration_scheduler_schedules');

        $preserve = [
            'status','s','paged','orderby','order',
            'start_from','start_to','end_from','end_to'
        ];

        $args = [];
        foreach ($preserve as $k) {
            if (!isset($_REQUEST[$k]) || $_REQUEST[$k] === '') continue;
            $v = wp_unslash($_REQUEST[$k]);

            if (in_array($k, ['status','orderby','order'], true)) {
                $v = sanitize_key($v);
            } else {
                $v = sanitize_text_field($v);
            }
            $args[$k] = $v;
        }

        // Add result counts for notices
        if ($action === 'bulk-trash') {
            $args['schedules_trashed'] = $trashed;
        } elseif ($action === 'bulk-restore') {
            $args['schedules_restored'] = $restored;
        } elseif ($action === 'bulk-delete') {
            $args['schedules_deleted_perm'] = $deleted_perm;
        }

        $base = add_query_arg($args, $base);

        wp_safe_redirect($base);
        exit;
    }

    public function get_views(): array {
        $status = $this->get_current_status();

        $counts = method_exists($this->repo, 'admin_counts_by_status')
            ? (array)$this->repo->admin_counts_by_status()
            : ['all' => 0, 'trash' => 0];

        $trash = (int)($counts['trash'] ?? 0);
        $all_total = (int)($counts['all'] ?? 0);
        $all_non_trash = max(0, $all_total - $trash);

        $base = admin_url('admin.php?page=adoration_scheduler_schedules');

        return [
            'all' => sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($base),
                ($status === 'all') ? ' class="current"' : '',
                esc_html__('All', 'adoration-scheduler'),
                (int)$all_non_trash
            ),
            'trash' => sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('status', 'trash', $base)),
                ($status === 'trash') ? ' class="current"' : '',
                esc_html__('Trash', 'adoration-scheduler'),
                (int)$trash
            ),
        ];
    }

    protected function extra_tablenav($which): void {
        if ($which !== 'top') return;

        $start_from = sanitize_text_field($_GET['start_from'] ?? '');
        $start_to   = sanitize_text_field($_GET['start_to'] ?? '');
        $end_from   = sanitize_text_field($_GET['end_from'] ?? '');
        $end_to     = sanitize_text_field($_GET['end_to'] ?? '');

        $base_url = admin_url('admin.php?page=adoration_scheduler_schedules');

        $reset_args = [];
        if (!empty($_GET['status']))  $reset_args['status']  = sanitize_key($_GET['status']);
        if (!empty($_GET['s']))       $reset_args['s']       = sanitize_text_field(wp_unslash($_GET['s']));
        if (!empty($_GET['orderby'])) $reset_args['orderby'] = sanitize_key($_GET['orderby']);
        if (!empty($_GET['order']))   $reset_args['order']   = sanitize_key($_GET['order']);

        $reset_url = add_query_arg($reset_args, $base_url);

        echo '<div class="alignleft actions">';

        echo '<span style="margin-right:6px;">' . esc_html__('Start:', 'adoration-scheduler') . '</span>';
        echo '<label class="screen-reader-text" for="start_from">' . esc_html__('Start date from', 'adoration-scheduler') . '</label>';
        echo '<input type="date" name="start_from" id="start_from" value="' . esc_attr($start_from) . '" />';

        echo '<span style="margin:0 6px;">–</span>';

        echo '<label class="screen-reader-text" for="start_to">' . esc_html__('Start date to', 'adoration-scheduler') . '</label>';
        echo '<input type="date" name="start_to" id="start_to" value="' . esc_attr($start_to) . '" />';

        echo '<span style="display:inline-block; width:16px;"></span>';

        echo '<span style="margin-right:6px;">' . esc_html__('End:', 'adoration-scheduler') . '</span>';
        echo '<label class="screen-reader-text" for="end_from">' . esc_html__('End date from', 'adoration-scheduler') . '</label>';
        echo '<input type="date" name="end_from" id="end_from" value="' . esc_attr($end_from) . '" />';

        echo '<span style="margin:0 6px;">–</span>';

        echo '<label class="screen-reader-text" for="end_to">' . esc_html__('End date to', 'adoration-scheduler') . '</label>';
        echo '<input type="date" name="end_to" id="end_to" value="' . esc_attr($end_to) . '" />';

        submit_button(__('Filter', 'adoration-scheduler'), 'button', 'filter_action', false, ['id' => 'post-query-submit']);

        if ($start_from || $start_to || $end_from || $end_to) {
            echo ' <a class="button" href="' . esc_url($reset_url) . '">' . esc_html__('Reset', 'adoration-scheduler') . '</a>';
        }

        echo '</div>';

        // One-time JS for click-to-copy shortcode buttons
        ?>
        <script>
        (function(){
            function copyText(text){
                if (!text) return;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).catch(function(){});
                    return;
                }

                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-10000px';
                ta.style.top = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch(e){}
                document.body.removeChild(ta);
            }

            document.addEventListener('click', function(ev){
                var btn = ev.target.closest('.as-copy-shortcode');
                if (!btn) return;

                var sc = btn.getAttribute('data-shortcode') || '';
                copyText(sc);

                var old = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function(){ btn.textContent = old; }, 800);
            });
        })();
        </script>
        <?php
    }

    public function prepare_items(): void {
        // NOTE: If your page wants bulk actions to redirect reliably,
        // call $table->process_bulk_action() BEFORE any HTML output.

        $per_page = 20;
        $paged    = max(1, (int)($_GET['paged'] ?? 1));

        $search = isset($_REQUEST['s'])
            ? sanitize_text_field(wp_unslash($_REQUEST['s']))
            : '';

        $orderby = sanitize_key($_GET['orderby'] ?? 'created_at');
        $order   = strtoupper(sanitize_key($_GET['order'] ?? 'DESC'));
        $order   = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        $status_filter = $this->get_current_status();

        $start_from = sanitize_text_field($_GET['start_from'] ?? '');
        $start_to   = sanitize_text_field($_GET['start_to'] ?? '');
        $end_from   = sanitize_text_field($_GET['end_from'] ?? '');
        $end_to     = sanitize_text_field($_GET['end_to'] ?? '');

        $repo_status = '';
        if ($status_filter === 'trash') {
            $repo_status = 'trash';
        }

        $result = method_exists($this->repo, 'admin_list')
            ? $this->repo->admin_list([
                'per_page'   => $per_page,
                'paged'      => $paged,
                'search'     => $search,
                'status'     => $repo_status,
                'orderby'    => $orderby,
                'order'      => $order,
                'start_from' => $start_from,
                'start_to'   => $start_to,
                'end_from'   => $end_from,
                'end_to'     => $end_to,
            ])
            : ['items' => [], 'total' => 0];

        $this->items = (array)($result['items'] ?? []);
        $total       = (int)($result['total'] ?? 0);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int)ceil($total / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    private function get_current_status(): string {
        $status = sanitize_key($_REQUEST['status'] ?? 'all');
        return in_array($status, ['all', 'trash'], true) ? $status : 'all';
    }
}
