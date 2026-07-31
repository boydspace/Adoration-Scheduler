<?php
namespace AdorationScheduler\Utils;

if ( ! defined('ABSPATH') ) exit;

/**
 * Neutralizes formula injection (CWE-1236) in CSV/XLSX exports.
 *
 * Several exports (People, Schedule, Coverage Report, Email Log) include
 * data that ultimately traces back to public, unauthenticated form
 * submissions — a person's first/last name, most notably. Nothing stops
 * someone from signing up with a name like `=HYPERLINK("https://evil",
 * "x")`; if an admin later opens that export in Excel/Sheets/LibreOffice,
 * a cell whose content starts with a formula-trigger character can execute
 * instead of displaying as plain text. The standard mitigation (used by
 * every major export library) is to prefix such cells with a leading
 * apostrophe, which every spreadsheet app treats as "force this to display
 * as text" without changing what the user sees.
 */
class SpreadsheetSafety
{
    private const TRIGGER_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Sanitize a single cell value before writing it to a CSV or XLSX cell.
     */
    public static function sanitize_cell($value): string
    {
        $value = (string) $value;
        if ($value === '') return $value;

        if (in_array($value[0], self::TRIGGER_CHARS, true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Sanitize every cell in a row (the common case for both fputcsv() and
     * XlsxWriter::add_row()).
     *
     * @param array<int, mixed> $row
     * @return array<int, string>
     */
    public static function sanitize_row(array $row): array
    {
        return array_map([self::class, 'sanitize_cell'], $row);
    }
}
