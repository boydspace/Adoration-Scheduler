<?php
namespace AdorationScheduler\Public;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\WaitlistRepository;
use AdorationScheduler\Services\NotificationService;
use AdorationScheduler\Services\ReminderScheduler;

if (!defined('ABSPATH')) exit;

class SignupHandler {

    /**
     * Must match AntiSpamSettingsPage::OPTION_NAME
     */
    private const OPT_ANTISPAM_OPTIONS = 'adoration_scheduler_antispam_options';

    public static function register(): void {
        add_action('admin_post_nopriv_adoration_public_signup', [self::class, 'handle']);
        add_action('admin_post_adoration_public_signup', [self::class, 'handle']);
    }

    private static function get_antispam_options(): array {
        $defaults = [
            'turnstile_enabled'    => 0,
            'turnstile_site_key'   => '',
            'turnstile_secret_key' => '',
        ];

        $saved = get_option(self::OPT_ANTISPAM_OPTIONS, []);
        $saved = is_array($saved) ? $saved : [];

        return wp_parse_args($saved, $defaults);
    }

    private static function turnstile_enabled(): bool {
        $o = self::get_antispam_options();
        return !empty($o['turnstile_enabled']);
    }

    /**
     * Best-effort client IP (Cloudflare / proxies / direct).
     */
    private static function get_client_ip(): string {
        $candidates = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) continue;

            $raw = (string) $_SERVER[$key];

