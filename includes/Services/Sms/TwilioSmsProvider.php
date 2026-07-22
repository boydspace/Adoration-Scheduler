<?php
namespace AdorationScheduler\Services\Sms;

if (!defined('ABSPATH')) exit;

/**
 * Twilio Programmable Messaging implementation of SmsProviderInterface.
 *
 * Credential lookup happens entirely outside this class (see SmsService) —
 * it just takes the three values it needs in its constructor, so it stays
 * trivially testable and reusable without any WordPress options coupling.
 *
 * wp_remote_post()/is_wp_error()/HTTP-status-range error handling here
 * follows the same convention as the only other outbound third-party API
 * call in this plugin: SignupHandler::verify_turnstile_or_bail() (Cloudflare
 * Turnstile) — log the raw failure with an [AdorationScheduler] prefix,
 * never surface it to the end user.
 */
class TwilioSmsProvider implements SmsProviderInterface
{
    private string $account_sid;
    private string $auth_token;
    private string $from_number;

    public function __construct(string $account_sid, string $auth_token, string $from_number)
    {
        $this->account_sid = trim($account_sid);
        $this->auth_token   = trim($auth_token);
        $this->from_number  = trim($from_number);
    }

    public function send(string $to_e164, string $message): array
    {
        if ($this->account_sid === '' || $this->auth_token === '' || $this->from_number === '') {
            return ['success' => false, 'error' => 'Twilio is not fully configured.'];
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->account_sid) . '/Messages.json';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
            ],
            'body' => [
                'To'   => $to_e164,
                'From' => $this->from_number,
                'Body' => $message,
            ],
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('[AdorationScheduler] Twilio send request error: ' . $err);
            return ['success' => false, 'error' => 'Could not reach Twilio. Please try again.'];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message_from_twilio = (is_array($json) && !empty($json['message'])) ? (string) $json['message'] : '';
            error_log('[AdorationScheduler] Twilio send HTTP ' . $code . ' body=' . $raw);
            return [
                'success' => false,
                'error'   => $message_from_twilio !== '' ? $message_from_twilio : ('Twilio returned HTTP ' . $code . '.'),
            ];
        }

        return ['success' => true, 'error' => null];
    }
}
