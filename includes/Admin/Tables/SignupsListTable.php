<?php
namespace AdorationScheduler\Admin\Tables;

use AdorationScheduler\Admin\Support\RowActionForm;
use AdorationScheduler\Utils\ClergyTitles;

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SignupsListTable extends \WP_List_Table {

    /**
     * Capability used for admin signup actions.
     * Falls back to manage_options in AdminSignupActionsService, but we gate UI too.
     */
    private const CAP_MANAGE_SIGNUPS = 'adoration_manage_signups';

    public function __construct() {
        parent::__construct([
            'singular' => 'signup',
            'plural'   => 'signups',
            'ajax'     => false,
        ]);
    }

    private function can_manage_signups(): bool {
        return current_user_can(self::CAP_MANAGE_SIGNUPS) || current_user_can('manage_options');
    }

    public function get_columns(): array {
        return [
            'cb'          => '<input type="checkbox" />',
            'person'      => __('Person', 'adoration-scheduler'),
            'email'       => __('Email', 'adoration-scheduler'),
            'schedule'    => __('Schedule', 'adoration-scheduler'),
            'slot'        => __('Slot', 'adoration-scheduler'),
            'status'      => __('Status', 'adoration-scheduler'),
            'created_at'  => __('Created', 'adoration-scheduler'),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'person'     => ['person', false],
            'email'      => ['email', false],
            'schedule'   => ['schedule', false],
            'status'     => ['status', false],
            'created_at' => ['created_at', true],
            'slot'       => ['slot', false],
        ];
    }

    protected function get_bulk_actions(): array {
        if (!$this->can_manage_signups()) {
            return [];
        }

        return [
            'bulk-cancel' => __('Cancel', 'adoration-scheduler'),
            'bulk-delete' => __('Delete', 'adoration-scheduler'),
        ];
    }

    protected function column_cb($item): string {
        if (!$this->can_manage_signups()) {
            return '';
        }

        return sprintf(
            '<input type="checkbox" name="signup_ids[]" value="%d" />',
            (int)($item['id'] ?? 0)
        );
    }

    public function column_person($item): string {
        $name = trim((string)($item['person_name'] ?? ''));
        if ($name === '') $name = __('(Unknown)', 'adoration-scheduler');

        // ✅ Clergy/religious title (2026-07-20): prefixed the same way
        // ProfileCardShortcode/PersonsRepository::full_name_with_title()
        // does — "Father John Smith" — so staff can see who's a priest,
        // deacon, etc. at a glance in the Signups list, not just on that
        // person's own profile card.
        $title = ClergyTitles::abbreviate((string)($item['person_title'] ?? ''));
        if ($title !== '' && $name !== __('(Unknown)', 'adoration-scheduler')) {
            $name = $title . ' ' . $name;
        }

        $actions = [];
        $signup_id = (int)($item['id'] ?? 0);

        // ✅ View/Edit modal
        if ($signup_id > 0) {
            $actions['view_edit'] =
                '<a href="#" class="as-signup-edit" data-signup-id="' . esc_attr((string)$signup_id) . '">'
                . esc_html__('View/Edit', 'adoration-scheduler')
                . '</a>';
        }

        // Only show cancel/delete links to users with permission
        if ($this->can_manage_signups() && $signup_id > 0) {

            // ✅ Cancel action (POST to admin-post.php) — matches AdminSignupActionsService
            if (strtolower((string)($item['status'] ?? '')) !== 'cancelled') {
                $actions['cancel'] = $this->admin_post_action_form_link(
                    'adoration_admin_cancel_signup',
                    $signup_id,
                    'adoration_admin_cancel_signup_' . $signup_id,
                    __('Cancel', 'adoration-scheduler'),
                    ''
                );
            }

            // ✅ Delete action (POST to admin-post.php) — matches AdminSignupActionsService
            $actions['delete'] = $this->admin_post_action_form_link(
                'adoration_admin_delete_signup',
                $signup_id,
                'adoration_admin_delete_signup_' . $signup_id,
                __('Delete', 'adoration-scheduler'),
                'color:#b32d2e',
                true
            );
        }

        return $name . $this->row_actions($actions);
    }

    /**
     * Render a "link" that actually submits a POST to admin-post.php.
     * This matches AdminSignupActionsService which only accepts POST.
     *
     * ✅ FIX: this used to render its own <form>...</form> per row, nested
     * inside SignupsPage::render()'s outer bulk-action <form>. Nested
     * forms are invalid HTML and silently broke Cancel/Delete — see
     * \AdorationScheduler\Admin\Support\RowActionForm for the full
     * explanation. Now renders a plain button that submits a single
     * shared out-of-band form instead.
     */
    private function admin_post_action_form_link(
        string $action,
        int $signup_id,
        string $nonce_action,
        string $label,
        string $style = '',
        bool $confirm = false
    ): string {
        $action = sanitize_key($action);
        if ($action === '' || $signup_id <= 0) return '';

        $return = add_query_arg(
            ['page' => 'adoration_scheduler_signups'],
            admin_url('admin.php')
        );

        $fields = [
            'action'    => $action,
            'signup_id' => $signup_id,
            'return'    => $return,
            '_wpnonce'  => wp_create_nonce($nonce_action),
        ];

        $confirm_msg = $confirm ? __('Delete this signup?', 'adoration-scheduler') : '';

        return RowActionForm::button($label, $fields, $style, $confirm_msg);
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'email':
            case 'schedule':
            case 'status':
            case 'created_at':
                return esc_html((string)($item[$column_name] ?? ''));

            case 'slot':
                return esc_html((string)($item['slot_label'] ?? ''));

            default:
                return '';
        }
    }

    /**
     * Filters row (Status + Schedule dropdowns)
     */
    protected function extra_tablenav($which): void {
        if ($which !== 'top') return;

        $current_status     = isset($_REQUEST['status']) ? sanitize_key((string)wp_unslash($_REQUEST['status'])) : '';
        $current_scheduleId = isset($_REQUEST['schedule_id']) ? (int)($_REQUEST['schedule_id']) : 0;

        $statuses = $this->get_distinct_statuses();

        echo '<div class="alignleft actions">';

        // Status filter
        echo '<label class="screen-reader-text" for="filter-status">' . esc_html__('Filter by status', 'adoration-scheduler') . '</label>';
        echo '<select name="status" id="filter-status">';
        echo '<option value="">' . esc_html__('All statuses', 'adoration-scheduler') . '</option>';
        foreach ($statuses as $st) {
            $sel = selected($current_status, $st, false);
            echo '<option value="' . esc_attr($st) . '"' . $sel . '>' . esc_html(ucfirst($st)) . '</option>';
        }
        echo '</select>';

        // Schedule filter
        $schedules = $this->get_schedules_for_filter();
        echo '<label class="screen-reader-text" for="filter-schedule">' . esc_html__('Filter by schedule', 'adoration-scheduler') . '</label>';
        echo '<select name="schedule_id" id="filter-schedule">';
        echo '<option value="0">' . esc_html__('All schedules', 'adoration-scheduler') . '</option>';
        foreach ($schedules as $sc) {
            $id  = (int)($sc['id'] ?? 0);
            $nm  = (string)($sc['name'] ?? '');
            if ($id <= 0) continue;
            $sel = selected($current_scheduleId, $id, false);
            echo '<option value="' . esc_attr((string)$id) . '"' . $sel . '>' . esc_html($nm) . '</option>';
        }
        echo '</select>';

        submit_button(__('Filter'), 'button', 'filter_action', false);

        echo '</div>';
    }

    public function prepare_items(): void {
        $this->process_bulk_action();

        global $wpdb;

        $per_page = 25;
        $paged    = max(1, (int)($_REQUEST['paged'] ?? 1));
        $offset   = ($paged - 1) * $per_page;

        $search = isset($_REQUEST['s']) ? trim((string)wp_unslash($_REQUEST['s'])) : '';

        $filter_status     = isset($_REQUEST['status']) ? sanitize_key((string)wp_unslash($_REQUEST['status'])) : '';
        $filter_scheduleId = isset($_REQUEST['schedule_id']) ? (int)($_REQUEST['schedule_id']) : 0;

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key((string)$_REQUEST['orderby']) : 'created_at';
        $order   = (isset($_REQUEST['order']) && strtoupper((string)$_REQUEST['order']) === 'ASC') ? 'ASC' : 'DESC';

        // map UI column => SQL
        $allowed_orderby = [
            'created_at' => 'su.created_at',
            'status'     => 'su.status',
            'email'      => 'p.email',
            'person'     => 'person_name',
            'schedule'   => 'sc.name',
            'slot'       => 'slot_sort',
        ];
        $order_by_sql = $allowed_orderby[$orderby] ?? 'su.created_at';

        $t_signups   = $wpdb->prefix . 'adoration_signups';
        $t_persons   = $wpdb->prefix . 'adoration_persons';
        $t_slots     = $wpdb->prefix . 'adoration_slots';
        $t_schedules = $wpdb->prefix . 'adoration_schedules';

        $where = [];
        $params = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(p.email LIKE %s OR p.first_name LIKE %s OR p.last_name LIKE %s OR sc.name LIKE %s)";
            array_push($params, $like, $like, $like, $like);
        }

        if ($filter_status !== '') {
            $where[] = "su.status = %s";
            $params[] = $filter_status;
        }

        if ($filter_scheduleId > 0) {
            $where[] = "su.schedule_id = %d";
            $params[] = $filter_scheduleId;
        }

        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Count
        $count_sql = "
            SELECT COUNT(*)
            FROM {$t_signups} su
            LEFT JOIN {$t_persons} p ON p.id = su.person_id
            LEFT JOIN {$t_schedules} sc ON sc.id = su.schedule_id
            {$where_sql}
        ";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, $params));

        // Items (slot_sort prefers start_at if exists; falls back to date+time)
        $items_sql = "
            SELECT
                su.id,
                su.status,
                su.created_at,
                TRIM(CONCAT(TRIM(COALESCE(p.first_name,'')), ' ', TRIM(COALESCE(p.last_name,'')))) AS person_name,
                p.title AS person_title,
                p.email AS email,
                sc.name AS schedule,
                CASE
                    WHEN sl.start_at IS NOT NULL AND sl.start_at <> '0000-00-00 00:00:00'
                        THEN DATE_FORMAT(sl.start_at, '%%Y-%%m-%%d %%H:%%i')
                    ELSE CONCAT(sl.date, ' ', LEFT(sl.start_time,5))
                END AS slot_label,
                CASE
                    WHEN sl.start_at IS NOT NULL AND sl.start_at <> '0000-00-00 00:00:00'
                        THEN sl.start_at
                    ELSE CONCAT(sl.date, ' ', sl.start_time)
                END AS slot_sort
            FROM {$t_signups} su
            LEFT JOIN {$t_persons} p ON p.id = su.person_id
            LEFT JOIN {$t_schedules} sc ON sc.id = su.schedule_id
            LEFT JOIN {$t_slots} sl ON sl.id = su.slot_id
            {$where_sql}
            ORDER BY {$order_by_sql} {$order}
            LIMIT %d OFFSET %d
        ";
        $items_params = array_merge($params, [$per_page, $offset]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array)$wpdb->get_results($wpdb->prepare($items_sql, $items_params), ARRAY_A);

        $this->items = $rows;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    public function process_bulk_action(): void {
        if ( ! $this->can_manage_signups() ) return;

        $action = $this->current_action();
        if (!in_array($action, ['bulk-cancel','bulk-delete'], true)) {
            return;
        }

        $ids = $_REQUEST['signup_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $this->redirect_with_toast(__('No signups selected.', 'adoration-scheduler'), 'error');
        }

        $signup_ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($signup_ids)) {
            $this->redirect_with_toast(__('No signups selected.', 'adoration-scheduler'), 'error');
        }

        check_admin_referer('bulk-' . $this->_args['plural']);

        // ✅ Use the same hardened service logic as single-item actions
        $svc = '\\AdorationScheduler\\Services\\AdminSignupActionsService';
        if (!class_exists($svc) || !method_exists($svc, 'cancel_signup_internal') || !method_exists($svc, 'delete_signup_internal')) {
            $this->redirect_with_toast(
                __('Bulk action service is missing. Please ensure AdminSignupActionsService is loaded and updated.', 'adoration-scheduler'),
                'error'
            );
        }

        $ok_count = 0;
        $fail_count = 0;

        foreach ($signup_ids as $sid) {
            if ($sid <= 0) continue;

            try {
                if ($action === 'bulk-cancel') {
                    $res = $svc::cancel_signup_internal($sid);
                } else {
                    $res = $svc::delete_signup_internal($sid);
                }

                if ($res === true) {
                    $ok_count++;
                } else {
                    $fail_count++;
                }
            } catch (\Throwable $e) {
                $fail_count++;
                error_log('[AdorationScheduler] Bulk action failed signup_id=' . (int)$sid . ' err=' . $e->getMessage());
            }
        }

        if ($ok_count > 0 && $fail_count === 0) {
            if ($action === 'bulk-cancel') {
                $this->redirect_with_toast(sprintf(__('Cancelled %d signup(s).', 'adoration-scheduler'), (int)$ok_count), 'success');
            } else {
                $this->redirect_with_toast(sprintf(__('Deleted %d signup(s).', 'adoration-scheduler'), (int)$ok_count), 'success');
            }
        }

        if ($ok_count > 0 && $fail_count > 0) {
            if ($action === 'bulk-cancel') {
                $this->redirect_with_toast(
                    sprintf(__('Cancelled %d signup(s); %d failed.', 'adoration-scheduler'), (int)$ok_count, (int)$fail_count),
                    'warning'
                );
            } else {
                $this->redirect_with_toast(
                    sprintf(__('Deleted %d signup(s); %d failed.', 'adoration-scheduler'), (int)$ok_count, (int)$fail_count),
                    'warning'
                );
            }
        }

        // no successes
        $this->redirect_with_toast(__('Bulk action failed.', 'adoration-scheduler'), 'error');
    }

    private function redirect_with_toast(string $msg, string $type = 'success'): void {
        $args = [
            'page'          => 'adoration_scheduler_signups',
            'as_toast'      => rawurlencode($msg),
            'as_toast_type' => $type,
        ];

        $preserve = ['s','orderby','order','status','schedule_id','paged'];
        foreach ($preserve as $k) {
            if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') {
                $args[$k] = (string)wp_unslash($_REQUEST[$k]);
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function get_distinct_statuses(): array {
        global $wpdb;
        $t = $wpdb->prefix . 'adoration_signups';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array)$wpdb->get_col("SELECT DISTINCT status FROM {$t} ORDER BY status ASC");

        $out = [];
        foreach ($rows as $r) {
            $s = sanitize_key((string)$r);
            if ($s !== '') $out[] = $s;
        }

        if (empty($out)) {
            $out = ['confirmed', 'pending', 'cancelled'];
        }

        return $out;
    }

    private function get_schedules_for_filter(): array {
        global $wpdb;
        $t = $wpdb->prefix . 'adoration_schedules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (array)$wpdb->get_results(
            "SELECT id, name FROM {$t} ORDER BY name ASC",
            ARRAY_A
        );
    }
}
