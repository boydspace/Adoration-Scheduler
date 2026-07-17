<?php
namespace AdorationScheduler\Utils;

if ( ! defined('ABSPATH') ) exit;

/**
 * Minimal, dependency-free .xlsx (OOXML SpreadsheetML) reader.
 *
 * Reads the FIRST worksheet only (fine for a simple roster-import file —
 * multi-sheet workbooks aren't a use case this plugin needs to support).
 * Resolves shared strings and inline strings, and reconstructs each row
 * respecting the cell's own column reference (e.g. "C7") rather than
 * assuming cells are contiguous, since Excel omits empty cells from the
 * XML entirely — a naive "just read cells in document order" approach
 * would silently shift columns on any row with a blank cell.
 *
 * Uses DOMDocument + DOMXPath (not SimpleXML) — a single DOMXPath
 * instance with the namespace registered once is used for every query,
 * which avoids SimpleXML's well-known gotcha where a namespace
 * registered via children($ns) doesn't propagate to further chained
 * property access on the returned nodes.
 *
 * Security: LIBXML_NONET disables any network access during parsing, and
 * PHP 8+ (this plugin's minimum) disables external entity substitution
 * by default — so this is XXE-safe without the old
 * libxml_disable_entity_loader() dance.
 */
class XlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function is_available(): bool
    {
        return class_exists('ZipArchive') && class_exists('DOMDocument');
    }

    /**
     * @return array<int, array<int, string>> Zero-indexed rows of zero-indexed columns.
     * @throws \RuntimeException on any parse failure.
     */
    public static function read_first_sheet(string $file_path): array
    {
        if (!self::is_available()) {
            throw new \RuntimeException('The PHP zip and DOM extensions are required to read .xlsx files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($file_path) !== true) {
            throw new \RuntimeException('That file could not be opened as a .xlsx workbook.');
        }

        $shared_strings = self::read_shared_strings($zip);

        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet_xml === false) {
            $sheet_xml = self::find_any_worksheet($zip);
        }

        $zip->close();

        if ($sheet_xml === false || $sheet_xml === null || $sheet_xml === '') {
            throw new \RuntimeException('No worksheet was found inside that .xlsx file.');
        }

        return self::parse_sheet_xml($sheet_xml, $shared_strings);
    }

    private static function find_any_worksheet(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#^xl/worksheets/.*\.xml$#', $name)) {
                $contents = $zip->getFromName($name);
                return $contents === false ? null : $contents;
            }
        }
        return null;
    }

    private static function load_xpath(string $xml): ?\DOMXPath
    {
        $doc = new \DOMDocument();

        $prev_errors = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_use_internal_errors($prev_errors);

        if (!$ok) return null;

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('x', self::NS);

        return $xpath;
    }

    /**
     * @return array<int, string>
     */
    private static function read_shared_strings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }

        $xpath = self::load_xpath($xml);
        if ($xpath === null) return [];

        $strings = [];
        $si_nodes = $xpath->query('//x:si');
        if ($si_nodes === false) return [];

        foreach ($si_nodes as $si) {
            // .//x:t matches a plain <t> child AND any <r><t> rich-text
            // run at any depth, so both shapes are handled uniformly.
            $t_nodes = $xpath->query('.//x:t', $si);
            $text = '';
            if ($t_nodes !== false) {
                foreach ($t_nodes as $t) {
                    $text .= $t->textContent;
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param array<int, string> $shared_strings
     * @return array<int, array<int, string>>
     */
    private static function parse_sheet_xml(string $xml, array $shared_strings): array
    {
        $xpath = self::load_xpath($xml);
        if ($xpath === null) {
            throw new \RuntimeException('That .xlsx file\'s worksheet data could not be read (invalid XML).');
        }

        $row_nodes = $xpath->query('//x:sheetData/x:row');
        if ($row_nodes === false || $row_nodes->length === 0) {
            return [];
        }

        $rows_out = [];

        foreach ($row_nodes as $row) {
            $cell_nodes = $xpath->query('./x:c', $row);
            $row_cells = [];
            $max_col_index = -1;

            if ($cell_nodes !== false) {
                foreach ($cell_nodes as $cell) {
                    /** @var \DOMElement $cell */
                    $ref = $cell->getAttribute('r');
                    $col_index = $ref !== '' ? self::col_index_from_ref($ref) : (count($row_cells));
                    $type = $cell->getAttribute('t');

                    $value = self::cell_value($xpath, $cell, $type, $shared_strings);

                    $row_cells[$col_index] = $value;
                    if ($col_index > $max_col_index) $max_col_index = $col_index;
                }
            }

            // Fill gaps so every row is a dense 0..max_col_index array —
            // a blank cell in the middle of a row (e.g. empty Phone) is
            // legitimate data, not a reason to shift later columns left.
            $dense = [];
            for ($i = 0; $i <= $max_col_index; $i++) {
                $dense[$i] = $row_cells[$i] ?? '';
            }

            $rows_out[] = $dense;
        }

        return $rows_out;
    }

    private static function cell_value(\DOMXPath $xpath, \DOMElement $cell, string $type, array $shared_strings): string
    {
        if ($type === 'inlineStr') {
            $t_nodes = $xpath->query('.//x:is//x:t', $cell);
            $text = '';
            if ($t_nodes !== false) {
                foreach ($t_nodes as $t) {
                    $text .= $t->textContent;
                }
            }
            return $text;
        }

        $v_nodes = $xpath->query('./x:v', $cell);
        $raw = ($v_nodes !== false && $v_nodes->length > 0) ? $v_nodes->item(0)->textContent : '';

        if ($type === 's') {
            $idx = (int) $raw;
            return $shared_strings[$idx] ?? '';
        }

        // "str" (formula result string), "b" (boolean), "n"/omitted (number)
        // — the raw <v> text is exactly what we want for a data import.
        return $raw;
    }

    /**
     * "C7" -> 2 (zero-indexed column). Ignores the row-number portion.
     */
    private static function col_index_from_ref(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return 0;
        }

        $letters = $m[1];
        $col = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $col = $col * 26 + (ord($letters[$i]) - 64);
        }

        return $col - 1;
    }
}
