<?php
namespace AdorationScheduler\Domain\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Repository is the attendance persistence boundary.

if ( ! defined('ABSPATH') ) exit;

/**
 * Persistence for 2.0 attendance records. Signups describe who was expected;
 * attendance describes who was actually present.
 */
class AttendanceRepository
{
    private string $table;
    private string $signups_table;
    private string $slots_table;
    private string $schedules_table;

    public function __construct()
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'adoration_';
        $this->table = $prefix . 'attendance';
        $this->signups_table = $prefix . 'signups';
        $this->slots_table = $prefix . 'slots';
        $this->schedules_table = $prefix . 'schedules';
    }

    public function find_by_signup(int $signup_id): ?array
    {
        global $wpdb;
        if ($signup_id <= 0) return null;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE signup_id = %d LIMIT 1", $this->table, $signup_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /** Attendance activity for chapel hours that are happening now. */
    public function list_current_for_chapel(int $chapel_id): array
    {
        global $wpdb;
        if ($chapel_id <= 0) return [];

        $now = current_time('mysql');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*
                   FROM %i a
                   INNER JOIN %i sl ON sl.id = a.slot_id
                  WHERE a.chapel_id = %d
                    AND sl.is_active = 1
                    AND sl.start_at IS NOT NULL
                    AND sl.end_at IS NOT NULL
                    AND sl.start_at <= %s
                    AND sl.end_at >= %s
                  ORDER BY a.checked_in_at DESC, a.id DESC",
                $this->table,
                $this->slots_table,
                $chapel_id,
                $now,
                $now
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Record an actual attendee. A signup-backed record is updated in place
     * so a coordinator can replace the scheduled person with a substitute;
     * guest records have no signup and are appended independently.
     *
     * @return int Attendance row ID, or 0 when validation/persistence fails.
     */
    public function record(array $data): int
    {
        global $wpdb;

        $signup_id = absint($data['signup_id'] ?? 0);
        $slot_id = absint($data['slot_id'] ?? 0);
        $schedule_id = absint($data['schedule_id'] ?? 0);
        $chapel_id = absint($data['chapel_id'] ?? 0);
        $scheduled_person_id = absint($data['scheduled_person_id'] ?? 0);
        $attendee_person_id = absint($data['attendee_person_id'] ?? 0);
        $type = sanitize_key((string)($data['attendance_type'] ?? 'scheduled'));
        $status = sanitize_key((string)($data['status'] ?? 'present'));
        $method = sanitize_key((string)($data['check_in_method'] ?? 'admin'));
        $guest_name = sanitize_text_field((string)($data['guest_name'] ?? ''));

        if ($slot_id <= 0 || $schedule_id <= 0 || $chapel_id <= 0) return 0;
        if (!in_array($type, ['scheduled', 'substitute', 'guest'], true)) return 0;
        if (!in_array($status, ['present', 'completed', 'absent', 'excused'], true)) return 0;
        if ($type === 'guest' && $guest_name === '') return 0;
        if ($type !== 'guest' && $attendee_person_id <= 0) return 0;
        if ($type === 'substitute' && ($signup_id <= 0 || $scheduled_person_id <= 0)) return 0;

        $now = current_time('mysql');
        $row = [
            'signup_id' => $signup_id > 0 ? $signup_id : null,
            'slot_id' => $slot_id,
            'schedule_id' => $schedule_id,
            'chapel_id' => $chapel_id,
            'scheduled_person_id' => $scheduled_person_id > 0 ? $scheduled_person_id : null,
            'attendee_person_id' => $attendee_person_id > 0 ? $attendee_person_id : null,
            'guest_name' => $guest_name !== '' ? $guest_name : null,
            'attendance_type' => $type,
            'status' => $status,
            'checked_in_at' => (string)($data['checked_in_at'] ?? $now),
            'checked_out_at' => !empty($data['checked_out_at']) ? (string)$data['checked_out_at'] : null,
            'check_in_method' => $method !== '' ? $method : 'admin',
            'check_out_method' => !empty($data['check_out_method']) ? sanitize_key((string)$data['check_out_method']) : null,
            'recorded_by_user_id' => absint($data['recorded_by_user_id'] ?? 0) ?: null,
            'notes' => !empty($data['notes']) ? sanitize_textarea_field((string)$data['notes']) : null,
            'migrated_from_legacy' => 0,
            'updated_at' => $now,
        ];

        $existing = $signup_id > 0 ? $this->find_by_signup($signup_id) : null;
        if ($existing) {
            $result = $wpdb->update($this->table, $row, ['id' => (int)$existing['id']]);
            return $result === false ? 0 : (int)$existing['id'];
        }

        $row['created_at'] = $now;
        $result = $wpdb->insert($this->table, $row);
        return $result === false ? 0 : (int)$wpdb->insert_id;
    }

    /**
     * Mirror the legacy signup fields while 1.x and 2.0 code coexist. A
     * future substitute record is never overwritten by this compatibility
     * path, even if an old personal link is tapped afterward.
     */
    public function sync_scheduled_signup(int $signup_id): bool
    {
        global $wpdb;
        if ($signup_id <= 0) return false;

        $source = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.id, s.slot_id, s.schedule_id, s.person_id,
                        s.replacement_requested_by, s.replacement_claimed_by,
                        s.checked_in_at, s.checked_out_at, s.check_in_method,
                        COALESCE(sl.chapel_id, sc.chapel_id) AS chapel_id
                   FROM %i s
                   INNER JOIN %i sl ON sl.id = s.slot_id
                   INNER JOIN %i sc ON sc.id = s.schedule_id
                  WHERE s.id = %d
                  LIMIT 1",
                $this->signups_table,
                $this->slots_table,
                $this->schedules_table,
                $signup_id
            ),
            ARRAY_A
        );

        if (!is_array($source)) return false;
        if (empty($source['checked_in_at'])) return $this->clear_scheduled_signup($signup_id);

        $existing = $this->find_by_signup($signup_id);
        $claimed_by = (int)($source['replacement_claimed_by'] ?? 0);
        $requested_by = (int)($source['replacement_requested_by'] ?? 0);
        if ($existing && ($existing['attendance_type'] ?? '') === 'substitute' && $claimed_by <= 0) {
            return true;
        }

        $is_substitute = $claimed_by > 0;
        $scheduled_person_id = $is_substitute && $requested_by > 0
            ? $requested_by
            : (int)$source['person_id'];
        $attendee_person_id = $is_substitute ? $claimed_by : (int)$source['person_id'];

        $now = current_time('mysql');
        $data = [
            'signup_id' => $signup_id,
            'slot_id' => (int)$source['slot_id'],
            'schedule_id' => (int)$source['schedule_id'],
            'chapel_id' => (int)$source['chapel_id'],
            'scheduled_person_id' => $scheduled_person_id,
            'attendee_person_id' => $attendee_person_id,
            'guest_name' => null,
            'attendance_type' => $is_substitute ? 'substitute' : 'scheduled',
            'status' => empty($source['checked_out_at']) ? 'present' : 'completed',
            'checked_in_at' => $source['checked_in_at'],
            'checked_out_at' => $source['checked_out_at'] ?: null,
            'check_in_method' => $source['check_in_method'] ?: 'self',
            'updated_at' => $now,
        ];

        if ($existing) {
            $result = $wpdb->update($this->table, $data, ['id' => (int)$existing['id']]);
            return $result !== false;
        }

        $data['created_at'] = $source['checked_in_at'];
        $result = $wpdb->insert($this->table, $data);
        return $result !== false;
    }

    public function clear_scheduled_signup(int $signup_id): bool
    {
        global $wpdb;
        if ($signup_id <= 0) return false;

        $existing = $this->find_by_signup($signup_id);
        if (!$existing || ($existing['attendance_type'] ?? '') !== 'scheduled') return true;

        return $wpdb->delete($this->table, ['id' => (int)$existing['id']], ['%d']) !== false;
    }
}
