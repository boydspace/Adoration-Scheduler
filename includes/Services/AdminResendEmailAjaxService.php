<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SignupAuditRepository;

class AdminResendEmailAjaxService
{
    /**
     * Must match the nonce used in SignupsPage JS:
     * wp_create_nonce('as_signup_resend')
     */
    private const NONCE_RESEND = 'as_signup_resend';

    public static function register(): void
    {
        add_action('wp_ajax_adoration_signup_resend', [__CLASS__, 'handle']);

        // Debug: confirm registration
        error_log('[AdorationScheduler] AdminResendEmailAjaxService registered wp_ajax_adoration_signup_resend');
    }

    private static function audit_log(int $signup_id, string $event_type, array $meta = []): void
    {
        if ($signup_id <= 0) return;
        if (!class_exists(SignupAuditRepository::class)) return;

        try {
            $repo = new SignupAuditRepository();

            $actor_user_id = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
            if ($actor_user_id <= 0) $actor_user_id = null;

            $actor_label = null;
            if ($actor_user_id && method_exists($repo, 'build_actor_label')) {
                $actor_label = $repo->build_actor_label((int)$actor_user_id);
            }

            $repo->log((int)$signup_id, (string)$event_type, is_array($meta) ? $meta : [], $actor_user_id, $actor_label);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Try to send using whatever API your NotificationService actually exposes.
     * This prevents “it silently does nothing” when method names drift.
     */
    private static function send_via_notification_service(string $email_type, array $args): bool
    {
        $cls = '\\AdorationScheduler\\Services\\NotificationService';

        if (!class_exists($cls)) {
            error_log('[AdorationScheduler] NotificationService class missing');
            return false;
        }

        // Map type -> candidate method names (static)
        $method_candidates = [
            'signup_confirmation' => ['send_signup_confirmation', 'sendSignupConfirmation', 'send_signup_confirm', 'sendSignupConfirm'],
            'reminder_24h'        => ['send_reminder_24h', 'sendReminder24h', 'send_reminder', 'sendReminder'],
            'magic_link'          => ['send_magic_link', 'sendMagicLink', 'send_magic', 'sendMagic'],
        ];

        $candidates = $method_candidates[$email_type] ?? [];

        foreach ($candidates as $m) {
            if (method_exists($cls, $m)) {
                try {
                    $ok = (bool) $cls::$m($args);
                    error_log('[AdorationScheduler] Resend used NotificationService::' . $m . '() -> ' . ($ok ? 'true' : 'false'));
                    return $ok;
                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] NotificationService::' . $m . ' threw: ' . $e->getMessage());
                    return false;
                }
            }
        }

        // Generic fallback methods some setups use
        $generic = ['send_template', 'sendTemplate', 'send', 'dispatch'];
        foreach ($generic as $m) {
            if (method_exists($cls, $m)) {
                try {
                    // Try (template_key, args) then (args)
                    $ref = new \ReflectionMethod($cls, $m);
                    $n = $ref->getNumberOfParameters();

                    if ($n >= 2) {
                        $ok = (bool) $cls::$m($email_type, $args);
                    } else {
                        $args2 = $args;
                        $args2['email_type'] = $email_type;
                        $ok = (bool) $cls::$m($args2);
                    }

                    error_log('[AdorationScheduler] Resend used NotificationService::' . $m . '() -> ' . ($ok ? 'true' : 'false'));
                    return $ok;

                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] NotificationService::' . $m . ' threw: ' . $e->getMessage());
                    return false;
                }
            }
        }

        error_log('[AdorationScheduler] No usable NotificationService method found for email_type=' . $email_type);
        return false;
    }

