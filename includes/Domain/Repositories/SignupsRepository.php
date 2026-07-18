<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use AdorationScheduler\Services\ReminderScheduler;
use AdorationScheduler\Services\EmailService;
use AdorationScheduler\Domain\Repositories\SignupAuditRepository;

/**
 * SignupsRepository
 *
 * Notes for "special event" mode:
 * - Signups are date-scoped (YYYY-MM-DD) and should allow the same person to
 *   sign up for the same slot on different dates.
 * - Duplicate protection therefore must include `date`.
 *
 * SAFETY RULE:
 * - This repository MUST NEVER delete records from adoration_persons.
 *   Removing a signup removes only the signup row.
 */
class SignupsRepository {

    private string $table;
    private string $persons_table;

    // ✅ schedules table for view-person reporting (safe LEFT JOIN)
    private string $schedules_table;

    // ✅ slots table for ordering + view-person reporting
    private string $slots_table;

    // ✅ NEW: audit repository (best-effort, no behavior changes)
    private ?SignupAuditRepository $audit_repo = null;

    public function __construct() {
        global $wpdb;
        $this->table           = $wpdb->prefix . 'adoration_signups';
        $this->persons_table   = $wpdb->prefix . 'adoration_persons';
        $this->schedules_table = $wpdb->prefix . 'adoration_schedules';
        $this->slots_table     = $wpdb->prefix . 'adoration_slots';

        // Best-effort: only instantiate if class exists (so this is a safe drop-in).
        if (class_exists(SignupAuditRepository::class)) {
            $this->audit_repo = new SignupAuditRepository();
        }
    }

    // -------------------------------------------------------------------------
    // Audit helpers (best-effort, never blocks core behavior)
    // -------------------------------------------------------------------------

    private function audit_log(int $signup_id, string $event_type, array $meta = []): void {
        if (!$this->audit_repo) return;

        try {
            $actor_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            if ($actor_user_id <= 0) {
                $actor_user_id = null;
            }

            $actor_label = null;
            if ($actor_user_id) {
                if (method_exists($this->audit_repo, 'build_actor_label')) {
                    $actor_label = $this->audit_repo->build_actor_label($actor_user_id);
                }
            }

            $this->audit_repo->log(
                (int)$signup_id,
                (string)$event_type,
                is_array($meta) ? $meta : [],
                $actor_user_id,
                $actor_label
            );
        } catch (\Throwable $e) {
            // Intentionally swallow; audit must never affect primary flows.
            error_log('[AdorationScheduler] Audit log failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers (safe table/column detection)
    // -------------------------------------------------------------------------

    private function slots_table_exists(): bool {
        static $cached = null;
        if ($cached !== null) return (bool) $cached;

        global $wpdb;
        try {
            $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->slots_table));
            $cached = !empty($found);
        } catch (\Throwable $e) {
            $cached = false;
        }
        return (bool) $cached;
    }

    /**
     * Does slots table have canonical datetime columns start_at/end_at?
     */
    private function slots_has_start_at_columns(): bool {
        static $cached = null;
        if ($cached !== null) return (bool) $cached;

        if (!$this->slots_table_exists()) {
            $cached = false;
            return false;
        }

        global $wpdb;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$this->slots_table}");
            $have = array_flip(array_map('strtolower', $cols));
            $cached = isset($have['start_at']) && isset($have['end_at']);
        } catch (\Throwable $e) {
            $cached = false;
        }

