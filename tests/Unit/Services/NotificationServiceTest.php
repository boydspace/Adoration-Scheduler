<?php
namespace AdorationScheduler\Tests\Unit\Services;

use AdorationScheduler\Tests\Support\AdorationTestCase;
use AdorationScheduler\Services\NotificationService;
use AdorationScheduler\Admin\Pages\EmailTemplatesPage;
use Brain\Monkey\Functions;

/**
 * Exercises NotificationService's public API only — render_subject(),
 * render_body(), should_send(), build_dedupe_key(), etc. stay
 * private static (no visibility widened for these tests, unlike
 * replace_tokens() which is public for SmsService to reuse). Behavior is
 * observed through wp_mail()'s captured arguments and each method's
 * boolean return, the same way NotificationServiceTest's real callers do.
 */
final class NotificationServiceTest extends AdorationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fake_wpdb();

        // get_templates_global() reads this option directly (not through
        // EmailTemplatesPage::get_templates()'s defaults-merge) — stub it
        // to the real default templates so merge-tag substitution is
        // exercised against the same text a real site actually sends,
        // rather than NotificationService's internal legacy fallback.
        Functions\when('get_option')->alias(function ($name, $default = false) {
            if ($name === EmailTemplatesPage::OPTION_KEY) {
                return EmailTemplatesPage::defaults();
            }
            return $default;
        });

        Functions\when('date_i18n')->alias(static fn($format) => gmdate($format));
    }

    public function test_send_signup_confirmation_rejects_blank_email(): void
    {
        $this->assertFalse(NotificationService::send_signup_confirmation(['to_email' => '']));
    }

    public function test_send_signup_confirmation_rejects_invalid_email(): void
    {
        $this->assertFalse(NotificationService::send_signup_confirmation(['to_email' => 'not-an-email']));
    }

    /**
     * should_send() treats an explicit opt-out as "nothing to do", not a
     * failure — callers (e.g. ReminderScheduler gating on a person's
     * email_reminder_opt_in) rely on this to distinguish "didn't send
     * because they opted out" from "tried to send and failed".
     */
    public function test_send_reminder_24h_treats_explicit_opt_out_as_success(): void
    {
        $sent = false;
        Functions\when('wp_mail')->alias(function (...$args) use (&$sent) {
            $sent = true;
            return true;
        });

        $ok = NotificationService::send_reminder_24h([
            'to_email' => 'person@example.test',
            'send'     => false,
        ]);

        $this->assertTrue($ok);
        $this->assertFalse($sent, 'wp_mail should never be called when send=false');
    }

    public function test_send_signup_confirmation_calls_wp_mail_with_expected_recipient_and_merge_tags(): void
    {
        $captured = [];
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers = '') use (&$captured) {
            $captured = ['to' => $to, 'subject' => $subject, 'body' => $body];
            return true;
        });

        $ok = NotificationService::send_signup_confirmation([
            'to_email'       => 'adorer@example.test',
            'title'          => 'Father',
            'first_name'     => 'Andy',
            'last_name'      => 'Boyd',
            'schedule_title' => 'Weekly Adoration',
            'slot_date'      => 'January 1, 2026',
            'slot_start'     => '10:00 AM',
            'slot_end'       => '11:00 AM',
        ]);

        $this->assertTrue($ok);
        $this->assertSame('adorer@example.test', $captured['to']);
        $this->assertStringContainsString('Father Andy', $captured['body']);
        $this->assertStringContainsString('Weekly Adoration', $captured['body']);
    }

    public function test_replace_tokens_title_first_name_has_no_stray_space_when_title_blank(): void
    {
        $result = NotificationService::replace_tokens('Hello {title_first_name},', [
            'title'      => '',
            'first_name' => 'Andy',
        ]);

        $this->assertSame('Hello Andy,', $result);
    }

    public function test_replace_tokens_title_first_name_includes_title_when_present(): void
    {
        $result = NotificationService::replace_tokens('Hello {title_first_name},', [
            'title'      => 'Father',
            'first_name' => 'Andy',
        ]);

        $this->assertSame('Hello Father Andy,', $result);
    }

    public function test_replace_tokens_substitutes_schedule_and_slot_tokens(): void
    {
        $result = NotificationService::replace_tokens('{schedule_title} on {slot_date} at {slot_start}', [
            'schedule_title' => 'Weekly Adoration',
            'slot_date'      => 'Jan 1',
            'slot_start'     => '10:00 AM',
        ]);

        $this->assertSame('Weekly Adoration on Jan 1 at 10:00 AM', $result);
    }

    public function test_send_test_template_rejects_invalid_recipient(): void
    {
        $this->assertFalse(NotificationService::send_test_template('signup_confirmation', 'not-an-email'));
    }

    public function test_send_test_template_sends_with_sample_data(): void
    {
        $sent_to = null;
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers = '') use (&$sent_to) {
            $sent_to = $to;
            return true;
        });

        $ok = NotificationService::send_test_template('signup_confirmation', 'admin@example.test');

        $this->assertTrue($ok);
        $this->assertSame('admin@example.test', $sent_to);
    }
}
