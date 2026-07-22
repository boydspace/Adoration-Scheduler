<?php
namespace AdorationScheduler\Frontend\Ajax;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Frontend\Shortcodes\MyScheduleShortcode;
use AdorationScheduler\Frontend\Shortcodes\NeededReplacementsShortcode;
use AdorationScheduler\Frontend\Shortcodes\MyReplacementRequestsShortcode;
use AdorationScheduler\Frontend\Shortcodes\ProfileCardShortcode;
use AdorationScheduler\Frontend\Shortcodes\CalendarSubscribeShortcode;
use AdorationScheduler\Frontend\Shortcodes\ReminderPreferencesShortcode;

/**
 * Backs the AJAX conversion of the My Adoration dashboard family (see
 * DashboardActionsAssets): once a row action (cancel, claim, request
 * replacement, update contact info, etc.) submits via fetch() instead of a
 * full page load, this endpoint re-renders the SAME shortcode instance
 * server-side so the widget can swap in fresh HTML rather than reloading
 * the page.
 *
 * Auth/gating is unchanged - each shortcode's render() still calls
 * guard_and_get_person() exactly as it would on a normal page load, so a
 * stale or expired session correctly falls back to the sign-in gate
 * instead of leaking stale data. This is deliberately a small whitelist,
 * not a generic "render any shortcode by name" endpoint.
 */
class WidgetRerenderAjax
{
    public const ACTION = 'adoration_rerender_widget';

    private const MAP = [
        'adoration_my_schedule'             => MyScheduleShortcode::class,
        'adoration_needed_replacements'     => NeededReplacementsShortcode::class,
        'adoration_my_replacement_requests' => MyReplacementRequestsShortcode::class,
        'adoration_profile_card'            => ProfileCardShortcode::class,
        'adoration_calendar_subscribe'      => CalendarSubscribeShortcode::class,
        'adoration_reminder_preferences'    => ReminderPreferencesShortcode::class,
    ];

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, self::ACTION) ) {
            wp_send_json_error(['message' => 'Security check failed. Please reload the page.'], 400);
        }

        $tag = isset($_POST['shortcode']) ? sanitize_key(wp_unslash($_POST['shortcode'])) : '';
        if ($tag === '' || !isset(self::MAP[$tag])) {
            wp_send_json_error(['message' => 'Unknown widget.'], 400);
        }

        // Only the two atts every shortcode in this family accepts are
        // honored here - anything else posted in the JSON blob is ignored.
        $raw_atts = isset($_POST['atts']) ? (string) wp_unslash($_POST['atts']) : '{}';
        $decoded  = json_decode($raw_atts, true);
        $decoded  = is_array($decoded) ? $decoded : [];

        $atts = [];
        if (isset($decoded['redirect'])) $atts['redirect'] = sanitize_text_field((string) $decoded['redirect']);
        if (isset($decoded['card']))     $atts['card']     = sanitize_text_field((string) $decoded['card']);

        $class = self::MAP[$tag];

        try {
            $html = $class::render($atts);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] WidgetRerenderAjax render failed tag=' . $tag . ' err=' . $e->getMessage());
            wp_send_json_error(['message' => 'Could not refresh that section. Please reload the page.'], 500);
        }

        wp_send_json_success(['html' => $html]);
    }
}
