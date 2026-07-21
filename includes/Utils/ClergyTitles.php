<?php
namespace AdorationScheduler\Utils;

if (!defined('ABSPATH')) exit;

/**
 * ClergyTitles
 *
 * Single source of truth for the predefined clergy/religious title list
 * (persons.title) and its space-constrained abbreviations.
 *
 * Storage stays free-text (persons.title VARCHAR(50), unchanged) — this is
 * a UI + display layer only. Admin/self-edit/signup forms show a plain
 * <select> of these titles (render_field_html()); there is deliberately no
 * "Other" free-text fallback (removed 2026-07-20 — user: "remove the
 * optional custom field. It is too much."). If a future title needs to be
 * supported, add it to MAP below rather than reintroducing a free-text
 * field.
 *
 * A person whose stored title predates this list (e.g. old free-text data)
 * and doesn't match any MAP key is still shown correctly: render_field_html()
 * adds it as an extra selected option so editing the person doesn't
 * silently wipe it, but the dropdown otherwise only offers the fixed list.
 *
 * Full spelled-out form ("Father") is used in prose contexts — the profile
 * card, email greetings/merge tags — where space isn't tight. The
 * abbreviation ("Fr.") is used in dense tabular admin/report/roster
 * contexts (Signups list, People list, Coverage Report, Attendance,
 * per-schedule Signups tab + waitlist, printed roster) where a full title
 * would crowd out the name in a narrow column. See abbreviate().
 */
class ClergyTitles
{
    /**
     * Full spelled-out form => abbreviation. Order here is also the order
     * shown in the dropdown (ordained hierarchy first, then religious).
     */
    private const MAP = [
        'Cardinal'   => 'Cdl.',
        'Bishop'     => 'Bp.',
        'Monsignor'  => 'Msgr.',
        'Father'     => 'Fr.',
        'Deacon'     => 'Dcn.',
        'Sister'     => 'Sr.',
        'Brother'    => 'Br.',
    ];

    /**
     * The predefined full-form options, in display order.
     */
    public static function options(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Abbreviated form for dense/tabular display. Falls back to the
     * original string unchanged for empty values or legacy free-text
     * values that aren't in the predefined list — never guesses at an
     * abbreviation for something it doesn't recognize.
     */
    public static function abbreviate(?string $title): string
    {
        $title = trim((string)($title ?? ''));
        if ($title === '') return '';
        return self::MAP[$title] ?? $title;
    }

    /**
     * Renders a plain <select> of the predefined titles. No free-text
     * fallback — pick from the list, or leave it blank.
     *
     * If $current_value doesn't match any predefined option (legacy
     * free-text data from before this list existed, or before the "Other"
     * field was removed), it's added as an extra selected option so saving
     * the surrounding form again doesn't silently blank it out.
     *
     * @param string $name          Field name (posted as-is).
     * @param string $id            HTML id for the select (caller supplies to avoid collisions across repeated form instances).
     * @param string $current_value Current stored title, if any.
     * @param string $select_class  CSS class(es) for the <select> (e.g. "regular-text" in wp-admin, "uk-select" in UIkit forms).
     */
    public static function render_field_html(
        string $name,
        string $id,
        string $current_value,
        string $select_class = 'regular-text'
    ): void {
        $current_value = trim($current_value);
        $options = self::options();
        $is_predefined = $current_value !== '' && in_array($current_value, $options, true);

        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="' . esc_attr($select_class) . '">';
        echo '<option value="">' . esc_html__('— None —', 'adoration-scheduler') . '</option>';
        foreach ($options as $opt) {
            echo '<option value="' . esc_attr($opt) . '" ' . selected($current_value, $opt, false) . '>' . esc_html($opt) . '</option>';
        }
        if ($current_value !== '' && !$is_predefined) {
            echo '<option value="' . esc_attr($current_value) . '" selected>' . esc_html($current_value) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Resolves a title field rendered by render_field_html() from $_POST.
     */
    public static function resolve_from_post(string $name = 'title'): string
    {
        return isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
    }

    /**
     * "Father Andrew" — title + first name, correctly spaced whether or not
     * a title is set (no stray leading/double space when title is blank).
     * Used for the email {title_first_name} merge tag; see
     * NotificationService::replace_tokens().
     */
    public static function with_first_name(string $title, string $first_name): string
    {
        $title = trim($title);
        $first_name = trim($first_name);
        return trim($title . ' ' . $first_name);
    }
}
