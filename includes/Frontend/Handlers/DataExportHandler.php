<?php
namespace AdorationScheduler\Frontend\Handlers;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;

/**
 * Self-service "Download My Data" — a signed-in person's own profile,
 * standing hours, and full signup history as a downloadable JSON file.
 * Read-only, so (unlike AccountDeletionService) it's fine to allow the
 * admin-preview fallback here — an admin previewing a matched person
 * record downloading a copy of that data has no destructive side effect.
 */
class DataExportHandler
{
    public const ACTION = 'adoration_export_my_data';

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION,        [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        $person = MagicLinkService::current_person_or_admin_match();
        $person_id = (int)($person['id'] ?? 0);

        if ($person_id <= 0) {
            $url = add_query_arg([
                'as_toast'      => rawurlencode('Please sign in again to download your data.'),
                'as_toast_type' => 'error',
            ], home_url('/'));
            wp_safe_redirect($url);
            exit;
        }

        check_admin_referer(self::ACTION . '_' . $person_id);

        $standing_hours = [];
        if (class_exists(StandingCommitmentsRepository::class)) {
            $standing_repo = new StandingCommitmentsRepository();
            foreach ($standing_repo->list_for_person($person_id, true) as $c) {
                $standing_hours[] = [
                    'schedule'   => (string)($c['schedule_name'] ?? ''),
                    'day_of_week' => self::day_label((int)($c['day_of_week'] ?? -1)),
                    'start_time' => (string)($c['start_time'] ?? ''),
                    'end_time'   => (string)($c['end_time'] ?? ''),
                    'started_on' => (string)($c['started_on'] ?? ''),
                    'active'     => !empty($c['is_active']),
                ];
            }
        }

        $signups = [];
        $signups_repo = new SignupsRepository();
        foreach ($signups_repo->list_for_person($person_id, false) as $s) {
            $signups[] = [
                'date'       => (string)($s['date'] ?? ''),
                'start_time' => (string)($s['slot_start_time'] ?? ''),
                'end_time'   => (string)($s['slot_end_time'] ?? ''),
                'schedule'   => (string)($s['schedule_name'] ?? ''),
                'status'     => (string)($s['status'] ?? ''),
                'type'       => (string)($s['type'] ?? ''),
            ];
        }

        $data = [
            'exported_at' => gmdate('c'),
            'profile' => [
                'title'              => (string)($person['title'] ?? ''),
                'first_name'         => (string)($person['first_name'] ?? ''),
                'last_name'          => (string)($person['last_name'] ?? ''),
                'email'              => (string)($person['email'] ?? ''),
                'phone'              => (string)($person['phone'] ?? ''),
                'parish'             => (string)($person['parish'] ?? ''),
                'substitute_opt_in'  => !empty($person['substitute_opt_in']),
                'approval_status'    => (string)($person['approval_status'] ?? ''),
                'account_created_at' => (string)($person['created_at'] ?? ''),
            ],
            'standing_hours' => $standing_hours,
            'signups'        => $signups,
        ];

        $filename = 'my-adoration-data-' . gmdate('Y-m-d') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function day_label(int $dow): string
    {
        $labels = [
            0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
        ];
        return $labels[$dow] ?? '';
    }
}
