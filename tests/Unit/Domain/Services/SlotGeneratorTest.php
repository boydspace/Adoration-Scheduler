<?php
namespace AdorationScheduler\Tests\Unit\Domain\Services;

use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Services\SlotGenerator;
use AdorationScheduler\Tests\Support\AdorationTestCase;

/**
 * build_slots_for_date() is the one place both event-schedule generation and
 * PerpetualSlotGenerator expand (segments, a calendar date) into real dated
 * slot rows, and the plugin's own docblocks flag its overnight-rollover math
 * as the trickiest logic in the codebase (see the "Overnight handling"
 * comment in SlotGenerator.php). It's pure — given a schedule array, a date,
 * and segments, it returns slot rows with no database or WordPress state
 * beyond wp_timezone() — which makes it an ideal first target: no FakeWpdb
 * query programming needed, just construct it and call the method.
 */
final class SlotGeneratorTest extends AdorationTestCase
{
    private SlotGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake_wpdb(); // repo constructors read $wpdb->prefix; never queried in these tests

        $this->generator = new SlotGenerator(
            new DatePatternsRepository(),
            new SegmentsRepository(),
            new SlotsRepository()
        );
    }

    private function schedule(array $overrides = []): array
    {
        return array_merge([
            'id'                   => 1,
            'chapel_id'            => 0,
            'default_slot_length'  => 60,
            'default_min_adorers'  => 1,
            'default_max_adorers'  => null,
            'is_overnight'         => false,
        ], $overrides);
    }

    public function test_simple_daytime_segment_produces_one_hour_slots(): void
    {
        $segments = [
            ['id' => 10, 'start_time' => '09:00', 'end_time' => '11:00', 'slot_length' => 60],
        ];

        $rows = $this->generator->build_slots_for_date($this->schedule(), '2026-07-22', $segments);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-07-22', $rows[0]['date']);
        $this->assertSame('09:00:00', $rows[0]['start_time']);
        $this->assertSame('10:00:00', $rows[0]['end_time']);
        $this->assertSame('10:00:00', $rows[1]['start_time']);
        $this->assertSame('11:00:00', $rows[1]['end_time']);
    }

    public function test_custom_segment_slot_length_overrides_schedule_default(): void
    {
        $segments = [
            ['id' => 10, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_length' => 30],
        ];

        $rows = $this->generator->build_slots_for_date($this->schedule(), '2026-07-22', $segments);

        $this->assertCount(2, $rows);
        $this->assertSame('09:30:00', $rows[0]['end_time']);
    }

    public function test_non_overnight_schedule_skips_a_segment_that_crosses_midnight(): void
    {
        // end (01:00) <= start (22:00) => crosses midnight, but is_overnight is false.
        $segments = [
            ['id' => 10, 'start_time' => '22:00', 'end_time' => '01:00', 'slot_length' => 60],
        ];

        $rows = $this->generator->build_slots_for_date($this->schedule(['is_overnight' => false]), '2026-07-22', $segments);

        $this->assertSame([], $rows, 'A midnight-crossing segment on a non-overnight schedule must be skipped, not silently mis-generated.');
    }

    public function test_overnight_schedule_single_crossing_segment_rolls_end_to_next_day(): void
    {
        $segments = [
            ['id' => 10, 'start_time' => '22:00', 'end_time' => '02:00', 'slot_length' => 60],
        ];

        $rows = $this->generator->build_slots_for_date($this->schedule(['is_overnight' => true]), '2026-07-22', $segments);

        $this->assertCount(4, $rows); // 22-23, 23-00, 00-01, 01-02
        $this->assertSame('2026-07-22', $rows[0]['date']);
        $this->assertSame('22:00:00', $rows[0]['start_time']);

        $last = $rows[count($rows) - 1];
        $this->assertSame('2026-07-23', $last['date'], 'The final hour (01:00-02:00) is really on the next calendar date.');
        $this->assertSame('02:00:00', $last['end_time']);

        // Canonical start_at/end_at must reflect true chronological order across midnight.
        $this->assertSame('2026-07-22 22:00:00', $rows[0]['start_at']);
        $this->assertSame('2026-07-23 02:00:00', $last['end_at']);
    }

    /**
     * Documented fallback in SlotGenerator::build_slots_for_date(): if an
     * overnight schedule has NO segment that itself crosses midnight (common
     * when the admin splits an overnight block into two separate segments,
     * e.g. "10pm-12am" + "12am-6am" instead of one "10pm-6am" segment), the
     * anchor is the LATEST segment start time, and anything starting earlier
     * than that anchor is treated as belonging to the next day.
     */
    public function test_overnight_schedule_two_split_segments_uses_latest_start_as_anchor(): void
    {
        // Neither segment individually "crosses midnight" (end <= start) —
        // 22:00-23:00 doesn't, and 00:00-01:00 doesn't either — so the
        // primary anchor detection (a segment that itself crosses) finds
        // nothing, and build_slots_for_date() must fall back to using the
        // LATEST segment start time (22:00) as the day's anchor. Anything
        // starting earlier than that anchor — the 00:00 segment — then
        // rolls to the next calendar date.
        $segments = [
            ['id' => 10, 'start_time' => '22:00', 'end_time' => '23:00', 'slot_length' => 60],
            ['id' => 11, 'start_time' => '00:00', 'end_time' => '01:00', 'slot_length' => 60],
        ];

        $rows = $this->generator->build_slots_for_date($this->schedule(['is_overnight' => true]), '2026-07-22', $segments);

        $seg10_rows = array_values(array_filter($rows, fn($r) => $r['segment_id'] === 10));
        $seg11_rows = array_values(array_filter($rows, fn($r) => $r['segment_id'] === 11));

        $this->assertCount(1, $seg10_rows);
        $this->assertCount(1, $seg11_rows);

        $this->assertSame('2026-07-22', $seg10_rows[0]['date'], 'The 10pm segment (the anchor itself) stays on the base date.');
        $this->assertSame('2026-07-23', $seg11_rows[0]['date'], 'The 12am segment starts earlier than the anchor, so it rolls to the next calendar date.');
    }

    public function test_empty_segments_returns_empty_array(): void
    {
        $this->assertSame([], $this->generator->build_slots_for_date($this->schedule(), '2026-07-22', []));
    }

    public function test_invalid_schedule_id_returns_empty_array(): void
    {
        $segments = [['id' => 10, 'start_time' => '09:00', 'end_time' => '10:00']];
        $rows = $this->generator->build_slots_for_date($this->schedule(['id' => 0]), '2026-07-22', $segments);
        $this->assertSame([], $rows);
    }

    public function test_partial_final_slot_is_clamped_to_segment_end(): void
    {
        // 90-minute slot length over a 2-hour window doesn't divide evenly;
        // the second slot should be clamped to the segment's actual end,
        // not overshoot it.
        $segments = [
            ['id' => 10, 'start_time' => '09:00', 'end_time' => '11:00', 'slot_length' => 90],
        ];

        $rows = $this->generator->build_slots_for_date($this->schedule(), '2026-07-22', $segments);

        $this->assertCount(2, $rows);
        $this->assertSame('09:00:00', $rows[0]['start_time']);
        $this->assertSame('10:30:00', $rows[0]['end_time']);
        $this->assertSame('10:30:00', $rows[1]['start_time']);
        $this->assertSame('11:00:00', $rows[1]['end_time'], 'Second slot must be clamped to the segment end, not run to 12:00.');
    }
}
