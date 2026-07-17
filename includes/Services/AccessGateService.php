<?php
namespace AdorationScheduler\Services;

use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * Central "is this visitor allowed to see scheduling content?" check.
 *
 * Reads the same option AccessSettingsPage writes
 * (`adoration_scheduler_access_options['require_approval']`) directly,
 * rather than depending on the Admin\Pages class, to keep this a plain
 * Services-layer check callable from any front-end shortcode.
 *
 * Deliberately scoped to this plugin's own pages only — it never touches
 * WordPress's own page/post visibility for the rest of the site.
 */
class AccessGateService
{
    private const OPTION_NAME = 'adoration_scheduler_access_options';

    public static function is_gate_enabled(): bool
    {
        $opts = get_option(self::OPTION_NAME, []);
        $opts = is_array($opts) ? $opts : [];

        return !empty($opts['require_approval']);
    }

    /**
     * True if the current visitor may see scheduling content right now.
     * WP admins/staff (anyone who can already manage some part of this
     * plugin) always get through, regardless of the gate — mirrors the
     * existing "let WP admins into My Adoration" behavior.
     */
    public static function visitor_is_allowed(): bool
    {
        if (self::current_user_is_staff()) {
            return true;
        }

        if (!self::is_gate_enabled()) {
            return true;
        }

        $status = self::current_person_status();
        return $status === PersonsRepository::STATUS_APPROVED;
    }

    /**
     * Approval status of the currently signed-in person (via magic-link
     * session), or null if nobody's signed in at all.
     */
    public static function current_person_status(): ?string
    {
        $person = MagicLinkService::current_person();
        if (!$person) return null;

        $repo = new PersonsRepository();
        return $repo->approval_status_of($person);
    }

    private static function current_user_is_staff(): bool
    {
        if (!is_user_logged_in()) return false;

        foreach ([
            'manage_options',
            'adoration_manage_signups',
            'adoration_manage_schedules',
            'adoration_manage_people',
            'adoration_manage_settings',
        ] as $cap) {
            if (current_user_can($cap)) return true;
        }

        return false;
    }
}
