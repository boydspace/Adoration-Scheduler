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
