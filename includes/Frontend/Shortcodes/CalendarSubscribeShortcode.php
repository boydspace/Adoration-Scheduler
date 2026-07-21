<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Services\CalendarFeedService;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_calendar_subscribe redirect="/my-adoration/"]
 *
 * A signed-in adorer's personal "Add your hours to your calendar" box
 * (webcal subscribe link + copy-link + reset-link). Originally embedded
 * inline inside MyScheduleShortcode; extracted to its own shortcode so it
 * can be placed independently of the standing-hours/upcoming-signups table
 * — e.g. near the top of the My Adoration page on its own, or left off a
 * layout that doesn't want it. One piece of the modular family that
 * replaced the retired [adoration_my_adoration] shortcode.
 */
class CalendarSubscribeShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_calendar_subscribe', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_calendar_subscribe');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];

        $uid = self::new_uid('ascalsub');
        $redirect_url = self::current_url();
        $card         = self::wants_card($atts['card']);

        $person_id = (int)($person['id'] ?? 0);

        $calendar_token = (new PersonsRepository())->get_or_create_calendar_token($person_id);
        $webcal_url = $calendar_token ? CalendarFeedService::personal_feed_url($calendar_token, true) : '';
        $https_url  = $calendar_token ? CalendarFeedService::personal_feed_url($calendar_token, false) : '';
        $regen_nonce = wp_create_nonce(CalendarFeedService::ACTION_REGENERATE . '_' . $person_id);

        ob_start();
        ?>
        <div class="adoration-widget adoration-calendar-subscribe uk-width-1-1" id="<?php echo esc_attr($uid); ?>" <?php echo self::ajax_wrapper_attrs('adoration_calendar_subscribe', $atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <?php if ($webcal_url !== ''): ?>
                    <div class="uk-alert as-calendar-subscribe" style="background:#f6f7f7;border-left:4px solid #2271b1;padding:12px 16px;">
                        <p class="uk-margin-remove-top uk-margin-small-bottom">
                            <strong>📅 Add your hours to your phone or computer calendar.</strong>
                            It stays in sync automatically as your schedule changes.
                        </p>
                        <p class="uk-margin-remove-bottom">
                            <a href="<?php echo esc_url($webcal_url); ?>" class="uk-button uk-button-primary uk-button-small adoration-btn">Subscribe to Calendar</a>
                            <button type="button" class="uk-button uk-button-default uk-button-small adoration-btn-secondary as-copy-cal-link" data-copy="<?php echo esc_attr($https_url); ?>">Copy Link</button>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="as-ajax-form" style="display:inline;margin-left:4px;">
                                <input type="hidden" name="action" value="<?php echo esc_attr(CalendarFeedService::ACTION_REGENERATE); ?>" />
                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($regen_nonce); ?>" />
                                <button type="submit" class="uk-button uk-button-link uk-button-small" style="font-size:12px;" onclick="return confirm('Reset your calendar link? Any calendar app already subscribed with the old link will stop getting updates.');">Reset link</button>
                            </form>
                        </p>
                    </div>
                <?php else: ?>
                    <p class="uk-margin-remove"><em>No calendar link is available right now.</em></p>
                <?php endif; ?>
            </div>

            <script>
            (function() {
                const root = document.getElementById(<?php echo json_encode($uid); ?>);
                if (!root) return;

                root.querySelectorAll('.as-copy-cal-link').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const url = btn.getAttribute('data-copy') || '';
                        if (!url) return;

                        const done = function() {
                            const old = btn.textContent;
                            btn.textContent = 'Copied';
                            window.setTimeout(function() { btn.textContent = old; }, 1200);
                        };

                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(done);
                            return;
                        }

                        const ta = document.createElement('textarea');
                        ta.value = url;
                        ta.style.position = 'fixed';
                        ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(ta);
                        done();
                    });
                });
            })();
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
