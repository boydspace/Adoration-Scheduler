<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\AnnouncementsRepository;

/**
 * Admin page for the broadcast announcements shown via the front-end
 * [adoration_announcements] shortcode. Mirrors ChapelsPage's
 * constructor + handle_request() (pre-output) + render() pattern.
 */
class AnnouncementsPage {

    private const PAGE_SLUG = 'adoration_scheduler_announcements';

    private AnnouncementsRepository $repo;

    public function __construct() {
        $this->repo = new AnnouncementsRepository();
    }

    /**
     * Runs BEFORE output (wired via Menu::load_announcements_page()).
     */
    public function handle_request(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adoration-scheduler'), 403);
        }

        // ✅ Create / Update
        if (isset($_POST['adoration_save_announcement'])) {
            check_admin_referer('adoration_save_announcement');

            $id    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $title = isset($_POST['title']) ? sanitize_text_field((string)$_POST['title']) : '';
            $body  = isset($_POST['body']) ? wp_kses_post((string) wp_unslash($_POST['body'])) : '';

            if ($title === '') {
                $this->redirect_with_notice('error_title_required');
            }

            if ($id > 0) {
                $ok = $this->repo->update($id, $title, $body);
                $this->redirect_with_notice($ok ? 'announcement_updated' : 'announcement_update_failed');
            }

            $new_id = $this->repo->create($title, $body, get_current_user_id());
            $this->redirect_with_notice($new_id > 0 ? 'announcement_added' : 'announcement_add_failed');
        }

        $action = isset($_GET['action']) ? sanitize_text_field((string)$_GET['action']) : '';
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        // ✅ Activate / Deactivate
        if (($action === 'activate' || $action === 'deactivate') && $id > 0) {
            check_admin_referer('adoration_announcement_toggle_' . $id);

            $ok = $this->repo->set_active($id, $action === 'activate');
            $this->redirect_with_notice($ok
                ? ($action === 'activate' ? 'announcement_activated' : 'announcement_deactivated')
                : 'announcement_toggle_failed'
            );
        }

