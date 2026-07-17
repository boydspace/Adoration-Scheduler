<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Admin\Tables\ChapelsListTable;

class ChapelsPage {

    private const PAGE_SLUG = 'adoration_scheduler_chapels';

    private ChapelsRepository $repo;
    private int $default_chapel_id = 0;

    public function __construct() {
        $this->repo = new ChapelsRepository();
    }

    /**
     * Runs BEFORE output (wired via Menu::load_chapels_page()).
     * Safe place for redirects and mutating actions.
     */
    public function handle_request(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adoration-scheduler'), 403);
        }

        // Ensure at least one exists (Main Chapel)
        $this->default_chapel_id = (int) $this->repo->ensure_default_chapel_exists();

        // ✅ Notices (querystring) - handled during render, not here

        // ✅ Create / Update
        if (isset($_POST['adoration_save_chapel'])) {
            check_admin_referer('adoration_save_chapel');

            $id        = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name      = isset($_POST['name']) ? sanitize_text_field((string)$_POST['name']) : '';
            $slug      = isset($_POST['slug']) ? sanitize_text_field((string)$_POST['slug']) : '';
            $is_active = !empty($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                $this->redirect_with_notice('error_name_required');
            }

            if ($id > 0) {
                $ok = $this->repo->update($id, [
                    'name'      => $name,
                    'slug'      => $slug,
                    'is_active' => $is_active,
                ]);

                $this->redirect_with_notice($ok ? 'chapel_updated' : 'chapel_update_failed');
            }

            $new_id = $this->repo->create($name, $slug !== '' ? $slug : null, (bool)$is_active);
            $this->redirect_with_notice($new_id > 0 ? 'chapel_added' : 'chapel_add_failed');
        }

        $action = isset($_GET['action']) ? sanitize_text_field((string)$_GET['action']) : '';
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        // ✅ Activate / Deactivate
        if (($action === 'activate' || $action === 'deactivate') && $id > 0) {
            check_admin_referer('adoration_chapel_toggle_' . $id);

            if ($action === 'deactivate') {
                $active = $this->repo->list_active();
                if (count($active) <= 1) {
                    $this->redirect_with_notice('cannot_deactivate_last');
                }
            }

            $ok = $this->repo->update($id, [
                'is_active' => ($action === 'activate') ? 1 : 0
            ]);

            $this->redirect_with_notice($ok
                ? ($action === 'activate' ? 'chapel_activated' : 'chapel_deactivated')
                : 'chapel_toggle_failed'
            );
        }