    /**
     * Generate a unique 24-char selector for adoration_magic_links.selector (UNIQUE, NOT NULL).
     */
    private static function generate_unique_selector(string $table_name): string
    {
        global $wpdb;

        // Extremely unlikely to collide, but we still check a few times.
        for ($i = 0; $i < 6; $i++) {
            $selector = substr(bin2hex(random_bytes(12)), 0, 24); // 24 chars
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table_name} WHERE selector = %s LIMIT 1", $selector)
            );
            if (empty($exists)) {
                return $selector;
            }
        }

        // Last resort: time-mixed selector (still 24 chars)
        return substr(hash('sha256', microtime(true) . '|' . wp_generate_password(12, false)), 0, 24);
    }

    public static function handle(): void
    {
        // Debug: confirm handler is being hit
        error_log('[AdorationScheduler] AdminResendEmailAjaxService::handle fired');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer(self::NONCE_RESEND);

        $signup_id  = isset($_POST['signup_id']) ? (int)$_POST['signup_id'] : 0;
        $email_type = isset($_POST['email_type']) ? sanitize_key((string)wp_unslash($_POST['email_type'])) : '';

        if ($signup_id <= 0) {
            wp_send_json_error(['message' => 'Invalid signup_id'], 400);
        }

        if (!in_array($email_type, ['signup_confirmation','reminder_24h','magic_link'], true)) {
            wp_send_json_error(['message' => 'Invalid email_type'], 400);
        }

        global $wpdb;

        $t_signups   = $wpdb->prefix . 'adoration_signups';
        $t_persons   = $wpdb->prefix . 'adoration_persons';
        $t_slots     = $wpdb->prefix . 'adoration_slots';
        $t_schedules = $wpdb->prefix . 'adoration_schedules';

        $sql = "
            SELECT
                su.id,
                su.status,
                su.is_active,
                su.person_id,
                su.slot_id,
                su.schedule_id,
                su.created_at,
                TRIM(CONCAT(TRIM(COALESCE(p.first_name,'')), ' ', TRIM(COALESCE(p.last_name,'')))) AS person_name,
                p.first_name AS first_name,
                p.last_name AS last_name,
                p.email AS person_email,
                sc.name AS schedule_name,
                sl.date AS slot_date,
                sl.start_time AS slot_start,
                sl.end_time AS slot_end,
                CASE
                    WHEN sl.start_at IS NOT NULL AND sl.start_at <> '0000-00-00 00:00:00'
                        THEN DATE_FORMAT(sl.start_at, '%%Y-%%m-%%d %%H:%%i')
                    ELSE CONCAT(sl.date, ' ', LEFT(sl.start_time,5))
                END AS slot_label
            FROM {$t_signups} su
            LEFT JOIN {$t_persons} p ON p.id = su.person_id
            LEFT JOIN {$t_schedules} sc ON sc.id = su.schedule_id
            LEFT JOIN {$t_slots} sl ON sl.id = su.slot_id
            WHERE su.id = %d
            LIMIT 1
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row($wpdb->prepare($sql, $signup_id), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Signup not found'], 404);
        }

        $to = trim((string)($row['person_email'] ?? ''));
        if ($to === '' || !is_email($to)) {
            wp_send_json_error(['message' => 'Signup has no valid email address'], 400);
        }

        $first = trim((string)($row['first_name'] ?? ''));
        $last  = trim((string)($row['last_name'] ?? ''));
        $person_name = trim((string)($row['person_name'] ?? ''));
        if ($person_name === '') $person_name = trim($first . ' ' . $last);

        $schedule_name = trim((string)($row['schedule_name'] ?? 'Adoration'));
        $slot_date  = trim((string)($row['slot_date'] ?? ''));
        $slot_start = trim((string)($row['slot_start'] ?? ''));
        $slot_end   = trim((string)($row['slot_end'] ?? ''));
        $slot_label = trim((string)($row['slot_label'] ?? ''));

        $args = [
            'to_email'       => $to,
            'first_name'     => $first,
            'last_name'      => $last,
            'person_name'    => $person_name,
            'schedule_title' => $schedule_name,
            'schedule_name'  => $schedule_name,
            'slot_date'      => $slot_date,
            'slot_start'     => $slot_start,
            'slot_end'       => $slot_end,
            'slot_label'     => $slot_label,
            'context'        => 'admin',
            'send'           => true,
            'signup_id'      => (int)($row['id'] ?? 0),
            'person_id'      => (int)($row['person_id'] ?? 0),
            'schedule_id'    => (int)($row['schedule_id'] ?? 0),
            'slot_id'        => (int)($row['slot_id'] ?? 0),

            // Force resend (if your NotificationService honors it)
            'dedupe_key'     => 'admin_resend:' . $email_type . ':' . (int)$signup_id . ':' . microtime(true),
            'dedupe_ttl'     => 0,
        ];

        // Special: magic_link requires token/url creation.
        if ($email_type === 'magic_link') {
            $person_id = (int)($row['person_id'] ?? 0);
            if ($person_id <= 0) {
                self::audit_log($signup_id, 'admin_resend_email', [
                    'email_type' => $email_type,
                    'to_email'   => $to,
                    'context'    => 'admin_ajax',
                    'success'    => false,
                    'error'      => 'Missing person_id for this signup',
                ]);
                wp_send_json_error(['message' => 'Missing person_id for this signup'], 400);
            }

            $t_magic = $wpdb->prefix . 'adoration_magic_links';

            $raw_token  = wp_generate_password(32, false, false);
            $token_hash = hash_hmac('sha256', (string)$raw_token, wp_salt('auth'));

            $now     = gmdate('Y-m-d H:i:s');
            $expires = gmdate('Y-m-d H:i:s', time() + (60 * 15));

            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
            if ($ip === '') $ip = '0.0.0.0';

            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : null;
            if (is_string($ua) && strlen($ua) > 255) $ua = substr($ua, 0, 255);

            // Invalidate old unused links for this person (best-effort)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_magic} SET used_at = %s WHERE person_id = %d AND used_at IS NULL",
                $now,
                $person_id
            ));

            // ✅ REQUIRED BY SCHEMA: selector (UNIQUE + NOT NULL)
            $selector = self::generate_unique_selector($t_magic);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $ins = $wpdb->insert($t_magic, [
                'person_id'   => $person_id,
                'selector'    => $selector,
                'token_hash'  => $token_hash,
                'expires_at'  => $expires,
                'used_at'     => null,
                'request_ip'  => $ip,
                'user_agent'  => $ua,
                // keep legacy columns if present in your table:
                'ip'          => $ip,
                'created_at'  => $now,
            ], [
                '%d','%s','%s','%s', null, '%s','%s','%s','%s'
            ]);

            if ($ins === false) {
                self::audit_log($signup_id, 'admin_resend_email', [
                    'email_type' => $email_type,
                    'to_email'   => $to,
                    'context'    => 'admin_ajax',
                    'success'    => false,
                    'error'      => 'Failed to create magic link: ' . $wpdb->last_error,
                ]);
                wp_send_json_error(['message' => 'Failed to create magic link: ' . $wpdb->last_error], 500);
            }

            $r = home_url('/my-adoration/');
            $consume_url = add_query_arg([
                'action' => 'adoration_magic_consume',
                // IMPORTANT: consume uses token_hash only in your current implementation, not selector
                't'      => $raw_token,
                'r'      => $r,
            ], admin_url('admin-post.php'));

            $args['token']      = $raw_token;
            $args['magic_url']  = $consume_url;
            $args['manage_url'] = $consume_url;
            // optionally expose selector if templates ever want it:
            $args['selector']   = $selector;
        }

        $ok = self::send_via_notification_service($email_type, $args);

        self::audit_log($signup_id, 'admin_resend_email', [
            'email_type' => $email_type,
            'to_email'   => $to,
            'context'    => 'admin_ajax',
            'success'    => (bool)$ok,
        ]);

        if (!$ok) {
            wp_send_json_error([
                'message' => 'Resend failed. Check debug.log for NotificationService method mismatch or mail failure.',
            ], 500);
        }

        $msg_map = [
            'signup_confirmation' => __('Confirmation email sent.', 'adoration-scheduler'),
            'reminder_24h'        => __('24h reminder email sent.', 'adoration-scheduler'),
            'magic_link'          => __('Magic link email sent.', 'adoration-scheduler'),
        ];

        wp_send_json_success(['message' => $msg_map[$email_type] ?? __('Email sent.', 'adoration-scheduler')]);
    }
}
