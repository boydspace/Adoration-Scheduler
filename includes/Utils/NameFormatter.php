<?php
namespace AdorationScheduler\Utils;

if (!defined('ABSPATH')) exit;

class NameFormatter {

    /**
     * "First L." (First name + Last initial)
     * - If last name missing -> "First"
     * - If first missing but last present -> "L."
     * - If both missing -> "—"
     */
    public static function first_last_initial(?string $first, ?string $last): string {
        $first = trim((string)($first ?? ''));
        $last  = trim((string)($last ?? ''));

        if ($first === '' && $last === '') return '—';
        if ($last === '') return $first !== '' ? $first : '—';
        if ($first === '') return strtoupper(substr($last, 0, 1)) . '.';

        $initial = strtoupper(substr($last, 0, 1));
        return $first . ' ' . $initial . '.';
    }

    /**
     * "First" only — no last name/initial at all.
     * - If first missing but last present -> falls back to first_last_initial's
     *   "L." behavior so there's still *something* rather than a blank pill.
     * - If both missing -> "—"
     */
    public static function first_name_only(?string $first, ?string $last): string {
        $first = trim((string)($first ?? ''));
        $last  = trim((string)($last ?? ''));

        if ($first !== '') return $first;
        if ($last !== '') return strtoupper(substr($last, 0, 1)) . '.';
        return '—';
    }

    /**
     * "First Last" — full name, no truncation.
     * - If one half missing, falls back to whichever half is present.
     * - If both missing -> "—"
     */
    public static function full_name(?string $first, ?string $last): string {
        $first = trim((string)($first ?? ''));
        $last  = trim((string)($last ?? ''));

        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : '—';
    }

    /**
     * Dispatch by a schedule's privacy_mode value. Centralizes the
     * mode -> formatter mapping so every call site (currently ScheduleShortcode)
     * stays in sync automatically if a new mode is ever added.
     *
     * counts_only isn't handled here — callers should skip building names at
     * all for that mode (it means "don't show names"), same as before.
     */
    public static function format(string $privacy_mode, ?string $first, ?string $last): string {
        switch ($privacy_mode) {
            case 'first_name_only':
                return self::first_name_only($first, $last);
            case 'names':
                return self::full_name($first, $last);
            case 'first_last_initial':
            default:
                return self::first_last_initial($first, $last);
        }
    }
}
