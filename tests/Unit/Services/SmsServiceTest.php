<?php
namespace AdorationScheduler\Tests\Unit\Services;

use AdorationScheduler\Tests\Support\AdorationTestCase;
use AdorationScheduler\Services\SmsService;
use AdorationScheduler\Admin\Pages\SmsSettingsPage;
use Brain\Monkey\Functions;

/**
 * Exercises SmsService's gating logic (not-configured / opted-out /
 * invalid-phone all no-op successfully; only a fully-cleared send actually
 * reaches Twilio) — not the Twilio HTTP details themselves, which
 * TwilioSmsProviderTest already covers. SmsService::provider() stays
 * private (no test seam added); instead the underlying wp_remote_post()
 * call is stubbed the same way TwilioSmsProviderTest does, so a real
 * TwilioSmsProvider is constructed but never actually reaches the network.
 */
final class SmsServiceTest extends AdorationTestCase
{
    private array $sms_options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms_options = [
            'enabled'            => 0,
            'twilio_account_sid' => '',
            'twilio_auth_token'  => '',
            'twilio_from_number' => '',
            'reminder_sms_body'  => 'Reminder: {schedule_title} at {slot_start}.',
        ];

        Functions\when('get_option')->alias(function ($name, $default = false) {
            if ($name === SmsSettingsPage::OPTION_NAME) {
                return $this->sms_options;
            }
            return $default;
        });

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_parse_args')->alias(static function ($args, $defaults) {
            return array_merge($defaults, is_array($args) ? $args : []);
        });
    }

    private function configure_sms(): void
    {
        $this->sms_options = [
            'enabled'            => 1,
            'twilio_account_sid' => 'ACtest',
            'twilio_auth_token'  => 'secret',
            'twilio_from_number' => '+15550001111',
            'reminder_sms_body'  => 'Reminder: {schedule_title} at {slot_start}.',
        ];
    }

    private function stub_twilio_response(int $code): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
    }

    public function test_send_reminder_sms_noops_when_not_configured(): void
    {
        $called = false;
        Functions\when('wp_remote_post')->alias(function (...$a) use (&$called) { $called = true; return []; });

        $ok = SmsService::send_reminder_sms([
            'phone'               => '(555) 123-4567',
            'sms_reminder_opt_in' => true,
        ]);

        $this->assertTrue($ok);
        $this->assertFalse($called, 'Twilio should never be contacted when SMS is not configured');
    }

    public function test_send_reminder_sms_noops_when_person_opted_out(): void
    {
        $this->configure_sms();
        $called = false;
        Functions\when('wp_remote_post')->alias(function (...$a) use (&$called) { $called = true; return []; });

        $ok = SmsService::send_reminder_sms([
            'phone'               => '(555) 123-4567',
            'sms_reminder_opt_in' => false,
        ]);

        $this->assertTrue($ok);
        $this->assertFalse($called, 'Twilio should never be contacted when the person opted out');
    }

    public function test_send_reminder_sms_noops_when_phone_invalid(): void
    {
        $this->configure_sms();
        $called = false;
        Functions\when('wp_remote_post')->alias(function (...$a) use (&$called) { $called = true; return []; });

        $ok = SmsService::send_reminder_sms([
            'phone'               => 'not a phone number',
            'sms_reminder_opt_in' => true,
        ]);

        $this->assertTrue($ok);
        $this->assertFalse($called, 'Twilio should never be contacted with no valid phone number');
    }

    public function test_send_reminder_sms_sends_when_fully_cleared(): void
    {
        $this->configure_sms();
        $this->stub_twilio_response(201);

        $captured = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured) {
            $captured = $args;
            return [];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $ok = SmsService::send_reminder_sms([
            'phone'               => '(555) 123-4567',
            'sms_reminder_opt_in' => true,
            'schedule_title'      => 'Weekly Adoration',
            'slot_start'          => '10:00 AM',
        ]);

        $this->assertTrue($ok);
        $this->assertNotNull($captured, 'Twilio should be contacted once every gate passes');
        $this->assertSame('+15551234567', $captured['body']['To']);
        $this->assertSame('Reminder: Weekly Adoration at 10:00 AM.', $captured['body']['Body']);
    }

    public function test_send_reminder_sms_returns_false_on_provider_failure(): void
    {
        $this->configure_sms();
        $this->stub_twilio_response(500);

        $ok = SmsService::send_reminder_sms([
            'phone'               => '(555) 123-4567',
            'sms_reminder_opt_in' => true,
        ]);

        $this->assertFalse($ok);
    }

    public function test_send_test_fails_when_not_configured(): void
    {
        $result = SmsService::send_test('+15551234567');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_send_test_succeeds_when_configured(): void
    {
        $this->configure_sms();
        $this->stub_twilio_response(201);

        $result = SmsService::send_test('+15551234567');

        $this->assertTrue($result['success']);
    }
}
