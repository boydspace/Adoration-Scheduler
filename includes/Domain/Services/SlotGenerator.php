<?php
namespace AdorationScheduler\Domain\Services;

use DateTime;
use DateTimeZone;
use Exception;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;

if ( ! defined('ABSPATH') ) exit;

class SlotGenerator
{
    private DatePatternsRepository $dateRepo;
    private SegmentsRepository $segmentsRepo;
    private SlotsRepository $slotsRepo;

    /**
     * Cache whether slots table has start_at/end_at columns.
     * Null = unknown, true/false = determined.
     */
    private ?bool $has_start_end_cols = null;

    public function __construct(
        DatePatternsRepository $dateRepo,
        SegmentsRepository $segmentsRepo,
        SlotsRepository $slotsRepo
    ) {
        $this->dateRepo     = $dateRepo;
        $this->segmentsRepo = $segmentsRepo;
        $this->slotsRepo    = $slotsRepo;
    }

    public function preview_for_event_schedule(array $schedule): array
    {
        return $this->build_slots_from_segments($schedule);
    }

    public function generate_for_event_schedule(array $schedule): int
    {
        $rows = $this->build_slots_from_segments($schedule);

        $inserted = 0;
        foreach ($rows as $row) {
            $row = $this->normalize_row_for_insert($row);
            $id = $this->slotsRepo->insert($row);
            if ($id > 0) $inserted++;
        }

        return $inserted;
    }

    public function sync_for_event_schedule(array $schedule): array
    {
        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) {
            return ['kept' => 0, 'inserted' => 0, 'deactivated' => 0];
        }

        $generated = $this->build_slots_from_segments($schedule);

        // Map generated rows by stable key based on legacy fields (date/start/end/segment)
        $gen_map = [];
        foreach ($generated as $g) {
            $gen_map[$this->slot_key($g)] = $g;
        }

        $existing = $this->slotsRepo->list_for_schedule($schedule_id);

        $exist_map = [];
        foreach ($existing as $ex) {
            $k = $this->slot_key($ex);
            if (!isset($exist_map[$k])) $exist_map[$k] = [];
            $exist_map[$k][] = $ex;
        }

        $keep_ids   = [];
        $kept       = 0;
        $inserted   = 0;

        $has_dt_cols = $this->slots_table_has_start_end_columns();

        foreach ($gen_map as $k => $g) {
            if (!empty($exist_map[$k])) {
                $row = array_shift($exist_map[$k]);
                $slot_id = (int)($row['id'] ?? 0);

                if ($slot_id > 0) {
                    $keep_ids[] = $slot_id;
                    $kept++;

                    // If canonical columns exist, ensure kept rows are hydrated/correct.
                    if ($has_dt_cols && method_exists($this->slotsRepo, 'update_canonical_datetimes')) {
                        $desired_start_at = (string)($g['start_at'] ?? '');
                        $desired_end_at   = (string)($g['end_at'] ?? '');

                        if ($desired_start_at !== '' && $desired_end_at !== '') {
                            $current_start_at = (string)($row['start_at'] ?? '');
                            $current_end_at   = (string)($row['end_at'] ?? '');

                            $needs_update =
                                $current_start_at === '' ||
                                $current_end_at === '' ||
                                $current_start_at === '0000-00-00 00:00:00' ||
                                $current_end_at === '0000-00-00 00:00:00' ||
                                $current_start_at !== $desired_start_at ||
                                $current_end_at !== $desired_end_at;

                            if ($needs_update) {
                                $this->slotsRepo->update_canonical_datetimes(
                                    $slot_id,
                                    $desired_start_at,
                                    $desired_end_at
                                );
                            }
                        }
                    }
                }
            } else {
                $g = $this->normalize_row_for_insert($g);
                $id = $this->slotsRepo->insert($g);
                if ($id > 0) {
                    $keep_ids[] = $id;
                    $inserted++;
                }
            }
        }

        $deactivated = $this->slotsRepo->deactivate_missing($schedule_id, $keep_ids);

