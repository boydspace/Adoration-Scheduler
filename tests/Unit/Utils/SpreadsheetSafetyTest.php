<?php
namespace AdorationScheduler\Tests\Unit\Utils;

use AdorationScheduler\Tests\Support\AdorationTestCase;
use AdorationScheduler\Utils\SpreadsheetSafety;

final class SpreadsheetSafetyTest extends AdorationTestCase
{
    public function test_leaves_ordinary_text_untouched(): void
    {
        $this->assertSame('John Smith', SpreadsheetSafety::sanitize_cell('John Smith'));
    }

    public function test_leaves_empty_string_untouched(): void
    {
        $this->assertSame('', SpreadsheetSafety::sanitize_cell(''));
    }

    /**
     * @dataProvider formulaTriggerProvider
     */
    public function test_prefixes_formula_trigger_characters(string $input): void
    {
        $result = SpreadsheetSafety::sanitize_cell($input);

        $this->assertStringStartsWith("'", $result);
        $this->assertSame("'" . $input, $result);
    }

    public function formulaTriggerProvider(): array
    {
        return [
            'equals'      => ['=HYPERLINK("https://evil.example","click")'],
            'plus'        => ['+1234'],
            'minus'       => ['-cmd|/c calc'],
            'at'          => ['@SUM(1,1)'],
            'tab'         => ["\tsomething"],
            'carriage'    => ["\rsomething"],
        ];
    }

    public function test_does_not_prefix_a_legitimate_leading_hyphenated_name(): void
    {
        // A real risk of over-eager sanitization: names/notes can legitimately
        // start with a hyphen (e.g. a hyphenated surname). This is a known,
        // accepted trade-off of the standard CSV-injection mitigation (Excel/
        // Sheets/GitHub all apply it the same way) — documented here so a
        // future reader doesn't "fix" it back into a vulnerability.
        $this->assertSame("'-Smith", SpreadsheetSafety::sanitize_cell('-Smith'));
    }

    public function test_sanitize_row_applies_to_every_cell(): void
    {
        $row = ['=cmd', 'Normal Name', 42, '+1'];
        $expected = ["'=cmd", 'Normal Name', '42', "'+1"];

        $this->assertSame($expected, SpreadsheetSafety::sanitize_row($row));
    }
}
