<?php
namespace AdorationScheduler\Domain\Services;

use DateTime;
use DateTimeZone;
use Exception;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\ScheduleClosuresRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * MonthlySlotGenerator
 *
 * Keeps a rolling window of real dated `slots` rows materialized for a
 * monthly (e.g. "1st Friday of the month") schedule, driven by nth-weekday-
 * of-month templates on date_patterns (day_of_week + week_of_month set,
 * date NULL — see DatePatternsRepository::list_monthly_templates_for_schedule).
 *
 * Unlike PerpetualSlotGenerator, this NEVER auto-creates signups: monthly
 * schedules use a per-occurrence signup model (each month's occurrence is
 * its own one-time slot people sign up for individually), not standing
 * commitments. This was an explicit product decision — see
 * project_adoration_scheduler_monthly_recurrence memory.
 *
 * Slot expansion itself (overnight rollover, canonical start_at/end_at) is
 * delegated to SlotGenerator::build_slots_for_date(), same as the perpetual
 * and event paths, so all schedule types behave identically for the same
 * segment configuration.
 *
 * Same conservative v1 rule as PerpetualSlotGenerator: this NEVER deactivates
 * or deletes slots, only ever inserts newly-covered dates going forward.
 */
class MonthlySlotGenerator
{
    private DatePatternsRepository $dateRepo;
    private SegmentsRepository $segmentsRepo;
    private SlotsRepository $slotsRepo;
    private SlotGenerator $slotBuilder;
    private ScheduleClosuresRepository $closuresRepo;

    public function __construct(
        DatePatternsRepository $dateRepo,
        SegmentsRepository $segmentsRepo,
        SlotsRepository $slotsRepo,
        ?ScheduleClosuresRepository $closuresRepo = null
    ) {
        $this->dateRepo     = $dateRepo;
        $this->segmentsRepo = $segmentsRepo;
        $this->slotsRepo    = $slotsRepo;
        $this->closuresRepo = $closuresRepo ?: new ScheduleClosuresRepository();

        $this->slotBuilder = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
    }

    /**
     * Materialize slots for [today, today + days_ahead] from the schedule's
     * nth-weekday-of-month templates.
     *
     * Returns ['generated' => int, 'inserted' => int]
     */
    public function sync_window(array $schedule, int $days_ahead): array
    {
        $result = ['generated' => 0, 'inserted' => 0];

        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) return $result;

        $days_ahead = (int)$days_ahead;
        if ($days_ahead < 0) $days_ahead = 0;
        if ($days_ahead > 366) $days_ahead = 366; // sanity cap

        $templates = $this->dateRepo->list_monthly_templates_for_schedule($schedule_id);
        if (empty($templates)) return $result; // nothing configured yet

        // Build day_of_week => [ [week_of_month, segments], ... ] map. A single
        // weekday can have more than one pattern (e.g. "1st Friday" AND "3rd
        // Friday" both configured under the same schedule).
        $patterns_by_dow = [];
        foreach ($templates as $t) {
            $dow = (int)($t['day_of_week'] ?? -1);
            $wom = (int)($t['week_of_month'] ?? 0);
            if ($dow < 0 || $dow > 6 || $wom < 1 || $wom > 6) continue;

            $template_id = (int)($t['id'] ?? 0);
            $segs = $template_id > 0 ? $this->segmentsRepo->list_for_date_pattern($template_id) : [];
            if (empty($segs)) continue;

            if (!isset($patterns_by_dow[$dow])) $patterns_by_dow[$dow] = [];
            $patterns_by_dow[$dow][] = ['week_of_month' => $wom, 'segments' => $segs];
        }

        if (empty($patterns_by_dow)) return $result; // templates exist but have no hours yet

        $tz = function_exists('wp_timezone')
            ? wp_timezone()
            : new DateTimeZone(get_option('timezone_string') ?: 'UTC');

        try {
            $cursor = new DateTime('today', $tz);
        } catch (Exception $e) {
            return $result;
        }

        $generated = [];

        for ($i = 0; $i <= $days_ahead; $i++) {
            $ymd = $cursor->format('Y-m-d');
            $dow = (int)$cursor->format('w'); // 0=Sunday..6=Saturday

            if (isset($patterns_by_dow[$dow])) {
                $day_of_month  = (int)$cursor->format('j');
                $days_in_month = (int)$cursor->format('t');

                // 1-based "which occurrence of this weekday within the month is this."
                $occurrence = intdiv($day_of_month - 1, 7) + 1;
                // True if no more of this weekday remain later in the same month.
                $is_last = ($day_of_month + 7) > $days_in_month;

                foreach ($patterns_by_dow[$dow] as $pattern) {
                    $wom = (int)$pattern['week_of_month'];
                    $matches = ($wom === $occurrence) || ($wom === 6 && $is_last);
                    if (!$matches) continue;

                    $rows = $this->slotBuilder->build_slots_for_date($schedule, $ymd, $pattern['segments']);
                    foreach ($rows as $row) {
                        $generated[] = $row;
                    }
                }
            }

            $cursor->modify('+1 day');
        }

        // $cursor now sits one day past the last covered date — the exclusive
        // upper bound of the window just walked.
        $window_start_at = (new DateTime('today', $tz))->format('Y-m-d') . ' 00:00:00';
        $window_end_at   = $cursor->format('Y-m-d') . ' 00:00:00';

        // Respect any active closures, same as PerpetualSlotGenerator — never
        // generate a slot whose time window falls inside a closure period.
        $closures = $this->closuresRepo->list_overlapping($schedule_id, $window_start_at, $window_end_at);
        if (!empty($closures)) {
            $generated = array_values(array_filter($generated, function ($row) use ($closures) {
                $row_start = (string)($row['start_at'] ?? '');
                $row_end   = (string)($row['end_at'] ?? '');
                if ($row_start === '' || $row_end === '') return true; // can't evaluate, keep (defensive)

                foreach ($closures as $c) {
                    $c_start = (string)($c['start_at'] ?? '');
                    $c_end   = (string)($c['end_at'] ?? '');
                    if ($c_start === '' || $c_end === '') continue;

                    if ($row_start < $c_end && $row_end > $c_start) {
                        return false; // overlaps a closure — skip generating this slot
                    }
                }
                return true;
            }));
        }

        $result['generated'] = count($generated);
        if (empty($generated)) return $result;

        // Dedupe against existing slots for this schedule (never touch/deactivate them).
        $existing = $this->slotsRepo->list_for_schedule($schedule_id);
        $exist_keys = [];
        foreach ($existing as $ex) {
            $exist_keys[$this->slot_key($ex)] = true;
        }

        foreach ($generated as $row) {
            $key = $this->slot_key($row);
            if (isset($exist_keys[$key])) continue;

            $id = $this->slotsRepo->insert($row);
            if ($id > 0) {
                $result['inserted']++;
                $exist_keys[$key] = true; // guard against dupes within this same batch
            }
        }

        return $result;
    }

    /**
     * Stable key for matching generated slots to existing slots.
     * Mirrors PerpetualSlotGenerator::slot_key() / SlotGenerator::slot_key().
     */
    private function slot_key(array $row): string {
        $schedule_id = (int)($row['schedule_id'] ?? 0);

        $date = (string)($row['date'] ?? '');
        $st   = substr((string)($row['start_time'] ?? ''), 0, 8);
        $et   = substr((string)($row['end_time'] ?? ''), 0, 8);
        $seg  = (int)($row['segment_id'] ?? 0);

        return $schedule_id . '|' . $date . '|' . $st . '|' . $et . '|' . $seg;
    }
}
