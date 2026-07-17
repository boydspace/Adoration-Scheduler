<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\SharedStyles;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_next_adoration_hour redirect="/my-adoration/"]
 *
 * Compact single-item widget: just the signed-in person's very next
 * upcoming commitment. Good for a sidebar or homepage, where the full
 * [adoration_my_schedule] table would be too much.
 */
class NextAdorationHourShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_next_adoration_hour', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_next_adoration_hour');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];

        $uid  = self::new_uid('asnext');
        $next = self::get_person_next_signup((int)($person['id'] ?? 0));
        $card = self::wants_card($atts['card']);

        ob_start();
        ?>
        <div class="adoration-widget adoration-next-hour uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <h3 class="uk-margin-remove-top uk-margin-small-bottom">Next Adoration Hour</h3>
                <?php if (!$next): ?>
                    <p class="uk-margin-remove">You don't have an upcoming commitment scheduled.</p>
                <?php else: ?>
                    <?php
                    $date_lbl = self::fmt_date((string)($next['date'] ?? ''));
                    $time_lbl = self::fmt_time_range((string)($next['start_time'] ?? ''), (string)($next['end_time'] ?? ''));
                    $chapel   = (string)($next['chapel_name'] ?? '');
                    $sched    = (string)($next['schedule_name'] ?? '');
                    $needs_replacement = !empty($next['needs_replacement']);
                    ?>
                    <p class="uk-text-large uk-margin-remove">
                        <strong><?php echo esc_html($date_lbl); ?></strong>
                    </p>
                    <p class="uk-margin-remove-top">
                        <?php echo esc_html($time_lbl); ?>
                        <?php if ($chapel !== ''): ?> • <?php echo esc_html($chapel); ?><?php endif; ?>
                    </p>
                    <?php if ($sched !== ''): ?>
                        <p class="uk-text-meta as-muted uk-margin-remove-top"><?php echo esc_html($sched); ?></p>
                    <?php endif; ?>
                    <?php if ($needs_replacement): ?>
                        <span class="uk-label uk-label-warning" style="font-size:10px;">Replacement Requested</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
