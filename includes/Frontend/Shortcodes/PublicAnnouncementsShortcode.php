<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Domain\Repositories\AnnouncementsRepository;
use AdorationScheduler\Frontend\AnnouncementsRenderer;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode: [adoration_public_announcements limit="10" card="0"]
 *
 * A public, front-page-worthy announcements feed — the public counterpart to
 * the gated [adoration_announcements] (My Adoration portal only, signed-in +
 * approved members). Deliberately does NOT check AccessGateService, same
 * reasoning as OpenHoursShortcode: safe to drop anywhere — including the
 * homepage — without triggering the approval gate or a redirect. Only pulls
 * rows an admin explicitly marked "Show on public front page"
 * (show_public = 1); a private-only announcement never appears here.
 *
 * Renders via the shared AnnouncementsRenderer (UIkit slider when there's
 * more than one live announcement).
 */
class PublicAnnouncementsShortcode
{
    public static function register(): void
    {
        add_shortcode('adoration_public_announcements', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'limit' => 10,
            'card'  => '0',
        ], (array)$atts, 'adoration_public_announcements');

        $uid   = 'aspubannounce_' . substr(wp_hash(uniqid('', true)), 0, 10);
        $limit = max(1, min(50, (int)$atts['limit']));
        $card  = self::wants_card($atts['card']);

        $rows = [];
        try {
            $repo = new AnnouncementsRepository();
            $rows = $repo->list_active_public($limit);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] PublicAnnouncementsShortcode failed: ' . $e->getMessage());
        }

        ob_start();
        ?>
        <div class="adoration-widget adoration-public-announcements uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <?php echo AnnouncementsRenderer::render_slider($rows, $uid); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Duplicated (not pulled from PersonDashboardTrait) since this
     * shortcode is deliberately ungated/public and doesn't use any of that
     * trait's sign-in/gate machinery — same pattern as OpenHoursShortcode,
     * the other public, non-dashboard shortcode in this plugin.
     */
    private static function wants_card($val): bool
    {
        if (is_bool($val)) return $val;
        $val = strtolower(trim((string)$val));
        return in_array($val, ['1', 'yes', 'true', 'on'], true);
    }

    private static function card_class(bool $card): string
    {
        return $card ? 'uk-card uk-card-default uk-card-body uk-width-1-1' : 'adoration-content';
    }
}
