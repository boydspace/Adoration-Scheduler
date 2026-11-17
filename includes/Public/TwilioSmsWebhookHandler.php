<?php
namespace AdorationScheduler\Public;

use AdorationScheduler\Admin\Pages\SmsSettingsPage;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Utils\PhoneNumberFormatter;

if (!defined('ABSPATH')) exit;

class TwilioSmsWebhookHandler
{
    public const ACTION = 'adoration_twilio_sms_webhook';

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    public static function webhook_url(): string
    {
        return admin_url('admin-post.php?action=' . self::ACTION);
    }

    public static function handle(): void
    {
        $signature = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_TWILIO_SIGNATURE']))
            : '';
        $options = SmsSettingsPage::get_options();
        $params = wp_unslash($_POST);
        if (!self::signature_is_valid(self::webhook_url(), $params, $signature, (string)$options['twilio_auth_token'])) {
            status_header(403);
            exit;
        }

        $body = strtoupper(trim(sanitize_text_field($params['Body'] ?? '')));
        $opt_out_type = strtoupper(trim(sanitize_text_field($params['OptOutType'] ?? '')));
        $stop_words = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
        if ($opt_out_type === 'STOP' || in_array($body, $stop_words, true)) {
            $from = PhoneNumberFormatter::to_e164(sanitize_text_field($params['From'] ?? ''));
            if ($from !== null) {
                (new PersonsRepository())->withdraw_sms_consent_by_phone($from);
            }
        }

        status_header(200);
        header('Content-Type: text/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed TwiML response.
        exit;
    }

    public static function signature_is_valid(string $url, array $params, string $signature, string $auth_token): bool
    {
        if ($signature === '' || $auth_token === '') return false;
        ksort($params, SORT_STRING);
        $payload = $url;
        foreach ($params as $key => $value) {
            if (is_scalar($value)) $payload .= (string)$key . (string)$value;
        }
        $expected = base64_encode(hash_hmac('sha1', $payload, $auth_token, true));
        return hash_equals($expected, $signature);
    }
}
