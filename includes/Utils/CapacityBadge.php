<?php
namespace AdorationScheduler\Utils;

if (!defined('ABSPATH')) exit;

/**
 * CapacityBadge
 *
 * Shared "how filled is this hour" status pill, used by both
 * OpenHoursShortcode (the public read-only hours board) and ScheduleShortcode
 * (the signup views) wherever they show a counts-only status rather than
 * name pills. Centralized so the two shortcodes can't drift into different
 * color schemes for the same concept.
 *
 * ✅ Coverage-first color scheme (2026-07-20): an OPEN/unfilled hour is the
 * problem state — it's the one that needs someone to sign up — so it's red.
 * A FULL hour is the resolved/good state, so it's green. Partially-filled
 * stays the amber "in progress" warning color in between. This is inverted
 * from the scheme this replaced (green = open/empty, red = full), which
 * read backwards for a coverage board: green looked like "good" for an
 * hour nobody had signed up for yet.
 *
 * ✅ Accessibility (2026-07-18, carried over): dark text on a light tint +
 * colored border, not white text on a saturated background — white-on-solid
 * measured under WCAG AA 4.5:1 contrast for this text size. This dark-on-
 * light pattern passes regardless of hue and matches the
 * .adoration-notice-* accent-border convention used elsewhere.
 */
class CapacityBadge
{
    /**
     * @param int $count Confirmed/committed count for this hour.
     * @param int|string|null $max Capacity for this hour, or null/'' for uncapped.
     * @param bool|null $is_full Pass a known is_full flag (e.g. a caller may
     *   already have factored in things this method can't); pass null to
     *   derive it from $count/$max.
     * @return array{0:string,1:string,2:string,3:string} [label, bg, fg, border]
     */
    public static function parts(int $count, $max, ?bool $is_full = null): array
    {
        $max = ($max === null || $max === '') ? null : (int)$max;
        if ($is_full === null) {
            $is_full = ($max !== null && $count >= $max);
        }

        // Full — resolved, no coverage gap.
        if ($is_full) {
            return [__('Filled', 'adoration-scheduler'), '#e4f5e9', '#10521c', '#00a32a'];
        }

        if ($max !== null) {
            $label = sprintf(
                /* translators: 1: confirmed count, 2: max spots */
                __('%1$d of %2$d filled', 'adoration-scheduler'),
                $count,
                $max
            );
            // Partially filled — some coverage, not yet full.
            if ($count > 0) {
                return [$label, '#fdf0d5', '#6b4e00', '#dba617'];
            }
            // Nobody signed up — the problem state.
            return [$label, '#fbe6e6', '#8a1f1f', '#d63638'];
        }

        // Uncapped hour: any signup at all counts as "has coverage."
        if ($count > 0) {
            return [__('Open', 'adoration-scheduler'), '#fdf0d5', '#6b4e00', '#dba617'];
        }
        return [__('Open — nobody signed up yet', 'adoration-scheduler'), '#fbe6e6', '#8a1f1f', '#d63638'];
    }

    public static function html_parts(string $label, string $bg, string $fg, string $border): string
    {
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;color:'
            . esc_attr($fg) . ';background:' . esc_attr($bg) . ';border:1px solid ' . esc_attr($border) . ';">'
            . esc_html($label) . '</span>';
    }

    /**
     * Convenience one-shot: compute + render in a single call.
     */
    public static function html(int $count, $max, ?bool $is_full = null): string
    {
        [$label, $bg, $fg, $border] = self::parts($count, $max, $is_full);
        return self::html_parts($label, $bg, $fg, $border);
    }
}
