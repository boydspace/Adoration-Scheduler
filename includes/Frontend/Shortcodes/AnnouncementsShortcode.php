<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\SharedStyles;
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
            $rows = $repo->list_active($limit);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] AnnouncementsShortcode failed: ' . $e->getMessage());
        }

        ob_start();
        ?>
        <div class="adoration-widget adoration-announcements uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <h3 class="uk-margin-remove-top">Announcements</h3>

                <?php if (empty($rows)): ?>
                    <p class="uk-margin-remove">No announcements right now.</p>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $title = (string)($row['title'] ?? '');
                        $body  = (string)($row['body'] ?? '');
                        $created = (string)($row['created_at'] ?? '');
                        $created_lbl = $created ? date_i18n(get_option('date_format'), strtotime($created)) : '';
                        ?>
                        <div class="uk-margin-medium-bottom">
                            <h4 class="uk-margin-remove-bottom"><?php echo esc_html($title); ?></h4>
                            <p class="uk-text-meta as-muted uk-margin-remove-top"><?php echo esc_html($created_lbl); ?></p>
                            <div class="uk-margin-remove-top">
                                <?php echo wp_kses_post(wpautop($body)); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
