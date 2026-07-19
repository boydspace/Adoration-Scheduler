<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\AnnouncementsRenderer;
use AdorationScheduler\Domain\Repositories\AnnouncementsRepository;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_announcements redirect="/my-adoration/" limit="10"]
 *
 * Admin broadcast announcements — the "news" piece standing in for the
 * private Facebook group's news posts. Uses the same sign-in + approval
 * gate as the rest of the dashboard family, since that mirrors the
 * original FB-group model this plugin is meant to replace: approved
 * members get news, nobody else does.
 *
 * Private counterpart to the ungated [adoration_public_announcements] —
 * pulls only rows marked "Show to signed-in members" (show_private = 1),
 * which may differ from the public set. Renders via the shared
 * AnnouncementsRenderer (UIkit slider when there's more than one live
 * announcement) instead of a stacked list.
 */
class AnnouncementsShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_announcements', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'limit'    => 10,
            'card'     => '0',
        ], (array)$atts, 'adoration_announcements');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];

        $uid = self::new_uid('asannounce');
        $limit = max(1, min(50, (int)$atts['limit']));
        $card = self::wants_card($atts['card']);

        $rows = [];
        try {
            $repo = new AnnouncementsRepository();
            $rows = $repo->list_active_private($limit);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] AnnouncementsShortcode failed: ' . $e->getMessage());
        }

        ob_start();
        ?>
        <div class="adoration-widget adoration-announcements uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <h3 class="uk-margin-remove-top">Announcements</h3>
                <?php echo AnnouncementsRenderer::render_slider($rows, $uid); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
