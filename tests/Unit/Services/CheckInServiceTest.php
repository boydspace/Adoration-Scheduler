<?php
namespace AdorationScheduler\Tests\Unit\Services;

use AdorationScheduler\Services\CheckInService;
use AdorationScheduler\Tests\Support\AdorationTestCase;

class CheckInServiceTest extends AdorationTestCase
{
    public function test_mode_normalization_allows_only_checkout(): void
    {
        $this->assertSame('out', CheckInService::normalize_mode('out'));
        $this->assertSame('in', CheckInService::normalize_mode('in'));
        $this->assertSame('in', CheckInService::normalize_mode('OUT'));
        $this->assertSame('in', CheckInService::normalize_mode('anything'));
        $this->assertSame('in', CheckInService::normalize_mode(''));
    }

    public function test_checkin_is_blocked_before_early_arrival_window(): void
    {
        $slot = ['start_at' => '2026-08-02 10:00:00'];
        $now = new \DateTimeImmutable('2026-08-02 09:29:59', new \DateTimeZone('UTC'));

        $this->assertTrue(CheckInService::is_checkin_too_early($slot, $now));
    }

    public function test_checkin_opens_exactly_thirty_minutes_before_start(): void
    {
        $slot = ['start_at' => '2026-08-02 10:00:00'];
        $opening = new \DateTimeImmutable('2026-08-02 09:30:00', new \DateTimeZone('UTC'));
        $after_start = new \DateTimeImmutable('2026-08-02 10:15:00', new \DateTimeZone('UTC'));

        $this->assertFalse(CheckInService::is_checkin_too_early($slot, $opening));
        $this->assertFalse(CheckInService::is_checkin_too_early($slot, $after_start));
    }

    public function test_missing_or_invalid_slot_time_fails_open(): void
    {
        $now = new \DateTimeImmutable('2026-08-02 09:00:00', new \DateTimeZone('UTC'));

        $this->assertFalse(CheckInService::is_checkin_too_early(null, $now));
        $this->assertFalse(CheckInService::is_checkin_too_early([], $now));
        $this->assertFalse(CheckInService::is_checkin_too_early(['start_at' => 'not-a-date'], $now));
    }

    public function test_early_window_crosses_midnight_correctly(): void
    {
        $slot = ['start_at' => '2026-08-03 00:15:00'];
        $before_window = new \DateTimeImmutable('2026-08-02 23:44:59', new \DateTimeZone('UTC'));
        $opening = new \DateTimeImmutable('2026-08-02 23:45:00', new \DateTimeZone('UTC'));

        $this->assertTrue(CheckInService::is_checkin_too_early($slot, $before_window));
        $this->assertFalse(CheckInService::is_checkin_too_early($slot, $opening));
    }

    public function test_custom_early_window_is_enforced_and_clamped(): void
    {
        $slot = ['start_at' => '2026-08-02 10:00:00'];
        $at_start = new \DateTimeImmutable('2026-08-02 10:00:00', new \DateTimeZone('UTC'));
        $one_minute_early = new \DateTimeImmutable('2026-08-02 09:59:00', new \DateTimeZone('UTC'));
        $ninety_minutes_early = new \DateTimeImmutable('2026-08-02 08:30:00', new \DateTimeZone('UTC'));

        $this->assertTrue(CheckInService::is_checkin_too_early($slot, $one_minute_early, 0));
        $this->assertFalse(CheckInService::is_checkin_too_early($slot, $at_start, 0));
        $this->assertFalse(CheckInService::is_checkin_too_early($slot, $ninety_minutes_early, 120));
        $this->assertTrue(CheckInService::is_checkin_too_early($slot, $ninety_minutes_early, 60));
    }

    public function test_chapel_policy_defaults_and_normalization(): void
    {
        $defaults = CheckInService::policy_from_chapel([]);
        $this->assertSame(30, $defaults['checkin_early_minutes']);
        $this->assertTrue($defaults['guest_checkin_enabled']);
        $this->assertTrue($defaults['checkout_enabled']);
        $this->assertSame('first_last_initial', $defaults['kiosk_name_display']);

        $custom = CheckInService::policy_from_chapel([
            'checkin_early_minutes' => 999,
            'guest_checkin_enabled' => 0,
            'checkout_enabled' => 0,
            'kiosk_name_display' => 'not-valid',
        ]);
        $this->assertSame(120, $custom['checkin_early_minutes']);
        $this->assertFalse($custom['guest_checkin_enabled']);
        $this->assertFalse($custom['checkout_enabled']);
        $this->assertSame('first_last_initial', $custom['kiosk_name_display']);
    }

    public function test_kiosk_name_privacy_modes(): void
    {
        $this->assertSame('Maria T.', CheckInService::format_kiosk_name('Maria', 'Therese', 'first_last_initial'));
        $this->assertSame('Maria', CheckInService::format_kiosk_name('Maria', 'Therese', 'first_name'));
        $this->assertSame('M.T.', CheckInService::format_kiosk_name('Maria', 'Therese', 'initials'));
        $this->assertSame('Maria Therese', CheckInService::format_kiosk_name('Maria', 'Therese', 'full_name'));
        $this->assertSame('Maria T.', CheckInService::format_kiosk_name(' Maria ', ' Therese ', 'unknown'));
    }

    public function test_kiosk_accepts_only_signup_on_live_roster(): void
    {
        $roster = [
            ['id' => 41],
            ['id' => '42'],
            ['person_first_name' => 'Missing ID'],
        ];

        $this->assertTrue(CheckInService::is_current_signup($roster, 41));
        $this->assertTrue(CheckInService::is_current_signup($roster, 42));
        $this->assertFalse(CheckInService::is_current_signup($roster, 43));
        $this->assertFalse(CheckInService::is_current_signup($roster, 0));
        $this->assertFalse(CheckInService::is_current_signup([], 41));
    }
}