        return [
            'kept'        => (int)$kept,
            'inserted'    => (int)$inserted,
            'deactivated' => (int)$deactivated,
        ];
    }

    /**
     * Build slot rows from date patterns + segments.
     *
     * IMPORTANT:
     * - start_at/end_at are always computed for correct ordering across midnight.
     * - legacy `date` MUST reflect the real calendar date of the slot (do NOT anchor),
     *   otherwise display, matching, and sync become confusing/broken.
     */
    private function build_slots_from_segments(array $schedule): array
    {
        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) return [];

        $dates = $this->dateRepo->list_for_schedule($schedule_id);
        if (empty($dates)) return [];

        $out = [];

        foreach ($dates as $d) {
            $date_pattern_id = (int)($d['id'] ?? 0);
            $base_date = $this->normalize_ymd((string)($d['date'] ?? ''));
            if ($base_date === '') continue;

            $segments = $this->segmentsRepo->list_for_date_pattern($date_pattern_id);
            if (empty($segments)) continue;

            $out = array_merge($out, $this->build_slots_for_date($schedule, $base_date, $segments));
        }

        // True chronological sort (always by start_at)
        usort($out, function($a, $b) {
            $aa = (string)($a['start_at'] ?? '');
            $bb = (string)($b['start_at'] ?? '');
            if ($aa !== $bb) return strcmp($aa, $bb);

            $segA = (int)($a['segment_id'] ?? 0);
            $segB = (int)($b['segment_id'] ?? 0);
            return $segA <=> $segB;
        });

        return $out;
    }

    /**
     * Build slot rows for a SINGLE real calendar date, given a schedule and the
     * segments that apply to that date (either from a literal date_patterns row,
     * for event schedules, or from a weekday template, for perpetual schedules).
     *
     * This holds all the overnight-rollover math and is the one place both the
     * event-schedule generator and PerpetualSlotGenerator expand segments into slots,
     * so overnight behavior stays identical between the two.
     *
     * IMPORTANT: this does not sort or dedupe — callers combine/sort as needed.
     */
    public function build_slots_for_date(array $schedule, string $base_date, array $segments): array
    {
        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) return [];

        $base_date = $this->normalize_ymd($base_date);
        if ($base_date === '') return [];
        if (empty($segments)) return [];

        $chapel_id = (int)($schedule['chapel_id'] ?? 0);

        $default_len = (int)($schedule['default_slot_length'] ?? 60);
        if ($default_len <= 0) $default_len = 60;

        $default_min = (int)($schedule['default_min_adorers'] ?? 1);
        if ($default_min < 0) $default_min = 0;

        $default_max = null;
        if (($schedule['default_max_adorers'] ?? '') !== '' && $schedule['default_max_adorers'] !== null) {
            $default_max = (int)$schedule['default_max_adorers'];
            if ($default_max < 0) $default_max = 0;
        }

        // Overnight behavior gated by this flag
        $is_overnight = !empty($schedule['is_overnight']);

        $tz = function_exists('wp_timezone')
            ? wp_timezone()
            : new DateTimeZone(get_option('timezone_string') ?: 'UTC');

        $out = [];

        /**
         * Overnight handling:
         * We need an "anchor" start time that represents the beginning of the schedule-day.
         *
         * Primary: earliest segment that crosses midnight (end <= start).
         * Fallback: if no crossing segments exist (common when user splits overnight into 2 segments),
         *           anchor to the LATEST segment start time. Then anything earlier is treated as next day.
         */
        $rollover_anchor_minutes = null;

        if ($is_overnight) {
            $max_start_minutes = null;

            foreach ($segments as $seg_probe) {
                $st = $this->normalize_time((string)($seg_probe['start_time'] ?? ''));
                $et = $this->normalize_time((string)($seg_probe['end_time'] ?? ''));
                if ($st === '' || $et === '') continue;

                $st_min = $this->minutes_since_midnight($st);
                $et_min = $this->minutes_since_midnight($et);

                if ($max_start_minutes === null || $st_min > $max_start_minutes) {
                    $max_start_minutes = $st_min;
                }

                $crosses = ($et_min <= $st_min);
                if ($crosses) {
                    if ($rollover_anchor_minutes === null || $st_min < $rollover_anchor_minutes) {
                        $rollover_anchor_minutes = $st_min;
                    }
                }
            }

            // Fallback: no crossing segments were found, but overnight is enabled.
            // Use latest start time as the "day start" anchor.
            if ($rollover_anchor_minutes === null && $max_start_minutes !== null) {
                $rollover_anchor_minutes = $max_start_minutes;
            }
        }

        foreach ($segments as $seg) {
            $start_time = $this->normalize_time((string)($seg['start_time'] ?? ''));
            $end_time   = $this->normalize_time((string)($seg['end_time'] ?? ''));
            if ($start_time === '' || $end_time === '') continue;

            $slot_len = (int)($seg['slot_length'] ?? 0);
            if ($slot_len <= 0) $slot_len = $default_len;

            $segment_id = (int)($seg['id'] ?? 0);

            $start_minutes = $this->minutes_since_midnight($start_time);
            $end_minutes   = $this->minutes_since_midnight($end_time);

            $segment_crosses_midnight = ($end_minutes <= $start_minutes);

            // If NOT overnight, segments that cross midnight are not allowed (skip)
            if (!$is_overnight && $segment_crosses_midnight) {
                continue;
            }

            $start_dt = $this->make_dt($base_date, $start_time, $tz);
            $end_dt   = $this->make_dt($base_date, $end_time, $tz);
            if (!$start_dt || !$end_dt) continue;

            // If overnight and crosses midnight, end is the next day
            if ($is_overnight && $segment_crosses_midnight) {
                $end_dt->modify('+1 day');
            }

            // If overnight anchor exists, and segment starts earlier than anchor, it belongs to next day
            if ($is_overnight && $rollover_anchor_minutes !== null && $start_minutes < $rollover_anchor_minutes) {
                $start_dt->modify('+1 day');
                $end_dt->modify('+1 day');
            }

            $cursor = clone $start_dt;

            while ($cursor->getTimestamp() < $end_dt->getTimestamp()) {
                $next = (clone $cursor)->modify('+' . $slot_len . ' minutes');

                if ($next->getTimestamp() > $end_dt->getTimestamp()) {
                    $next = clone $end_dt;
                }

                if ($next->getTimestamp() <= $cursor->getTimestamp()) {
                    break;
                }

                $out[] = [
                    'schedule_id' => $schedule_id,
                    'chapel_id'   => $chapel_id,

                    // legacy date MUST be the real calendar date of the slot
                    'date'        => $cursor->format('Y-m-d'),
                    'start_time'  => $cursor->format('H:i:s'),
                    'end_time'    => $next->format('H:i:s'),

                    // Canonical fields
                    'start_at'    => $cursor->format('Y-m-d H:i:s'),
                    'end_at'      => $next->format('Y-m-d H:i:s'),

                    'min_adorers' => $default_min,
                    'max_adorers' => $default_max,
                    'segment_id'  => $segment_id,
                    'is_active'   => 1,
                    'public_note' => null,
                ];

                $cursor = $next;
            }
        }

        return $out;
    }

    private function normalize_row_for_insert(array $row): array
    {
        if (!$this->slots_table_has_start_end_columns()) {
            unset($row['start_at'], $row['end_at']);
        } else {
            $date = (string)($row['date'] ?? '');
            $st   = (string)($row['start_time'] ?? '');
            $et   = (string)($row['end_time'] ?? '');

            if (!isset($row['start_at'], $row['end_at']) && $date !== '' && $st !== '' && $et !== '') {
                [$sa, $ea] = $this->compute_start_end_at($date, $st, $et);
                $row['start_at'] = $sa;
                $row['end_at']   = $ea;
            }
        }

        return $row;
    }

    private function slots_table_has_start_end_columns(): bool
    {
        if ($this->has_start_end_cols !== null) return $this->has_start_end_cols;

        global $wpdb;
        $table = $wpdb->prefix . 'adoration_slots';

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}");
            $have = array_flip(array_map('strtolower', $cols));
            $this->has_start_end_cols = isset($have['start_at']) && isset($have['end_at']);
        } catch (\Throwable $e) {
            $this->has_start_end_cols = false;
        }

        return $this->has_start_end_cols;
    }

    private function compute_start_end_at(string $date, string $start_time, string $end_time): array
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

        $start_dt = new DateTime(trim($date . ' ' . $start_time), $tz);
        $end_dt   = new DateTime(trim($date . ' ' . $end_time), $tz);

        if ($end_dt <= $start_dt) {
            $end_dt->modify('+1 day');
        }

        return [
            $start_dt->format('Y-m-d H:i:s'),
            $end_dt->format('Y-m-d H:i:s'),
        ];
    }

    private function normalize_ymd(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) return substr($raw, 0, 10);
        return '';
    }

    private function normalize_time(string $time): string
    {
        $time = trim($time);
        if ($time === '') return '';

        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        $parts = explode(':', $time);
        if (count($parts) < 2) return '';

        $h = (int)$parts[0];
        $m = (int)$parts[1];
        $s = isset($parts[2]) ? (int)$parts[2] : 0;

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) return '';

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    private function minutes_since_midnight(string $time_his): int
    {
        $parts = explode(':', $time_his);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        return ($h * 60) + $m;
    }

    private function make_dt(string $date_ymd, string $time_his, DateTimeZone $tz): ?DateTime
    {
        try {
            return new DateTime($date_ymd . ' ' . $time_his, $tz);
        } catch (Exception $e) {
            return null;
        }
    }

    private function slot_key(array $row): string
    {
        $schedule_id = (int)($row['schedule_id'] ?? 0);

        $date = (string)($row['date'] ?? '');
        $st   = substr((string)($row['start_time'] ?? ''), 0, 8);
        $et   = substr((string)($row['end_time'] ?? ''), 0, 8);
        $seg  = (int)($row['segment_id'] ?? 0);

        return $schedule_id . '|' . $date . '|' . $st . '|' . $et . '|' . $seg;
    }
}
