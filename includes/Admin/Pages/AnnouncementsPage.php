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

            // ✅ Visibility (2026-07-19): independent checkboxes, not a
            // 3-way enum — an announcement can go to the public front page,
            // to signed-in members, both, or (if an admin unchecks both,
            // e.g. to draft it) neither.
            $show_public  = !empty($_POST['show_public']);
            $show_private = !empty($_POST['show_private']);
            $image_id     = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;

            if ($title === '') {
                $this->redirect_with_notice('error_title_required');
            }

            if ($id > 0) {
                $ok = $this->repo->update($id, $title, $body, $show_public, $show_private, $image_id > 0 ? $image_id : null);
                $this->redirect_with_notice($ok ? 'announcement_updated' : 'announcement_update_failed');
            }

            $new_id = $this->repo->create($title, $body, get_current_user_id(), $show_public, $show_private, $image_id > 0 ? $image_id : null);
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

        // Reordering is now handled entirely client-side via drag-and-drop
        // (see render_list_and_add_form()'s JS) + AnnouncementsReorderAjax —
        // an earlier Up/Down-button version lived here but Andy reported it
        // didn't do anything, so it was replaced rather than debugged in
        // place.
    }

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adoration-scheduler'), 403);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Announcements', 'adoration-scheduler') . '</h1>';
        \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_announcements');
        echo '<p class="description">' . esc_html__('Shown on the front end via [adoration_announcements] (members) and [adoration_public_announcements] (public). Use the arrows below to control the order they appear in.', 'adoration-scheduler') . '</p>';

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
        $this->render_form(['id' => 0, 'title' => '', 'body' => '', 'show_public' => 0, 'show_private' => 1, 'image_id' => null]);

        echo '<h2 style="margin-top:24px;">' . esc_html__('All Announcements', 'adoration-scheduler') . '</h2>';

        $rows = $this->repo->list_all(200, 0);

        if (empty($rows)) {
            echo '<p>' . esc_html__('No announcements yet.', 'adoration-scheduler') . '</p>';
            return;
        }

        // ✅ Drag-to-reorder (2026-07-19): replaces an earlier Up/Down
        // button version Andy reported as non-functional. jQuery UI
        // Sortable is bundled with WP core (no extra library); dragging a
        // row posts the full new order to AnnouncementsReorderAjax, which
        // persists it in one pass via AnnouncementsRepository::reorder().
        wp_enqueue_script('jquery-ui-sortable');
        $reorder_nonce = wp_create_nonce('adoration_reorder_announcements');

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:36px;"></th>';
        echo '<th style="width:60px;">' . esc_html__('Image', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Title', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Visibility', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Status', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Posted', 'adoration-scheduler') . '</th>';
        echo '<th>' . esc_html__('Actions', 'adoration-scheduler') . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="adoration-announcements-sortable">';

        foreach ($rows as $row) {
            $rid       = (int)($row['id'] ?? 0);
            $title     = (string)($row['title'] ?? '');
            $is_active = !empty($row['is_active']);
            $created   = (string)($row['created_at'] ?? '');
            $created_lbl = $created ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($created)) : '';

            $show_public  = !empty($row['show_public']);
            $show_private = !empty($row['show_private']);
            $image_id     = (int)($row['image_id'] ?? 0);

            $toggle_action = $is_active ? 'deactivate' : 'activate';
            $toggle_label  = $is_active ? __('Deactivate', 'adoration-scheduler') : __('Activate', 'adoration-scheduler');
            $toggle_nonce  = 'adoration_announcement_toggle_' . $rid;
            $delete_nonce  = 'adoration_announcement_delete_' . $rid;

            $visibility_bits = [];
            if ($show_public)  $visibility_bits[] = esc_html__('Public', 'adoration-scheduler');
            if ($show_private) $visibility_bits[] = esc_html__('Members', 'adoration-scheduler');
            $visibility_lbl = !empty($visibility_bits) ? implode(' + ', $visibility_bits) : esc_html__('Hidden', 'adoration-scheduler');

            echo '<tr data-id="' . esc_attr((string)$rid) . '">';
            echo '<td class="adoration-reorder-handle" style="cursor:move;text-align:center;color:#787c82;" aria-label="' . esc_attr__('Drag to reorder', 'adoration-scheduler') . '" title="' . esc_attr__('Drag to reorder', 'adoration-scheduler') . '">&#9776;</td>';
            echo '<td>';
            if ($image_id > 0) {
                $thumb = wp_get_attachment_image($image_id, [40, 40], true, ['style' => 'border-radius:3px;']);
                echo $thumb !== '' ? $thumb : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '</td>';
            echo '<td><strong>' . esc_html($title) . '</strong></td>';
            echo '<td>' . esc_html($visibility_lbl) . '</td>';
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
        echo '<p class="description" style="margin-top:8px;">' . esc_html__('Drag the ☰ handle to reorder — the new order is saved automatically.', 'adoration-scheduler') . '</p>';

        // ✅ FIX (2026-07-19): this used to be a raw <script> tag echoed
        // right here in the page body — which runs at that exact point in
        // the HTML stream, BEFORE WordPress prints the enqueued
        // jquery-ui-sortable script (and its jquery-ui-core/jquery
        // dependencies) down in the admin footer. So $.fn.sortable didn't
        // exist yet, the "if (!$.fn.sortable) return;" guard silently
        // bailed, and dragging fell back to the browser's native text
        // selection — exactly what Andy saw. wp_add_inline_script() prints
        // this JS immediately after jquery-ui-sortable's OWN script tag,
        // wherever WordPress decides to place that (header or footer),
        // guaranteeing correct load order regardless.
        $inline_js = '
jQuery(function($) {
    if (!$.fn.sortable) return;

    var $list = $("#adoration-announcements-sortable");
    if (!$list.length) return;

    // Standard "fix row width while dragging" helper — a <tr> pulled out
    // of table layout by Sortable collapses to content width otherwise,
    // since it is briefly outside the <table>\'s own column-sizing context.
    function fixHelper(e, ui) {
        ui.children().each(function() {
            $(this).width($(this).width());
        });
        return ui;
    }

    $list.sortable({
        handle: ".adoration-reorder-handle",
        helper: fixHelper,
        axis: "y",
        update: function() {
            var order = $list.find("tr").map(function() {
                return $(this).data("id");
            }).get();

            $.post(ajaxurl, {
                action: "adoration_reorder_announcements",
                _wpnonce: "' . esc_js($reorder_nonce) . '",
                order: order
            });
        }
    });
});
';

        wp_add_inline_script('jquery-ui-sortable', $inline_js);
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
        $id           = (int)($row['id'] ?? 0);
        $title        = (string)($row['title'] ?? '');
        $body         = (string)($row['body'] ?? '');
        $show_public  = !empty($row['show_public']);
        $show_private = array_key_exists('show_private', $row) ? !empty($row['show_private']) : true;
        $image_id     = (int)($row['image_id'] ?? 0);

        // ✅ Image picker (2026-07-19): first use of the WP media library in
        // this plugin — wp_enqueue_media() + a plain wp.media() frame,
        // storing just the attachment ID in a hidden input. No library
        // beyond WP core's own bundled media JS.
        wp_enqueue_media();

        $image_url = $image_id > 0 ? wp_get_attachment_image_url($image_id, 'medium') : '';

        echo '<form method="post" style="max-width: 720px;" id="adoration-announcement-form">';
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

        echo '<tr>';
        echo '  <th scope="row">' . esc_html__('Show', 'adoration-scheduler') . '</th>';
        echo '  <td>';
        echo '    <label><input type="checkbox" name="show_public" value="1"' . checked($show_public, true, false) . '> ' . esc_html__('Public front page', 'adoration-scheduler') . '</label><br>';
        echo '    <label><input type="checkbox" name="show_private" value="1"' . checked($show_private, true, false) . '> ' . esc_html__('Signed-in members (My Adoration)', 'adoration-scheduler') . '</label>';
        echo '    <p class="description">' . esc_html__('Independent — check either, both, or neither (unchecking both hides it everywhere without deleting or deactivating it).', 'adoration-scheduler') . '</p>';
        echo '  </td>';
        echo '</tr>';

        echo '<tr>';
        echo '  <th scope="row">' . esc_html__('Image', 'adoration-scheduler') . '</th>';
        echo '  <td>';
        echo '    <input type="hidden" name="image_id" id="announcement_image_id" value="' . esc_attr((string)$image_id) . '">';
        echo '    <div id="announcement_image_preview" style="margin-bottom:8px;' . ($image_url === '' ? 'display:none;' : '') . '">';
        echo '      <img src="' . esc_url((string)$image_url) . '" style="max-width:200px;height:auto;display:block;border-radius:3px;">';
        echo '    </div>';
        echo '    <button type="button" class="button" id="announcement_image_choose">' . esc_html__('Choose Image', 'adoration-scheduler') . '</button> ';
        echo '    <button type="button" class="button" id="announcement_image_remove"' . ($image_url === '' ? ' style="display:none;"' : '') . '>' . esc_html__('Remove Image', 'adoration-scheduler') . '</button>';
        echo '    <p class="description">' . esc_html__('Optional — used as the card image when several announcements slide as a carousel.', 'adoration-scheduler') . '</p>';
        echo '  </td>';
        echo '</tr>';
        echo '</table>';

        echo '<p>';
        echo '  <button type="submit" name="adoration_save_announcement" class="button button-primary">' . esc_html__('Save Announcement', 'adoration-scheduler') . '</button> ';
        echo '  <a class="button" href="' . esc_url($this->page_url()) . '">' . esc_html__('Cancel', 'adoration-scheduler') . '</a>';
        echo '</p>';

        echo '</form>';
        ?>
        <script>
        (function() {
            var frame = null;
            var chooseBtn = document.getElementById('announcement_image_choose');
            var removeBtn = document.getElementById('announcement_image_remove');
            var input     = document.getElementById('announcement_image_id');
            var preview   = document.getElementById('announcement_image_preview');

            if (!chooseBtn || !window.wp || !wp.media) return;

            chooseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }

                frame = wp.media({
                    title: <?php echo wp_json_encode(__('Select Announcement Image', 'adoration-scheduler')); ?>,
                    button: { text: <?php echo wp_json_encode(__('Use this image', 'adoration-scheduler')); ?> },
                    library: { type: 'image' },
                    multiple: false
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    input.value = attachment.id;
                    var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
                    preview.innerHTML = '<img src="' + url + '" style="max-width:200px;height:auto;display:block;border-radius:3px;">';
                    preview.style.display = '';
                    removeBtn.style.display = '';
                });

                frame.open();
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    input.value = '';
                    preview.style.display = 'none';
                    preview.innerHTML = '';
                    removeBtn.style.display = 'none';
                });
            }
        })();
        </script>
        <?php
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
