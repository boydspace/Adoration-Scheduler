<?php
namespace AdorationScheduler\Admin\Tables;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\ChapelsRepository;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ChapelsListTable extends \WP_List_Table {

    private const PAGE_SLUG = 'adoration_scheduler_chapels';

    private ChapelsRepository $repo;
    private int $default_chapel_id = 0;

    public function __construct() {
        parent::__construct([
            'singular' => 'chapel',
            'plural'   => 'chapels',
            'ajax'     => false,
        ]);

        $this->repo = new ChapelsRepository();
        $this->default_chapel_id = (int) $this->repo->ensure_default_chapel_exists();
    }

    public function no_items() {
        esc_html_e('No chapels found.', 'adoration-scheduler');
    }

    public function get_columns(): array {
        return [
            'name'      => __('Name', 'adoration-scheduler'),
            'slug'      => __('Slug', 'adoration-scheduler'),
            'is_active' => __('Active', 'adoration-scheduler'),
            'id'        => __('ID', 'adoration-scheduler'),
        ];
    }

    protected function get_sortable_columns(): array {
        // We’ll sort in PHP for now; these keys help WP_List_Table UI.
        return [
            'name' => ['name', false],
            'slug' => ['slug', false],
            'id'   => ['id', false],
        ];
    }

    public function prepare_items(): void {
        $data = $this->repo->list_all();

        // Basic sorting in PHP
        $orderby = isset($_GET['orderby']) ? sanitize_text_field((string)$_GET['orderby']) : 'name';
        $order   = isset($_GET['order']) ? strtoupper(sanitize_text_field((string)$_GET['order'])) : 'ASC';
        $order   = in_array($order, ['ASC','DESC'], true) ? $order : 'ASC';

        usort($data, function($a, $b) use ($orderby, $order) {
            $va = $a[$orderby] ?? '';
            $vb = $b[$orderby] ?? '';

            if ($orderby === 'id') {
                $va = (int)$va;
                $vb = (int)$vb;
                $cmp = $va <=> $vb;
            } else {
                $cmp = strcmp((string)$va, (string)$vb);
            }

            return ($order === 'DESC') ? -$cmp : $cmp;
        });

        $per_page     = 25;
        $current_page = $this->get_pagenum();
        $total_items  = count($data);

        $this->items = array_slice($data, ($current_page - 1) * $per_page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'name'];
    }

    public function column_name($item): string {
        $id   = (int)($item['id'] ?? 0);
        $name = (string)($item['name'] ?? '');
        $slug = (string)($item['slug'] ?? '');

        $edit_url = add_query_arg([
            'page'   => self::PAGE_SLUG,
            'action' => 'edit',
            'id'     => $id,
        ], admin_url('admin.php'));

        $toggle_action = !empty($item['is_active']) ? 'deactivate' : 'activate';

        $toggle_url = wp_nonce_url(
            add_query_arg([
                'page'   => self::PAGE_SLUG,
                'action' => $toggle_action,
                'id'     => $id,
            ], admin_url('admin.php')),
            'adoration_chapel_toggle_' . $id
        );

        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html__('Edit', 'adoration-scheduler')),
            $toggle_action => sprintf(
                '<a href="%s">%s</a>',
                esc_url($toggle_url),
                $toggle_action === 'activate'
                    ? esc_html__('Activate', 'adoration-scheduler')
                    : esc_html__('Deactivate', 'adoration-scheduler')
            ),
        ];

        // ✅ Delete (never allowed for default chapel; also protect main-chapel slug)
        $is_default = ($id > 0 && $id === $this->default_chapel_id) || ($slug === 'main-chapel');

        if ($id > 0 && ! $is_default) {
            $delete_url = wp_nonce_url(
                add_query_arg([
                    'page'   => self::PAGE_SLUG,
                    'action' => 'delete',
                    'id'     => $id,
                ], admin_url('admin.php')),
                'adoration_chapel_delete_' . $id
            );

            $actions['delete'] = sprintf(
                '<a href="%s" onclick="return confirm(%s);" style="color:#b32d2e;">%s</a>',
                esc_url($delete_url),
                esc_js(__('Are you sure you want to delete this chapel? Any schedules/slots using it will be moved to Main Chapel.', 'adoration-scheduler')),
                esc_html__('Delete', 'adoration-scheduler')
            );
        }

        return sprintf(
            '<a class="row-title" href="%s">%s</a> %s',
            esc_url($edit_url),
            esc_html($name),
            $this->row_actions($actions)
        );
    }

    public function column_slug($item): string {
        return esc_html((string)($item['slug'] ?? ''));
    }

    public function column_is_active($item): string {
        return !empty($item['is_active'])
            ? '<span style="color:#1d7f2a;font-weight:600;">' . esc_html__('Yes', 'adoration-scheduler') . '</span>'
            : '<span style="color:#b32d2e;font-weight:600;">' . esc_html__('No', 'adoration-scheduler') . '</span>';
    }

    public function column_id($item): string {
        return esc_html((string)($item['id'] ?? ''));
    }

    protected function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html((string)$item[$column_name]) : '';
    }
}
