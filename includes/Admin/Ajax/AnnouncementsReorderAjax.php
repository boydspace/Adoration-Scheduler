<?php
namespace AdorationScheduler\Admin\Ajax;

use AdorationScheduler\Domain\Repositories\AnnouncementsRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * AJAX endpoint backing drag-to-reorder on the Announcements admin page
 * (jQuery UI Sortable — bundled with WP core, no extra library). Replaces
 * an earlier Up/Down button version Andy reported as non-functional.
 *
 * Receives the full list of announcement IDs in their new on-screen order
 * after a drag and persists it in one pass via
 * AnnouncementsRepository::reorder().
 */
class AnnouncementsReorderAjax {

    public static function register(): void {
        add_action('wp_ajax_adoration_reorder_announcements', [__CLASS__, 'handle']);
    }

    public static function handle(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, 'adoration_reorder_announcements') ) {
            wp_send_json_error(['message' => 'Bad nonce'], 400);
        }

        $ids_raw = isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : [];
        $ids = array_values(array_filter(array_map('intval', $ids_raw), fn($v) => $v > 0));

        if (empty($ids)) {
            wp_send_json_error(['message' => 'No order received'], 400);
        }

        try {
            $repo = new AnnouncementsRepository();
            $ok = $repo->reorder($ids);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] AnnouncementsReorderAjax failed: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Server error'], 500);
        }

        if (!$ok) {
            wp_send_json_error(['message' => 'Could not save order'], 500);
        }

        wp_send_json_success(['reordered' => count($ids)]);
    }
}
