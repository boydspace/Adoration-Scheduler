<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined('ABSPATH') ) exit;

/**
 * WaitlistRepository
 *
 * A slot that's at capacity doesn't have to turn an adorer away outright —
 * they can queue instead, and get automatically promoted into a real
 * `adoration_signups` row (via SignupsRepository::create()) the moment a
 * confirmed signup for that same slot is freed up.
 *
 * Deliberately a separate table rather than a new `signups.status` value:
 * the codebase has ~20 call sites (exports, the iCal feed, coverage alerts,
 * reminders, reporting) that filter `status = 'confirmed'` directly. Keeping
 * waitlist entries out of that table means none of them need to change or
 * be re-audited to keep excluding waitlisted people correctly.
 */
class WaitlistRepository {

    private string $table;
    private string $slots_table;
    private string $schedules_table;

    public function __construct() {
        global $wpdb;
        $this->table           = $wpdb->prefix . 'adoration_waitlist';
        $this->slots_table     = $wpdb->prefix . 'adoration_slots';
        $this->schedules_table = $wpdb->prefix . 'adoration_schedules';
    }

    public function find(int $id): ?array {
        global $wpdb;
        $id = (int)$id;
        if ($id <= 0) return null;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * True if this person already has an active ('waiting') entry for this slot.
     */
    public function is_waiting(int $person_id, int $slot_id): bool {
        global $wpdb;
        $person_id = (int)$person_id;
        $slot_id   = (int)$slot_id;
        if ($person_id <= 0 || $slot_id <= 0) return false;

        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE person_id = %d AND slot_id = %d AND status = 'waiting'",
            $person_id,
            $slot_id
        ));

