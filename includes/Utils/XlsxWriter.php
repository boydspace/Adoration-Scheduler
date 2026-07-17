<?php
namespace AdorationScheduler\Utils;

if ( ! defined('ABSPATH') ) exit;

/**
 * Minimal, dependency-free single-sheet .xlsx (OOXML SpreadsheetML) writer.
 *
 * No bundled third-party library — this plugin ships with "no external
 * services required" and prefers hand-rolled format support over adding a
 * dependency (mirrors IcsBuilder's approach to RFC 5545 for the calendar
 * feed feature). Every cell is written as an inline string
 * (`t="inlineStr"`), which is valid OOXML and opens cleanly in Excel,
 * Google Sheets, LibreOffice, and Numbers — no shared-strings table or
 * styles part needed for a plain data export.
 *
 * Usage:
 *   $xlsx = new XlsxWriter();
 *   $xlsx->add_row(['Title', 'First Name', 'Last Name']); // header
 *   $xlsx->add_row(['Fr.', 'John', 'Smith']);
 *   $xlsx->output('people.xlsx'); // streams + exit
 *   // or: $bytes = $xlsx->to_string();
 */
class XlsxWriter
{
    /** @var array<int, array<int, string>> */
    private array $rows = [];

    public function add_row(array $cells): void
    {
        $this->rows[] = array_map(static fn($v) => (string)$v, array_values($cells));
    }

    public static function is_available(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Builds the .xlsx file and returns its raw bytes.
     */
    public function to_string(): string
    {
        $tmp = wp_tempnam('adoration-xlsx');
        if (!$tmp) {
            $tmp = tempnam(sys_get_temp_dir(), 'as_xlsx_');
        }

        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');

        $zip->addFromString('[Content_Types].xml', $this->content_types_xml());
        $zip->addFromString('_rels/.rels', $this->root_rels_xml());
        $zip->addFromString('xl/workbook.xml', $this->workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbook_rels_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet_xml());

        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Streams the file as a download and exits (mirrors the CSV export
     * convention used elsewhere in this plugin, e.g. EmailLogPage).
     */
    public function output(string $filename): void
    {
        $bytes = $this->to_string();

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Length: ' . strlen($bytes));
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private function content_types_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private function root_rels_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbook_rels_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    private function sheet_xml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $r = 1;
        foreach ($this->rows as $row) {
            $xml .= '<row r="' . $r . '">';
            $c = 1;
            foreach ($row as $value) {
                $ref = self::col_letter($c) . $r;
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::escape($value) . '</t></is></c>';
                $c++;
            }
            $xml .= '</row>';
            $r++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private static function escape(string $value): string
    {
        // Strip control chars XML can't represent (keep tab/newline/CR).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function col_letter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $rem = ($col - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $col = intdiv($col - 1, 26);
        }
        return $letter;
    }
}
