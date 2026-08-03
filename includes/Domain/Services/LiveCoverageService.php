<?php
namespace AdorationScheduler\Domain\Services;

use AdorationScheduler\Domain\Repositories\AttendanceRepository;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;

if ( ! defined('ABSPATH') ) exit;

/** Builds the small, read-only snapshot used by the administrator dashboard. */
class LiveCoverageService {
    public function snapshot(int $lookahead_minutes = 60): array {
        $chapels_repo = new ChapelsRepository();
        $slots_repo = new SlotsRepository();
        $signups_repo = new SignupsRepository();
        $attendance_repo = new AttendanceRepository();
        $result = [];

        foreach ($chapels_repo->list_active() as $chapel) {
            $chapel_id = (int)$chapel['id'];
            $slots = $slots_repo->list_current_for_chapel($chapel_id);
            $signups = $signups_repo->list_current_for_chapel($chapel_id);
            $attendance = $attendance_repo->list_current_for_chapel($chapel_id);
            $signups_by_slot = [];
            $present_by_slot = [];
            $signup_present_by_slot = [];
            $guests = 0;
            $substitutes = 0;
            $last_activity = '';

            foreach ($signups as $signup) {
                $slot_id = (int)($signup['slot_id'] ?? 0);
                $signups_by_slot[$slot_id] = ($signups_by_slot[$slot_id] ?? 0) + 1;
            }
            foreach ($attendance as $record) {
                foreach (['checked_in_at', 'checked_out_at'] as $field) {
                    if (!empty($record[$field]) && (string)$record[$field] > $last_activity) {
                        $last_activity = (string)$record[$field];
                    }
                }
                if (($record['status'] ?? '') !== 'present' || !empty($record['checked_out_at'])) continue;
                $slot_id = (int)$record['slot_id'];
                $present_by_slot[$slot_id] = ($present_by_slot[$slot_id] ?? 0) + 1;
                if (!empty($record['signup_id'])) {
                    $signup_present_by_slot[$slot_id] = ($signup_present_by_slot[$slot_id] ?? 0) + 1;
                }
                if (($record['attendance_type'] ?? '') === 'guest') $guests++;
                if (($record['attendance_type'] ?? '') === 'substitute') $substitutes++;
            }

            $scheduled = count($signups);
            $present = array_sum($present_by_slot);
            $missing = 0;
            $state = empty($slots) ? 'between_hours' : 'covered';
            foreach ($slots as $slot) {
                $slot_id = (int)$slot['id'];
                $required = max(1, (int)($slot['min_adorers'] ?? 1));
                $slot_present = (int)($present_by_slot[$slot_id] ?? 0);
                $slot_scheduled = (int)($signups_by_slot[$slot_id] ?? 0);
                $slot_signup_present = (int)($signup_present_by_slot[$slot_id] ?? 0);
                $missing += max(0, $slot_scheduled - $slot_signup_present);
                if ($slot_present < $required) {
                    $state = $slot_scheduled >= $required && $state !== 'needs_attention'
                        ? 'awaiting_checkin'
                        : 'needs_attention';
                }
            }

            $result[] = [
                'chapel' => $chapel,
                'slots' => $slots,
                'scheduled' => $scheduled,
                'present' => $present,
                'guests' => $guests,
                'substitutes' => $substitutes,
                'missing' => $missing,
                'state' => $state,
                'last_activity' => $last_activity,
                'next_slot' => $slots_repo->find_next_for_chapel($chapel_id, $lookahead_minutes),
            ];
        }

        return $result;
    }
}
