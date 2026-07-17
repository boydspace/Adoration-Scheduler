<?php
namespace AdorationScheduler\Frontend\Ajax;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Services\MagicLinkService;

if ( ! defined('ABSPATH') ) exit;

/**
 * AJAX search backing the "ask a specific person" picker in the Request
 * Replacement modal (Direct-to-person swap requests). Public-facing but
 * signed-in-only: authenticated via
 * MagicLinkService::current_person_or_admin_match() (the parishioner
 * session cookie, or a WP staff admin's own email-matched person record).
 * Deliberately returns only id + display
 * name — no email/phone — via PersonsRepository::search_by_name_for_target(),
 * a narrower method than the admin-facing people search.
 */
class PersonTargetSearchAjax
{
    public const ACTION = 'adoration_search_replacement_target';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, self::ACTION) ) {
            wp_send_json_error(['message' => 'Bad nonce'], 400);
        }

        $person = MagicLinkService::current_person_or_admin_match();
        $person_id = (int)($person['id'] ?? 0);
        if ($person_id <= 0) {
            wp_send_json_error(['message' => 'Please sign in again.'], 403);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $q = trim($q);

        // Require a couple characters before hitting the DB — avoids
        // "browse the whole directory" behavior via an empty/1-char query.
        if (strlen($q) < 2) {
            wp_send_json_success(['results' => []]);
        }

        $repo = new PersonsRepository();
        $rows = $repo->search_by_name_for_target($q, $person_id, 8);

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
