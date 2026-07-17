<?php
namespace AdorationScheduler\Admin\Ajax;

use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) exit;

class PeopleSearchAjax {

    public static function register(): void {
        add_action('wp_ajax_adoration_people_search', [__CLASS__, 'handle']);
    }

    public static function handle(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, 'adoration_people_search') ) {
            wp_send_json_error(['message' => 'Bad nonce'], 400);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $q = trim($q);

        $limit = 20;
        $repo = new PersonsRepository();

        $results = [];

        // If numeric, try exact ID first
        if ($q !== '' && ctype_digit($q)) {
            $id = (int)$q;
            if ($id > 0) {
                $p = $repo->find($id);
                if ($p && isset($p['id'])) {
                    $results[] = self::format_result($p);
                }
            }
        }

        // ✅ Allow empty query for "dropdown" behavior (shows first page)
        $rows = $repo->list_all_people_with_stats($limit, 0, $q);

        foreach ($rows as $p) {
            if (!isset($p['id'])) continue;
            $rid = (int)$p['id'];
            if ($rid <= 0) continue;

            $already = false;
            foreach ($results as $r) {
                if ((int)$r['id'] === $rid) { $already = true; break; }
            }
            if ($already) continue;

            $results[] = self::format_result($p);
            if (count($results) >= $limit) break;
        }

        wp_send_json_success([
            'results' => $results,
        ]);
    }

    private static function format_result(array $p): array {
        $id    = (int)($p['id'] ?? 0);
        $first = trim((string)($p['first_name'] ?? ''));
        $last  = trim((string)($p['last_name'] ?? ''));
        $email = trim((string)($p['email'] ?? ''));

        $name = trim($first . ' ' . $last);
        if ($name === '') $name = '—';

        $suffix = ($email !== '') ? " ({$email})" : '';
        $label = "{$id} — {$name}{$suffix}";

        return [
            'id'    => $id,
            'label' => $label,
        ];
    }
}