            // X_FORWARDED_FOR may contain: client, proxy1, proxy2...
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = array_map('trim', explode(',', $raw));
                $raw = (string)($parts[0] ?? '');
            }

            $ip = trim($raw);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Honeypot + timing defense.
     * Must match fields included in ScheduleShortcode.php form.
     */
    public static function validate_honeypot(): void {
        $hp_name = 'as_website';
        $ts_name = 'as_form_ts';

        // 1) Honeypot must be empty
        $hp_value = isset($_POST[$hp_name]) ? trim((string) wp_unslash($_POST[$hp_name])) : '';
        if ($hp_value !== '') {
            self::redirect_back('err', 'Please try again.');
        }

        // 2) Minimum submit time
        $min_seconds = 3;

        $ts_value = isset($_POST[$ts_name]) ? (int) $_POST[$ts_name] : 0;
        if ($ts_value <= 0) {
            self::redirect_back('err', 'Please try again.');
        }

        if ((time() - $ts_value) < $min_seconds) {
            self::redirect_back('err', 'Please try again.');
        }
    }

    /**
     * Turnstile token extraction (future-proof).
     */
    private static function get_turnstile_token(): string {
        $keys = [
            'cf-turnstile-response', // standard
            'turnstile_response',    // fallback
            'turnstile-token',       // fallback
        ];

        foreach ($keys as $k) {
            if (!empty($_POST[$k])) {
                return trim((string) wp_unslash($_POST[$k]));
            }
        }
        return '';
    }

    /**
     * Verify Turnstile token server-side (only if enabled).
     */
    public static function verify_turnstile_or_bail(): void {
        if (!self::turnstile_enabled()) {
            return;
        }

        $o = self::get_antispam_options();
        $secret = trim((string)($o['turnstile_secret_key'] ?? ''));

        if ($secret === '') {
            self::redirect_back('err', 'Anti-spam is enabled but not configured. Please contact the administrator.');
        }

        $token = self::get_turnstile_token();
        if ($token === '') {
            self::redirect_back('err', 'Please complete the anti-spam check and try again.');
        }

        $ip = self::get_client_ip();
        $body = [
            'secret'   => $secret,
            'response' => $token,
        ];

        if ($ip !== '' && $ip !== '0.0.0.0') {
            $body['remoteip'] = $ip;
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            error_log('[AdorationScheduler] Turnstile verify request error: ' . $response->get_error_message());
            self::redirect_back('err', 'Anti-spam verification failed. Please try again.');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            error_log('[AdorationScheduler] Turnstile verify HTTP ' . $code . ' body=' . $raw);
            self::redirect_back('err', 'Anti-spam verification failed. Please try again.');
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['success'])) {
            $errs = '';
            if (is_array($json) && !empty($json['error-codes']) && is_array($json['error-codes'])) {
                $errs = implode(',', $json['error-codes']);
            }
            error_log('[AdorationScheduler] Turnstile verify failed. errors=' . $errs . ' body=' . $raw);
            self::redirect_back('err', 'Anti-spam verification failed. Please try again.');
        }
    }

    /**
     * Generic rate limiter using transients.
     */
    private static function rate_limit_generic(string $bucket_key, int $window_seconds, int $max_attempts, int $block_seconds): void {
        $now  = time();
        $data = get_transient($bucket_key);

        if (!is_array($data)) {
            $data = [
                'start'         => $now,
                'count'         => 0,
                'blocked_until' => 0,
            ];
        }

        $blocked_until = (int)($data['blocked_until'] ?? 0);
        if ($blocked_until > $now) {
            self::redirect_back('err', 'Too many attempts. Please wait a few minutes and try again.');
        }

        $start = (int)($data['start'] ?? $now);
        if (($now - $start) > $window_seconds) {
            $data['start'] = $now;
            $data['count'] = 0;
        }

        $data['count'] = (int)($data['count'] ?? 0) + 1;

        if ($data['count'] > $max_attempts) {
            $data['blocked_until'] = $now + $block_seconds;
        }

        $ttl = max($window_seconds, $block_seconds) + 60;
        set_transient($bucket_key, $data, $ttl);

        if ((int)($data['blocked_until'] ?? 0) > $now) {
            self::redirect_back('err', 'Too many attempts. Please wait a few minutes and try again.');
        }
    }

    public static function rate_limit_by_ip(): void {
        $ip = self::get_client_ip();

        $window_seconds = 10 * 60;
        $max_attempts   = 8;
        $block_seconds  = 15 * 60;

        $key = 'as_rl_ip_' . substr(wp_hash($ip), 0, 20);

        self::rate_limit_generic($key, $window_seconds, $max_attempts, $block_seconds);
    }

    public static function rate_limit_by_email(string $email_norm): void {
        $window_seconds = 30 * 60;
        $max_attempts   = 4;
        $block_seconds  = 60 * 60;

        $key = 'as_rl_em_' . substr(wp_hash($email_norm), 0, 20);

        self::rate_limit_generic($key, $window_seconds, $max_attempts, $block_seconds);
    }

    public static function normalize_phone_us(?string $raw): ?string {
        $raw = (string)($raw ?? '');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) return null;

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) return null;

        $a = substr($digits, 0, 3);
        $b = substr($digits, 3, 3);
        $c = substr($digits, 6, 4);

        return sprintf('(%s) %s-%s', $a, $b, $c);
    }

    /**
     * Redirect back with message (NEW METHOD ONLY).
     *
     * Uses:
     *   ?as_toast=...&as_toast_type=success|error|warning|info&as_toast_sticky=1
     *
     * @param string $msg   Accepts 'ok'/'err' (legacy callers) OR toast types: success|error|warning|info
     */
    public static function redirect_back(string $msg, string $text = '', bool $sticky = false): void {
        $ref = wp_get_referer();
        $url = $ref ? $ref : home_url('/');

        // Strip existing so refresh/back doesn't stack messages.
        $url = remove_query_arg(['as_toast','as_toast_type','as_toast_sticky'], $url);

        $text = sanitize_text_field(wp_unslash($text));
        $msg  = sanitize_key($msg);

        // Map ok/err into toast types (so existing callers don't need edits)
        if ($msg === 'ok') {
            $toast_type = 'success';
        } elseif ($msg === 'err') {
            $toast_type = 'error';
        } else {
            $toast_type = in_array($msg, ['success','error','warning','info'], true) ? $msg : 'info';
        }

        // Only add toast params if we actually have text
        if ($text !== '') {
            // ✅ add_query_arg() does NOT urlencode new values (common WP
            // gotcha) — without this, punctuation like apostrophes rides
            // raw into the redirect URL and can get silently mangled/
            // stripped by browsers or security layers along the way.
            $url = add_query_arg([
                'as_toast'        => rawurlencode($text),
                'as_toast_type'   => $toast_type,
                'as_toast_sticky' => $sticky ? '1' : '0',
            ], $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * For graceful "already signed up" UX.
     */
    private static function redirect_ok_already_signed_up(): void {
        self::redirect_back('ok', 'You are already signed up for that time. Thank you!');
    }

    public static function handle(): void {
        $nonce = isset($_POST['adoration_public_nonce'])
            ? (string) wp_unslash($_POST['adoration_public_nonce'])
            : '';

        if ($nonce === '' || !wp_verify_nonce($nonce, 'adoration_public_signup')) {
            self::redirect_back('err', 'Security check failed. Please try again.');
        }

        self::validate_honeypot();
        self::verify_turnstile_or_bail();
        self::rate_limit_by_ip();

        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $slot_id     = (int)($_POST['slot_id'] ?? 0);

        if ($schedule_id <= 0 || $slot_id <= 0) {
            self::redirect_back('err', 'Missing schedule or slot.');
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone_raw = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $phone = self::normalize_phone_us($phone_raw);

        if ($first === '' || $last === '' || $email === '' || $phone_raw === '') {
            self::redirect_back('err', 'All fields are required.');
        }
        if (!is_email($email)) {
            self::redirect_back('err', 'Please enter a valid email address.');
        }
        if ($phone === null) {
            self::redirect_back('err', 'Please enter a valid US phone number (10 digits).');
        }

        $email_norm = strtolower(trim($email));
        self::rate_limit_by_email($email_norm);

        $schedulesRepo = new SchedulesRepository();
        $slotsRepo     = new SlotsRepository();
        $personsRepo   = new PersonsRepository();
        $signupsRepo   = new SignupsRepository();

        $schedule = $schedulesRepo->find($schedule_id);
        if (!$schedule || (($schedule['status'] ?? 'draft') !== 'active')) {
            self::redirect_back('err', 'That schedule is not available.');
        }

        $slot = $slotsRepo->find($slot_id);
        if (!$slot || (int)($slot['schedule_id'] ?? 0) !== $schedule_id) {
            self::redirect_back('err', 'Invalid time slot.');
        }
        if ((int)($slot['is_active'] ?? 0) !== 1) {
            self::redirect_back('err', 'That time slot is not available.');
        }

        $signup_date = sanitize_text_field((string)($slot['date'] ?? ''));
        if ($signup_date === '') {
            self::redirect_back('err', 'That time slot is missing a date. Please contact the administrator.');
        }

        // Name/email consistency check if person exists
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
                self::redirect_back('err', "That email address is already used by {$display}. Please use the correct email.");
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
            self::redirect_back('err', 'Could not save your contact info. Please double-check and try again.');
        }

        /**
         * DUPLICATE + CAPACITY HARDENING
         */
        global $wpdb;
        $signups_table = $wpdb->prefix . 'adoration_signups';
        $slots_table   = $wpdb->prefix . 'adoration_slots';

        $now_gmt = gmdate('Y-m-d H:i:s');
        $signup_id = 0;

        // Detect is_active column (for backwards compatibility)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $has_is_active = (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$signups_table} LIKE %s", 'is_active')
        );

        // Start transaction
        $wpdb->query('START TRANSACTION');

        // Lock slot row
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $slot_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, max_adorers
             FROM {$slots_table}
             WHERE id = %d
             LIMIT 1
             FOR UPDATE",
            $slot_id
        ), ARRAY_A);

        if (!is_array($slot_row) || empty($slot_row['id'])) {
            $wpdb->query('ROLLBACK');
            self::redirect_back('err', 'That time slot is not available.');
        }

        $max = ($slot_row['max_adorers'] !== null && $slot_row['max_adorers'] !== '') ? (int)$slot_row['max_adorers'] : null;

        if ($max !== null) {
            // Lock + count confirmed actives (if column exists)
            if ($has_is_active) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
                $confirmed = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$signups_table}
                     WHERE slot_id = %d AND date = %s AND status = %s AND is_active = 1
                     FOR UPDATE",
                    $slot_id,
                    $signup_date,
                    'confirmed'
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
                $confirmed = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$signups_table}
                     WHERE slot_id = %d AND date = %s AND status = %s
                     FOR UPDATE",
                    $slot_id,
                    $signup_date,
                    'confirmed'
                ));
            }

            if ($confirmed >= $max) {
                $join_waitlist = isset($_POST['join_waitlist']) && (string) wp_unslash($_POST['join_waitlist']) === '1';

                // Nothing was written to the signups table yet in this
                // transaction, so it's safe to just release the lock and
                // hand off to the (separate-table) waitlist instead.
                $wpdb->query('COMMIT');

                if (!$join_waitlist) {
                    self::redirect_back('err', 'That slot is full. You can join the waitlist and we\'ll email you if a spot opens up.');
                }

                $waitlist_repo = new WaitlistRepository();
                $waitlist_id = $waitlist_repo->join((int)$person_id, $schedule_id, $slot_id, $signup_date);

                if ($waitlist_id <= 0) {
                    self::redirect_back('err', 'Could not join the waitlist. Please try again.');
                }

                $position = $waitlist_repo->position_in_line($waitlist_id);

                try {
                    NotificationService::send_waitlist_joined([
                        'to_email'       => $email_norm,
                        'first_name'     => $first,
                        'last_name'      => $last,
                        'person_name'    => trim($first . ' ' . $last),
                        'schedule_title' => trim((string)($schedule['name'] ?? $schedule['title'] ?? 'Adoration')),
                        'schedule_name'  => trim((string)($schedule['name'] ?? $schedule['title'] ?? 'Adoration')),
                        'slot_date'      => $signup_date,
                        'slot_start'     => trim((string)($slot['start_time'] ?? $slot['start'] ?? '')),
                        'slot_end'       => trim((string)($slot['end_time'] ?? $slot['end'] ?? '')),
                        'position'       => $position,
                        'manage_url'     => home_url('/my-adoration/'),
                        'send'           => true,
                    ]);
                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] Waitlist joined email exception: ' . $e->getMessage());
                }

                self::redirect_back('ok', "You're #{$position} on the waitlist. We'll email you if a spot opens up.");
            }
        }

        /**
         * ✅ REAL FIX:
         * Under UNIQUE(person_id, slot_id, is_active), re-signup MUST reactivate a cancelled/inactive row
         * instead of creating a second row (which later breaks cancellation).
         *
         * We check for ANY existing row for this person+slot and lock it.
         */
        $existing_sql = "
            SELECT id, status" . ($has_is_active ? ", is_active" : "") . "
            FROM {$signups_table}
            WHERE person_id = %d
              AND slot_id = %d
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $existing_row = $wpdb->get_row($wpdb->prepare(
            $existing_sql,
            (int)$person_id,
            (int)$slot_id
        ), ARRAY_A);

        if (is_array($existing_row) && !empty($existing_row['id'])) {
            $ex_id     = (int)$existing_row['id'];
            $ex_status = strtolower(trim((string)($existing_row['status'] ?? '')));
            $ex_active = $has_is_active ? (int)($existing_row['is_active'] ?? 1) : 1;

            // If already active/confirmed-ish -> already signed up
            if (($has_is_active && $ex_active === 1 && $ex_status !== 'cancelled')
                || (!$has_is_active && $ex_status !== 'cancelled')) {
                $wpdb->query('ROLLBACK');
                self::redirect_ok_already_signed_up();
            }

            // Otherwise reactivate THIS row
            $update_data = [
                'schedule_id' => (int)$schedule_id,
                'date'        => $signup_date,
                'type'        => 'one_time',
                'status'      => 'confirmed',
                'created_via' => 'public_form',
                'updated_at'  => $now_gmt,
            ];
            $update_fmt  = ['%d','%s','%s','%s','%s','%s'];

            if ($has_is_active) {
                $update_data['is_active'] = 1;
                $update_fmt[] = '%d';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $updated = $wpdb->update(
                $signups_table,
                $update_data,
                ['id' => $ex_id],
                $update_fmt,
                ['%d']
            );

            if ($updated === false) {
                error_log('[AdorationScheduler] Reactivate signup failed signup_id=' . $ex_id . ' err=' . $wpdb->last_error);
                $wpdb->query('ROLLBACK');
                self::redirect_back('err', 'Could not save your signup. Please try again.');
            }

            $signup_id = $ex_id;
            $wpdb->query('COMMIT');
            error_log('[AdorationScheduler] Public signup reactivated signup_id=' . $signup_id);

        } else {
            // No existing row for this person+slot -> insert new
            $insert_data = [
                'person_id'   => (int)$person_id,
                'schedule_id' => (int)$schedule_id,
                'slot_id'     => (int)$slot_id,
                'date'        => $signup_date,
                'type'        => 'one_time',
                'status'      => 'confirmed',
                'created_via' => 'public_form',
                'created_at'  => $now_gmt,
                'updated_at'  => $now_gmt,
            ];
            $insert_fmt = ['%d','%d','%d','%s','%s','%s','%s','%s','%s'];

            if ($has_is_active) {
                $insert_data['is_active'] = 1;
                $insert_fmt[] = '%d';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $insert_ok = $wpdb->insert($signups_table, $insert_data, $insert_fmt);

            if ($insert_ok === false) {
                error_log('[AdorationScheduler] Signup insert failed: ' . (string)$wpdb->last_error);
                $wpdb->query('ROLLBACK');
                self::redirect_back('err', 'Could not save your signup. Please try again.');
            }

            $signup_id = (int)$wpdb->insert_id;
            $wpdb->query('COMMIT');
            error_log('[AdorationScheduler] Public signup inserted signup_id=' . $signup_id);
        }

        // --- Send confirmation email + schedule reminder (best-effort) --------
        if ($signup_id <= 0) {
            self::redirect_back('err', 'Could not save your signup. Please try again.');
        }

        $schedule_title = trim((string)($schedule['name'] ?? $schedule['title'] ?? 'Adoration'));

        $start = trim((string)($slot['start_time'] ?? $slot['start'] ?? ''));
        $end   = trim((string)($slot['end_time'] ?? $slot['end'] ?? ''));

        $slot_label = trim($signup_date . ' ' . $start);
        if ($end !== '') $slot_label .= '–' . $end;

        $person_name = trim($first . ' ' . $last);

        $manage_url = home_url('/my-adoration/');

        try {
            $sent = NotificationService::send_signup_confirmation([
                'to_email'       => $email_norm,
                'first_name'     => $first,
                'last_name'      => $last,
                'person_name'    => $person_name,
                'schedule_title' => $schedule_title,
                'schedule_name'  => $schedule_title,
                'slot_date'      => $signup_date,
                'slot_start'     => $start,
                'slot_end'       => $end,
                'slot_label'     => $slot_label,
                'manage_url'     => $manage_url,
                'context'        => 'public',
                'send'           => true,
                'signup_id'      => $signup_id,
                'person_id'      => (int)$person_id,
            ]);

            error_log('[AdorationScheduler] Public signup confirmation email signup_id=' . $signup_id . ' sent=' . ($sent ? 'YES' : 'NO'));
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Public signup email exception: ' . $e->getMessage());
        }

        try {
            $rs = new ReminderScheduler();
            $rs->schedule_24h($signup_id);
            error_log('[AdorationScheduler] ReminderScheduler scheduled 24h reminder signup_id=' . $signup_id);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] ReminderScheduler exception: ' . $e->getMessage());
        }

        self::redirect_back('ok', 'You are signed up. Thank you!');
    }
}
