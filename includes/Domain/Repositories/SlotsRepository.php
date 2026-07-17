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
     *
     * IMPORTANT:
     * wpdb->insert() DOES NOT ignore unknown columns.
     * So if the DB table does not yet have start_at/end_at, we MUST strip them.
     */
    public function insert(array $row): int {
        global $wpdb;

        // If the DB doesn't have canonical datetime columns, don't try to insert them.
        if (!$this->has_start_at_columns()) {
            unset($row['start_at'], $row['end_at']);
        } else {
            // Normalize empty strings to NULL so ordering is clean
            if (array_key_exists('start_at', $row) && $row['start_at'] === '') $row['start_at'] = null;
            if (array_key_exists('end_at', $row) && $row['end_at'] === '') $row['end_at'] = null;
        }

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

        if (!$this->has_start_at_columns()) {
            return false;
        }

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
            "SELECT COUNT(*) FROM {$this->table} WHERE schedule_id = %d",
            $schedule_id
        );
        return (int) $wpdb->get_var($sql);
    }

    public function count_active_by_schedule(int $schedule_id): int {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE schedule_id = %d AND is_active = 1",
            $schedule_id
        );
        return (int) $wpdb->get_var($sql);
    }

    public function count_inactive_by_schedule(int $schedule_id): int {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE schedule_id = %d AND is_active = 0",
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
             FROM {$this->table}
             WHERE id = %d
             LIMIT 1",
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
     * Does the table have canonical datetime columns start_at/end_at?
     */
    private function has_start_at_columns(): bool {
        static $cached = null;
        if ($cached !== null) return (bool) $cached;

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$this->table}");
        $have = array_flip(array_map('strtolower', $cols));

        $cached = isset($have['start_at']) && isset($have['end_at']);
        return (bool) $cached;
    }

    /**
     * ORDER BY used for list queries (true chronological if start_at exists).
     * NULL-safe so legacy/bad rows don't float to the top.
     */
    private function order_by_sql(): string {
        if ($this->has_start_at_columns()) {
            // ✅ Canonical: handles overnight correctly + NULL-safe
            return "CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
                    start_at ASC,
                    CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
                    end_at ASC,
                    id ASC";
        }

        // ✅ Legacy: best-effort (does NOT solve overnight)
        return "`date` ASC, TIME(start_time) ASC, id ASC";
    }

    /**
     * Run a list query with a fallback ORDER BY in case TIME() causes issues.
     */
    private function run_list_query(string $sql, string $fallback_sql): array {
        global $wpdb;

        $wpdb->last_error = '';
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

        $order  = $this->order_by_sql();
        $has_dt = $this->has_start_at_columns();

        $select = $has_dt
            ? "id, schedule_id, chapel_id, `date`, start_time, end_time, start_at, end_at,
               min_adorers, max_adorers, segment_id, is_active, public_note"
            : "id, schedule_id, chapel_id, `date`, start_time, end_time,
               min_adorers, max_adorers, segment_id, is_active, public_note";

        $sql_primary = $wpdb->prepare(
            "SELECT {$select}
             FROM {$this->table}
             WHERE schedule_id = %d
             ORDER BY {$order}",
            $schedule_id
        );

        $sql_fallback = $wpdb->prepare(
            "SELECT {$select}
             FROM {$this->table}
             WHERE schedule_id = %d
             ORDER BY `date` ASC, start_time ASC, id ASC",
            $schedule_id
        );

        return $this->run_list_query($sql_primary, $sql_fallback);
    }

    /**
     * List ACTIVE slots only.
     */
    public function list_active_for_schedule(int $schedule_id): array {
        global $wpdb;

        $order = $this->order_by_sql();

        $sql_primary = $wpdb->prepare(
            "SELECT *
             FROM {$this->table}
             WHERE schedule_id = %d AND is_active = 1
             ORDER BY {$order}",
            $schedule_id
        );

        $sql_fallback = $wpdb->prepare(
            "SELECT *
             FROM {$this->table}
             WHERE schedule_id = %d AND is_active = 1
             ORDER BY `date` ASC, start_time ASC, id ASC",
            $schedule_id
        );

        return $this->run_list_query($sql_primary, $sql_fallback);
    }

    /**
     * List slots (active + inactive), limited.
     */
    public function list_for_schedule_limited(int $schedule_id, int $limit = 100): array {
        global $wpdb;

        $limit  = max(1, min(500, (int) $limit));
        $order  = $this->order_by_sql();
        $has_dt = $this->has_start_at_columns();

        $select = $has_dt
            ? "id, schedule_id, chapel_id, `date`, start_time, end_time, start_at, end_at,
               min_adorers, max_adorers, segment_id, is_active, public_note"
            : "id, schedule_id, chapel_id, `date`, start_time, end_time,
               min_adorers, max_adorers, segment_id, is_active, public_note";

        $sql_primary = $wpdb->prepare(
            "SELECT {$select}
             FROM {$this->table}
             WHERE schedule_id = %d
             ORDER BY {$order}
             LIMIT %d",
            $schedule_id,
            $limit
        );

        $sql_fallback = $wpdb->prepare(
            "SELECT {$select}
             FROM {$this->table}
             WHERE schedule_id = %d
             ORDER BY `date` ASC, start_time ASC, id ASC
             LIMIT %d",
            $schedule_id,
            $limit
        );

        return $this->run_list_query($sql_primary, $sql_fallback);
    }

    /**
     * ✅ Canonical list for Signups tab.
     * We try start_at ordering first (best). If columns don't exist, fall back to legacy.
     */
    public function list_for_signups_tab(int $schedule_id, int $limit = 2000): array {
        global $wpdb;

        $limit = max(1, min(2000, (int) $limit));

        // Best-case: start_at/end_at exists.
        if ($this->has_start_at_columns()) {
            $sql = $wpdb->prepare(
                "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time, start_at, end_at,
                        min_adorers, max_adorers, segment_id, is_active, public_note
                 FROM {$this->table}
                 WHERE schedule_id = %d
                 ORDER BY
                   CASE WHEN start_at IS NULL THEN 1 ELSE 0 END ASC,
                   start_at ASC,
                   CASE WHEN end_at IS NULL THEN 1 ELSE 0 END ASC,
                   end_at ASC,
                   id ASC
                 LIMIT %d",
                $schedule_id,
                $limit
            );

            $rows = $wpdb->get_results($sql, ARRAY_A);
            return is_array($rows) ? $rows : [];
        }

        // Legacy fallback: robust datetime sort key (handles weird start_time formats).
        $sql_fallback = $wpdb->prepare(
            "SELECT id, schedule_id, chapel_id, `date`, start_time, end_time,
                    min_adorers, max_adorers, segment_id, is_active, public_note
             FROM {$this->table}
             WHERE schedule_id = %d
             ORDER BY
               STR_TO_DATE(
                 CONCAT(
                   `date`, ' ',
                   CASE
                     WHEN start_time REGEXP '^[0-9]{1,2}:[0-9]{2}$'
                       THEN CONCAT(LPAD(SUBSTRING_INDEX(start_time,':',1),2,'0'), ':', SUBSTRING_INDEX(start_time,':',-1), ':00')
                     WHEN start_time REGEXP '^[0-9]{1,2}:[0-9]{2}:[0-9]{2}$'
                       THEN CONCAT(
                              LPAD(SUBSTRING_INDEX(start_time,':',1),2,'0'), ':',
                              SUBSTRING_INDEX(SUBSTRING_INDEX(start_time,':',2),':',-1), ':',
                              SUBSTRING_INDEX(start_time,':',-1)
                            )
                     ELSE start_time
                   END
                 ),
                 '%Y-%m-%d %H:%i:%s'
               ) ASC,
               id ASC
             LIMIT %d",
            $schedule_id,
            $limit
        );

        $rows2 = $wpdb->get_results($sql_fallback, ARRAY_A);

        if (!empty($wpdb->last_error)) {
            error_log('[AdorationScheduler] SignupsTab legacy ordering failed: ' . $wpdb->last_error);
            return [];
        }

        return is_array($rows2) ? $rows2 : [];
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
             FROM {$this->table}
             WHERE schedule_id = %d AND is_active = 1 AND `date` BETWEEN %s AND %s
             GROUP BY `date`",
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
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND is_active = 1
               AND start_time = %s
               AND `date` >= %s
               AND (DAYOFWEEK(`date`) - 1) = %d
             ORDER BY `date` ASC
             LIMIT %d",
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

        $order = $this->order_by_sql();

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND `date` = %s AND is_active = 1
             ORDER BY {$order}",
            $schedule_id,
            $ymd
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Closures: all ACTIVE slots for a schedule whose time window overlaps
     * [start_at, end_at) — standard interval-overlap test using the canonical
     * start_at/end_at columns (falls back to date+start_time/end_time if the
     * canonical columns aren't present, best-effort / no overnight support in
     * that fallback case).
     */
    public function list_active_overlapping(int $schedule_id, string $start_at, string $end_at): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0 || $start_at === '' || $end_at === '') return [];

        if ($this->has_start_at_columns()) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE schedule_id = %d AND is_active = 1
                   AND start_at < %s AND end_at > %s
                 ORDER BY start_at ASC",
                $schedule_id,
                $end_at,
                $start_at
            );
            return (array) $wpdb->get_results($sql, ARRAY_A);
        }

        // Legacy fallback: best-effort, does not correctly handle overnight slots.
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND is_active = 1
               AND TIMESTAMP(`date`, start_time) < %s
               AND TIMESTAMP(`date`, end_time) > %s
             ORDER BY `date` ASC, start_time ASC",
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

        if ($this->has_start_at_columns()) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE schedule_id = %d AND is_active = 0
                   AND start_at < %s AND end_at > %s
                 ORDER BY start_at ASC",
                $schedule_id,
                $end_at,
                $start_at
            );
            return (array) $wpdb->get_results($sql, ARRAY_A);
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE schedule_id = %d AND is_active = 0
               AND TIMESTAMP(`date`, start_time) < %s
               AND TIMESTAMP(`date`, end_time) > %s
             ORDER BY `date` ASC, start_time ASC",
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
            "UPDATE {$this->table} SET is_active = 1 WHERE id IN ($placeholders)",
            $ids
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
            "UPDATE {$this->table} SET is_active = 0 WHERE id IN ($placeholders)",
            $ids
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
                "UPDATE {$this->table} SET is_active = 0 WHERE schedule_id = %d",
                $schedule_id
            );
            $result = $wpdb->query($sql);
            return ($result === false) ? 0 : (int) $result;
        }

        $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
        $params = array_merge([$schedule_id], $keep_ids);

        $sql = $wpdb->prepare(
            "UPDATE {$this->table}
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

        if (!$this->has_start_at_columns()) {
            // Canonical datetime columns not present yet (very old/unmigrated
            // install) — bail rather than guess with a less reliable query.
            return [];
        }

        // start_at/end_at are stored as site-LOCAL wall-clock datetimes (see
        // SlotGenerator, which builds them from a wp_timezone() DateTime
        // cursor, not UTC) — so the window bounds must be computed the same
        // way, not via MySQL's NOW()/UTC_TIMESTAMP() which may not agree with
        // WP's configured timezone. Same approach ReminderScheduler uses.
        $tz  = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);

        $window_start = $now->format('Y-m-d H:i:s');
        $window_end   = $now->modify('+' . $within_hours . ' hours')->format('Y-m-d H:i:s');

        $alerted_clause = $only_unalerted ? "AND s.coverage_alert_sent_at IS NULL" : "";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT s.*, sch.name AS schedule_name, sch.type AS schedule_type, ch.name AS chapel_name
             FROM {$this->table} s
             INNER JOIN {$schedules_table} sch ON sch.id = s.schedule_id
             LEFT JOIN {$chapels_table} ch ON ch.id = s.chapel_id
             WHERE s.is_active = 1
               AND sch.status = 'active'
               AND s.start_at >= %s
               AND s.start_at <  %s
               AND NOT EXISTS (
                   SELECT 1 FROM {$signups_table} sig
                   WHERE sig.slot_id = s.id AND sig.status = 'confirmed'
               )
               {$alerted_clause}
             ORDER BY s.start_at ASC",
            $window_start,
            $window_end
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
            "UPDATE {$this->table} SET coverage_alert_sent_at = NOW() WHERE id IN ($placeholders)",
            $ids
        );
        $result = $wpdb->query($sql);
        return ($result === false) ? 0 : (int) $result;
    }
}
