<?php
namespace AdorationScheduler\Frontend;

if ( ! defined('ABSPATH') ) exit;

/**
 * Ensures the My Adoration page renders even if the shortcode was removed.
 */
class MyAdorationPageService
{
    public const OPT_PAGE_ID   = 'adoration_scheduler_my_adoration_page_id';
    public const DEFAULT_SLUG  = 'my-adoration';
    public const SHORTCODE     = 'adoration_my_adoration';

    public static function register(): void
    {
        // ✅ Must run BEFORE WordPress's own do_shortcode() (priority 11 on
        // `the_content`) — otherwise has_shortcode() below is checking
        // content that's already had the real shortcode replaced by its
        // rendered HTML, always looks "missing", and appends a second,
        // now-too-late copy that never gets processed (prints as literal
        // "[adoration_my_adoration]" text). This was the actual cause of
        // the shortcode-showing-as-text bug.
        add_filter('the_content', [__CLASS__, 'maybe_inject_shortcode'], 9);
    }

    public static function maybe_inject_shortcode(string $content): string
    {
        // Only affect front-end main content
        if (is_admin() || is_feed() || is_preview() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $content;
        }

        if (!is_singular('page') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $page_id = (int) get_queried_object_id();
        if ($page_id <= 0) return $content;

        // Optional: only inject on published pages
        $status = (string) get_post_status($page_id);
        if ($status !== 'publish') {
            return $content;
        }

        // Avoid injecting into password-protected pages (rare, but safer)
        $post = get_post($page_id);
        if ($post && ! empty($post->post_password)) {
            return $content;
        }

        // Match by saved page ID first (best), fallback to slug match
        $saved_id = (int) get_option(self::OPT_PAGE_ID, 0);

        $is_target =
            ($saved_id > 0 && $page_id === $saved_id)
            || (get_post_field('post_name', $page_id) === self::DEFAULT_SLUG);

        if (!$is_target) {
            return $content;
        }

        // If shortcode is already present, do nothing
        if (function_exists('has_shortcode') && has_shortcode((string)$content, self::SHORTCODE)) {
            return $content;
        }

        // Append shortcode when missing
        $content = rtrim((string)$content);
        if ($content !== '') {
            $content .= "\n\n";
        }
        $content .= '[' . self::SHORTCODE . ']';

        return $content;
    }
}
