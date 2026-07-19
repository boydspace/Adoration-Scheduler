<?php
namespace AdorationScheduler\Frontend;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shared UIkit slider/carousel markup for announcements — used by both the
 * public [adoration_public_announcements] and the gated (private)
 * [adoration_announcements] shortcodes, so a live site with several
 * announcements shows one at a time in a carousel instead of stacking them
 * vertically (or side-by-side).
 *
 * ✅ FIX (2026-07-19): deliberately does NOT use any uk-card/uk-card-*
 * classes — Andy manages card/tile styling himself via the YOOtheme Builder
 * and doesn't want this plugin imposing its own boxed look on top of that.
 * Slides are plain, unstyled content; only slider mechanics (positioning,
 * nav arrows, dot nav) get UIkit classes. Also deliberately one slide per
 * view (uk-child-width-1-1) — Andy wants exactly one notice visible at a
 * time, not two side-by-side on wider screens.
 *
 * Requires UIkit's JS (UikitLoader::print_once()) to be on the page for the
 * uk-slider/uk-slidenav/uk-dotnav behavior to activate — callers are
 * responsible for calling that themselves, since some pages already have
 * their own copy loaded earlier.
 */
class AnnouncementsRenderer
{
    /**
     * @param array  $rows Rows from AnnouncementsRepository::list_active_public()
     *                     or list_active_private().
     * @param string $uid  Unique id prefix for this shortcode instance (from
     *                     PersonDashboardTrait::new_uid() or equivalent).
     */
    public static function render_slider(array $rows, string $uid): string
    {
        if (empty($rows)) {
            return '<p class="uk-margin-remove">' . esc_html__('No announcements right now.', 'adoration-scheduler') . '</p>';
        }

        // A one-slide "slider" is just visual overhead (empty arrows/dots) —
        // show the same content markup without the slider chrome around it.
        if (count($rows) === 1) {
            return self::render_item($rows[0]);
        }

        $slider_id = esc_attr($uid . '_slider');

        // ✅ FIX (2026-07-19): the previous markup put uk-slider directly on
        // the position-relative wrapper and skipped the uk-slider-container
        // div around uk-slider-items. That container is what clips overflow
        // — without it, the next slide peeked in at the edge instead of
        // being hidden until you page to it. This now matches UIkit's own
        // reference slider structure: uk-slider on the outermost element,
        // uk-slider-container (overflow: hidden) wrapping uk-slider-items.
        ob_start();
        ?>
        <div uk-slider="finite: true" id="<?php echo $slider_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
            <div class="uk-position-relative uk-visible-toggle uk-slider-container-offset" tabindex="-1">
                <div class="uk-slider-container">
                    <div class="uk-slider-items uk-child-width-1-1">
                        <?php foreach ($rows as $row): ?>
                            <div><?php echo self::render_item($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a class="uk-position-center-left uk-position-small uk-hidden-hover" href="#" uk-slidenav-previous uk-slider-item="previous" aria-label="<?php esc_attr_e('Previous announcement', 'adoration-scheduler'); ?>"></a>
                <a class="uk-position-center-right uk-position-small uk-hidden-hover" href="#" uk-slidenav-next uk-slider-item="next" aria-label="<?php esc_attr_e('Next announcement', 'adoration-scheduler'); ?>"></a>
            </div>

            <ul class="uk-slider-nav uk-dotnav uk-flex-center uk-margin"></ul>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * A single announcement's content — no card/box/border of any kind, by
     * design (see class docblock). Just heading, date, image, and body.
     */
    private static function render_item(array $row): string
    {
        $title    = (string)($row['title'] ?? '');
        $body     = (string)($row['body'] ?? '');
        $created  = (string)($row['created_at'] ?? '');
        $image_id = (int)($row['image_id'] ?? 0);

        $created_lbl = $created ? date_i18n(get_option('date_format'), strtotime($created)) : '';

        ob_start();
        ?>
        <?php if ($image_id > 0): ?>
            <?php $img = wp_get_attachment_image($image_id, 'large', false, ['class' => 'uk-width-1-1']); ?>
            <?php if ($img !== ''): ?>
                <div class="uk-margin-small-bottom">
                    <?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <h3 class="uk-margin-remove-bottom"><?php echo esc_html($title); ?></h3>
        <?php if ($created_lbl !== ''): ?>
            <p class="uk-text-meta as-muted uk-margin-remove-top"><?php echo esc_html($created_lbl); ?></p>
        <?php endif; ?>
        <div class="uk-margin-remove-top">
            <?php echo wp_kses_post(wpautop($body)); ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
