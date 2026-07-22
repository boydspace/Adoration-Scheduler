<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

use AdorationScheduler\Admin\Pages\SmsSettingsPage;
use AdorationScheduler\Services\Sms\SmsProviderInterface;
use AdorationScheduler\Services\Sms\TwilioSmsProvider;
use AdorationScheduler\Utils\PhoneNumberFormatter;

/**
 * Orchestrates SMS sends — the SMS-side counterpart to
 * EmailService/NotificationService. Not hook-registered; it's a facade
 * called synchronously by whoever wants to send an SMS (currently just
 * ReminderScheduler::send_reminder()), same as EmailService.
 *
 * Provider construction is isolated to provider() so a second provider can
 * be added later by branching there — nothing else in this class, or any
 * caller, needs to know which provider is configured.
 */
class SmsService
{
    /**
     * Sends the 24h reminder text. Expects the same $args shape
     * ReminderScheduler::send_reminder() already builds for the email
     * reminder, plus a 'phone' key. Never throws — best-effort, same
     * posture as the email/reminder call sites that use it.
     *
     * @return bool True if a message was sent (or SMS is simply not
     *   configured/not applicable — callers don't need to distinguish
     *   "off" from "sent"). False only on an actual send failure.
     */
    public static function send_reminder_sms(array $args): bool
    {
        if (!SmsSettingsPage::is_configured()) {
            return true;
        }

        // ✅ Per-person opt-in (2026-07-21): the parish-level switch above
        // only means "SMS is available here" — whether THIS person
        // actually wants a text is their own choice, set on
        // [adoration_reminder_preferences] and passed through by
        // ReminderScheduler::send_reminder(). Off by default.
        if (empty($args['sms_reminder_opt_in'])) {
            return true;
        }

        $to_e164 = PhoneNumberFormatter::to_e164((string)($args['phone'] ?? ''));
        if ($to_e164 === null) {
            return true; // no valid phone on file — not an error, just nothing to send
        }

        $template = (string) SmsSettingsPage::get_options()['reminder_sms_body'];
        $message  = NotificationService::replace_tokens($template, $args);

        $result = self::provider()->send($to_e164, $message);

        if (empty($result['success'])) {
            error_log('[AdorationScheduler] SMS reminder failed to=' . $to_e164 . ' error=' . (string)($result['error'] ?? 'unknown'));
            return false;
        }

        return true;
    }

    /**
     * @return array{success: bool, error: string|null}
     */
    public static function send_test(string $to_e164): array
    {
        if (!SmsSettingsPage::is_configured()) {
            return ['success' => false, 'error' => 'SMS reminders are not fully configured yet.'];
        }

        return self::provider()->send($to_e164, 'This is a test message from Adoration Scheduler.');
    }

    private static function provider(): SmsProviderInterface
    {
        $o = SmsSettingsPage::get_options();

        return new TwilioSmsProvider(
            (string) $o['twilio_account_sid'],
            (string) $o['twilio_auth_token'],
            (string) $o['twilio_from_number']
        );
    }
}
