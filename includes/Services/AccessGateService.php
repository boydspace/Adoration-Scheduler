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

    /**
     * Must match Installer's private OPT_REQUEST_ACCESS_PAGE_ID /
     * REQUEST_ACCESS_SLUG — duplicated here (rather than made public over
     * there) to keep this a plain Services-layer class with no dependency
     * on Core\Installer.
     */
    private const OPT_REQUEST_ACCESS_PAGE_ID = 'adoration_scheduler_request_access_page_id';
    private const REQUEST_ACCESS_SLUG        = 'request-access';

    /**
     * Shortcode tags treated as "this whole page IS the gated thing" —
     * used by maybe_redirect_gated_page() to decide whether to send a
     * blocked visitor to the Request Access page entirely.
     *
     * ✅ FIX (2026-07-18): this used to be every gated shortcode
     * (adoration_profile_card, adoration_announcements,
     * adoration_next_adoration_hour, etc.) — but those are small
     * composable widgets meant to be dropped on all kinds of pages
     * alongside unrelated content. A homepage with a login form AND, say,
     * an announcements widget doesn't mean the WHOLE homepage should
     * bounce a logged-out visitor to Request Access — only [adoration_schedule]
     * (a dedicated per-schedule page, one per page by convention) and the
     * "My Adoration" portal page (identified separately, by page ID/slug,
     * in is_my_adoration_page() below — it's a page dedicated to those
     * modular widgets specifically) are narrow/whole-page enough to
     * redirect the entire page for. Every other gated shortcode still
     * falls back to gated_html()'s inline swap — replacing just that one
     * widget's spot, leaving the rest of the page (login forms included)
     * untouched.
     */
    private const FULL_PAGE_GATED_SHORTCODES = [
        'adoration_schedule',
        'adoration_my_adoration',
    ];

    public static function register(): void
    {
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_gated_page']);
    }

    public static function is_gate_enabled(): bool
    {
        $opts = get_option(self::OPTION_NAME, []);
        $opts = is_array($opts) ? $opts : [];

        return !empty($opts['require_approval']);
    }

    /**
     * Redirects a blocked visitor to the Request Access page BEFORE any of
     * this plugin's shortcodes render, so they land on a clean, dedicated
     * page instead of their originally-requested page with the form
     * swapped in for just one shortcode while everything else on that
     * page — theme content, other plugins, anything outside this plugin's
     * control — still shows around it.
     *
     * Runs on `template_redirect`, which fires before any theme/page
     * output starts, so wp_safe_redirect() here is safe (unlike trying to
     * redirect from inside a shortcode's own render(), which runs deep
     * inside content output, well after headers are already sent).
     *
     * If this doesn't catch a gated shortcode for some reason (e.g. it was
     * injected by something that doesn't store it in the post's own
     * post_content — a page builder's own content model, a widget, etc.),
     * gated_html() below still catches it inline as a fallback. The
     * visitor ends up blocked either way, just less cleanly in that case.
     */
    public static function maybe_redirect_gated_page(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        if (self::visitor_is_allowed()) {
            return;
        }

        $post = get_queried_object();
        if (!($post instanceof \WP_Post)) {
            return;
        }

        // Never redirect the Request Access page itself — that would loop.
        if (self::is_request_access_page((int)$post->ID)) {
            return;
        }

        // Only redirect for pages that ARE the gated thing (a schedule
        // page, or the dedicated My Adoration portal page) — not any page
        // that merely embeds one small gated widget among other content.
        $is_dedicated_gated_page =
            self::content_has_full_page_shortcode((string)$post->post_content)
            || self::is_my_adoration_page((int)$post->ID);

        if (!$is_dedicated_gated_page) {
            return;
        }

        $target = self::request_access_url();
        if ($target === '') {
            return;
        }

        $current = self::current_request_url();
        if ($current !== '') {
            $target = add_query_arg('return', rawurlencode($current), $target);
        }

        wp_safe_redirect($target);
        exit;
    }

    private static function content_has_full_page_shortcode(string $content): bool
    {
        if ($content === '' || !function_exists('has_shortcode')) {
            return false;
        }

        foreach (self::FULL_PAGE_GATED_SHORTCODES as $tag) {
            if (has_shortcode($content, $tag)) {
                return true;
            }
        }

        return false;
    }

    private static function is_request_access_page(int $post_id): bool
    {
        if ($post_id <= 0) return false;

        $saved_id = (int) get_option(self::OPT_REQUEST_ACCESS_PAGE_ID, 0);
        if ($saved_id > 0 && $saved_id === $post_id) {
            return true;
        }

        return get_post_field('post_name', $post_id) === self::REQUEST_ACCESS_SLUG;
    }

    /**
     * True if $post_id is the site's designated "My Adoration" portal page
     * — matched the same way MyAdorationPageService identifies it (saved
     * page ID option first, else the /my-adoration/ slug). That page is
     * dedicated to the modular My Adoration widgets (profile card,
     * standing hours, replacement requests, etc.) even though none of
     * them individually is a "whole page" shortcode the way
     * [adoration_schedule] is — so it still qualifies for the full-page
     * redirect rather than the inline per-widget swap.
     */
    private static function is_my_adoration_page(int $post_id): bool
    {
        if ($post_id <= 0) return false;

        $saved_id = (int) get_option('adoration_scheduler_my_adoration_page_id', 0);
        if ($saved_id > 0 && $saved_id === $post_id) {
            return true;
        }

        return get_post_field('post_name', $post_id) === 'my-adoration';
    }

    /**
     * URL of the Request Access page, resolved the same way Installer
     * provisions it: saved page ID first, else the /request-access/ slug.
     */
    public static function request_access_url(): string
    {
        $saved_id = (int) get_option(self::OPT_REQUEST_ACCESS_PAGE_ID, 0);
        if ($saved_id > 0) {
            $url = get_permalink($saved_id);
            if ($url) {
                return $url;
            }
        }

        return home_url('/' . self::REQUEST_ACCESS_SLUG . '/');
    }

    private static function current_request_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if ($host === '') return '';

        return $scheme . $host . $uri;
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
     * True once we've already rendered the Request Access form during
     * this page load. Reset per-request (this is a plain static, so it's
     * naturally fresh on every new PHP process/request).
     *
     * NOTE: since maybe_redirect_gated_page() now sends a blocked visitor
     * to the dedicated Request Access page before any shortcode ever
     * renders, gated_html() below should rarely fire in practice — it's
     * the inline fallback for the cases the early redirect doesn't catch.
     */
    private static bool $gate_html_shown = false;

    /**
     * Renders [adoration_request_access] — but only the FIRST time this is
     * called during a page load. Every other call returns an empty string.
     *
     * WHY THIS EXISTS: a page built from several of this plugin's shortcodes
     * (e.g. a typical "My Adoration" page, which is commonly 5-7 separate
     * modular shortcodes — see PersonDashboardTrait) each independently
     * call visitor_is_allowed() and, when it's false, used to each render
     * their own full copy of the Request Access form. An unapproved visitor
     * on a page with 7 gated shortcodes would see the same form stacked 7
     * times. Every gated shortcode should call THIS method instead of
     * calling do_shortcode('[adoration_request_access]') directly, so the
     * visitor sees exactly one form no matter how many gated shortcodes
     * are on the page.
     */
    public static function gated_html(): string
    {
        if (self::$gate_html_shown) {
            return '';
        }

        self::$gate_html_shown = true;

        return do_shortcode('[adoration_request_access]');
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
