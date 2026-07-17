<?php
namespace AdorationScheduler\Admin\Tables;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\EmailLogRepository;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class EmailLogListTable extends \WP_List_Table {

    private EmailLogRepository $repo;
    private int $total_items = 0;

    public function __construct() {
        parent::__construct([
            'singular' => 'email_log',
            'plural'   => 'email_logs',
            'ajax'     => false,
        ]);
        $this->repo = new EmailLogRepository();
    }

    public function get_columns(): array {
        return [
            'created_at' => __('When', 'adoration-scheduler'),
            'to_email'   => __('To', 'adoration-scheduler'),
            'type'       => __('Type', 'adoration-scheduler'),
            'context'    => __('Context', 'adoration-scheduler'),
            'schedule_id'=> __('Schedule', 'adoration-scheduler'),
            'success'    => __('Status', 'adoration-scheduler'),
            'subject'    => __('Subject', 'adoration-scheduler'),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'created_at' => ['created_at', true],
            'to_email'   => ['to_email', false],
            'type'       => ['type', false],
            'context'    => ['context', false],
            'success'    => ['success', false],
            'id'         => ['id', false],
        ];
    }

    public function get_views(): array {
        $base = remove_query_arg(['success','paged']);
        $current = isset($_GET['success']) ? (string)$_GET['success'] : '';

        $mk = function(string $label, string $val) use ($base, $current): string {
            $url = add_query_arg(['success' => $val], $base);
            $class = ($current === $val) ? 'class="current"' : '';
            return sprintf('<a href="%s" %s>%s</a>', esc_url($url), $class, esc_html($label));
        };

        return [
            'all'    => $mk(__('All', 'adoration-scheduler'), ''),
            'sent'   => $mk(__('Sent', 'adoration-scheduler'), '1'),
            'failed' => $mk(__('Failed', 'adoration-scheduler'), '0'),
        ];
    }

    public function prepare_items(): void {
        $per_page = $this->get_items_per_page('adoration_email_log_per_page', 20);

        $paged   = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $orderby = sanitize_key($_GET['orderby'] ?? 'created_at');
        $order   = sanitize_key($_GET['order'] ?? 'DESC');

        $s = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $success = isset($_GET['success']) ? (string)sanitize_text_field($_GET['success']) : '';

        $res = $this->repo->query([
            's'        => $s,
            'success'  => $success,
            'paged'    => $paged,
            'per_page' => (int)$per_page,
            'orderby'  => $orderby,
            'order'    => $order,
        ]);

        $this->items = $res['rows'];
        $this->total_items = (int)$res['total'];

        $this->set_pagination_args([
            'total_items' => $this->total_items,
            'per_page'    => $per_page,
        ]);
    }

    protected function column_success($item): string {
        $ok = !empty($item['success']);
        if ($ok) return '<span style="color: #0a7a0a; font-weight:600;">Sent</span>';
        return '<span style="color: #b32d2e; font-weight:600;">Failed</span>';
    }

    protected function column_created_at($item): string {
        $id = (int)($item['id'] ?? 0);

        $view_url = add_query_arg([
            'page'   => 'adoration_scheduler_email_log',
            'action' => 'view',
            'id'     => $id,
        ], admin_url('admin.php'));

        $resend_url = wp_nonce_url(
            add_query_arg([
                'action' => 'adoration_resend_email_log',
                'id'     => $id,
            ], admin_url('admin-post.php')),
            'adoration_resend_email_log_' . $id
        );

        $actions = [
            'view'   => sprintf('<a href="%s">%s</a>', esc_url($view_url), esc_html__('View', 'adoration-scheduler')),
            'resend' => sprintf('<a href="%s">%s</a>', esc_url($resend_url), esc_html__('Resend', 'adoration-scheduler')),
        ];

        $when = esc_html((string)($item['created_at'] ?? ''));

        return $when . $this->row_actions($actions);
    }

    protected function column_subject($item): string {
        $subj = (string)($item['subject'] ?? '');
        $subj = wp_strip_all_tags($subj);
        if (strlen($subj) > 120) $subj = substr($subj, 0, 120) . '…';
        return esc_html($subj);
    }

    protected function column_schedule_id($item): string {
        $sid = (int)($item['schedule_id'] ?? 0);
        return $sid > 0 ? (string)$sid : '—';
    }

    public function column_default($item, $column_name) {
        $val = $item[$column_name] ?? '';
        return esc_html((string)$val);
    }
}