        // ✅ Delete
        if ($action === 'delete' && $id > 0) {
            check_admin_referer('adoration_announcement_delete_' . $id);

            $ok = $this->repo->delete($id);
            $this->redirect_with_notice($ok ? 'announcement_deleted' : 'announcement_delete_failed');
        }
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adoration-scheduler'), 403);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Announcements', 'adoration-scheduler') . '</h1>';
        \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_announcements');
        echo '<p class="description">' . esc_html__('Shown on the front end via the [adoration_announcements] shortcode.', 'adoration-scheduler') . '</p>';

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

        echo '<h2>' . esc_html__('New Announcement', 'adoration-scheduler') . '</h2>';
        $this->render_form(['id' => 0, 'title' => '', 'body' => '']);

        echo '<h2 style="margin-top:24px;">' . esc_html__('All Announcements', 'adoration-scheduler') . '</h2>';

        $rows = $this->repo->list_all(200, 0);

        if (empty($rows)) {
            echo '<p>' . esc_html__('No announcements yet.', 'adoration-scheduler') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Title', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Status', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Posted', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Actions', 'adoration-scheduler') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $rid       = (int)($row['id'] ?? 0);
            $title     = (string)($row['title'] ?? '');
            $is_active = !empty($row['is_active']);
            $created   = (string)($row['created_at'] ?? '');
            $created_lbl = $created ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($created)) : '';

            $toggle_action = $is_active ? 'deactivate' : 'activate';
            $toggle_label  = $is_active ? __('Deactivate', 'adoration-scheduler') : __('Activate', 'adoration-scheduler');
            $toggle_nonce  = 'adoration_announcement_toggle_' . $rid;
            $delete_nonce  = 'adoration_announcement_delete_' . $rid;

            echo '<tr>';
            echo '<td><strong>' . esc_html($title) . '</strong></td>';
            echo '<td>' . ($is_active
                ? '<span style="color:#00a32a;">' . esc_html__('Active', 'adoration-scheduler') . '</span>'
                : '<span style="color:#787c82;">' . esc_html__('Inactive', 'adoration-scheduler') . '</span>') . '</td>';
            echo '<td>' . esc_html($created_lbl) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . esc_url($this->page_url(['action' => 'edit', 'id' => $rid])) . '">' . esc_html__('Edit', 'adoration-scheduler') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url(wp_nonce_url($this->page_url(['action' => $toggle_action, 'id' => $rid]), $toggle_nonce)) . '">' . esc_html($toggle_label) . '</a> ';
            echo '<a class="button button-small" style="color:#b32d2e;" href="' . esc_url(wp_nonce_url($this->page_url(['action' => 'delete', 'id' => $rid]), $delete_nonce)) . '" onclick="return confirm(\'' . esc_js(__('Delete this announcement?', 'adoration-scheduler')) . '\');">' . esc_html__('Delete', 'adoration-scheduler') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_edit_form(int $id): void {
        $row = $this->repo->find($id);
        if (!$row) {
            $this->admin_notice('error', __('Announcement not found.', 'adoration-scheduler'));
            echo '<p><a class="button" href="' . esc_url($this->page_url()) . '">' . esc_html__('Back', 'adoration-scheduler') . '</a></p>';
            return;
        }

        echo '<p><a class="button" href="' . esc_url($this->page_url()) . '">&larr; ' . esc_html__('Back to Announcements', 'adoration-scheduler') . '</a></p>';
        echo '<h2>' . esc_html__('Edit Announcement', 'adoration-scheduler') . '</h2>';

        $this->render_form($row);
    }

    private function render_form(array $row): void {
        $id    = (int)($row['id'] ?? 0);
        $title = (string)($row['title'] ?? '');
        $body  = (string)($row['body'] ?? '');

        echo '<form method="post" style="max-width: 720px;">';
        wp_nonce_field('adoration_save_announcement');

        echo '<input type="hidden" name="id" value="' . esc_attr((string)$id) . '">';

        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '  <th scope="row"><label for="title">' . esc_html__('Title', 'adoration-scheduler') . '</label></th>';
        echo '  <td><input name="title" id="title" type="text" class="regular-text" value="' . esc_attr($title) . '" required></td>';
        echo '</tr>';

        echo '<tr>';
        echo '  <th scope="row"><label for="body">' . esc_html__('Message', 'adoration-scheduler') . '</label></th>';
        echo '  <td><textarea name="body" id="body" rows="5" class="large-text">' . esc_textarea($body) . '</textarea></td>';
        echo '</tr>';
        echo '</table>';

        echo '<p>';
        echo '  <button type="submit" name="adoration_save_announcement" class="button button-primary">' . esc_html__('Save Announcement', 'adoration-scheduler') . '</button> ';
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
            case 'announcement_added':
                $this->admin_notice('success', __('Announcement posted.', 'adoration-scheduler'));
                break;
            case 'announcement_updated':
                $this->admin_notice('success', __('Announcement updated.', 'adoration-scheduler'));
                break;
            case 'announcement_activated':
                $this->admin_notice('success', __('Announcement activated.', 'adoration-scheduler'));
                break;
            case 'announcement_deactivated':
                $this->admin_notice('success', __('Announcement deactivated.', 'adoration-scheduler'));
                break;
            case 'announcement_deleted':
                $this->admin_notice('success', __('Announcement deleted.', 'adoration-scheduler'));
                break;
            case 'error_title_required':
                $this->admin_notice('error', __('Title is required.', 'adoration-scheduler'));
                break;
            case 'announcement_add_failed':
                $this->admin_notice('error', __('Failed to post announcement.', 'adoration-scheduler'));
                break;
            case 'announcement_update_failed':
                $this->admin_notice('error', __('Failed to update announcement.', 'adoration-scheduler'));
                break;
            case 'announcement_toggle_failed':
                $this->admin_notice('error', __('Could not change announcement status.', 'adoration-scheduler'));
                break;
            case 'announcement_delete_failed':
                $this->admin_notice('error', __('Could not delete announcement.', 'adoration-scheduler'));
                break;
        }
    }

    private function admin_notice(string $type, string $message): void {
        $type = in_array($type, ['success','error','warning','info'], true) ? $type : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
