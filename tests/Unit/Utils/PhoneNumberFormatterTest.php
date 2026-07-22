<?php
namespace AdorationScheduler\Tests\Unit\Utils;

use AdorationScheduler\Tests\Support\AdorationTestCase;
use AdorationScheduler\Utils\PhoneNumberFormatter;

final class PhoneNumberFormatterTest extends AdorationTestCase
{
    public function test_formats_plain_ten_digits(): void
    {
        $this->assertSame('+15551234567', PhoneNumberFormatter::to_e164('5551234567'));
    }

    public function test_formats_display_format(): void
    {
        $this->assertSame('+15551234567', PhoneNumberFormatter::to_e164('(555) 123-4567'));
    }

    public function test_strips_leading_us_country_code(): void
    {
        $this->assertSame('+15551234567', PhoneNumberFormatter::to_e164('15551234567'));
    }

    public function test_strips_leading_plus_one(): void
    {
        $this->assertSame('+15551234567', PhoneNumberFormatter::to_e164('+1 (555) 123-4567'));
    }

    public function test_rejects_too_few_digits(): void
    {
        $this->assertNull(PhoneNumberFormatter::to_e164('555123'));
    }

    public function test_rejects_too_many_digits(): void
    {
        $this->assertNull(PhoneNumberFormatter::to_e164('9155551234567'));
    }

    public function test_rejects_eleven_digits_without_leading_one(): void
    {
        $this->assertNull(PhoneNumberFormatter::to_e164('25551234567'));
    }

    public function test_rejects_empty_and_null(): void
    {
        $this->assertNull(PhoneNumberFormatter::to_e164(''));
        $this->assertNull(PhoneNumberFormatter::to_e164(null));
    }

    public function test_rejects_non_numeric_garbage(): void
    {
        $this->assertNull(PhoneNumberFormatter::to_e164('not a phone number'));
    }
}
