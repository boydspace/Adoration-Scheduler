<?php
namespace AdorationScheduler\Frontend;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shared, theme-safe UIkit bootstrap.
 *
 * Originally lived only inside ScheduleShortcode (added when Andy reported
 * the perpetual weekly-grid modal rendering as plain inline content because
 * this plugin only ever CHECKED for `window.UIkit` — it never loaded one
 * itself). MyAdorationShortcode has its own `uk-modal` popups too (Contact
 * Info + Cancel Signup) and hit the exact same problem, so this was
 * extracted into one shared place both shortcodes can call, instead of each
 * having its own "print once per page" flag (which would risk double
 * injection if both shortcodes ever appear on the same page).
 *
 * Behavior:
 *  - Does nothing if `window.UIkit` already exists — a theme that already
 *    bundles UIkit (in any way, WP-registered or not) is always left alone;
 *    we never load a second copy.
 *  - Otherwise injects a single pinned UIkit build (CSS + JS + icons) from a
 *    CDN, so modals work even on a theme with no UIkit at all.
 *  - A `adoration_scheduler_load_bundled_uikit` filter lets a site owner
 *    force this off entirely.
 */
class UikitLoader
{
    private const UIKIT_CSS_URL   = 'https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css';
    private const UIKIT_JS_URL    = 'https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js';
    private const UIKIT_ICONS_URL = 'https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js';

    private static bool $printed = false;

    /**
     * Print the loader at most once per page, no matter how many times
     * (or from how many different shortcodes) this is called.
     */
    public static function print_once(): string
    {
        if (self::$printed) return '';
        self::$printed = true;

        if (!apply_filters('adoration_scheduler_load_bundled_uikit', true)) {
            return '';
        }

        $css_url   = self::UIKIT_CSS_URL;
        $js_url    = self::UIKIT_JS_URL;
        $icons_url = self::UIKIT_ICONS_URL;

        ob_start();
        ?>
        <script>
        (function() {
            if (window.__adorationUikitLoaderRan) return;
            window.__adorationUikitLoaderRan = true;

            function alreadyHasUikit() {
                return !!(window.UIkit && typeof window.UIkit.modal === 'function');
            }

            function inject() {
                if (alreadyHasUikit()) return; // theme already provides a working UIkit

                var css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = <?php echo json_encode($css_url); ?>;
                document.head.appendChild(css);

                var js = document.createElement('script');
                js.src = <?php echo json_encode($js_url); ?>;
                js.onload = function() {
                    var icons = document.createElement('script');
                    icons.src = <?php echo json_encode($icons_url); ?>;
                    document.head.appendChild(icons);
                };
                document.head.appendChild(js);
            }

            // Give any theme-loaded (possibly deferred) UIkit a moment to
            // register itself before we decide we need our own copy.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', inject);
            } else {
                inject();
            }
        })();
        </script>
        <?php
        return (string)ob_get_clean();
    }
}