        // ✅ Delete
        if ($action === 'delete' && $id > 0) {
            check_admin_referer('adoration_chapel_delete_' . $id);

            // protect default
            if ($id === (int)$this->default_chapel_id) {
                $this->redirect_with_notice('cannot_delete_default');
            }

            $row = $this->repo->find($id);
            if (!$row) {
                $this->redirect_with_notice('chapel_not_found');
            }

            // protect "main-chapel" slug too
            $slug = (string)($row['slug'] ?? '');
            if ($slug === 'main-chapel') {
                $this->redirect_with_notice('cannot_delete_default');
            }

            $all = $this->repo->list_all();
            if (count($all) <= 1) {
                $this->redirect_with_notice('cannot_delete_last');
            }

            // Reassign usage to default chapel
            $this->reassign_chapel_usage($id, (int)$this->default_chapel_id);

            $ok = $this->repo->delete($id);
            $this->redirect_with_notice($ok ? 'chapel_deleted' : 'chapel_delete_failed');
        }
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adoration-scheduler'), 403);
        }

        // Ensure we know default (in case render called without load hook)
        if ($this->default_chapel_id <= 0) {
            $this->default_chapel_id = (int) $this->repo->ensure_default_chapel_exists();
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Chapels', 'adoration-scheduler') . '</h1>';
        \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_chapels');

        if (!empty($_GET['adoration_notice'])) {
            $key = sanitize_text_field((string)$_GET['adoration_notice']);
            $this->render_notice_from_key($key);
        }

        $action = isset($_GET['action']) ? sanitize_text_field((string)$_GET['action']) : '';
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($action === 'edit' && $id > 0) {
            $this->render_edit_form($id);
        } else {
            $this->render_list_and_add_form();
        }

        echo '</div>';
    }

    private function render_list_and_add_form(): void {
        echo '<hr class="wp-header-end">';

        echo '<h2>' . esc_html__('Add Chapel', 'adoration-scheduler') . '</h2>';
        $this->render_form([
            'id'        => 0,
            'name'      => '',
            'slug'      => '',
            'is_active' => 1,
        ]);

        echo '<h2 style="margin-top:24px;">' . esc_html__('All Chapels', 'adoration-scheduler') . '</h2>';

        $table = new ChapelsListTable();
        $table->prepare_items();

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        $table->display();
        echo '</form>';
    }

    private function render_edit_form(int $id): void {
        $chapel = $this->repo->find($id);
        if (!$chapel) {
            $this->admin_notice('error', __('Chapel not found.', 'adoration-scheduler'));
            echo '<p><a class="button" href="' . esc_url($this->page_url()) . '">' . esc_html__('Back', 'adoration-scheduler') . '</a></p>';
            return;
        }

        echo '<p><a class="button" href="' . esc_url($this->page_url()) . '">&larr; ' . esc_html__('Back to Chapels', 'adoration-scheduler') . '</a></p>';
        echo '<h2>' . esc_html__('Edit Chapel', 'adoration-scheduler') . '</h2>';

        $this->render_form($chapel);
    }

    private function render_form(array $chapel): void {
        $id        = (int)($chapel['id'] ?? 0);
        $name      = (string)($chapel['name'] ?? '');
        $slug      = (string)($chapel['slug'] ?? '');
        $is_active = !empty($chapel['is_active']) ? 1 : 0;

        echo '<form method="post" style="max-width: 720px;">';
        wp_nonce_field('adoration_save_chapel');

        echo '<input type="hidden" name="id" value="' . esc_attr((string)$id) . '">';

        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '  <th scope="row"><label for="name">' . esc_html__('Name', 'adoration-scheduler') . '</label></th>';
        echo '  <td><input name="name" id="name" type="text" class="regular-text" value="' . esc_attr($name) . '" required></td>';
        echo '</tr>';

        echo '<tr>';
        echo '  <th scope="row"><label for="slug">' . esc_html__('Slug', 'adoration-scheduler') . '</label></th>';
        echo '  <td>';
        echo '    <input name="slug" id="slug" type="text" class="regular-text" value="' . esc_attr($slug) . '">';
        echo '    <p class="description">' . esc_html__('Leave blank to auto-generate from name.', 'adoration-scheduler') . '</p>';
        echo '  </td>';
        echo '</tr>';

        echo '<tr>';
        echo '  <th scope="row">' . esc_html__('Active', 'adoration-scheduler') . '</th>';
        echo '  <td>';
        echo '    <label><input type="checkbox" name="is_active" value="1" ' . checked($is_active, 1, false) . '> ' . esc_html__('Active chapel (visible in dropdowns).', 'adoration-scheduler') . '</label>';
        echo '  </td>';
        echo '</tr>';

        echo '</table>';

        echo '<p>';
        echo '  <button type="submit" name="adoration_save_chapel" class="button button-primary">' . esc_html__('Save Chapel', 'adoration-scheduler') . '</button> ';
        echo '  <a class="button" href="' . esc_url($this->page_url()) . '">' . esc_html__('Cancel', 'adoration-scheduler') . '</a>';
        echo '</p>';

        echo '</form>';
    }

    private function page_url(array $args = []): string {
        $base = admin_url('admin.php');
        $args = array_merge(['page' => self::PAGE_SLUG], $args);
        return add_query_arg($args, $base);
    }

    private function redirect_with_notice(string $key): void {
        wp_safe_redirect($this->page_url(['adoration_notice' => $key]));
        exit;
    }

    private function render_notice_from_key(string $key): void {
        switch ($key) {
            case 'chapel_added':
                $this->admin_notice('success', __('Chapel created.', 'adoration-scheduler'));
                break;
            case 'chapel_updated':
                $this->admin_notice('success', __('Chapel updated.', 'adoration-scheduler'));
                break;
            case 'chapel_activated':
                $this->admin_notice('success', __('Chapel activated.', 'adoration-scheduler'));
                break;
            case 'chapel_deactivated':
                $this->admin_notice('success', __('Chapel deactivated.', 'adoration-scheduler'));
                break;
            case 'chapel_deleted':
                $this->admin_notice('success', __('Chapel deleted.', 'adoration-scheduler'));
                break;

            case 'cannot_deactivate_last':
                $this->admin_notice('error', __('You cannot deactivate the last active chapel.', 'adoration-scheduler'));
                break;
            case 'cannot_delete_default':
                $this->admin_notice('error', __('You cannot delete the default (Main) chapel.', 'adoration-scheduler'));
                break;
            case 'cannot_delete_last':
                $this->admin_notice('error', __('You cannot delete the last remaining chapel.', 'adoration-scheduler'));
                break;

            case 'chapel_not_found':
                $this->admin_notice('error', __('Chapel not found.', 'adoration-scheduler'));
                break;

            case 'error_name_required':
                $this->admin_notice('error', __('Name is required.', 'adoration-scheduler'));
                break;

            case 'chapel_add_failed':
                $this->admin_notice('error', __('Failed to create chapel. Slug may already exist.', 'adoration-scheduler'));
                break;
            case 'chapel_update_failed':
                $this->admin_notice('error', __('Failed to update chapel.', 'adoration-scheduler'));
                break;

            case 'chapel_toggle_failed':
                $this->admin_notice('error', __('Could not change chapel status.', 'adoration-scheduler'));
                break;
            case 'chapel_delete_failed':
                $this->admin_notice('error', __('Could not delete chapel.', 'adoration-scheduler'));
                break;
        }
    }

    private function admin_notice(string $type, string $message): void {
        $type = in_array($type, ['success','error','warning','info'], true) ? $type : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function reassign_chapel_usage(int $from_id, int $to_id): void {
        global $wpdb;

        $from_id = (int)$from_id;
        $to_id   = (int)$to_id;
        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) return;

        $schedules_table = $wpdb->prefix . 'adoration_schedules';
        $slots_table     = $wpdb->prefix . 'adoration_slots';

        if ($this->table_exists($schedules_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query($wpdb->prepare(
                "UPDATE {$schedules_table} SET chapel_id = %d WHERE chapel_id = %d",
                $to_id,
                $from_id
            ));
        }

        if ($this->table_exists($slots_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query($wpdb->prepare(
                "UPDATE {$slots_table} SET chapel_id = %d WHERE chapel_id = %d",
                $to_id,
                $from_id
            ));
        }
    }

    private function table_exists(string $table): bool {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return $exists === $table;
    }
}
