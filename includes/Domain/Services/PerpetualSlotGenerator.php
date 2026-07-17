<?php
namespace AdorationScheduler\Domain\Services;

use DateTime;
use DateTimeZone;
use Exception;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Repositories\ScheduleClosuresRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * PerpetualSlotGenerator
 *
 * Keeps a rolling window of real dated `slots` rows materialized for a perpetual
 * (24/7/365-style) schedule, driven by weekday templates on date_patterns
 * (day_of_week set, date NULL — see DatePatternsRepository::list_weekday_templates_for_schedule).
 *
 * Slot expansion itself (overnight rollover, canonical start_at/end_at) is delegated
 * to SlotGenerator::build_slots_for_date() so perpetual and event schedules behave
 * identically for the same segment configuration — this class only handles turning
 * "day of week" templates into a run of real calendar dates.
 *
 * IMPORTANT, intentionally conservative for v1:
 * - This NEVER deactivates or deletes slots. It only ever inserts newly-covered
 *   dates going forward. If an admin removes a weekday from the template, existing
 *   generated slots for that weekday are left alone (admin can deactivate manually
 *   from the Slots tab) rather than risk auto-deactivating slots that already have
 *   signups.
 * - Standing-commitment auto-signups respect each slot's max_adorers capacity and
 *   never duplicate an existing signup for the same person+slot+date.
 */
class PerpetualSlotGenerator
{
    private DatePatternsRepository $dateRepo;
    private SegmentsRepository $segmentsRepo;
    private SlotsRepository $slotsRepo;
    private StandingCommitmentsRepository $commitmentsRepo;
    private SignupsRepository $signupsRepo;
    private SlotGenerator $slotBuilder;
    private ScheduleClosuresRepository $closuresRepo;

    public function __construct(
        DatePatternsRepository $dateRepo,
        SegmentsRepository $segmentsRepo,
        SlotsRepository $slotsRepo,
        StandingCommitmentsRepository $commitmentsRepo,
        SignupsRepository $signupsRepo,
        ?ScheduleClosuresRepository $closuresRepo = null
    ) {
        $this->dateRepo        = $dateRepo;
        $this->segmentsRepo    = $segmentsRepo;
        $this->slotsRepo       = $slotsRepo;
        $this->commitmentsRepo = $commitmentsRepo;
        $this->signupsRepo     = $signupsRepo;
        // Optional param (defaults itself) so existing call sites don't need updating.
        $this->closuresRepo    = $closuresRepo ?: new ScheduleClosuresRepository();

        // Reuses build_slots_for_date() only; never touches its event-schedule methods.
        $this->slotBuilder = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
    }

    /**
     * Materialize slots for [today, today + days_ahead] from the schedule's weekday
     * templates, then auto-create signups for any active standing commitments that
     * match a newly-relevant slot.
     *
     * Returns ['generated' => int, 'inserted' => int, 'signups_created' => int]
     */
    public function sync_window(array $schedule, int $days_ahead): array
    {
        $result = ['generated' => 0, 'inserted' => 0, 'signups_created' => 0];

        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) return $result;

        $days_ahead = (int)$days_ahead;
        if ($days_ahead < 0) $days_ahead = 0;
        if ($days_ahead > 366) $days_ahead = 366; // sanity cap

        $templates = $this->dateRepo->list_weekday_templates_for_schedule($schedule_id);
        if (empty($templates)) return $result; // nothing configured yet

        // Build day_of_week => segments map.
        $segments_by_dow = [];
        foreach ($templates as $t) {
            $dow = (int)($t['day_of_week'] ?? -1);
            if ($dow < 0 || $dow > 6) continue;

            $template_id = (int)($t['id'] ?? 0);
            $segs = $template_id > 0 ? $this->segmentsRepo->list_for_date_pattern($template_id) : [];
            if (!empty($segs)) {
                $segments_by_dow[$dow] = $segs;
            }
        }

        if (empty($segments_by_dow)) return $result; // templates exist but have no hours yet

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

