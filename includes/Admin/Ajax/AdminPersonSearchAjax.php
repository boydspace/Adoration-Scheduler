<?php
namespace AdorationScheduler\Admin\Ajax;

use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * Admin-only typeahead search backing the "Existing person" picker in the
 * per-schedule Add Signup / Add Standing Commitment modals (2026-07-21,
 * no-account adorers) — lets an admin reuse an already-created person
 * (most importantly one with no email, who can't be found by the usual
 * email-based upsert) instead of creating a duplicate record every time.
 *
 * Deliberately a SEPARATE endpoint from PersonTargetSearchAjax rather than
 * widening that one's auth: PersonTargetSearchAjax is public (`nopriv`)
 * and gated on a parishioner's own front-end session — mixing an
 * admin-capability bypass into that trust boundary would make its security
 * model harder to audit. This one is capability-gated only, no `nopriv`.
 * Both wrap the same PersonsRepository::search_by_name_for_target() query.
 */
class AdminPersonSearchAjax
{
    public const ACTION = 'adoration_admin_search_person';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        if ( ! current_user_can('adoration_manage_signups') && ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, self::ACTION) ) {
            wp_send_json_error(['message' => 'Bad nonce'], 400);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $q = trim($q);

        if (strlen($q) < 2) {
            wp_send_json_success(['results' => []]);
        }

        $repo = new PersonsRepository();
        $rows = $repo->search_by_name_for_target($q, 0, 8);

        $results = [];
        foreach ($rows as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) continue;

            $title = trim((string)($p['title'] ?? ''));
            $first = trim((string)($p['first_name'] ?? ''));
            $last  = trim((string)($p['last_name'] ?? ''));
            $parish = trim((string)($p['parish'] ?? ''));

            $name = trim(trim($title . ' ' . $first) . ' ' . $last);
            if ($name === '') $name = '(unnamed)';

            $label = $name . ($parish !== '' ? " — {$parish}" : '');

            $results[] = [
                'id'    => $id,
                'label' => $label,
            ];
        }

        wp_send_json_success(['results' => $results]);
    }
}
