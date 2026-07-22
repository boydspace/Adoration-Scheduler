<?php
namespace AdorationScheduler\Services\Sms;

if (!defined('ABSPATH')) exit;

/**
 * Contract every SMS provider implementation sends through — kept
 * deliberately thin (one method) so a second provider (ClickSend, Vonage,
 * etc.) can be added later as a self-contained class implementing this
 * interface, without touching SmsService or anything upstream of it.
 */
interface SmsProviderInterface
{
    /**
     * @param string $to_e164 Recipient phone number in E.164 format (e.g. "+15551234567").
     * @param string $message Plain-text message body.
     * @return array{success: bool, error: string|null}
     */
    public function send(string $to_e164, string $message): array;
}