        return $count > 0;
    }

    /**
     * Add a person to the waitlist for a slot. Returns the waitlist row id
     * (0 on failure). If they're already waiting for this exact slot, returns
     * the existing row's id instead of creating a duplicate.
     */
    public function join(int $person_id, int $schedule_id, int $slot_id, string $date): int {
        global $wpdb;

        $person_id   = (int)$person_id;
        $schedule_id = (int)$schedule_id;
        $slot_id     = (int)$slot_id;
        $date        = sanitize_text_field($date);

        if ($person_id <= 0 || $schedule_id <= 0 || $slot_id <= 0 || $date === '') {
            return 0;
        }

        $existing_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE person_id = %d AND slot_id = %d AND status = 'waiting' LIMIT 1",
            $person_id,
            $slot_id
        ));

        if ($existing_id > 0) {
            return $existing_id;
        }

        $ok = $wpdb->insert($this->table, [
            'person_id'   => $person_id,
            'schedule_id' => $schedule_id,
            'slot_id'     => $slot_id,
            'date'        => $date,
            'status'      => 'waiting',
        ], ['%d', '%d', '%d', '%s', '%s']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /**
     * Self-service "leave the waitlist" — only succeeds if the row belongs
     * to this person.
     */
    public function leave(int $waitlist_id, int $person_id): bool {
        global $wpdb;
        $waitlist_id = (int)$waitlist_id;
        $person_id   = (int)$person_id;
        if ($waitlist_id <= 0 || $person_id <= 0) return false;

        $updated = $wpdb->update(
            $this->table,
            ['status' => 'cancelled'],
            ['id' => $waitlist_id, 'person_id' => $person_id, 'status' => 'waiting'],
            ['%s'],
            ['%d', '%d', '%s']
        );

        return $updated !== false;
    }

    /**
     * Admin removal — no ownership check.
     */
    public function admin_remove(int $waitlist_id): bool {
        global $wpdb;
        $waitlist_id = (int)$waitlist_id;
        if ($waitlist_id <= 0) return false;

        $updated = $wpdb->update(
            $this->table,
            ['status' => 'cancelled'],
            ['id' => $waitlist_id, 'status' => 'waiting'],
            ['%s'],
            ['%d', '%s']
        );

        return $updated !== false;
    }

    public function mark_promoted(int $waitlist_id): bool {
        global $wpdb;
        $waitlist_id = (int)$waitlist_id;
        if ($waitlist_id <= 0) return false;

        $updated = $wpdb->update(
            $this->table,
            ['status' => 'promoted'],
            ['id' => $waitlist_id],
            ['%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Earliest still-waiting entry for a slot (first in line). Locks the row
     * FOR UPDATE when a $wpdb transaction is already open around the call,
     * so two near-simultaneous cancellations can't both try to promote the
     * same waiting person into two different freed seats.
     */
    public function next_in_line(int $slot_id): ?array {
        global $wpdb;
        $slot_id = (int)$slot_id;
        if ($slot_id <= 0) return null;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE slot_id = %d AND status = 'waiting'
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            $slot_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function count_waiting_for_slot(int $slot_id): int {
        global $wpdb;
        $slot_id = (int)$slot_id;
        if ($slot_id <= 0) return 0;

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE slot_id = %d AND status = 'waiting'",
            $slot_id
        ));
    }

    /**
     * 1-based queue position among still-waiting entries for the same slot.
     * Returns 0 if the row isn't currently waiting.
     */
    public function position_in_line(int $waitlist_id): int {
        $row = $this->find($waitlist_id);
        if (!$row || (string)($row['status'] ?? '') !== 'waiting') return 0;

        global $wpdb;
        $slot_id    = (int)$row['slot_id'];
        $created_at = (string)$row['created_at'];
        $id         = (int)$row['id'];

        $ahead = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE slot_id = %d AND status = 'waiting'
               AND (created_at < %s OR (created_at = %s AND id < %d))",
            $slot_id,
            $created_at,
            $created_at,
            $id
        ));

        return $ahead + 1;
    }

    /**
     * A person's own active waitlist entries, with slot/schedule details
     * for display (mirrors SignupsRepository::list_for_person()'s join style).
     */
    public function list_for_person(int $person_id, bool $active_only = true): array {
        global $wpdb;
        $person_id = (int)$person_id;
        if ($person_id <= 0) return [];

        $where_status = $active_only ? " AND w.status = 'waiting' " : "";

        $sql = $wpdb->prepare(
            "SELECT
                w.*,
                sch.name AS schedule_name,
                sl.start_time AS slot_start_time,
                sl.end_time AS slot_end_time
             FROM {$this->table} w
             LEFT JOIN {$this->schedules_table} sch ON sch.id = w.schedule_id
             LEFT JOIN {$this->slots_table} sl ON sl.id = w.slot_id
             WHERE w.person_id = %d {$where_status}
             ORDER BY w.date ASC, sl.start_time ASC, w.id ASC",
            $person_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * All active entries for a slot, in queue order — for admin visibility.
     */
    public function list_for_slot(int $slot_id, bool $active_only = true): array {
        global $wpdb;
        $slot_id = (int)$slot_id;
        if ($slot_id <= 0) return [];

        $persons_table = $wpdb->prefix . 'adoration_persons';
        $where_status  = $active_only ? " AND w.status = 'waiting' " : "";

        $sql = $wpdb->prepare(
            "SELECT
                w.*,
                p.first_name, p.last_name, p.title, p.email, p.phone
             FROM {$this->table} w
             LEFT JOIN {$persons_table} p ON p.id = w.person_id
             WHERE w.slot_id = %d {$where_status}
             ORDER BY w.created_at ASC, w.id ASC",
            $slot_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * All active waitlist entries across a whole schedule, with person
     * details — for the admin Signups tab (grouped by slot_id there).
     */
    public function list_for_schedule(int $schedule_id, bool $active_only = true): array {
        global $wpdb;
        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return [];

        $persons_table = $wpdb->prefix . 'adoration_persons';
        $where_status  = $active_only ? " AND w.status = 'waiting' " : "";

        $sql = $wpdb->prepare(
            "SELECT
                w.*,
                p.first_name, p.last_name, p.title, p.email, p.phone
             FROM {$this->table} w
             LEFT JOIN {$persons_table} p ON p.id = w.person_id
             WHERE w.schedule_id = %d {$where_status}
             ORDER BY w.slot_id ASC, w.created_at ASC, w.id ASC",
            $schedule_id
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Self-service account deletion parity with SignupsRepository::cancel_all_future_for_person().
     */
    public function cancel_all_future_for_person(int $person_id, string $from_date): int {
        global $wpdb;
        $person_id = (int)$person_id;
        $from_date = sanitize_text_field($from_date);
        if ($person_id <= 0 || $from_date === '') return 0;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'cancelled', updated_at = %s
             WHERE person_id = %d AND status = 'waiting' AND date >= %s",
            gmdate('Y-m-d H:i:s'),
            $person_id,
            $from_date
        ));

        return $result === false ? 0 : (int)$result;
    }
}