        return (bool) $cached;
    }

    /**
     * ORDER BY for signups lists when joining slots.
     * Uses canonical slot.start_at/end_at if present, NULL-safe.
     */
    private function order_by_slot_chrono_sql(string $slot_alias = 'sl'): string {
        if ($this->slots_has_start_at_columns()) {
            return "CASE WHEN {$slot_alias}.start_at IS NULL THEN 1 ELSE 0 END ASC,
                    {$slot_alias}.start_at ASC,
                    CASE WHEN {$slot_alias}.end_at IS NULL THEN 1 ELSE 0 END ASC,
                    {$slot_alias}.end_at ASC";
        }

        // Legacy fallback (best-effort)
        return "{$slot_alias}.`date` ASC, {$slot_alias}.start_time ASC";
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Returns [slot_id => count] for a schedule.
     * Counts only 'confirmed' signups.
     */
    public function counts_by_slot_for_schedule(int $schedule_id): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT slot_id, COUNT(*) AS c
             FROM {$this->table}
             WHERE schedule_id = %d AND status = 'confirmed'
             GROUP BY slot_id",
            $schedule_id
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['slot_id']] = (int)$r['c'];
        }
        return $out;
    }

    /**
     * ✅ Coverage report (2026-07-17): confirmed hours served per person
     * within a date range, optionally scoped to one schedule (0 = all
     * schedules). Used by the "Hours Served by Person" table on the
     * Coverage Report admin page — for stewardship recognition / year-end
     * reports, not for anything the plugin acts on automatically.
     *
     * Prefers start_at/end_at (canonical, timezone-correct, handles
     * overnight slots naturally) for duration; falls back to a
     * mod-24h TIMEDIFF on start_time/end_time on older installs that
     * don't have those columns yet, same fallback pattern used elsewhere
     * in this repository (see list_for_person()).
     *
     * Returns rows: person_id, first_name, last_name, email,
     * signup_count, total_minutes (int).
     */
    public function hours_report_by_person(int $schedule_id, string $from_ymd, string $to_ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $from_ymd = sanitize_text_field($from_ymd);
        $to_ymd   = sanitize_text_field($to_ymd);
        if ($from_ymd === '' || $to_ymd === '') return [];

        if (!$this->slots_table_exists()) return [];

        $duration_expr = $this->slots_has_start_at_columns()
            ? "TIMESTAMPDIFF(MINUTE, sl.start_at, sl.end_at)"
            : "(MOD(TIME_TO_SEC(TIMEDIFF(sl.end_time, sl.start_time)) + 86400, 86400) / 60)";

        $schedule_where = ($schedule_id > 0) ? "AND s.schedule_id = %d" : "";

        $params = [$from_ymd, $to_ymd];
        if ($schedule_id > 0) $params[] = $schedule_id;

        $sql = $wpdb->prepare(
            "SELECT
                s.person_id,
                p.first_name, p.last_name, p.email,
                COUNT(*) AS signup_count,
                SUM({$duration_expr}) AS total_minutes
             FROM {$this->table} s
             JOIN {$this->slots_table} sl ON sl.id = s.slot_id
             JOIN {$this->persons_table} p ON p.id = s.person_id
             WHERE s.status = 'confirmed'
               AND s.date BETWEEN %s AND %s
               {$schedule_where}
             GROUP BY s.person_id, p.first_name, p.last_name, p.email
             ORDER BY total_minutes DESC",
            $params
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as &$r) {
            $r['signup_count']  = (int)($r['signup_count'] ?? 0);
            $r['total_minutes'] = (int)round((float)($r['total_minutes'] ?? 0));
        }
        unset($r);

        return $rows;
    }

    /**
     * ✅ List signups for a person (optionally only confirmed).
     * Includes schedule_name/schedule_slug (if schedules table exists)
     * AND slot timing fields (if slots table exists).
     *
     * Returned extra keys (may be NULL if tables missing):
     *  - schedule_name, schedule_slug
     *  - slot_date, slot_start_time, slot_end_time, slot_start_at, slot_end_at
     */
    public function list_for_person(int $person_id, bool $confirmed_only = false): array {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return [];

        $where_status = $confirmed_only ? " AND s.status = 'confirmed' " : "";

        // Check schedules table exists (avoid hard SQL failure on some installs)
        $has_schedules = false;
        try {
            $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->schedules_table));
            $has_schedules = !empty($found);
        } catch (\Throwable $e) {
            $has_schedules = false;
        }

        $has_slots = $this->slots_table_exists();
        $has_slot_dt = $this->slots_has_start_at_columns();

        $select_schedule = $has_schedules
            ? "sch.name AS schedule_name, sch.slug AS schedule_slug"
            : "NULL AS schedule_name, NULL AS schedule_slug";

        $select_slot = $has_slots
            ? (
                $has_slot_dt
                    ? "sl.`date` AS slot_date,
                       sl.start_time AS slot_start_time,
                       sl.end_time AS slot_end_time,
                       sl.start_at AS slot_start_at,
                       sl.end_at AS slot_end_at"
                    : "sl.`date` AS slot_date,
                       sl.start_time AS slot_start_time,
                       sl.end_time AS slot_end_time,
                       NULL AS slot_start_at,
                       NULL AS slot_end_at"
            )
            : "NULL AS slot_date, NULL AS slot_start_time, NULL AS slot_end_time, NULL AS slot_start_at, NULL AS slot_end_at";

        $join_schedule = $has_schedules
            ? "LEFT JOIN {$this->schedules_table} sch ON sch.id = s.schedule_id"
            : "";

        $join_slot = $has_slots
            ? "LEFT JOIN {$this->slots_table} sl ON sl.id = s.slot_id"
            : "";

        // ✅ Prefer ordering by the actual slot time (canonical), then by signup date as a tie-breaker.
        $order = $has_slots
            ? ($this->order_by_slot_chrono_sql('sl') . ", s.date DESC, s.id ASC")
            : "s.date DESC, s.schedule_id ASC, s.slot_id ASC, s.id ASC";

        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                {$select_schedule},
                {$select_slot}
             FROM {$this->table} s
             {$join_schedule}
             {$join_slot}
             WHERE s.person_id = %d {$where_status}
             ORDER BY {$order}",
            $person_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Self-service account deletion: cancel every NOT-YET-OCCURRED signup
     * for this person (status <> 'cancelled' AND date >= $from_date), so
     * those hours immediately become open for someone else to cover
     * instead of quietly staying "confirmed" under a name that no longer
     * resolves to a real, contactable adorer. Past signups are left
     * exactly as they are — they're history, not something to undo.
     *
     * Returns the number of rows cancelled.
     */
    public function cancel_all_future_for_person(int $person_id, string $from_date): int {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE person_id = %d AND status <> 'cancelled' AND date >= %s",
            $person_id,
            $from_date
        ));

        if (empty($ids)) return 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'cancelled', is_active = 0
             WHERE person_id = %d AND status <> 'cancelled' AND date >= %s",
            $person_id,
            $from_date
        ));

        foreach ($ids as $id) {
            $this->audit_log((int)$id, 'cancelled_account_deletion', ['person_id' => $person_id]);
        }

        return $updated !== false ? (int)$updated : 0;
    }

    /**
     * Self-service account deletion: any OTHER person's signup that has an
     * exclusive direct-to-person swap request aimed at this person
     * (replacement_target_person_id) would otherwise dangle once this
     * person is anonymized — nobody could ever claim it, since the only
     * person allowed to is gone. Reopen those requests to the general
     * substitute pool instead of leaving them stuck.
     *
     * Returns the number of rows updated.
     */
    public function clear_targets_pointing_at(int $target_person_id): int {
        global $wpdb;

        $target_person_id = (int)$target_person_id;
        if ($target_person_id <= 0) return 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE replacement_target_person_id = %d",
            $target_person_id
        ));

        if (empty($ids)) return 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET replacement_target_person_id = NULL WHERE replacement_target_person_id = %d",
            $target_person_id
        ));

        foreach ($ids as $id) {
            $this->audit_log((int)$id, 'replacement_target_cleared_account_deletion', ['target_person_id' => $target_person_id]);
        }

        return $updated !== false ? (int)$updated : 0;
    }

    /**
     * ✅ List signups for a schedule (optionally only confirmed),
     * JOINED with persons so UI can show name/email/phone.
     *
     * IMPORTANT: Sort chronologically by the SLOT (start_at if available),
     * not by slot_id or signup date.
     */
    public function list_for_schedule(int $schedule_id, bool $confirmed_only = false): array {
        global $wpdb;

        $where_status = $confirmed_only ? " AND s.status = 'confirmed' " : "";

        $has_slots = $this->slots_table_exists();

        $join_slot = $has_slots
            ? "LEFT JOIN {$this->slots_table} sl ON sl.id = s.slot_id"
            : "";

        $select_slot = $has_slots
            ? (
                $this->slots_has_start_at_columns()
                    ? ", sl.start_at AS slot_start_at, sl.end_at AS slot_end_at, sl.start_time AS slot_start_time, sl.end_time AS slot_end_time, sl.`date` AS slot_date"
                    : ", NULL AS slot_start_at, NULL AS slot_end_at, sl.start_time AS slot_start_time, sl.end_time AS slot_end_time, sl.`date` AS slot_date"
            )
            : ", NULL AS slot_start_at, NULL AS slot_end_at, NULL AS slot_start_time, NULL AS slot_end_time, NULL AS slot_date";

        $order = $has_slots
            ? ($this->order_by_slot_chrono_sql('sl') . ", s.date ASC, s.id ASC")
            : "s.slot_id ASC, s.date ASC, s.id ASC";

        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                p.phone      AS phone
                {$select_slot}
             FROM {$this->table} s
             LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
             {$join_slot}
             WHERE s.schedule_id = %d {$where_status}
             ORDER BY {$order}",
            $schedule_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Coverage Calendar: distinct-slots-with-a-confirmed-signup count per date,
     * within [start_ymd, end_ymd]. Returns [date_ymd => count]. Compare against
     * SlotsRepository::count_by_date_in_range() for the same schedule/range to
     * get an "N open of M" badge. (Simplification: counts a slot as "filled" if
     * it has at least one confirmed signup, regardless of max_adorers capacity.)
     */
    public function count_filled_slots_by_date_in_range(int $schedule_id, string $start_ymd, string $end_ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $sql = $wpdb->prepare(
            "SELECT `date`, COUNT(DISTINCT slot_id) AS c
             FROM {$this->table}
             WHERE schedule_id = %d AND status = 'confirmed' AND `date` BETWEEN %s AND %s
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
     * ✅ Coverage Calendar: all signups for a schedule on one specific date,
     * JOINED with persons and slot times. Includes cancelled rows so the admin
     * can see history for that date too (caller filters by status if needed).
     */
    public function list_for_schedule_on_date(int $schedule_id, string $ymd): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0 || $ymd === '') return [];

        $has_slots = $this->slots_table_exists();
        $join_slot = $has_slots ? "LEFT JOIN {$this->slots_table} sl ON sl.id = s.slot_id" : "";
        $select_slot = $has_slots
            ? ", sl.start_time AS slot_start_time, sl.end_time AS slot_end_time"
            : ", NULL AS slot_start_time, NULL AS slot_end_time";

        $sql = $wpdb->prepare(
            "SELECT s.*, p.first_name AS first_name, p.last_name AS last_name, p.email AS email, p.phone AS phone
                {$select_slot}
             FROM {$this->table} s
             LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
             {$join_slot}
             WHERE s.schedule_id = %d AND s.date = %s
             ORDER BY sl.start_time ASC, s.id ASC",
            $schedule_id,
            $ymd
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ List signups for a specific slot (optionally only confirmed),
     * JOINED with persons.
     *
     * Slot-scoped list doesn't need slot ordering (they're all the same slot),
     * but we keep a stable ordering by signup date/id.
     */
    public function list_for_slot(int $slot_id, bool $confirmed_only = false): array {
        global $wpdb;

        $where_status = $confirmed_only ? " AND s.status = 'confirmed' " : "";

        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                p.phone      AS phone
             FROM {$this->table} s
             LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
             WHERE s.slot_id = %d {$where_status}
             ORDER BY s.date ASC, s.id ASC",
            $slot_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * ✅ Public weekly view: confirmed-signup counts for a scoped set of slot IDs
     * (e.g. the handful of upcoming candidate dates for one weekly-hour cell) —
     * avoids pulling counts for an entire schedule's slots just to check a few.
     * Returns [slot_id => count].
     */
    public function counts_by_slot_ids(array $slot_ids): array {
        global $wpdb;

        $slot_ids = array_values(array_filter(array_map('intval', $slot_ids)));
        if (empty($slot_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($slot_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT slot_id, COUNT(*) AS c FROM {$this->table}
             WHERE slot_id IN ($placeholders) AND status = 'confirmed'
             GROUP BY slot_id",
            $slot_ids
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['slot_id']] = (int)$r['c'];
        }
        return $out;
    }

    /**
     * ✅ Closures: all confirmed signup IDs across a set of slot IDs (used to bulk-cancel
     * signups falling inside an admin-declared closure/blackout window).
     */
    public function list_confirmed_ids_for_slot_ids(array $slot_ids): array {
        global $wpdb;

        $slot_ids = array_values(array_filter(array_map('intval', $slot_ids)));
        if (empty($slot_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($slot_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE slot_id IN ($placeholders) AND status = 'confirmed'",
            $slot_ids
        );

        $ids = (array) $wpdb->get_col($sql);
        return array_map('intval', $ids);
    }

    /**
     * Fetch a single signup row by id (JOINED with persons).
     * (Optionally also join slot canonical times if available.)
     */
    public function find(int $signup_id): ?array {
        global $wpdb;

        $has_slots = $this->slots_table_exists();
        $join_slot = $has_slots ? "LEFT JOIN {$this->slots_table} sl ON sl.id = s.slot_id" : "";

        $select_slot = $has_slots
            ? (
                $this->slots_has_start_at_columns()
                    ? ", sl.start_at AS slot_start_at, sl.end_at AS slot_end_at, sl.start_time AS slot_start_time, sl.end_time AS slot_end_time, sl.`date` AS slot_date"
                    : ", NULL AS slot_start_at, NULL AS slot_end_at, sl.start_time AS slot_start_time, sl.end_time AS slot_end_time, sl.`date` AS slot_date"
            )
            : ", NULL AS slot_start_at, NULL AS slot_end_at, NULL AS slot_start_time, NULL AS slot_end_time, NULL AS slot_date";

        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                p.phone      AS phone
                {$select_slot}
             FROM {$this->table} s
             LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
             {$join_slot}
             WHERE s.id = %d
             LIMIT 1",
            $signup_id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }

    /**
     * Generic duplicate check for slot+person by status.
     *
     * Kept for backwards compatibility. For event/date-scoped signups prefer:
     *   exists_for_slot_person_date()
     */
    public function exists_for_slot_person(int $slot_id, int $person_id, ?string $status = 'confirmed'): bool {
        global $wpdb;

        $slot_id   = (int)$slot_id;
        $person_id = (int)$person_id;

        if ($slot_id <= 0 || $person_id <= 0) return false;

        if ($status === null) {
            $sql = $wpdb->prepare(
                "SELECT 1
                 FROM {$this->table}
                 WHERE slot_id = %d AND person_id = %d
                 LIMIT 1",
                $slot_id,
                $person_id
            );
        } else {
            $status = sanitize_text_field($status);
            $sql = $wpdb->prepare(
                "SELECT 1
                 FROM {$this->table}
                 WHERE slot_id = %d AND person_id = %d AND status = %s
                 LIMIT 1",
                $slot_id,
                $person_id,
                $status
            );
        }

        $found = $wpdb->get_var($sql);
        return !empty($found);
    }

    /**
     * Date-scoped duplicate check for slot+person(+date) by status.
     */
    public function exists_for_slot_person_date(int $slot_id, int $person_id, string $date, ?string $status = 'confirmed'): bool {
        global $wpdb;

        $slot_id   = (int)$slot_id;
        $person_id = (int)$person_id;
        $date      = sanitize_text_field($date);

        if ($slot_id <= 0 || $person_id <= 0 || $date === '') return false;

        if ($status === null) {
            $sql = $wpdb->prepare(
                "SELECT 1
                 FROM {$this->table}
                 WHERE slot_id = %d AND person_id = %d AND date = %s
                 LIMIT 1",
                $slot_id,
                $person_id,
                $date
            );
        } else {
            $status = sanitize_text_field($status);
            $sql = $wpdb->prepare(
                "SELECT 1
                 FROM {$this->table}
                 WHERE slot_id = %d AND person_id = %d AND date = %s AND status = %s
                 LIMIT 1",
                $slot_id,
                $person_id,
                $date,
                $status
            );
        }

        $found = $wpdb->get_var($sql);
        return !empty($found);
    }

    /**
     * Cross-slot duplicate check: does this person already have a confirmed
     * signup on this schedule, for this exact date + time-of-day, regardless
     * of *which* slot row it's attached to?
     *
     * exists_for_slot_person_date() alone only protects against re-signing up
     * for the SAME slot_id. That's not enough for perpetual/standing-commitment
     * auto-signups (see PerpetualSlotGenerator::apply_standing_commitments()):
     * if two slot rows ever exist for the same schedule/date/start_time (e.g.
     * leftover duplicate weekday-template segments from before Quick Setup's
     * replace-not-stack fix), the per-slot check doesn't catch it and the same
     * person gets auto-signed-up — and emailed — once per duplicate slot.
     */
    public function exists_confirmed_for_schedule_datetime(int $schedule_id, int $person_id, string $date, string $start_time): bool {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $person_id   = (int)$person_id;
        $date        = sanitize_text_field($date);
        $start_time  = substr(sanitize_text_field($start_time), 0, 8);

        if ($schedule_id <= 0 || $person_id <= 0 || $date === '' || $start_time === '') return false;

        $sql = $wpdb->prepare(
            "SELECT 1
             FROM {$this->table} s
             JOIN {$this->slots_table} sl ON sl.id = s.slot_id
             WHERE s.schedule_id = %d AND s.person_id = %d AND s.date = %s
               AND s.status = 'confirmed' AND sl.start_time = %s
             LIMIT 1",
            $schedule_id,
            $person_id,
            $date,
            $start_time
        );

        $found = $wpdb->get_var($sql);
        return !empty($found);
    }

    /**
     * Backwards-compatible helper: confirmed signup exists for slot+person.
     */
    public function exists_confirmed_for_slot_person(int $slot_id, int $person_id): bool {
        return $this->exists_for_slot_person($slot_id, $person_id, 'confirmed');
    }

    /**
     * New helper (recommended for special events):
     * Confirmed signup exists for slot+person+date.
     */
    public function exists_confirmed_for_slot_person_date(int $slot_id, int $person_id, string $date): bool {
        return $this->exists_for_slot_person_date($slot_id, $person_id, $date, 'confirmed');
    }

    // -------------------------------------------------------------------------
    // REPLACEMENT REQUESTS (Phase 3)
    //
    // A person flags an upcoming signup as needing coverage WITHOUT
    // cancelling it — they stay "on the hook" until a substitute claims it
    // via claim_replacement(), which reassigns person_id directly (same
    // approach as reassign_person_and_dedupe() above) rather than creating a
    // second signup row, so the slot's capacity accounting never double-counts.
    // -------------------------------------------------------------------------

    /**
     * Flag a signup as needing a replacement. Ownership is enforced via the
     * WHERE clause (person_id must match), not just checked beforehand.
     *
     * $target_person_id (Direct-to-person swap requests): when set, the
     * request is EXCLUSIVE to that one person — hidden from the general
     * "Coverage Needed" pool (see list_open_replacement_requests()) until
     * either they claim it or the requester reopens it via
     * clear_replacement_target(). Null/0 means "ask everyone," the
     * original broadcast behavior.
     */
    public function mark_needs_replacement(int $signup_id, int $person_id, string $note = '', ?int $target_person_id = null): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        $person_id = (int)$person_id;
        if ($signup_id <= 0 || $person_id <= 0) return false;

        $note = sanitize_text_field($note);
        if (strlen($note) > 500) $note = substr($note, 0, 500);

        $target_person_id = ($target_person_id !== null && (int)$target_person_id > 0) ? (int)$target_person_id : null;

        $now = current_time('mysql');

        $res = $wpdb->update(
            $this->table,
            [
                'needs_replacement'            => 1,
                'replacement_requested_at'     => $now,
                'replacement_requested_by'     => $person_id,
                'replacement_note'             => ($note !== '' ? $note : null),
                'replacement_target_person_id' => $target_person_id,
            ],
            [
                'id'        => $signup_id,
                'person_id' => $person_id,
                'status'    => 'confirmed',
                'is_active' => 1,
            ],
            ['%d', '%s', '%d', '%s', '%d'],
            ['%d', '%d', '%s', '%d']
        );

        $ok = ($res !== false && (int)$res > 0);

        if ($ok) {
            $this->audit_log($signup_id, 'replacement_requested', [
                'person_id'         => $person_id,
                'note'              => $note,
                'target_person_id'  => $target_person_id,
            ]);
        }

        return $ok;
    }

    /**
     * Undo a replacement request (change of mind) — only while still unclaimed.
     */
    public function cancel_replacement_request(int $signup_id, int $person_id): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        $person_id = (int)$person_id;
        if ($signup_id <= 0 || $person_id <= 0) return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $res = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET needs_replacement = 0,
                 replacement_requested_at = NULL,
                 replacement_requested_by = NULL,
                 replacement_note = NULL,
                 replacement_target_person_id = NULL
             WHERE id = %d
               AND person_id = %d
               AND replacement_claimed_by IS NULL",
            $signup_id,
            $person_id
        ));

        $ok = ($res !== false && (int)$res > 0);

        if ($ok) {
            $this->audit_log($signup_id, 'replacement_request_cancelled', [
                'person_id' => $person_id,
            ]);
        }

        return $ok;
    }

    /**
     * "Open to everyone instead": the requester revokes exclusive targeting
     * on their own still-open request, so it falls back into the general
     * "Coverage Needed" pool. Ownership + still-open enforced in the WHERE.
     */
    public function clear_replacement_target(int $signup_id, int $person_id): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        $person_id = (int)$person_id;
        if ($signup_id <= 0 || $person_id <= 0) return false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $res = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET replacement_target_person_id = NULL
             WHERE id = %d
               AND person_id = %d
               AND needs_replacement = 1
               AND replacement_claimed_by IS NULL",
            $signup_id,
            $person_id
        ));

        $ok = ($res !== false && (int)$res > 0);

        if ($ok) {
            $this->audit_log($signup_id, 'replacement_opened_to_everyone', [
                'person_id' => $person_id,
            ]);
        }

        return $ok;
    }

    /**
     * A substitute claims an open replacement request.
     *
     * Returns: 'ok', 'not_found', 'not_open', 'own_request', 'already_booked',
     * 'not_your_request', or 'failed'. 'not_your_request' means the request
     * is exclusively targeted at someone else (Direct-to-person swap
     * requests) — only that person may claim it until the requester
     * reopens it via clear_replacement_target().
     */
    public function claim_replacement(int $signup_id, int $claiming_person_id): string {
        global $wpdb;

        $signup_id          = (int)$signup_id;
        $claiming_person_id = (int)$claiming_person_id;
        if ($signup_id <= 0 || $claiming_person_id <= 0) return 'failed';

        $row = $this->find($signup_id);
        if (!$row) return 'not_found';

        if ((int)($row['needs_replacement'] ?? 0) !== 1
            || !empty($row['replacement_claimed_by'])
            || (string)($row['status'] ?? '') !== 'confirmed'
            || (int)($row['is_active'] ?? 0) !== 1
        ) {
            return 'not_open';
        }

        $original_person_id = (int)($row['person_id'] ?? 0);
        if ($original_person_id === $claiming_person_id) {
            return 'own_request';
        }

        $target_person_id = (int)($row['replacement_target_person_id'] ?? 0);
        if ($target_person_id > 0 && $target_person_id !== $claiming_person_id) {
            return 'not_your_request';
        }

        $slot_id = (int)($row['slot_id'] ?? 0);
        $date    = (string)($row['date'] ?? '');

        if ($slot_id > 0 && $date !== '' && $this->exists_for_slot_person_date($slot_id, $claiming_person_id, $date, null)) {
            return 'already_booked';
        }

        $now = current_time('mysql');

        // Atomic: only succeeds if the request is STILL open AND (untargeted
        // OR targeted at this claimant) — race-safe against two substitutes
        // clicking "claim" close together, and against a targeted request
        // being claimed by anyone but the intended person. Same idiom as
        // MagicLinkService's one-time-token consume.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET person_id = %d,
                 needs_replacement = 0,
                 replacement_claimed_by = %d,
                 replacement_claimed_at = %s
             WHERE id = %d
               AND needs_replacement = 1
               AND replacement_claimed_by IS NULL
               AND (replacement_target_person_id IS NULL OR replacement_target_person_id = %d)
             LIMIT 1",
            $claiming_person_id,
            $claiming_person_id,
            $now,
            $signup_id,
            $claiming_person_id
        ));

        if ($updated !== 1) {
            return 'not_open';
        }

        $this->audit_log($signup_id, 'replacement_claimed', [
            'original_person_id' => $original_person_id,
            'claimed_by'         => $claiming_person_id,
            'slot_id'            => $slot_id,
            'date'               => $date,
        ]);

        return 'ok';
    }

    /**
     * "Coverage Needed": open (unclaimed) future replacement requests,
     * optionally excluding one person's own requests. Same join shape as
     * MyAdorationShortcode::get_person_signups_upcoming() so callers get
     * chapel_name/schedule_name/start_time/end_time ready to render.
     *
     * Excludes exclusively-targeted requests (Direct-to-person swap
     * requests) — those only show up via list_requests_targeted_at() for
     * the one person they were asked of, until the requester reopens them.
     */
    public function list_open_replacement_requests(int $exclude_person_id = 0, int $limit = 50): array {
        global $wpdb;

        $limit = max(1, min(200, (int)$limit));
        $today = current_time('Y-m-d');

        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';

        $exclude_sql = '';
        $params = [$today];
        if ($exclude_person_id > 0) {
            $exclude_sql = " AND s.person_id != %d";
            $params[] = $exclude_person_id;
        }
        $params[] = $limit;

        $sql = "
            SELECT
                s.id,
                s.date,
                s.person_id,
                s.replacement_requested_at,
                s.replacement_note,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name,
                p.first_name AS requester_first_name,
                p.last_name  AS requester_last_name
            FROM {$this->table} s
            INNER JOIN {$slots} sl ON sl.id = s.slot_id
            INNER JOIN {$sched} sc ON sc.id = s.schedule_id
            INNER JOIN {$chapels} ch ON ch.id = sc.chapel_id
            LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
            WHERE s.needs_replacement = 1
              AND s.replacement_claimed_by IS NULL
              AND s.replacement_target_person_id IS NULL
              AND s.status = 'confirmed'
              AND s.is_active = 1
              AND s.date >= %s
              {$exclude_sql}
            ORDER BY s.date ASC, sl.start_time ASC
            LIMIT %d
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * "Asked of You": open (unclaimed) future replacement requests
     * exclusively targeted at $person_id (Direct-to-person swap requests).
     * Same shape as list_open_replacement_requests() plus the requester's
     * name, since the UI shows "X is asking you to cover...".
     */
    public function list_requests_targeted_at(int $person_id, int $limit = 50): array {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return [];

        $limit = max(1, min(200, (int)$limit));
        $today = current_time('Y-m-d');

        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';

        $sql = $wpdb->prepare("
            SELECT
                s.id,
                s.date,
                s.person_id,
                s.replacement_requested_at,
                s.replacement_note,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name,
                p.first_name AS requester_first_name,
                p.last_name  AS requester_last_name
            FROM {$this->table} s
            INNER JOIN {$slots} sl ON sl.id = s.slot_id
            INNER JOIN {$sched} sc ON sc.id = s.schedule_id
            INNER JOIN {$chapels} ch ON ch.id = sc.chapel_id
            LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
            WHERE s.needs_replacement = 1
              AND s.replacement_claimed_by IS NULL
              AND s.replacement_target_person_id = %d
              AND s.status = 'confirmed'
              AND s.is_active = 1
              AND s.date >= %s
            ORDER BY s.date ASC, sl.start_time ASC
            LIMIT %d
        ", $person_id, $today, $limit);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * "Recently Fulfilled": claimed replacement requests, most recent first.
     */
    public function list_fulfilled_replacement_requests(int $limit = 20): array {
        global $wpdb;

        $limit = max(1, min(100, (int)$limit));

        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';
        $persons = $this->persons_table;

        $sql = $wpdb->prepare("
            SELECT
                s.id,
                s.date,
                s.replacement_claimed_at,
                s.replacement_requested_by,
                s.replacement_claimed_by,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name,
                req.first_name AS requester_first_name,
                req.last_name  AS requester_last_name,
                sub.first_name AS substitute_first_name,
                sub.last_name  AS substitute_last_name
            FROM {$this->table} s
            INNER JOIN {$slots} sl ON sl.id = s.slot_id
            INNER JOIN {$sched} sc ON sc.id = s.schedule_id
            INNER JOIN {$chapels} ch ON ch.id = sc.chapel_id
            LEFT JOIN {$persons} req ON req.id = s.replacement_requested_by
            LEFT JOIN {$persons} sub ON sub.id = s.replacement_claimed_by
            WHERE s.replacement_claimed_by IS NOT NULL
            ORDER BY s.replacement_claimed_at DESC, s.id DESC
            LIMIT %d
        ", $limit);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Full context for a single signup (chapel/schedule/slot/person names),
     * used by ReplacementRequestService when composing notification emails.
     */
    public function get_replacement_context(int $signup_id): ?array {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return null;

        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';

        $sql = $wpdb->prepare("
            SELECT
                s.*,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                tgt.first_name AS target_first_name,
                tgt.last_name  AS target_last_name,
                tgt.email      AS target_email,
                sl.start_time AS slot_start_time,
                sl.end_time   AS slot_end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name
            FROM {$this->table} s
            LEFT JOIN {$this->persons_table} p ON p.id = s.person_id
            LEFT JOIN {$this->persons_table} tgt ON tgt.id = s.replacement_target_person_id
            LEFT JOIN {$slots} sl ON sl.id = s.slot_id
            LEFT JOIN {$sched} sc ON sc.id = s.schedule_id
            LEFT JOIN {$chapels} ch ON ch.id = sc.chapel_id
            WHERE s.id = %d
            LIMIT 1
        ", $signup_id);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Count of currently open replacement requests (for admin badges/summaries).
     */
    public function count_open_replacement_requests(): int {
        global $wpdb;

        $today = current_time('Y-m-d');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE needs_replacement = 1
               AND replacement_claimed_by IS NULL
               AND status = 'confirmed'
               AND is_active = 1
               AND date >= %s",
            $today
        ));
    }

    // -------------------------------------------------------------------------
    // MERGE TOOL SUPPORT (Option 2)
    // -------------------------------------------------------------------------

    /**
     * Reassign ALL signups from one person to another, deduping conflicts.
     *
     * Conflict rule (dedupe):
     * - If the target person already has a signup with the same slot_id + date + status,
     *   we delete the source signup row (skipped++).
     * - Otherwise, we update the source signup's person_id to the target (moved++).
     *
     * Returns: ['moved' => int, 'skipped' => int]
     */
    public function reassign_person_and_dedupe(int $from_id, int $to_id): array {
        global $wpdb;

        $from_id = (int)$from_id;
        $to_id   = (int)$to_id;

        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            return ['moved' => 0, 'skipped' => 0];
        }

        $moved = 0;
        $skipped = 0;

        // Pull all signups for the source person
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, slot_id, date, status
                 FROM {$this->table}
                 WHERE person_id = %d",
                $from_id
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $signup_id = (int)($row['id'] ?? 0);
            $slot_id   = (int)($row['slot_id'] ?? 0);
            $date      = (string)($row['date'] ?? '');
            $status    = (string)($row['status'] ?? 'confirmed');

            if ($signup_id <= 0 || $slot_id <= 0 || $date === '') {
                continue;
            }

            $status_key = ($status !== '' ? $status : 'confirmed');

            // Does target already have this exact signup?
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$this->table}
                     WHERE person_id = %d
                       AND slot_id = %d
                       AND date = %s
                       AND status = %s
                     LIMIT 1",
                    $to_id,
                    $slot_id,
                    $date,
                    $status_key
                )
            );

            if ($exists > 0) {
                // Dedupe by removing the source signup row (safe: only signups table)
                $wpdb->delete($this->table, ['id' => $signup_id], ['%d']);
                $skipped++;
                continue;
            }

            // Move it to the target person
            $res = $wpdb->update(
                $this->table,
                ['person_id' => $to_id],
                ['id' => $signup_id],
                ['%d'],
                ['%d']
            );

            if ($res !== false) {
                $moved++;
            }
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }

    /**
     * Create a signup row (persons-based).
     *
     * Returns inserted ID (0 on failure).
     */
    public function create(array $row): int {
        global $wpdb;

        $person_id   = (int)($row['person_id'] ?? 0);
        $schedule_id = (int)($row['schedule_id'] ?? 0);
        $slot_id     = (int)($row['slot_id'] ?? 0);
        $date        = sanitize_text_field($row['date'] ?? '');

        if ($person_id <= 0 || $schedule_id <= 0 || $slot_id <= 0 || $date === '') {
            return 0;
        }

        $status      = sanitize_text_field($row['status'] ?? 'confirmed');
        $type        = sanitize_text_field($row['type'] ?? 'one_time');
        $created_via = sanitize_text_field($row['created_via'] ?? 'admin');

        // Prevent duplicates for the same person+slot+date at the same status (default confirmed)
        $dup_status = ($status !== '' ? $status : 'confirmed');
        if ($this->exists_for_slot_person_date($slot_id, $person_id, $date, $dup_status)) {
            return 0;
        }

        $final_status = ($status !== '' ? $status : 'confirmed');

        $ok = $wpdb->insert($this->table, [
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => $date,
            'type'        => ($type !== '' ? $type : 'one_time'),
            'status'      => $final_status,
            'created_via' => ($created_via !== '' ? $created_via : 'admin'),
        ], [
            '%d','%d','%d','%s','%s','%s','%s'
        ]);

        $insert_id = $ok ? (int)$wpdb->insert_id : 0;
        if ($insert_id <= 0) return 0;

        // ✅ Audit trail (best-effort, no behavior changes)
        $this->audit_log($insert_id, 'status_changed', [
            'from'       => null,
            'to'         => $final_status,
            'context'    => ($created_via !== '' ? $created_via : 'admin'),
            'schedule_id'=> $schedule_id,
            'slot_id'    => $slot_id,
            'date'       => $date,
        ]);

        // Best-effort post-insert actions
        if ($final_status === 'confirmed') {

            // 1) Confirmation email
            //
            // ✅ Bug fix (2026-07-17): skip this for created_via === 'standing_commitment'.
            // PerpetualSlotGenerator::apply_standing_commitments() calls create() once per
            // *future occurrence* it auto-fills for a standing commitment — often 8-9 calls
            // in one sync_window() run (one per matching weekday in the rolling window).
            // Sending a "signup confirmed" email on every one of those inserts meant a
            // single "claim this weekly hour" action produced 8-9 near-identical emails in
            // one burst, on top of the dedicated "your weekly commitment is confirmed" email
            // already sent by StandingSignupHandler/EditSchedulePage's add-commitment action.
            // The commitment itself is already confirmed by that one email; each individual
            // future date doesn't need its own. (The 24h reminder below is unaffected — a
            // reminder shortly before each specific occurrence is still wanted.)
            if ($created_via !== 'standing_commitment') {
                try {
                    if (class_exists(EmailService::class)) {
                        $mailer = new EmailService();
                        if (method_exists($mailer, 'send_signup_confirmation')) {
                            $mailer->send_signup_confirmation($insert_id);
                        } else {
                            error_log('[AdorationScheduler] EmailService missing send_signup_confirmation()');
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] Confirmation email failed for signup ' . $insert_id . ': ' . $e->getMessage());
                }
            }

            // 2) Reminder scheduling
            try {
                if (class_exists(ReminderScheduler::class)) {
                    $scheduler = new ReminderScheduler();
                    if (method_exists($scheduler, 'schedule_24h')) {
                        $scheduler->schedule_24h($insert_id);
                    }
                }
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] Reminder schedule failed for signup ' . $insert_id . ': ' . $insert_id . ' ' . $e->getMessage());
            }
        }

        return $insert_id;
    }

    /**
     * Delete a signup row by id and cleanup related scheduled reminders.
     *
     * IMPORTANT:
     * - This deletes ONLY from adoration_signups.
     * - It MUST NOT delete from adoration_persons.
     */
    public function delete_signup_and_cleanup(int $signup_id): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return false;

        // Capture a tiny bit of context for audit (best-effort)
        $existing = null;
        try {
            $existing = $this->find($signup_id);
        } catch (\Throwable $e) {
            $existing = null;
        }

        // Clear any pending reminder events for this signup, if ReminderScheduler exists.
        if (class_exists(ReminderScheduler::class)) {
            if (method_exists(ReminderScheduler::class, 'unschedule_for_signup')) {
                ReminderScheduler::unschedule_for_signup($signup_id);
            }
        }

        $result = $wpdb->delete($this->table, ['id' => $signup_id], ['%d']);
        $ok = ($result !== false && (int)$result > 0);

        // ✅ Audit trail (best-effort, no behavior changes)
        if ($ok) {
            $this->audit_log($signup_id, 'admin_deleted', [
                'cleanup'     => true,
                'person_id'   => isset($existing['person_id']) ? (int)$existing['person_id'] : null,
                'schedule_id' => isset($existing['schedule_id']) ? (int)$existing['schedule_id'] : null,
                'slot_id'     => isset($existing['slot_id']) ? (int)$existing['slot_id'] : null,
                'date'        => isset($existing['date']) ? (string)$existing['date'] : null,
                'status'      => isset($existing['status']) ? (string)$existing['status'] : null,
            ]);
        }

        return $ok;
    }

    /**
     * SAFE DELETE:
     * Deletes ONLY the signup record. No reminder unscheduling.
     * (Kept for cases where you truly want only DB delete.)
     */
    public function delete_signup_only(int $signup_id): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return false;

        // Capture a tiny bit of context for audit (best-effort)
        $existing = null;
        try {
            $existing = $this->find($signup_id);
        } catch (\Throwable $e) {
            $existing = null;
        }

        $result = $wpdb->delete($this->table, ['id' => $signup_id], ['%d']);
        $ok = ($result !== false && (int)$result > 0);

        // ✅ Audit trail (best-effort, no behavior changes)
        if ($ok) {
            $this->audit_log($signup_id, 'admin_deleted', [
                'cleanup'     => false,
                'person_id'   => isset($existing['person_id']) ? (int)$existing['person_id'] : null,
                'schedule_id' => isset($existing['schedule_id']) ? (int)$existing['schedule_id'] : null,
                'slot_id'     => isset($existing['slot_id']) ? (int)$existing['slot_id'] : null,
                'date'        => isset($existing['date']) ? (string)$existing['date'] : null,
                'status'      => isset($existing['status']) ? (string)$existing['status'] : null,
            ]);
        }

        return $ok;
    }

    /**
     * Backwards-compatible delete() used by older code.
     *
     * We make this safe and choose the cleanup version so reminders are also cleared.
     * It still deletes ONLY the signup row (never persons).
     */
    public function delete(int $signup_id): bool {
        return $this->delete_signup_and_cleanup($signup_id);
    }
}
