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
}
