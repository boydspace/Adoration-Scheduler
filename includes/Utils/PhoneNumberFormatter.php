<?php
namespace AdorationScheduler\Utils;

if (!defined('ABSPATH')) exit;

/**
 * PhoneNumberFormatter
 *
 * Converts a stored phone number to E.164 (e.g. "+15551234567") for SMS
 * providers (Twilio requires this format) at send time.
 *
 * `persons.phone` itself stays in the existing `(XXX) XXX-XXXX` display
 * format produced by UpdateContactInfoHandler::normalize_phone_us() — this
 * is a separate, one-way converter applied only when sending an SMS, not a
 * replacement for that storage format. Uses the same digit-extraction
 * approach (strip non-digits, drop a leading US country-code 1 if 11
 * digits, require exactly 10 digits) so both agree on what counts as a
 * valid US number.
 */
class PhoneNumberFormatter
{
    /**
     * Returns null (never guesses) for anything that doesn't resolve to a
     * valid 10-digit US number.
     */
    public static function to_e164(?string $raw): ?string
    {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') return null;

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') return null;

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return '+1' . $digits;
    }
}
