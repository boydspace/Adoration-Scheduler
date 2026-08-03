<?php
namespace AdorationScheduler\Domain\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Repository is the persistence boundary; scheduling reads must reflect current data.

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
            \AdorationScheduler\Core\Logger::error('[AdorationScheduler] Audit log failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers (safe table/column detection)
    // -------------------------------------------------------------------------

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
             FROM %i
             WHERE schedule_id = %d AND status = 'confirmed'
             GROUP BY slot_id",
            $this->table,
            $schedule_id
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
     * Uses the canonical start_at/end_at columns (timezone-correct,
     * handles overnight slots naturally) for duration — guaranteed present
     * by Installer.php on any installed/upgraded site.
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

        // The (%d = 0 OR ...) form lets the schedule filter stay optional
        // (0 = all schedules) without interpolating a conditional WHERE
        // fragment.
        $sql = $wpdb->prepare(
            "SELECT
                s.person_id,
                p.title, p.first_name, p.last_name, p.email,
                COUNT(*) AS signup_count,
                SUM(TIMESTAMPDIFF(MINUTE, sl.start_at, sl.end_at)) AS total_minutes
             FROM %i s
             JOIN %i sl ON sl.id = s.slot_id
             JOIN %i p ON p.id = s.person_id
             WHERE s.status = 'confirmed'
               AND s.date BETWEEN %s AND %s
               AND (%d = 0 OR s.schedule_id = %d)
             GROUP BY s.person_id, p.title, p.first_name, p.last_name, p.email
             ORDER BY total_minutes DESC",
            $this->table,
            $this->slots_table,
            $this->persons_table,
            $from_ymd,
            $to_ymd,
            $schedule_id,
            $schedule_id
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as &$r) {
            $r['signup_count']  = (int)($r['signup_count'] ?? 0);
            $r['total_minutes'] = (int)round((float)($r['total_minutes'] ?? 0));
        }
        unset($r);

        return $rows;
    }

    /**
     * ✅ Reminder lead-time rescheduling (2026-07-21): just the ids, no
     * joins — used by ReminderPreferencesHandler to unschedule + reschedule
     * a person's already-pending reminders after they change their lead
     * time, so the change takes effect on signups made before the
     * preference was saved, not only ones made after. Deliberately not a
     * reuse of list_for_person() below (that does a 3-table join for full
     * display data this doesn't need).
     */
    public function list_upcoming_confirmed_ids_for_person(int $person_id): array {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return [];

        $today = wp_date('Y-m-d');

        $sql = $wpdb->prepare(
            "SELECT id FROM %i WHERE person_id = %d AND status = 'confirmed' AND date >= %s",
            $this->table,
            $person_id,
            $today
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $ids = $wpdb->get_col($sql);
        return array_map('intval', (array)$ids);
    }

    /**
     * ✅ List signups for a person (optionally only confirmed).
     * Includes schedule_name/schedule_slug and slot timing fields.
     */
    public function list_for_person(int $person_id, bool $confirmed_only = false): array {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return [];

        // The (%d = 0 OR ...) form lets "only confirmed" stay an optional
        // filter without interpolating a conditional WHERE fragment.
        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                sch.name AS schedule_name, sch.slug AS schedule_slug,
                sl.`date` AS slot_date,
                sl.start_time AS slot_start_time,
                sl.end_time AS slot_end_time,
                sl.start_at AS slot_start_at,
                sl.end_at AS slot_end_at
             FROM %i s
             LEFT JOIN %i sch ON sch.id = s.schedule_id
             LEFT JOIN %i sl ON sl.id = s.slot_id
             WHERE s.person_id = %d
               AND (%d = 0 OR s.status = 'confirmed')
             ORDER BY
                CASE WHEN sl.start_at IS NULL THEN 1 ELSE 0 END ASC,
                sl.start_at ASC,
                CASE WHEN sl.end_at IS NULL THEN 1 ELSE 0 END ASC,
                sl.end_at ASC,
                s.date DESC, s.id ASC",
            $this->table,
            $this->schedules_table,
            $this->slots_table,
            $person_id,
            $confirmed_only ? 1 : 0
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM %i
             WHERE person_id = %d AND status <> 'cancelled' AND date >= %s",
            $this->table,
            $person_id,
            $from_date
        ));

        if (empty($ids)) return 0;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET status = 'cancelled', is_active = 0
             WHERE person_id = %d AND status <> 'cancelled' AND date >= %s",
            $this->table,
            $person_id,
            $from_date
        ));

        foreach ($ids as $id) {
            $this->audit_log((int)$id, 'cancelled_account_deletion', ['person_id' => $person_id]);
        }

        return $updated !== false ? (int)$updated : 0;
    }

    /**
     * Find signup IDs tied to a specific standing commitment (same
     * schedule, person, weekday, and start time) that are still active
     * (not already cancelled) on or after $from_date.
     *
     * WHY THIS EXISTS: PerpetualSlotGenerator::apply_standing_commitments()
     * auto-creates a dated signup for every future occurrence of an active
     * commitment. StandingCommitmentsRepository::end() only deactivates the
     * commitment row itself — it never touches those already-materialized
     * signups, so ending a commitment used to leave every future dated
     * signup it had generated confirmed and active forever. This method
     * finds them so the caller (EditSchedulePage's end-commitment handler)
     * can cancel them too.
     *
     * Matches by JOINing to the slots table for start_time (signups don't
     * store start_time directly — see exists_confirmed_for_schedule_datetime()
     * for the same join pattern), then filters by weekday in PHP using
     * DateTime::format('w') to match the 0=Sunday..6=Saturday convention
     * PerpetualSlotGenerator itself uses when it created these rows.
     *
     * @return int[] signup IDs
     */
    public function list_ids_for_commitment(
        int $schedule_id,
        int $person_id,
        int $day_of_week,
        string $start_time,
        string $from_date
    ): array {
        global $wpdb;

        $schedule_id = (int)$schedule_id;
        $person_id   = (int)$person_id;
        $day_of_week = (int)$day_of_week;
        $start_time  = substr(sanitize_text_field($start_time), 0, 8);
        $from_date   = sanitize_text_field($from_date);

        if ($schedule_id <= 0 || $person_id <= 0 || $day_of_week < 0 || $day_of_week > 6 || $start_time === '' || $from_date === '') {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.date
             FROM %i s
             JOIN %i sl ON sl.id = s.slot_id
             WHERE s.schedule_id = %d AND s.person_id = %d AND s.date >= %s
               AND s.status <> 'cancelled' AND sl.start_time = %s",
            $this->table,
            $this->slots_table,
            $schedule_id,
            $person_id,
            $from_date,
            $start_time
        ), ARRAY_A);

        if (empty($rows)) return [];

        $ids = [];
        foreach ($rows as $row) {
            $date = (string)($row['date'] ?? '');
            if ($date === '') continue;

            try {
                $dow = (int)(new \DateTime($date))->format('w');
            } catch (\Exception $e) {
                continue;
            }

            if ($dow === $day_of_week) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids;
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

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM %i WHERE replacement_target_person_id = %d",
            $this->table,
            $target_person_id
        ));

        if (empty($ids)) return 0;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET replacement_target_person_id = NULL WHERE replacement_target_person_id = %d",
            $this->table,
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

        // The (%d = 0 OR ...) form lets "only confirmed" stay an optional
        // filter without interpolating a conditional WHERE fragment.
        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                p.title      AS title,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                p.phone      AS phone,
                sl.start_at AS slot_start_at, sl.end_at AS slot_end_at,
                sl.start_time AS slot_start_time, sl.end_time AS slot_end_time,
                sl.`date` AS slot_date
             FROM %i s
             LEFT JOIN %i p ON p.id = s.person_id
             LEFT JOIN %i sl ON sl.id = s.slot_id
             WHERE s.schedule_id = %d
               AND (%d = 0 OR s.status = 'confirmed')
             ORDER BY
                CASE WHEN sl.start_at IS NULL THEN 1 ELSE 0 END ASC,
                sl.start_at ASC,
                CASE WHEN sl.end_at IS NULL THEN 1 ELSE 0 END ASC,
                sl.end_at ASC,
                s.date ASC, s.id ASC",
            $this->table,
            $this->persons_table,
            $this->slots_table,
            $schedule_id,
            $confirmed_only ? 1 : 0
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
             FROM %i
             WHERE schedule_id = %d AND status = 'confirmed' AND `date` BETWEEN %s AND %s
             GROUP BY `date`",
            $this->table,
            $schedule_id,
            $start_ymd,
            $end_ymd
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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

        $sql = $wpdb->prepare(
            "SELECT s.*, p.title AS title, p.first_name AS first_name, p.last_name AS last_name, p.email AS email, p.phone AS phone,
                sl.start_time AS slot_start_time, sl.end_time AS slot_end_time
             FROM %i s
             LEFT JOIN %i p ON p.id = s.person_id
             LEFT JOIN %i sl ON sl.id = s.slot_id
             WHERE s.schedule_id = %d AND s.date = %s
             ORDER BY sl.start_time ASC, s.id ASC",
            $this->table,
            $this->persons_table,
            $this->slots_table,
            $schedule_id,
            $ymd
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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

        // The (%d = 0 OR ...) form lets "only confirmed" stay an optional
        // filter without interpolating a conditional WHERE fragment.
        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                p.title      AS title,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                p.phone      AS phone
             FROM %i s
             LEFT JOIN %i p ON p.id = s.person_id
             WHERE s.slot_id = %d
               AND (%d = 0 OR s.status = 'confirmed')
             ORDER BY s.date ASC, s.id ASC",
            $this->table,
            $this->persons_table,
            $slot_id,
            $confirmed_only ? 1 : 0
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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

        $out = [];
        foreach ($slot_ids as $slot_id) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE slot_id = %d AND status = 'confirmed'",
                $this->table,
                $slot_id
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared immediately above.
            $count = (int) $wpdb->get_var($sql);
            if ($count > 0) {
                $out[$slot_id] = $count;
            }
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

        // Dynamic-length IN-clause: the placeholder count varies with the
        // number of slot IDs, so the arg list can't be individually enumerated.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $placeholders = implode(',', array_fill(0, count($slot_ids), '%d'));
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder-only fragment matches the validated integer slot ID list.
            "SELECT id FROM %i WHERE slot_id IN ($placeholders) AND status = 'confirmed'",
            ...array_merge([$this->table], $slot_ids)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $ids = (array) $wpdb->get_col($sql);
        return array_map('intval', $ids);
    }

    /**
     * Fetch a single signup row by id (JOINED with persons).
     * (Optionally also join slot canonical times if available.)
     */
    public function find(int $signup_id): ?array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT
                s.*,
                p.first_name AS first_name,
                p.last_name  AS last_name,
                p.email      AS email,
                p.phone      AS phone,
                sl.start_at AS slot_start_at, sl.end_at AS slot_end_at,
                sl.start_time AS slot_start_time, sl.end_time AS slot_end_time,
                sl.`date` AS slot_date
             FROM %i s
             LEFT JOIN %i p ON p.id = s.person_id
             LEFT JOIN %i sl ON sl.id = s.slot_id
             WHERE s.id = %d
             LIMIT 1",
            $this->table,
            $this->persons_table,
            $this->slots_table,
            $signup_id
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
                 FROM %i
                 WHERE slot_id = %d AND person_id = %d
                 LIMIT 1",
                $this->table,
                $slot_id,
                $person_id
            );
        } else {
            $status = sanitize_text_field($status);
            $sql = $wpdb->prepare(
                "SELECT 1
                 FROM %i
                 WHERE slot_id = %d AND person_id = %d AND status = %s
                 LIMIT 1",
                $this->table,
                $slot_id,
                $person_id,
                $status
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
                 FROM %i
                 WHERE slot_id = %d AND person_id = %d AND date = %s
                 LIMIT 1",
                $this->table,
                $slot_id,
                $person_id,
                $date
            );
        } else {
            $status = sanitize_text_field($status);
            $sql = $wpdb->prepare(
                "SELECT 1
                 FROM %i
                 WHERE slot_id = %d AND person_id = %d AND date = %s AND status = %s
                 LIMIT 1",
                $this->table,
                $slot_id,
                $person_id,
                $date,
                $status
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
             FROM %i s
             JOIN %i sl ON sl.id = s.slot_id
             WHERE s.schedule_id = %d AND s.person_id = %d AND s.date = %s
               AND s.status = 'confirmed' AND sl.start_time = %s
             LIMIT 1",
            $this->table,
            $this->slots_table,
            $schedule_id,
            $person_id,
            $date,
            $start_time
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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

        $res = $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET needs_replacement = 0,
                 replacement_requested_at = NULL,
                 replacement_requested_by = NULL,
                 replacement_note = NULL,
                 replacement_target_person_id = NULL
             WHERE id = %d
               AND person_id = %d
               AND replacement_claimed_by IS NULL",
            $this->table,
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

        $res = $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET replacement_target_person_id = NULL
             WHERE id = %d
               AND person_id = %d
               AND needs_replacement = 1
               AND replacement_claimed_by IS NULL",
            $this->table,
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
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET person_id = %d,
                 needs_replacement = 0,
                 replacement_claimed_by = %d,
                 replacement_claimed_at = %s
             WHERE id = %d
               AND needs_replacement = 1
               AND replacement_claimed_by IS NULL
               AND (replacement_target_person_id IS NULL OR replacement_target_person_id = %d)
             LIMIT 1",
            $this->table,
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

        // The (%d = 0 OR ...) form lets the excluded-person filter stay
        // optional (0 = no exclusion) without interpolating a conditional
        // WHERE fragment.
        $prepared = $wpdb->prepare(
            "
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
                p.last_name  AS requester_last_name,
                p.title      AS requester_title
            FROM %i s
            INNER JOIN %i sl ON sl.id = s.slot_id
            INNER JOIN %i sc ON sc.id = s.schedule_id
            INNER JOIN %i ch ON ch.id = sc.chapel_id
            LEFT JOIN %i p ON p.id = s.person_id
            WHERE s.needs_replacement = 1
              AND s.replacement_claimed_by IS NULL
              AND s.replacement_target_person_id IS NULL
              AND s.status = 'confirmed'
              AND s.is_active = 1
              AND s.date >= %s
              AND (%d = 0 OR s.person_id != %d)
            ORDER BY s.date ASC, sl.start_time ASC
            LIMIT %d
        ",
            $this->table, $slots, $sched, $chapels, $this->persons_table, $today, $exclude_person_id, $exclude_person_id, $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = $wpdb->get_results($prepared, ARRAY_A);
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
                p.last_name  AS requester_last_name,
                p.title      AS requester_title
            FROM %i s
            INNER JOIN %i sl ON sl.id = s.slot_id
            INNER JOIN %i sc ON sc.id = s.schedule_id
            INNER JOIN %i ch ON ch.id = sc.chapel_id
            LEFT JOIN %i p ON p.id = s.person_id
            WHERE s.needs_replacement = 1
              AND s.replacement_claimed_by IS NULL
              AND s.replacement_target_person_id = %d
              AND s.status = 'confirmed'
              AND s.is_active = 1
              AND s.date >= %s
            ORDER BY s.date ASC, sl.start_time ASC
            LIMIT %d
        ", $this->table, $slots, $sched, $chapels, $this->persons_table, $person_id, $today, $limit);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
            FROM %i s
            INNER JOIN %i sl ON sl.id = s.slot_id
            INNER JOIN %i sc ON sc.id = s.schedule_id
            INNER JOIN %i ch ON ch.id = sc.chapel_id
            LEFT JOIN %i req ON req.id = s.replacement_requested_by
            LEFT JOIN %i sub ON sub.id = s.replacement_claimed_by
            WHERE s.replacement_claimed_by IS NOT NULL
            ORDER BY s.replacement_claimed_at DESC, s.id DESC
            LIMIT %d
        ", $this->table, $slots, $sched, $chapels, $persons, $persons, $limit);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
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
            FROM %i s
            LEFT JOIN %i p ON p.id = s.person_id
            LEFT JOIN %i tgt ON tgt.id = s.replacement_target_person_id
            LEFT JOIN %i sl ON sl.id = s.slot_id
            LEFT JOIN %i sc ON sc.id = s.schedule_id
            LEFT JOIN %i ch ON ch.id = sc.chapel_id
            WHERE s.id = %d
            LIMIT 1
        ", $this->table, $this->persons_table, $this->persons_table, $slots, $sched, $chapels, $signup_id);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Count of currently open replacement requests (for admin badges/summaries).
     */
    public function count_open_replacement_requests(): int {
        global $wpdb;

        $today = current_time('Y-m-d');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE needs_replacement = 1
               AND replacement_claimed_by IS NULL
               AND status = 'confirmed'
               AND is_active = 1
               AND date >= %s",
            $this->table,
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
                 FROM %i
                 WHERE person_id = %d",
                $this->table,
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
                     FROM %i
                     WHERE person_id = %d
                       AND slot_id = %d
                       AND date = %s
                       AND status = %s
                     LIMIT 1",
                    $this->table,
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
                            \AdorationScheduler\Core\Logger::error('[AdorationScheduler] EmailService missing send_signup_confirmation()');
                        }
                    }
                } catch (\Throwable $e) {
                    \AdorationScheduler\Core\Logger::error('[AdorationScheduler] Confirmation email failed for signup ' . $insert_id . ': ' . $e->getMessage());
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
                \AdorationScheduler\Core\Logger::error('[AdorationScheduler] Reminder schedule failed for signup ' . $insert_id . ': ' . $insert_id . ' ' . $e->getMessage());
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

    // -------------------------------------------------------------------------
    // ATTENDANCE / CHECK-IN (2026-07-18)
    //
    // Deliberately per-occurrence: a standing weekly commitment auto-fills a
    // separate signup row per future date (see PerpetualSlotGenerator), and
    // each of those rows gets its own checked_in_at/checked_out_at — so
    // attendance history for a recurring hour can show "present 6 of the
    // last 8 weeks" instead of one all-or-nothing flag on the commitment.
    // -------------------------------------------------------------------------

    /**
     * Look up (or lazily create) this signup's bearer token for no-login
     * check-in links, mirroring PersonsRepository::get_or_create_calendar_token().
     */
    public function get_or_create_checkin_token(int $signup_id): ?string {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return null;

        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT checkin_token FROM %i WHERE id = %d LIMIT 1", $this->table, $signup_id)
        );
        $existing = trim((string)$existing);
        if ($existing !== '') return $existing;

        // 32 raw bytes -> 64 hex chars, matches the CHAR(64) column.
        $token = bin2hex(random_bytes(32));

        $res = $wpdb->update(
            $this->table,
            ['checkin_token' => $token],
            ['id' => $signup_id],
            ['%s'],
            ['%d']
        );

        return ($res === 1) ? $token : null;
    }

    /**
     * Look up a signup by its raw check-in token (as it appears in the
     * email link's URL). Returns null on no match — callers should treat
     * that identically to "link not found" without leaking why.
     */
    public function find_by_checkin_token(string $token): ?array {
        global $wpdb;

        $token = trim($token);
        if ($token === '') return null;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE checkin_token = %s LIMIT 1", $this->table, $token),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Record arrival. $method is one of 'self' (portal button or email
     * link), 'kiosk' (chapel walk-up page), or 'admin' (marked after the
     * fact by staff). Idempotent — calling twice doesn't overwrite an
     * earlier check-in time with a later click.
     */
    public function check_in(int $signup_id, string $method = 'self'): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return false;

        $method = in_array($method, ['self', 'kiosk', 'admin'], true) ? $method : 'self';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT checked_in_at FROM %i WHERE id = %d LIMIT 1", $this->table, $signup_id),
            ARRAY_A
        );
        if (!is_array($row)) return false;
        if (!empty($row['checked_in_at'])) return true; // already checked in — not an error

        $res = $wpdb->update(
            $this->table,
            [
                'checked_in_at'   => current_time('mysql'),
                'check_in_method' => $method,
            ],
            ['id' => $signup_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($res !== false) {
            $this->audit_log($signup_id, 'checked_in', ['method' => $method]);
        }

        return $res !== false;
    }

    /**
     * Record departure. Only meaningful after check_in(); silently a no-op
     * if never checked in (nothing to "leave" from).
     */
    public function check_out(int $signup_id): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return false;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT checked_in_at, checked_out_at FROM %i WHERE id = %d LIMIT 1", $this->table, $signup_id),
            ARRAY_A
        );
        if (!is_array($row) || empty($row['checked_in_at'])) return false;
        if (!empty($row['checked_out_at'])) return true; // already checked out

        $res = $wpdb->update(
            $this->table,
            ['checked_out_at' => current_time('mysql')],
            ['id' => $signup_id],
            ['%s'],
            ['%d']
        );

        if ($res !== false) {
            $this->audit_log($signup_id, 'checked_out', []);
        }

        return $res !== false;
    }

    /**
     * Admin override: set (or clear) attendance directly, regardless of
     * current state — used by the Attendance admin page's "mark present" /
     * "mark absent" controls, which need to be able to correct a bad
     * self-report as well as fill one in after the fact.
     */
    public function set_attendance_admin(int $signup_id, bool $present): bool {
        global $wpdb;

        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return false;

        $existing_id = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT id FROM %i WHERE id = %d LIMIT 1", $this->table, $signup_id)
        );
        if ($existing_id !== $signup_id) return false;

        if ($present) {
            $data   = ['checked_in_at' => current_time('mysql'), 'check_in_method' => 'admin'];
            $format = ['%s', '%s'];
        } else {
            $data   = ['checked_in_at' => null, 'checked_out_at' => null, 'check_in_method' => null];
            $format = ['%s', '%s', '%s'];
        }

        $res = $wpdb->update($this->table, $data, ['id' => $signup_id], $format, ['%d']);

        if ($res !== false) {
            $this->audit_log($signup_id, $present ? 'checked_in' : 'attendance_cleared', ['method' => 'admin']);
        }

        return $res !== false;
    }

    /**
     * Confirmed signups whose slot started more than $grace_minutes ago,
     * nobody has checked in, and no no-show alert has been sent yet for
     * this occurrence — the query behind the no-show digest cron
     * (NoShowAlertService). Joins to slots/schedules/chapels for a
     * human-readable digest, same shape as list_open_replacement_requests().
     */
    public function find_unchecked_in_past_grace(int $grace_minutes = 30, int $limit = 100): array {
        global $wpdb;

        $grace_minutes = max(0, (int)$grace_minutes);
        $limit = max(1, min(500, (int)$limit));

        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';

        // Site-local "now minus grace period", compared against the slot's
        // real start_at datetime column (already timezone-normalized —
        // see SlotsRepository/Installer's start_at/end_at columns).
        $cutoff = current_time('mysql');

        $prepared = $wpdb->prepare(
            "
            SELECT
                s.id,
                s.date,
                s.person_id,
                sl.start_time,
                sl.end_time,
                sl.start_at,
                sc.name AS schedule_name,
                ch.name AS chapel_name,
                p.first_name AS person_first_name,
                p.last_name  AS person_last_name
            FROM %i s
            INNER JOIN %i sl ON sl.id = s.slot_id
            INNER JOIN %i sc ON sc.id = s.schedule_id
            INNER JOIN %i ch ON ch.id = sc.chapel_id
            LEFT JOIN %i p ON p.id = s.person_id
            WHERE s.status = 'confirmed'
              AND s.is_active = 1
              AND s.checked_in_at IS NULL
              AND s.no_show_alert_sent_at IS NULL
              AND sl.start_at IS NOT NULL
              AND sl.start_at <= DATE_SUB(%s, INTERVAL %d MINUTE)
            ORDER BY sl.start_at ASC
            LIMIT %d
        ",
            $this->table, $slots, $sched, $chapels, $this->persons_table,
            $cutoff, $grace_minutes, $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Dedupe stamp so find_unchecked_in_past_grace() doesn't re-flag the
     * same gap on every cron run.
     */
    public function mark_no_show_alert_sent(array $signup_ids): void {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $signup_ids), fn($id) => $id > 0)));
        if (empty($ids)) return;

        // Dynamic-length IN-clause: the placeholder count varies with the
        // number of signup IDs, so the arg list can't be individually enumerated.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic %d list and replacement list have the same validated ID count.
        $prepared = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder-only fragment matches the validated integer signup ID list.
            "UPDATE %i SET no_show_alert_sent_at = %s WHERE id IN ({$placeholders})",
            ...array_merge([$this->table, current_time('mysql')], $ids)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $wpdb->query($prepared);
    }

    /**
     * Attendance rows for the admin Attendance page: signups within a date
     * range (inclusive), newest first, with everything the page's table
     * needs to render without N+1 queries.
     */
    public function list_for_attendance(string $date_from, string $date_to, int $schedule_id = 0, int $limit = 500): array {
        global $wpdb;

        $slots   = $wpdb->prefix . 'adoration_slots';
        $sched   = $wpdb->prefix . 'adoration_schedules';
        $chapels = $wpdb->prefix . 'adoration_chapels';

        $limit = max(1, min(2000, (int)$limit));

        // The (%d = 0 OR ...) form lets the schedule filter stay optional
        // (0 = all schedules) without interpolating a conditional WHERE
        // fragment.
        $prepared = $wpdb->prepare(
            "
            SELECT
                s.id,
                s.date,
                s.status,
                s.person_id,
                s.checked_in_at,
                s.checked_out_at,
                s.check_in_method,
                sl.start_time,
                sl.end_time,
                sc.id   AS schedule_id,
                sc.name AS schedule_name,
                ch.name AS chapel_name,
                p.title      AS person_title,
                p.first_name AS person_first_name,
                p.last_name  AS person_last_name
            FROM %i s
            INNER JOIN %i sl ON sl.id = s.slot_id
            INNER JOIN %i sc ON sc.id = s.schedule_id
            INNER JOIN %i ch ON ch.id = sc.chapel_id
            LEFT JOIN %i p ON p.id = s.person_id
            WHERE s.status = 'confirmed'
              AND s.is_active = 1
              AND s.date BETWEEN %s AND %s
              AND (%d = 0 OR s.schedule_id = %d)
            ORDER BY s.date DESC, sl.start_time DESC
            LIMIT %d
        ",
            $this->table, $slots, $sched, $chapels, $this->persons_table,
            $date_from, $date_to, $schedule_id, $schedule_id, $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Everyone currently scheduled "right now" at a chapel — the query
     * behind the kiosk check-in page. A slot counts as "now" if the
     * current site-local time falls within [start_at, end_at], so a
     * walk-up adorer sees their own name (and only names actually on the
     * clock, not the whole day's roster).
     */
    public function list_current_for_chapel(int $chapel_id): array {
        global $wpdb;

        $chapel_id = (int)$chapel_id;
        if ($chapel_id <= 0) return [];

        $slots = $wpdb->prefix . 'adoration_slots';
        $sched = $wpdb->prefix . 'adoration_schedules';
        $now   = current_time('mysql');

        $prepared = $wpdb->prepare(
            "
            SELECT
                s.id,
                s.checked_in_at,
                s.checked_out_at,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                p.first_name AS person_first_name,
                p.last_name  AS person_last_name
            FROM %i s
            INNER JOIN %i sl ON sl.id = s.slot_id
            INNER JOIN %i sc ON sc.id = s.schedule_id
            LEFT JOIN %i p ON p.id = s.person_id
            WHERE s.status = 'confirmed'
              AND s.is_active = 1
              AND sc.chapel_id = %d
              AND sl.start_at IS NOT NULL
              AND sl.end_at IS NOT NULL
              AND sl.start_at <= %s
              AND sl.end_at   >= %s
            ORDER BY sl.start_at ASC
        ",
            $this->table, $slots, $sched, $this->persons_table,
            $chapel_id, $now, $now
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above or assembled only from fixed/schema-validated fragments; dynamic values and identifiers use placeholders.
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
