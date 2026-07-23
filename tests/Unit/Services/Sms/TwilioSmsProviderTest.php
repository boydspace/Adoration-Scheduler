<?php
namespace AdorationScheduler\Tests\Unit\Services\Sms;

use AdorationScheduler\Tests\Support\AdorationTestCase;
use AdorationScheduler\Services\Sms\TwilioSmsProvider;
use Brain\Monkey\Functions;

// Minimal stand-in for WordPress's real WP_Error — this suite never loads
// WordPress itself (see AdorationTestCase), so the class doesn't otherwise
// exist. Just enough surface for is_wp_error()/get_error_message().
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $message;
        public function __construct(string $code = '', string $message = '')
        {
            $this->message = $message;
        }
        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

/**
 * Never hits a real network call (mirrors this suite's convention — see
 * tests/Unit/Regression/StandingCommitmentEmailBugTest.php's docblock on
 * why the full send pipeline isn't faked further than necessary). Exercises
 * request-building and response-parsing only.
 */
final class TwilioSmsProviderTest extends AdorationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('is_wp_error')->alias(static fn($thing) => $thing instanceof WP_Error);
    }

    public function test_send_fails_fast_when_not_fully_configured(): void
    {
        $provider = new TwilioSmsProvider('', '', '');
        $result = $provider->send('+15551234567', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_send_returns_friendly_error_on_wp_error(): void
    {
        Functions\when('wp_remote_post')->justReturn(new WP_Error('http_request_failed', 'Connection timed out'));

        $provider = new TwilioSmsProvider('ACxxx', 'authtoken', '+15550001111');
        $result = $provider->send('+15551234567', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertSame('Could not reach Twilio. Please try again.', $result['error']);
    }

    public function test_send_returns_twilios_own_error_message_on_http_failure(): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(400);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['message' => "The 'To' number is not valid."]));

        $provider = new TwilioSmsProvider('ACxxx', 'authtoken', '+15550001111');
        $result = $provider->send('+1invalid', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertSame("The 'To' number is not valid.", $result['error']);
    }

    public function test_send_falls_back_to_generic_error_when_body_has_no_message(): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $provider = new TwilioSmsProvider('ACxxx', 'authtoken', '+15550001111');
        $result = $provider->send('+15551234567', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertSame('Twilio returned HTTP 500.', $result['error']);
    }

    public function test_send_succeeds_on_2xx_response(): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['sid' => 'SM123', 'status' => 'queued']));

        $provider = new TwilioSmsProvider('ACxxx', 'authtoken', '+15550001111');
        $result = $provider->send('+15551234567', 'Test message');

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
    }

    public function test_send_posts_expected_request_shape(): void
    {
        $captured_url  = null;
        $captured_args = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured_url, &$captured_args) {
            $captured_url  = $url;
            $captured_args = $args;
            return [];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $provider = new TwilioSmsProvider('AC123', 'secrettoken', '+15550001111');
        $provider->send('+15559998888', 'Reminder: your hour is soon.');

        $this->assertStringContainsString('/Accounts/AC123/Messages.json', $captured_url);
        $this->assertSame('+15559998888', $captured_args['body']['To']);
        $this->assertSame('+15550001111', $captured_args['body']['From']);
        $this->assertSame('Reminder: your hour is soon.', $captured_args['body']['Body']);
        $this->assertSame('Basic ' . base64_encode('AC123:secrettoken'), $captured_args['headers']['Authorization']);
    }
}
