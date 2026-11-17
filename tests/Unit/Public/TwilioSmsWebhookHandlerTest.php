<?php
namespace AdorationScheduler\Tests\Unit\Public;

use AdorationScheduler\Public\TwilioSmsWebhookHandler;
use AdorationScheduler\Tests\Support\AdorationTestCase;

final class TwilioSmsWebhookHandlerTest extends AdorationTestCase
{
    public function test_validates_twilio_request_signature(): void
    {
        $url = 'https://example.test/wp-admin/admin-post.php?action=adoration_twilio_sms_webhook';
        $params = ['From' => '+15551234567', 'Body' => 'STOP'];
        ksort($params, SORT_STRING);
        $payload = $url;
        foreach ($params as $key => $value) $payload .= $key . $value;
        $signature = base64_encode(hash_hmac('sha1', $payload, 'secret', true));

        $this->assertTrue(TwilioSmsWebhookHandler::signature_is_valid($url, $params, $signature, 'secret'));
        $this->assertFalse(TwilioSmsWebhookHandler::signature_is_valid($url, $params, 'invalid', 'secret'));
    }
}
