<?php
namespace AdorationScheduler\Public;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Services\PerpetualSlotGenerator;
use AdorationScheduler\Services\NotificationService;

if (!defined('ABSPATH')) exit;

/**
 * StandingSignupHandler
 *
 * Public, self-service equivalent of the admin "assign an adorer to an hour"
 * action on the Standing Commitments tab. Lets a parishioner claim an open
 * weekly hour on a perpetual schedule as their own recurring commitment,
 * straight from the public schedule shortcode — previously this was
 * admin-only.
 *
 * Reuses SignupHandler's anti-spam/rate-limit/redirect plumbing (promoted to
 * public static there) rather than duplicating it.
 *
 * SECURITY: day_of_week/start_time are validated against the schedule's real,
 * server-side weekly pattern (PerpetualSlotGenerator::describe_weekly_pattern())
 * before anything is created — the end_time is always taken from that source,
 * never trusted from the client.
 */
class StandingSignupHandler {

    public static function register(): void {
        add_action('admin_post_nopriv_adoration_public_claim_standing', [self::class, 'handle']);
        add_action('admin_post_adoration_public_claim_standing', [self::class, 'handle']);
    }

    public static function handle(): void {
        $nonce = isset($_POST['adoration_public_nonce_standing'])
            ? (string) wp_unslash($_POST['adoration_public_nonce_standing'])
            : '';

        if ($nonce === '' || !wp_verify_nonce($nonce, 'adoration_public_claim_standing')) {
            SignupHandler::redirect_back('err', 'Security check failed. Please try again.');
        }

        SignupHandler::validate_honeypot();
        SignupHandler::verify_turnstile_or_bail();
        SignupHandler::rate_limit_by_ip();

        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $day_of_week = isset($_POST['day_of_week']) ? (int) wp_unslash($_POST['day_of_week']) : -1;
        $start_time_in = sanitize_text_field(wp_unslash($_POST['start_time'] ?? ''));

        if ($schedule_id <= 0 || $day_of_week < 0 || $day_of_week > 6 || $start_time_in === '') {
            SignupHandler::redirect_back('err', 'Missing schedule or weekly hour.');
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone_raw = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $phone = SignupHandler::normalize_phone_us($phone_raw);

        if ($first === '' || $last === '' || $email === '' || $phone_raw === '') {
            SignupHandler::redirect_back('err', 'All fields are required.');
        }
        if (!is_email($email)) {
            SignupHandler::redirect_back('err', 'Please enter a valid email address.');
        }
        if ($phone === null) {
            SignupHandler::redirect_back('err', 'Please enter a valid US phone number (10 digits).');
        }

        $schedulesRepo = new SchedulesRepository();
        $schedule = $schedulesRepo->find($schedule_id);

        if (!$schedule || (($schedule['status'] ?? 'draft') !== 'active')) {
            SignupHandler::redirect_back('err', 'That schedule is not available.');
        }
        if ((string)($schedule['type'] ?? 'event') !== 'perpetual') {
            SignupHandler::redirect_back('err', 'That schedule does not support weekly commitments.');
        }

        // ✅ Never trust client-supplied end_time — look up the real weekly pattern
        // and only accept a start_time that's actually configured for this day.
        $dateRepo        = new DatePatternsRepository();
        $segmentsRepo    = new SegmentsRepository();
        $slotsRepo       = new SlotsRepository();
        $commitmentsRepo = new StandingCommitmentsRepository();
        $signupsRepo     = new SignupsRepository();

        $perpGenerator = new PerpetualSlotGenerator($dateRepo, $segmentsRepo, $slotsRepo, $commitmentsRepo, $signupsRepo);
        $pattern = $perpGenerator->describe_weekly_pattern($schedule);

        $day_options = $pattern[$day_of_week] ?? [];
        $matched = null;
        foreach ($day_options as $opt) {
            if ((string)($opt['start_time'] ?? '') === $start_time_in) {
                $matched = $opt;
                break;
            }
        }

        if ($matched === null) {
            SignupHandler::redirect_back('err', 'That hour is no longer offered. Please refresh the page and try again.');
        }

        $start_time = (string)$matched['start_time'];
        $end_time   = (string)$matched['end_time'];

        // Capacity: how many people already hold this weekly hour vs. the
        // schedule's configured default_max_adorers (null = unlimited).
        $default_max = ($schedule['default_max_adorers'] ?? '') !== '' && $schedule['default_max_adorers'] !== null
            ? (int)$schedule['default_max_adorers']
            : null;

        if ($default_max !== null) {
            $current = $commitmentsRepo->count_active_for_day_time($schedule_id, $day_of_week, $start_time);
            if ($current >= $default_max) {
                SignupHandler::redirect_back('err', 'That weekly hour is already fully committed. Please choose another, or cover a single date instead.');
            }
        }

        $email_norm = strtolower(trim($email));
        SignupHandler::rate_limit_by_email($email_norm);

        $personsRepo = new PersonsRepository();

        // Name/email consistency check if person exists (mirrors SignupHandler).
        $existing = $personsRepo->find_by_email($email_norm);
        if ($existing) {
            $ex_first = trim((string)($existing['first_name'] ?? ''));
            $ex_last  = trim((string)($existing['last_name'] ?? ''));

            $first_conflict = ($ex_first !== '' && strcasecmp($ex_first, $first) !== 0);
            $last_conflict  = ($ex_last !== '' && strcasecmp($ex_last, $last) !== 0);

            if ($first_conflict || $last_conflict) {
                $display = method_exists($personsRepo, 'display_name_for_person')
                    ? $personsRepo->display_name_for_person($existing)
                    : trim($ex_first . ' ' . $ex_last);

                if ($display === '') $display = 'an existing adorer';
                SignupHandler::redirect_back('err', "That email address is already used by {$display}. Please use the correct email.");
            }
        }

        $person_id = $personsRepo->upsert_by_email([
            'title'      => $title,
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email_norm,
            'phone'      => $phone,
        ]);

        if ($person_id <= 0) {
            SignupHandler::redirect_back('err', 'Could not save your contact info. Please double-check and try again.');
        }

        $commitment_id = $commitmentsRepo->create([
            'schedule_id' => $schedule_id,
            'chapel_id'   => (int)($schedule['chapel_id'] ?? 0),
            'person_id'   => $person_id,
            'day_of_week' => $day_of_week,
            'start_time'  => $start_time,
            'end_time'    => $end_time,
        ]);

        if (!$commitment_id) {
            SignupHandler::redirect_back('err', 'Could not save your weekly commitment. It may already be taken — please refresh and try again.');
        }

        // Immediately fill already-generated dates in the rolling window for this
        // hour, same as the admin-side flow.
        try {
            $days_ahead = (int)($schedule['rolling_window_days'] ?? 60);
            if ($days_ahead <= 0) $days_ahead = 60;
            $perpGenerator->sync_window($schedule, $days_ahead);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Standing signup sync_window failed: ' . $e->getMessage());
        }

        // Best-effort confirmation email (never blocks the redirect).
        try {
            $day_labels = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            $day_label = $day_labels[$day_of_week] ?? '';
            $schedule_title = trim((string)($schedule['name'] ?? 'Adoration'));
            $manage_url = home_url('/my-adoration/');

            NotificationService::send_signup_confirmation([
                'to_email'       => $email_norm,
                'first_name'     => $first,
                'last_name'      => $last,
                'person_name'    => trim($first . ' ' . $last),
                'schedule_title' => $schedule_title,
                'schedule_name'  => $schedule_title,
                'slot_date'      => '',
                'slot_start'     => $start_time,
                'slot_end'       => $end_time,
                'slot_label'     => 'Every ' . $day_label . ', ' . $matched['label'],
                'manage_url'     => $manage_url,
                'context'        => 'public_standing',
                'send'           => true,
                'signup_id'      => 0,
                'person_id'      => (int)$person_id,
            ]);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Standing signup confirmation email failed: ' . $e->getMessage());
        }

        SignupHandler::redirect_back('ok', 'You\'re all set! That hour is now your standing weekly commitment. Thank you!');
    }
}
