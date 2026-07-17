<?php
namespace AdorationScheduler\Admin\Ajax;

if ( ! defined('ABSPATH') ) exit;

class PeopleMergePreviewAjax {

    public static function register(): void {
        add_action('wp_ajax_adoration_merge_preview', [__CLASS__, 'handle']);
    }

    public static function handle(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, 'adoration_merge_preview') ) {
            wp_send_json_error(['message' => 'Bad nonce'], 400);
        }

        $from_id = isset($_GET['from_person_id']) ? (int)$_GET['from_person_id'] : 0;
        $to_id   = isset($_GET['to_person_id']) ? (int)$_GET['to_person_id'] : 0;

        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            wp_send_json_success([
                'overlap_count' => 0,
            ]);
        }

        // We try to compute overlap using whatever SignupsRepository methods exist.
        $slot_ids_from = self::get_slot_ids_for_person($from_id);
        $slot_ids_to   = self::get_slot_ids_for_person($to_id);

        if ($slot_ids_from === null || $slot_ids_to === null) {
            // Graceful degrade if repo methods aren’t available yet.
            wp_send_json_success([
                'overlap_count' => null,
                'unavailable'   => true,
            ]);
        }

        $set_to = array_fill_keys($slot_ids_to, true);

        $overlap = 0;
        foreach ($slot_ids_from as $sid) {
            if (isset($set_to[$sid])) $overlap++;
        }

        wp_send_json_success([
            'overlap_count' => $overlap,
            'unavailable'   => false,
        ]);
    }

    /**
     * Returns an array of slot IDs for a person, or null if we can’t figure it out.
     */
    private static function get_slot_ids_for_person(int $person_id): ?array {
        // Try likely repository class names
        $candidates = [
            '\\AdorationScheduler\\Domain\\Repositories\\SignupsRepository',
            '\\AdorationScheduler\\Repositories\\SignupsRepository',
        ];

        $repoClass = null;
        foreach ($candidates as $c) {
            if (class_exists($c)) { $repoClass = $c; break; }
        }
        if (!$repoClass) return null;

        $repo = new $repoClass();

        // Try a few common method names
        $methods = [
            'get_slot_ids_for_person',
            'list_slot_ids_for_person',
            'get_signup_slot_ids_for_person',
            'get_signups_for_person',
            'list_signups_for_person',
            'find_by_person_id',
        ];

        foreach ($methods as $m) {
            if (!method_exists($repo, $m)) continue;

            try {
                $rows = $repo->$m($person_id);

                // If it returns plain ints (slot IDs)
                if (is_array($rows) && (count($rows) === 0 || is_int(reset($rows)))) {
                    $out = array_map('intval', $rows);
                    $out = array_values(array_filter($out, fn($v) => $v > 0));
                    return $out;
                }

                // If it returns rows like ['slot_id' => 123] or objects with ->slot_id
                if (is_array($rows)) {
                    $out = [];
                    foreach ($rows as $r) {
                        if (is_array($r) && isset($r['slot_id'])) {
                            $out[] = (int)$r['slot_id'];
                        } elseif (is_object($r) && isset($r->slot_id)) {
                            $out[] = (int)$r->slot_id;
                        }
                    }
                    $out = array_values(array_filter($out, fn($v) => $v > 0));
                    return $out;
                }
            } catch (\Throwable $e) {
                // Try next method
            }
        }

        return null;
    }
}
