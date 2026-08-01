<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class SlotsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_slots';
    }

    public function delete_by_schedule(int $schedule_id): void {
        global $wpdb;
        $wpdb->delete($this->table, ['schedule_id' => $schedule_id], ['%d']);
    }

    /**
     * Insert a slot row.
     */
    public function insert(array $row): int {
        global $wpdb;

        // Normalize empty strings to NULL so ordering is clean
        if (array_key_exists('start_at', $row) && $row['start_at'] === '') $row['start_at'] = null;
        if (array_key_exists('end_at', $row) && $row['end_at'] === '') $row['end_at'] = null;

        $ok = $wpdb->insert($this->table, $row);

        if (!$ok && !empty($wpdb->last_error)) {
            error_log('[AdorationScheduler] SlotsRepository::insert failed: ' . $wpdb->last_error);
        }

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * ✅ NEW: Update canonical datetime fields (start_at/end_at) for an existing slot.
     * This is critical so "Safe Sync" can hydrate kept rows and fix overnight ordering.
     */
    public function update_canonical_datetimes(int $slot_id, ?string $start_at, ?string $end_at): bool {
        global $wpdb;

        $slot_id = (int)$slot_id;
        if ($slot_id <= 0) return false;

        $start_at = is_string($start_at) ? trim($start_at) : null;
        $end_at   = is_string($end_at) ? trim($end_at) : null;

        if ($start_at === '') $start_at = null;
        if ($end_at === '')   $end_at   = null;

        // If both are null, don't churn DB.
        if ($start_at === null && $end_at === null) {
            return true;
        }

        // Build update arrays dynamically so formats match NULL vs string properly.
        $data    = [];
        $formats = [];

        if ($start_at !== null) {
            $data['start_at'] = $start_at;
            $formats[] = '%s';
        } else {
            $data['start_at'] = null;
            $formats[] = '%s'; // wpdb will send NULL; format still ok
        }

        if ($end_at !== null) {
            $data['end_at'] = $end_at;
            $formats[] = '%s';
        } else {
            $data['end_at'] = null;
            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $this->table,
            $data,
            ['id' => $slot_id],
            $formats,
            ['%d']
        );

        if ($result === false && !empty($wpdb->last_error)) {
            error_log('[AdorationScheduler] SlotsRepository::update_canonical_datetimes failed: ' . $wpdb->last_error);
        }

        return ($result !== false);
    }

    public function count_by_schedule(int $schedule_id): int {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE schedule_id = %d",
            $this->table,
            $schedule_id
        );
        return (int) $wpdb->get_var($sql);
    }

    public function count_active_by_schedule(int $schedule_id): int {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE schedule_id = %d AND is_active = 1",
            $this->table,
            $schedule_id
        );
        return (int) $wpdb->get_var($sql);
    }

    public function count_inactive_by_schedule(int $schedule_id): int {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE schedule_id = %d AND is_active = 0",
            $this->table,
            $schedule_id
        );
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Find a single slot row by ID.
     */
    public function find(int $slot_id): ?array {
        global $wpdb;

        $slot_id = (int) $slot_id;
        if ($slot_id <= 0) return null;

        $sql = $wpdb->prepare(
            "SELECT *
             FROM %i
             WHERE id = %d
             LIMIT 1",
            $this->table,
            $slot_id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array) $row : null;
    }

    /**
     * Update ONLY editable admin fields for a slot.
     *
     * Editable:
     * - min_adorers
     * - max_adorers
     * - is_active
     * - public_note
     */
    public function update_editable_fields(
        int $slot_id,
        ?int $min_adorers,
        ?int $max_adorers,
        int $is_active,
        ?string $public_note = null
    ): bool {
        global $wpdb;

        $slot_id = (int) $slot_id;
        if ($slot_id <= 0) return false;

        $is_active = $is_active ? 1 : 0;

        $min_adorers = ($min_adorers === null) ? null : max(0, (int) $min_adorers);

        if ($max_adorers === null) {
            $max_adorers = null;
        } else {
            $max_adorers = max(0, (int) $max_adorers);
        }

        if ($max_adorers !== null && $min_adorers !== null && $max_adorers < $min_adorers) {
            $max_adorers = $min_adorers;
        }

        if ($public_note !== null) {
            $public_note = trim((string) $public_note);
            if ($public_note === '') {
                $public_note = null;
            } else {
                if (strlen($public_note) > 255) {
                    $public_note = substr($public_note, 0, 255);
                }
            }
        }

        $data = [
            'min_adorers' => $min_adorers,
            'max_adorers' => $max_adorers,
            'is_active'   => $is_active,
            'public_note' => $public_note,
        ];

        $formats = ['%d', '%d', '%d', '%s'];
        $where   = ['id' => $slot_id];
        $wfmt    = ['%d'];

        $result = $wpdb->update($this->table, $data, $where, $formats, $wfmt);
        return ($result !== false);
    }

    /**
     * Run a list query with a fallback ORDER BY in case TIME() causes issues.
     *
     * $sql/$fallback_sql are always the output of $wpdb->prepare() at each
     * call site below, never raw text — this shared helper just can't be
     * traced back to that by a static analyzer across the function boundary.
     */
    private function run_list_query(string $sql, string $fallback_sql): array {
        global $wpdb;

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!empty($wpdb->last_error)) {
            error_log('[AdorationScheduler] SlotsRepository query error (primary): ' . $wpdb->last_error);
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($fallback_sql, ARRAY_A);

            if (!empty($wpdb->last_error)) {
                error_log('[AdorationScheduler] SlotsRepository query error (fallback): ' . $wpdb->last_error);
            }
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * List ALL slots for a schedule (active + inactive).
     */
    public function list_for_schedule(int $schedule_id): array {
        global $wpdb;

        $sql_primary = $wpdb->prepare(
            "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time, start_at, end_at,
                    min_adorers, max_adorers, segment_id, is_active, public_note
             FROM %i
             WHERE schedule_id = %d
             ORDER BY
                CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
                start_at ASC,
                CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
                end_at ASC,
                id ASC",
            $this->table,
            $schedule_id
        );

        $sql_fallback = $wpdb->prepare(
            "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time,
                    min_adorers, max_adorers, segment_id, is_active, public_note
             FROM %i
             WHERE schedule_id = %d
             ORDER BY `date` ASC, start_time ASC, id ASC",
            $this->table,
            $schedule_id
        );

        return $this->run_list_query($sql_primary, $sql_fallback);
    }

    /**
     * ✅ iCal feed / [adoration_open_hours]: upcoming active slots for a
     * schedule, with chapel name and confirmed-signup count attached —
     * deliberately no join to persons/signups beyond a COUNT(), so this
     * never has adorer names to leak. Shared by the public calendar feed
     * and the public open-hours shortcode.
     *
     * @return array<int, array{
     *   id:int, date:string, start_time:string, end_time:string,
     *   start_at:?string, end_at:?string, min_adorers:?int, max_adorers:?int,
     *   chapel_name:string, confirmed_count:int, is_full:bool
     * }>
     */
    public function list_upcoming_with_status(int $schedule_id, int $days_ahead = 180): array {
        global $wpdb;

        if ($schedule_id <= 0) return [];
        $days_ahead = max(1, min(365, $days_ahead));

        $chapels_table = $wpdb->prefix . 'adoration_chapels';
        $signups_table = $wpdb->prefix . 'adoration_signups';

        $tz  = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);

        $window_start = $now->format('Y-m-d H:i:s');
        $window_end   = $now->modify('+' . $days_ahead . ' days')->format('Y-m-d H:i:s');

        $sql = $wpdb->prepare(
            "SELECT s.id, s.`date`, s.start_time, s.end_time, s.start_at, s.end_at,
                    s.min_adorers, s.max_adorers, ch.name AS chapel_name,
                    (SELECT COUNT(*) FROM %i sig
                      WHERE sig.slot_id = s.id AND sig.status = 'confirmed') AS confirmed_count
             FROM %i s
             LEFT JOIN %i ch ON ch.id = s.chapel_id
             WHERE s.schedule_id = %d
               AND s.is_active = 1
               AND s.start_at >= %s
               AND s.start_at <  %s
             ORDER BY s.start_at ASC",
            $signups_table,
            $this->table,
            $chapels_table,
            $schedule_id,
            $window_start,
            $window_end
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as &$row) {
            $max = isset($row['max_adorers']) && $row['max_adorers'] !== null ? (int)$row['max_adorers'] : null;
            $count = (int)($row['confirmed_count'] ?? 0);
            $row['confirmed_count'] = $count;
            $row['is_full'] = ($max !== null && $count >= $max);
        }
        unset($row);

        return $rows;
    }

    /**
     * ✅ Printable roster (2026-07-17): active slots for a schedule within an
     * explicit [from_ymd, to_ymd] date range, chronological, with chapel
     * name — for the admin "Print Roster" view. Sibling to
     * list_upcoming_with_status() (which takes a rolling "days ahead from
     * now" window instead of an explicit range) but this one doesn't
     * compute confirmed_count/is_full since the roster page joins actual
     * signup rows separately to get names, not just counts.
     */
    public function list_for_roster(int $schedule_id, string $from_ymd, string $to_ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $from_ymd = sanitize_text_field($from_ymd);
        $to_ymd   = sanitize_text_field($to_ymd);
        if ($schedule_id <= 0 || $from_ymd === '' || $to_ymd === '') return [];

        $chapels_table = $wpdb->prefix . 'adoration_chapels';

        $sql = $wpdb->prepare(
            "SELECT s.id, s.`date`, s.start_time, s.end_time, s.start_at, s.end_at,
                    s.min_adorers, s.max_adorers, ch.name AS chapel_name
             FROM %i s
             LEFT JOIN %i ch ON ch.id = s.chapel_id
             WHERE s.schedule_id = %d
               AND s.is_active = 1
               AND s.`date` BETWEEN %s AND %s
             ORDER BY
               CASE WHEN s.start_at IS NULL THEN 1 ELSE 0 END ASC,
               s.start_at ASC,
               s.id ASC",
            $this->table,
            $chapels_table,
            $schedule_id,
            $from_ymd,
            $to_ymd
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        return $rows;
    }

    /**
     * ✅ Coverage report (2026-07-17): month-by-month fill rate within a
     * date range, optionally scoped to one schedule (0 = all schedules).
     * "Filled" means at least one confirmed signup, regardless of max —
     * a separate total_capacity/total_confirmed pair is also returned so
     * the report can show a capacity-weighted fill % too, since "filled"
     * alone doesn't distinguish a 1/1 slot from a 1/6 slot.
     *
     * Returns rows keyed by 'YYYY-MM': total_slots, filled_slots,
     * total_capacity, total_confirmed (all ints).
     */
    public function fill_rate_by_month(int $schedule_id, string $from_ymd, string $to_ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $from_ymd = sanitize_text_field($from_ymd);
        $to_ymd   = sanitize_text_field($to_ymd);
        if ($from_ymd === '' || $to_ymd === '') return [];

        $signups_table = $wpdb->prefix . 'adoration_signups';

        // The (%d = 0 OR ...) form lets the schedule filter stay optional
        // (0 = all schedules) without interpolating a conditional WHERE
        // fragment.
        $sql = $wpdb->prepare(
            "SELECT
                DATE_FORMAT(s.`date`, '%%Y-%%m') AS ym,
                COUNT(*) AS total_slots,
                SUM(CASE WHEN COALESCE(c.confirmed_count, 0) > 0 THEN 1 ELSE 0 END) AS filled_slots,
                SUM(COALESCE(s.max_adorers, 0)) AS total_capacity,
                SUM(COALESCE(c.confirmed_count, 0)) AS total_confirmed
             FROM %i s
             LEFT JOIN (
                SELECT slot_id, COUNT(*) AS confirmed_count
                FROM %i
                WHERE status = 'confirmed'
                GROUP BY slot_id
             ) c ON c.slot_id = s.id
             WHERE s.is_active = 1
               AND s.`date` BETWEEN %s AND %s
               AND (%d = 0 OR s.schedule_id = %d)
             GROUP BY ym
             ORDER BY ym ASC",
            $this->table,
            $signups_table,
            $from_ymd,
            $to_ymd,
            $schedule_id,
            $schedule_id
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $ym = (string)($r['ym'] ?? '');
            if ($ym === '') continue;
            $out[$ym] = [
                'total_slots'     => (int)($r['total_slots'] ?? 0),
                'filled_slots'    => (int)($r['filled_slots'] ?? 0),
                'total_capacity'  => (int)($r['total_capacity'] ?? 0),
                'total_confirmed' => (int)($r['total_confirmed'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * List ACTIVE slots only.
     */
    public function list_active_for_schedule(int $schedule_id): array {
        global $wpdb;

        $sql_primary = $wpdb->prepare(
            "SELECT *
             FROM %i
             WHERE schedule_id = %d AND is_active = 1
             ORDER BY
                CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
                start_at ASC,
                CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
                end_at ASC,
                id ASC",
            $this->table,
            $schedule_id
        );

        $sql_fallback = $wpdb->prepare(
            "SELECT *
             FROM %i
             WHERE schedule_id = %d AND is_active = 1
             ORDER BY `date` ASC, start_time ASC, id ASC",
            $this->table,
            $schedule_id
        );

        return $this->run_list_query($sql_primary, $sql_fallback);
    }

    /**
     * List slots (active + inactive), limited.
     */
    public function list_for_schedule_limited(int $schedule_id, int $limit = 100): array {
        global $wpdb;

        $limit = max(1, min(500, (int) $limit));

        $sql_primary = $wpdb->prepare(
            "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time, start_at, end_at,
                    min_adorers, max_adorers, segment_id, is_active, public_note
             FROM %i
             WHERE schedule_id = %d
             ORDER BY
                CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
                start_at ASC,
                CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
                end_at ASC,
                id ASC
             LIMIT %d",
            $this->table,
            $schedule_id,
            $limit
        );

        $sql_fallback = $wpdb->prepare(
            "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time,
                    min_adorers, max_adorers, segment_id, is_active, public_note
             FROM %i
             WHERE schedule_id = %d
             ORDER BY `date` ASC, start_time ASC, id ASC
             LIMIT %d",
            $this->table,
            $schedule_id,
            $limit
        );

        return $this->run_list_query($sql_primary, $sql_fallback);
    }

    /**
     * ✅ Canonical list for Signups tab.
     */
    public function list_for_signups_tab(int $schedule_id, int $limit = 2000): array {
        global $wpdb;

        $limit = max(1, min(2000, (int) $limit));

        $sql = $wpdb->prepare(
            "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time, start_at, end_at,
                    min_adorers, max_adorers, segment_id, is_active, public_note
             FROM %i
             WHERE schedule_id = %d
             ORDER BY
               CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
               start_at ASC,
               CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
               end_at ASC,
               id ASC
             LIMIT %d",
            $this->table,
            $schedule_id,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * ✅ Coverage Calendar: slot count per date within [start_ymd, end_ymd], active only.
     * Returns [date_ymd => count].
     */
    public function count_by_date_in_range(int $schedule_id, string $start_ymd, string $end_ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $sql = $wpdb->prepare(
            "SELECT `date`, COUNT(*) AS c
             FROM %i
             WHERE schedule_id = %d AND is_active = 1 AND `date` BETWEEN %s AND %s
             GROUP BY `date`",
            $this->table,
            $schedule_id,
            $start_ymd,
            $end_ymd
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['date']] = (int)$r['c'];
        }
        return $out;
    }

    /**
     * ✅ Public weekly view: upcoming ACTIVE slots for a schedule matching a given
     * weekday + start_time (e.g. "the next 6 Tuesdays at 3:00 AM"), from $from_ymd
     * forward. Uses MySQL's DAYOFWEEK()-1 so day_of_week matches the same
     * 0=Sunday..6=Saturday convention used everywhere else in this plugin
     * (date_patterns.day_of_week, PHP DateTime::format('w')).
     *
     * Used to populate the "cover just one date" picker for a weekly-hour cell
     * without materializing/looking at the whole rolling window.
     */
    public function list_next_occurrences_for_day_time(int $schedule_id, int $day_of_week, string $start_time, string $from_ymd, int $limit = 6): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $day_of_week = (int)$day_of_week;
        $limit = max(1, min(52, (int)$limit));

        if ($schedule_id <= 0 || $day_of_week < 0 || $day_of_week > 6 || $start_time === '' || $from_ymd === '') {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM %i
             WHERE schedule_id = %d AND is_active = 1
               AND start_time = %s
               AND `date` >= %s
               AND (DAYOFWEEK(`date`) - 1) = %d
             ORDER BY `date` ASC
             LIMIT %d",
            $this->table,
            $schedule_id,
            $start_time,
            $from_ymd,
            $day_of_week,
            $limit
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Coverage Calendar: all active slots for one specific date, ordered by time.
     */
    public function list_for_schedule_on_date(int $schedule_id, string $ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0 || $ymd === '') return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM %i
             WHERE schedule_id = %d AND `date` = %s AND is_active = 1
             ORDER BY
                CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
                start_at ASC,
                CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
                end_at ASC,
                id ASC",
            $this->table,
            $schedule_id,
            $ymd
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Closures: all ACTIVE slots for a schedule whose time window overlaps
     * [start_at, end_at) — standard interval-overlap test using the canonical
     * start_at/end_at columns.
     */
    public function list_active_overlapping(int $schedule_id, string $start_at, string $end_at): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0 || $start_at === '' || $end_at === '') return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM %i
             WHERE schedule_id = %d AND is_active = 1
               AND start_at < %s AND end_at > %s
             ORDER BY start_at ASC",
            $this->table,
            $schedule_id,
            $end_at,
            $start_at
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Closures: all INACTIVE slots for a schedule whose time window overlaps
     * [start_at, end_at) — used when removing a closure, to find the slots it
     * most likely deactivated so they can be reactivated.
     */
    public function list_inactive_overlapping(int $schedule_id, string $start_at, string $end_at): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0 || $start_at === '' || $end_at === '') return [];

        $sql = $wpdb->prepare(
            "SELECT * FROM %i
             WHERE schedule_id = %d AND is_active = 0
               AND start_at < %s AND end_at > %s
             ORDER BY start_at ASC",
            $this->table,
            $schedule_id,
            $end_at,
            $start_at
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Closures: reactivate slots by ID (used when an admin removes a closure —
     * best-effort heuristic: reactivates whatever is currently inactive in that
     * date range, since that's very likely what the closure itself deactivated).
     */
    public function reactivate_by_ids(array $ids): int {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "UPDATE %i SET is_active = 1 WHERE id IN ($placeholders)",
            array_merge([$this->table], $ids)
        );
        $result = $wpdb->query($sql);
        return ($result === false) ? 0 : (int) $result;
    }

    /**
     * ✅ Closures: deactivate a specific set of slots by ID. Returns rows affected.
     */
    public function deactivate_by_ids(array $ids): int {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "UPDATE %i SET is_active = 0 WHERE id IN ($placeholders)",
            array_merge([$this->table], $ids)
        );
        $result = $wpdb->query($sql);
        return ($result === false) ? 0 : (int) $result;
    }

    /**
     * Deactivate slots NOT in keep list. Returns rows affected.
     */
    public function deactivate_missing(int $schedule_id, array $keep_ids): int {
        global $wpdb;

        $schedule_id = (int) $schedule_id;
        $keep_ids = array_values(array_filter(array_map('intval', $keep_ids)));

        if (empty($keep_ids)) {
            $sql = $wpdb->prepare(
                "UPDATE %i SET is_active = 0 WHERE schedule_id = %d",
                $this->table,
                $schedule_id
            );
            $result = $wpdb->query($sql);
            return ($result === false) ? 0 : (int) $result;
        }

        $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
        $params = array_merge([$this->table, $schedule_id], $keep_ids);

        $sql = $wpdb->prepare(
            "UPDATE %i
             SET is_active = 0
             WHERE schedule_id = %d
               AND id NOT IN ($placeholders)",
            $params
        );

        $result = $wpdb->query($sql);
        return ($result === false) ? 0 : (int) $result;
    }

    /**
     * ✅ Coverage-gap alerting: active slots, on any active schedule (any
     * type — event or perpetual, and any future type), starting within the
     * next $within_hours that have ZERO confirmed signups. Mirrors the same
     * "filled = at least one confirmed signup" simplification already used
     * by SignupsRepository::count_filled_slots_by_date_in_range() for the
     * Coverage Calendar, rather than doing full max_adorers capacity math.
     *
     * @param int  $within_hours     How far ahead (from now) counts as "urgent".
     * @param bool $only_unalerted   If true, exclude slots whose coverage_alert_sent_at
     *                               is already set (used for "once per gap" mode).
     * @return array Each row includes slot fields plus schedule_name, schedule_type,
     *               chapel_name (aliased) via JOIN.
     */
    public function find_open_urgent_slots(int $within_hours, bool $only_unalerted): array {
        global $wpdb;

        $within_hours = max(1, (int)$within_hours);

        $schedules_table = $wpdb->prefix . 'adoration_schedules';
        $chapels_table   = $wpdb->prefix . 'adoration_chapels';
        $signups_table   = $wpdb->prefix . 'adoration_signups';

        // start_at/end_at are stored as site-LOCAL wall-clock datetimes (see
        // SlotGenerator, which builds them from a wp_timezone() DateTime
        // cursor, not UTC) — so the window bounds must be computed the same
        // way, not via MySQL's NOW()/UTC_TIMESTAMP() which may not agree with
        // WP's configured timezone. Same approach ReminderScheduler uses.
        $tz  = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);

        $window_start = $now->format('Y-m-d H:i:s');
        $window_end   = $now->modify('+' . $within_hours . ' hours')->format('Y-m-d H:i:s');

        // The (%d = 0 OR ...) form lets "only unalerted" stay an optional
        // filter without interpolating a conditional WHERE fragment.
        $sql = $wpdb->prepare(
            "SELECT s.*, sch.name AS schedule_name, sch.type AS schedule_type, ch.name AS chapel_name
             FROM %i s
             INNER JOIN %i sch ON sch.id = s.schedule_id
             LEFT JOIN %i ch ON ch.id = s.chapel_id
             WHERE s.is_active = 1
               AND sch.status = 'active'
               AND s.start_at >= %s
               AND s.start_at <  %s
               AND NOT EXISTS (
                   SELECT 1 FROM %i sig
                   WHERE sig.slot_id = s.id AND sig.status = 'confirmed'
               )
               AND (%d = 0 OR s.coverage_alert_sent_at IS NULL)
             ORDER BY s.start_at ASC",
            $this->table,
            $schedules_table,
            $chapels_table,
            $window_start,
            $window_end,
            $signups_table,
            $only_unalerted ? 1 : 0
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Coverage-gap alerting: stamp coverage_alert_sent_at = now for the
     * given slot IDs (called after an alert email is sent, regardless of
     * once/daily repeat mode — used as a "last sent" marker either way).
     */
    public function mark_coverage_alert_sent(array $slot_ids): int {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $slot_ids)));
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // NOW() (server/DB time), matching the convention already used by
        // this table's own created_at/updated_at (CURRENT_TIMESTAMP) — this
        // column is only ever compared to itself (IS NULL check), never to
        // start_at, so it doesn't need to be in "site local" time.
        $sql = $wpdb->prepare(
            "UPDATE %i SET coverage_alert_sent_at = NOW() WHERE id IN ($placeholders)",
            array_merge([$this->table], $ids)
        );
        $result = $wpdb->query($sql);
        return ($result === false) ? 0 : (int) $result;
    }
}