            if (isset($segments_by_dow[$dow])) {
                $rows = $this->slotBuilder->build_slots_for_date($schedule, $ymd, $segments_by_dow[$dow]);
                foreach ($rows as $row) {
                    $generated[] = $row;
                }
            }

            $cursor->modify('+1 day');
        }

        // $cursor now sits one day past the last covered date — the exclusive
        // upper bound of the window just walked.
        $window_start_at = (new DateTime('today', $tz))->format('Y-m-d') . ' 00:00:00';
        $window_end_at   = $cursor->format('Y-m-d') . ' 00:00:00';

        // Respect any active closures (e.g. holiday blackouts): never generate a slot
        // whose time window falls inside a closure period, so the daily sync doesn't
        // silently re-fill a window the admin explicitly shut down (see
        // ScheduleClosuresRepository — set once via the admin "Block Out a Date/Time
        // Range" action, checked here on every run, not just at creation time).
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

        $inserted_rows = [];

        foreach ($generated as $row) {
            $key = $this->slot_key($row);
            if (isset($exist_keys[$key])) continue;

            $id = $this->slotsRepo->insert($row);
            if ($id > 0) {
                $result['inserted']++;
                $row['id'] = $id;
                $inserted_rows[] = $row;
                $exist_keys[$key] = true; // guard against dupes within this same batch
            }
        }

        // Apply standing commitments to every slot in the window (not just newly
        // inserted ones — e.g. a commitment created today should also fill slots
        // that were generated yesterday but are still ahead of today).
        $window_slots = array_merge($existing, $inserted_rows);
        $result['signups_created'] = $this->apply_standing_commitments(
            $schedule_id,
            (int)($schedule['default_max_adorers'] ?? 0) > 0 ? (int)$schedule['default_max_adorers'] : null,
            $window_slots
        );

        return $result;
    }

    /**
     * For every slot in $window_slots, look up active standing commitments matching
     * its (day_of_week, start_time) and auto-create a signup for each committed
     * person who doesn't already have one for that exact date, respecting the
     * slot's own max_adorers (falling back to the schedule default if the slot's
     * own max_adorers is NULL/unset).
     *
     * Returns the number of signups created.
     */
    private function apply_standing_commitments(int $schedule_id, ?int $schedule_default_max, array $window_slots): int {
        global $wpdb;

        if (empty($window_slots)) return 0;

        // Pre-load active commitments grouped by "dow|start_time" so we don't hit the
        // DB per-slot.
        $rows = $this->commitmentsRepo->list_for_schedule($schedule_id, true);
        if (empty($rows)) return 0;

        $by_key = [];
        foreach ($rows as $r) {
            $dow = (int)($r['day_of_week'] ?? -1);
            $st  = substr((string)($r['start_time'] ?? ''), 0, 8);
            if ($dow < 0 || $st === '') continue;
            $k = $dow . '|' . $st;
            if (!isset($by_key[$k])) $by_key[$k] = [];
            $by_key[$k][] = $r;
        }
        if (empty($by_key)) return 0;

        $signups_table = $wpdb->prefix . 'adoration_signups';
        $created = 0;

        $today = current_time('Y-m-d');

        foreach ($window_slots as $slot) {
            $slot_id = (int)($slot['id'] ?? 0);
            $date    = (string)($slot['date'] ?? '');
            $st      = substr((string)($slot['start_time'] ?? ''), 0, 8);

            if ($slot_id <= 0 || $date === '' || $st === '') continue;
            if ($date < $today) continue; // never auto-fill past dates

            // Defensive: never auto-fill a slot that's been deactivated (e.g. by a
            // closure/blackout — see ScheduleClosuresRepository). $window_slots
            // includes existing rows regardless of is_active, so this must be
            // checked here even though sync_window() already filters closures out
            // of newly-generated rows.
            if (array_key_exists('is_active', $slot) && (int)$slot['is_active'] === 0) continue;

            try {
                $dow = (int)(new DateTime($date))->format('w');
            } catch (Exception $e) {
                continue;
            }

            $k = $dow . '|' . $st;
            if (empty($by_key[$k])) continue;

            // Capacity: prefer the slot's own max_adorers; fall back to schedule default.
            $max_adorers = null;
            if (array_key_exists('max_adorers', $slot) && $slot['max_adorers'] !== null && $slot['max_adorers'] !== '') {
                $max_adorers = (int)$slot['max_adorers'];
            } elseif ($schedule_default_max !== null) {
                $max_adorers = $schedule_default_max;
            }

            foreach ($by_key[$k] as $commitment) {
                $person_id = (int)($commitment['person_id'] ?? 0);
                if ($person_id <= 0) continue;

                if ($this->signupsRepo->exists_for_slot_person_date($slot_id, $person_id, $date, null)) {
                    continue; // already has a signup (confirmed or otherwise) for this date
                }

                if ($max_adorers !== null) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $current = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$signups_table} WHERE slot_id = %d AND status = 'confirmed'",
                        $slot_id
                    ));
                    if ($current >= $max_adorers) {
                        continue; // full
                    }
                }

                $insert_id = $this->signupsRepo->create([
                    'person_id'   => $person_id,
                    'schedule_id' => $schedule_id,
                    'slot_id'     => $slot_id,
                    'date'        => $date,
                    'status'      => 'confirmed',
                    'type'        => 'standing',
                    'created_via' => 'standing_commitment',
                ]);

                if ($insert_id > 0) $created++;
            }
        }

        return $created;
    }

    /**
     * Describe a perpetual schedule's recurring weekly pattern as real, bookable
     * hour options per weekday — [day_of_week => [{start_time,end_time,label}, ...]].
     *
     * Shared helper (used by both the admin Standing Commitments tab's weekly grid
     * and the public schedule shortcode's weekly view) so both places always agree
     * on what the weekly hours actually are, expanded via the same overnight/slot-
     * length logic as real slot generation (SlotGenerator::build_slots_for_date()).
     */
    public function describe_weekly_pattern(array $schedule): array {
        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) return [];

        $templates = $this->dateRepo->list_weekday_templates_for_schedule($schedule_id);
        if (empty($templates)) return [];

        $tz = function_exists('wp_timezone')
            ? wp_timezone()
            : new DateTimeZone(get_option('timezone_string') ?: 'UTC');

        try {
            $today = new DateTime('today', $tz);
        } catch (Exception $e) {
            return [];
        }

        $out = [];

        foreach ($templates as $t) {
            $dow = (int)($t['day_of_week'] ?? -1);
            if ($dow < 0 || $dow > 6) continue;

            $template_id = (int)($t['id'] ?? 0);
            $segs = $template_id > 0 ? $this->segmentsRepo->list_for_date_pattern($template_id) : [];
            if (empty($segs)) continue;

            // Find the next date (today or later) that falls on this weekday, so
            // build_slots_for_date() has a real calendar date to anchor against.
            $probe = clone $today;
            for ($i = 0; $i < 7; $i++) {
                if ((int)$probe->format('w') === $dow) break;
                $probe->modify('+1 day');
            }

            $rows = $this->slotBuilder->build_slots_for_date($schedule, $probe->format('Y-m-d'), $segs);

            $seen = [];
            $options = [];
            foreach ($rows as $row) {
                $st = substr((string)($row['start_time'] ?? ''), 0, 8);
                if ($st === '' || isset($seen[$st])) continue;
                $seen[$st] = true;

                $et = substr((string)($row['end_time'] ?? ''), 0, 8);

                $ts = strtotime('1970-01-01 ' . $st);
                $label = $ts !== false ? date_i18n('g:i A', $ts) : $st;
                $ets = strtotime('1970-01-01 ' . $et);
                if ($ets !== false) {
                    $label .= ' – ' . date_i18n('g:i A', $ets);
                }

                $options[] = ['start_time' => $st, 'end_time' => $et, 'label' => $label];
            }

            if (!empty($options)) {
                $out[$dow] = $options;
            }
        }

        return $out;
    }

    /**
     * Stable key for matching generated slots to existing slots.
     * Mirrors SlotGenerator::slot_key() / EditSchedulePage::slot_legacy_key().
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
