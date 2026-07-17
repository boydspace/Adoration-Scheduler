<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\EmailLogRepository;

class NotificationService
{
    /**
     * GLOBAL templates/settings option (EmailTemplatesPage).
     */
    private const OPT_EMAIL_TEMPLATES = 'adoration_scheduler_email_templates';

    /**
     * Default dedupe window (seconds) used only as a safety net.
     * Deterministic dedupe keys (signup_id, token) are preferred.
     */
    private const DEFAULT_DEDUPE_TTL = 300; // 5 minutes

    /**
     * Captured error text from wp_mail_failed (best-effort).
     */
    private static string $last_mail_error = '';

    public static function register(): void
    {
        // Capture failures when wp_mail() fails and triggers wp_mail_failed.
        add_action('wp_mail_failed', [__CLASS__, 'capture_wp_mail_failed'], 10, 1);
    }

    /**
     * Hook: wp_mail_failed
     */
    public static function capture_wp_mail_failed($wp_error): void
    {
        try {
            if (is_object($wp_error) && method_exists($wp_error, 'get_error_message')) {
                self::$last_mail_error = (string) $wp_error->get_error_message();
            } else {
                self::$last_mail_error = 'wp_mail_failed';
            }
        } catch (\Throwable $e) {
            self::$last_mail_error = 'wp_mail_failed';
        }
    }

    /**
     * Send a “preview/test” of a named template to an address.
     * Template keys:
     * - signup_confirmation
     * - reminder_24h
     * - magic_link
     */
    public static function send_test_template(string $template_key, string $to, int $schedule_id = 0): bool
    {
        $to = trim((string)$to);
        if ($to === '' || !is_email($to)) return false;

        $template_key = sanitize_key($template_key);
        if ($template_key === '') $template_key = 'signup_confirmation';

        $args = self::sample_args_for_test($template_key, $to, $schedule_id);

        $subject = self::render_subject($template_key, $args);
        $body    = self::render_body($template_key, $args);

        return self::send_mail(
            $to,
            $subject,
            $body,
            'admin',
            $template_key,
            $args,
            (string)($args['dedupe_key'] ?? ''),
            (int)($args['dedupe_ttl'] ?? 0)
        );
    }

    public static function send_signup_confirmation(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'signup_confirmation';
        $context = (string)($args['context'] ?? 'system');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        if ($dedupe_key === '') {
            // ✅ Critical: fallback prevents duplicate admin sends when signup_id missing on one path
            $dedupe_key = self::fallback_dedupe_key($type, $to, $args);
        }
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    public static function send_reminder_24h(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'reminder_24h';
        $context = (string)($args['context'] ?? 'system');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        if ($dedupe_key === '') {
            $dedupe_key = self::fallback_dedupe_key($type, $to, $args);
        }
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    public static function send_magic_link(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'magic_link';
        $context = (string)($args['context'] ?? 'system');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        if ($dedupe_key === '') {
            // If token missing, still dedupe by person_id + recipient for TTL window.
            $dedupe_key = self::fallback_dedupe_key($type, $to, $args);
        }
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    /**
     * ✅ Admin notice: a new access request came in (approval gate).
     * Previously a hardcoded wp_mail() in AccessRequestHandler — moved
     * into the templated system so it's editable from Email Templates.
     */
    public static function send_access_request_admin_notice(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'access_request_admin';
        $context = (string)($args['context'] ?? 'admin');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    /**
     * ✅ Person notice: their access request was approved. Previously a
     * hardcoded wp_mail() in AccessRequestHandler.
     */
    public static function send_access_approved(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'access_approved';
        $context = (string)($args['context'] ?? 'admin');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        if ($dedupe_key === '') {
            $dedupe_key = self::fallback_dedupe_key($type, $to, $args);
        }
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    /**
     * ✅ Admin coverage-gap digest (daily cron). Previously a hardcoded
     * wp_mail() in CoverageAlertService.
     */
    public static function send_coverage_digest(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'coverage_digest';
        $context = (string)($args['context'] ?? 'admin');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    /**
     * ✅ Replacement/coverage-needed notice, sent individually to the
     * admin and each substitute/target. Previously a hardcoded wp_mail()
     * loop in ReplacementRequestService.
     */
    public static function send_replacement_needed(array $args): bool
    {
        $args = self::normalize_args($args);

        $to = trim((string)($args['to_email'] ?? ''));
        if ($to === '' || ! is_email($to)) return false;

        if (!self::should_send($args)) return true;

        $type    = 'replacement_needed';
        $context = (string)($args['context'] ?? 'admin');

        $subject = self::render_subject($type, $args);
        $body    = self::render_body($type, $args);

        $dedupe_key = self::build_dedupe_key($type, $args);
        $dedupe_ttl = self::dedupe_ttl($args);

        return self::send_mail($to, $subject, $body, $context, $type, $args, $dedupe_key, $dedupe_ttl);
    }

    private static function send_mail(
        string $to,
        string $subject,
        string $body,
        string $context,
        string $type,
        array $args,
        string $dedupe_key = '',
        int $dedupe_ttl = 0
    ): bool {
        $templates = self::get_templates_for_args($args);

        // Optional: DEDUPE
        if ($dedupe_key !== '') {
            $transient_key = 'adoration_mail_' . md5($dedupe_key);

            if (get_transient($transient_key)) {
                // Treat deduped as success (we intentionally skipped).
                return true;
            }

            if ($dedupe_ttl > 0) {
                // Set BEFORE sending so a near-simultaneous second call is blocked.
                set_transient($transient_key, 1, $dedupe_ttl);
            }
        }

        $from_name  = trim((string)($templates['from_name'] ?? get_bloginfo('name')));
        $from_email = trim((string)($templates['from_email'] ?? get_option('admin_email')));

        // Plain-text by default (matches your original stable behavior)
        $headers = [];
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        if ($from_email !== '' && is_email($from_email)) {
            if ($from_name !== '') {
                $headers[] = 'From: ' . sprintf('%s <%s>', self::sanitize_header_name($from_name), $from_email);
            } else {
                $headers[] = 'From: ' . $from_email;
            }
        }

        // Reply-To (global uses reply_to_email, schedule UI saves reply_to)
        $reply_to = trim((string)($templates['reply_to_email'] ?? $templates['reply_to'] ?? ''));
        if ($reply_to !== '') {
            // Allow "Name <email>" or plain email
            if (preg_match('/<([^>]+)>/', $reply_to, $m)) {
                $inner = trim($m[1]);
                if (is_email($inner)) {
                    $headers[] = 'Reply-To: ' . $reply_to;
                }
            } elseif (is_email($reply_to)) {
                $headers[] = 'Reply-To: ' . $reply_to;
            }
        }

        $subject = apply_filters('adoration_scheduler_email_subject', $subject, $context, $type, $args);
        $body    = apply_filters('adoration_scheduler_email_body', $body, $context, $type, $args);
        $headers = apply_filters('adoration_scheduler_email_headers', $headers, $context, $type, $args);

        // Reset last error, then attempt send.
        self::$last_mail_error = '';
        $sent = (bool) wp_mail($to, $subject, $body, $headers);

        // Log send attempt (best-effort; never break sending)
        try {
            if (class_exists(EmailLogRepository::class)) {
                $repo = new EmailLogRepository();

                $headers_str = '';
                if (is_array($headers)) {
                    $headers_str = implode("\n", array_map('strval', $headers));
                } else {
                    $headers_str = (string)$headers;
                }

                $schedule_id = isset($args['schedule_id']) ? (int)$args['schedule_id'] : null;
                $signup_id   = isset($args['signup_id']) ? (int)$args['signup_id'] : null;

                $repo->insert([
                    'to_email'      => $to,
                    'type'          => $type,
                    'context'       => $context,
                    'schedule_id'   => ($schedule_id && $schedule_id > 0) ? $schedule_id : null,
                    'signup_id'     => ($signup_id && $signup_id > 0) ? $signup_id : null,
                    'subject'       => $subject,
                    'body'          => $body,
                    'headers'       => $headers_str,
                    'success'       => $sent ? 1 : 0,
                    'error_message' => $sent ? '' : (self::$last_mail_error !== '' ? self::$last_mail_error : 'wp_mail returned false'),
                ]);
            }
        } catch (\Throwable $e) {
            // ignore logging failures
        }

        return $sent;
    }

    private static function render_subject(string $type, array $args): string
    {
        $templates = self::get_templates_for_args($args);

        $key = $type . '_subject';
        $tpl = isset($templates[$key]) ? (string)$templates[$key] : '';

        if ($tpl !== '') {
            return self::replace_tokens($tpl, $args);
        }

        // Fallback (legacy)
        $schedule_name = trim((string)($args['schedule_name'] ?? $args['schedule_title'] ?? 'Adoration'));

        switch ($type) {
            case 'signup_confirmation':
                return sprintf('[%s] Adoration Signup Confirmation', $schedule_name);

            case 'magic_link':
                return sprintf('[%s] Your Secure Access Link', $schedule_name);

            case 'reminder_24h':
                $slot_date  = trim((string)($args['slot_date'] ?? ''));
                $slot_start = trim((string)($args['slot_start'] ?? ''));
                $when = trim($slot_date . ' ' . $slot_start);
                return $when !== ''
                    ? sprintf('Reminder: Adoration Tomorrow (%s)', $when)
                    : sprintf('[%s] Reminder', $schedule_name);

            case 'access_request_admin':
                return sprintf('[%s] New Adoration access request: %s', get_bloginfo('name'), trim((string)($args['requester_name'] ?? '')));

            case 'access_approved':
                return sprintf('[%s] Your Adoration access request was approved', get_bloginfo('name'));

            case 'coverage_digest':
                return sprintf('[%s] %d Adoration hour(s) need coverage', get_bloginfo('name'), (int)($args['gap_count'] ?? 0));

            case 'replacement_needed':
                return sprintf('[%s] Coverage needed: %s', get_bloginfo('name'), trim((string)($args['slot_label'] ?? '')));

            default:
                return sprintf('[%s] Notification', $schedule_name);
        }
    }

    private static function render_body(string $type, array $args): string
    {
        $templates = self::get_templates_for_args($args);

        $key = $type . '_body';
        $tpl = isset($templates[$key]) ? (string)$templates[$key] : '';

        if ($tpl !== '') {
            return self::replace_tokens($tpl, $args);
        }

        // Fallback (legacy)
        $person_name   = trim((string)($args['person_name'] ?? ''));
        $schedule_name = trim((string)($args['schedule_name'] ?? $args['schedule_title'] ?? 'Adoration'));
        $slot_label    = trim((string)($args['slot_label'] ?? ''));

        $manage_url = trim((string)($args['manage_url'] ?? ''));
        $magic_url  = trim((string)($args['magic_url'] ?? ''));
        if ($magic_url === '' && $manage_url !== '') $magic_url = $manage_url;

        $hello = ($person_name !== '') ? "Hello {$person_name}," : "Hello,";

        switch ($type) {
            case 'signup_confirmation':
                $lines = [];
                $lines[] = $hello;
                $lines[] = '';
                $lines[] = "This message confirms your Adoration signup.";
                $lines[] = '';

                if ($schedule_name !== '') $lines[] = "Schedule: {$schedule_name}";
                if ($slot_label !== '')    $lines[] = "Time: {$slot_label}";

                $lines[] = '';
                if ($manage_url !== '') {
                    $lines[] = "Manage your commitment here:";
                    $lines[] = $manage_url;
                    $lines[] = '';
                }

                $lines[] = "Thank you for your faithful presence in prayer.";
                $lines[] = '';
                $lines[] = get_bloginfo('name') ?: 'Your Parish';

                return implode("\n", $lines);

            case 'reminder_24h':
                $lines = [];
                $lines[] = $hello;
                $lines[] = '';
                $lines[] = "This is a friendly reminder that you are scheduled for Eucharistic Adoration.";
                $lines[] = '';

                if ($schedule_name !== '') $lines[] = "Schedule: {$schedule_name}";
                if ($slot_label !== '')    $lines[] = "When: {$slot_label}";

                $lines[] = '';
                if ($manage_url !== '') {
                    $lines[] = "Manage your commitment here:";
                    $lines[] = $manage_url;
                    $lines[] = '';
                }

                $lines[] = "Thank you for your generosity in prayer.";
                $lines[] = '';
                $lines[] = get_bloginfo('name') ?: 'Your Parish';

                return implode("\n", $lines);

            case 'magic_link':
                $lines = [];
                $lines[] = $hello;
                $lines[] = '';
                $lines[] = "Here is your secure link:";
                $lines[] = $magic_url !== '' ? $magic_url : '(missing link)';
                $lines[] = '';
                $lines[] = "If you did not request this, you can ignore this email.";
                $lines[] = '';
                $lines[] = get_bloginfo('name') ?: 'Your Parish';

                return implode("\n", $lines);

            case 'access_request_admin':
                $requester_name  = trim((string)($args['requester_name'] ?? $person_name));
                $requester_email = trim((string)($args['requester_email'] ?? ''));
                $review_url      = trim((string)($args['review_url'] ?? ''));

                $lines = [];
                $lines[] = "A new access request was submitted:";
                $lines[] = '';
                $lines[] = "Name: {$requester_name}";
                if ($requester_email !== '') $lines[] = "Email: {$requester_email}";
                $lines[] = '';
                if ($review_url !== '') {
                    $lines[] = "Review pending requests:";
                    $lines[] = $review_url;
                }

                return implode("\n", $lines);

            case 'access_approved':
                $sign_in_url = trim((string)($args['sign_in_url'] ?? $manage_url));

                $lines = [];
                $lines[] = $hello;
                $lines[] = '';
                $lines[] = "Good news — your access request has been approved. You can now sign in to view the schedule and manage your Adoration commitments.";
                $lines[] = '';
                if ($sign_in_url !== '') {
                    $lines[] = "Sign in here:";
                    $lines[] = $sign_in_url;
                    $lines[] = '';
                }
                $lines[] = "You'll get a one-time sign-in link by email each time (no password required, unless you set one from your profile once signed in).";

                return implode("\n", $lines);

            case 'coverage_digest':
                $gap_count    = (int)($args['gap_count'] ?? 0);
                $window_hours = (int)($args['window_hours'] ?? 48);
                $gap_list     = (string)($args['gap_list'] ?? '');
                $signups_url  = trim((string)($args['signups_url'] ?? ''));

                $lines = [];
                $lines[] = sprintf(
                    "The following %d Adoration hour(s) have nobody signed up, and each starts within the next %d hours:",
                    $gap_count,
                    $window_hours
                );
                $lines[] = '';
                if ($gap_list !== '') $lines[] = $gap_list;
                $lines[] = '';
                $lines[] = "View the Coverage Calendar or Signups page to assign someone, or share the schedule with parishioners so they can claim it themselves.";
                if ($signups_url !== '') {
                    $lines[] = '';
                    $lines[] = $signups_url;
                }

                return implode("\n", $lines);

            case 'replacement_needed':
                $requester_name = trim((string)($args['requester_name'] ?? $person_name));
                $note_txt       = trim((string)($args['note'] ?? ''));
                $target_name    = trim((string)($args['target_name'] ?? ''));
                $claim_url      = trim((string)($args['claim_url'] ?? $manage_url));

                $lines = [];
                $lines[] = "{$requester_name} requested a replacement for their Adoration commitment:";
                $lines[] = '';
                if ($slot_label !== '')    $lines[] = "When: {$slot_label}";
                if ($schedule_name !== '') $lines[] = "Schedule: {$schedule_name}";
                if ($note_txt !== '')      $lines[] = "Note: {$note_txt}";
                if ($target_name !== '') {
                    $lines[] = '';
                    $lines[] = "This was asked specifically of {$target_name}.";
                }
                $lines[] = '';
                if ($claim_url !== '') {
                    $lines[] = "Sign in to view or claim it:";
                    $lines[] = $claim_url;
                }

                return implode("\n", $lines);

            default:
                return $hello . "\n\n" . "Notification." . "\n\n" . (get_bloginfo('name') ?: 'Your Parish');
        }
    }

    private static function get_templates_global(): array
    {
        $raw = get_option(self::OPT_EMAIL_TEMPLATES, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Schedule overrides:
     * - stored via SchedulesRepository::save_email_templates()
     * - read via SchedulesRepository::get_email_templates()
     *
     * ALSO:
     * If schedule_id is missing but signup_id exists, we auto-resolve schedule_id
     * from the signups table so schedule overrides still work.
     */
    private static function get_templates_for_args(array $args): array
    {
        $global = self::get_templates_global();

        $schedule_id = (int)($args['schedule_id'] ?? 0);

        if ($schedule_id <= 0) {
            $schedule_id = self::resolve_schedule_id_from_signup($args);
        }

        if ($schedule_id <= 0) {
            return $global;
        }

        if (!class_exists('\AdorationScheduler\Domain\Repositories\SchedulesRepository')) {
            return $global;
        }

        $repo  = new SchedulesRepository();
        $block = $repo->get_email_templates($schedule_id);

        $enabled = !empty($block['enabled']);
        $schedule_templates = is_array($block['templates'] ?? null) ? $block['templates'] : [];

        if (!$enabled || empty($schedule_templates)) {
            return $global;
        }

        // Schedule wins on overlapping keys
        return array_merge($global, $schedule_templates);
    }

    /**
     * If we only have signup_id (common path), resolve schedule_id from DB.
     */
    private static function resolve_schedule_id_from_signup(array $args): int
    {
        global $wpdb;

        $signup_id = (int)($args['signup_id'] ?? 0);
        if ($signup_id <= 0) return 0;

        $table = $wpdb->prefix . 'adoration_signups';

        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT schedule_id FROM {$table} WHERE id = %d LIMIT 1",
                $signup_id
            )
        );

        return (int)$val;
    }

    private static function replace_tokens(string $text, array $args): string
    {
        $schedule_title = (string)($args['schedule_title'] ?? $args['schedule_name'] ?? '');
        $schedule_name  = (string)($args['schedule_name'] ?? $args['schedule_title'] ?? '');

        $first_name  = (string)($args['first_name'] ?? '');
        $last_name   = (string)($args['last_name'] ?? '');
        $person_name = (string)($args['person_name'] ?? '');

        if ($first_name === '' && $person_name !== '') {
            $first_name = $person_name;
        }

        $repl = [
            '{first_name}'     => $first_name,
            '{last_name}'      => $last_name,
            '{person_name}'    => $person_name,
            '{schedule_title}' => $schedule_title !== '' ? $schedule_title : $schedule_name,
            '{schedule_name}'  => $schedule_name !== '' ? $schedule_name : $schedule_title,
            '{slot_label}'     => (string)($args['slot_label'] ?? ''),
            '{slot_date}'      => (string)($args['slot_date'] ?? ''),
            '{slot_start}'     => (string)($args['slot_start'] ?? ''),
            '{slot_end}'       => (string)($args['slot_end'] ?? ''),
            '{church_name}'    => (string)($args['church_name'] ?? get_bloginfo('name')),
            '{manage_url}'     => (string)($args['manage_url'] ?? $args['magic_url'] ?? ''),
            '{magic_url}'      => (string)($args['magic_url'] ?? $args['manage_url'] ?? ''),

            // ✅ Access request / approval tokens
            '{requester_name}'  => (string)($args['requester_name'] ?? $person_name),
            '{requester_email}' => (string)($args['requester_email'] ?? ''),
            '{review_url}'      => (string)($args['review_url'] ?? ''),
            '{sign_in_url}'     => (string)($args['sign_in_url'] ?? $args['manage_url'] ?? ''),

            // ✅ Coverage-digest tokens
            '{gap_count}'    => (string)($args['gap_count'] ?? ''),
            '{window_hours}' => (string)($args['window_hours'] ?? ''),
            '{gap_list}'     => (string)($args['gap_list'] ?? ''),
            '{signups_url}'  => (string)($args['signups_url'] ?? ''),

            // ✅ Replacement-request tokens
            '{note}'        => (string)($args['note'] ?? ''),
            '{target_name}' => (string)($args['target_name'] ?? ''),
            '{claim_url}'   => (string)($args['claim_url'] ?? $args['manage_url'] ?? ''),
        ];

        return strtr($text, $repl);
    }

    private static function sanitize_header_name(string $name): string
    {
        $name = wp_specialchars_decode($name, ENT_QUOTES);
        $name = preg_replace("/[\r\n]+/", ' ', $name);
        return trim((string)$name);
    }

    private static function should_send(array $args): bool
    {
        return array_key_exists('send', $args) ? (bool)$args['send'] : true;
    }

    private static function dedupe_ttl(array $args): int
    {
        $ttl = isset($args['dedupe_ttl']) ? (int)$args['dedupe_ttl'] : self::DEFAULT_DEDUPE_TTL;
        return max(0, $ttl);
    }

    private static function normalize_args(array $args): array
    {
        if ((!isset($args['to_email']) || trim((string)$args['to_email']) === '') && isset($args['email'])) {
            $args['to_email'] = $args['email'];
        }

        if ((!isset($args['person_name']) || trim((string)$args['person_name']) === '') && isset($args['name'])) {
            $args['person_name'] = $args['name'];
        }

        if ((!isset($args['schedule_name']) || trim((string)$args['schedule_name']) === '') && isset($args['schedule'])) {
            $args['schedule_name'] = $args['schedule'];
        }
        if ((!isset($args['schedule_title']) || trim((string)$args['schedule_title']) === '') && isset($args['schedule'])) {
            $args['schedule_title'] = $args['schedule'];
        }

        if ((!isset($args['schedule_title']) || trim((string)$args['schedule_title']) === '') && isset($args['schedule_name'])) {
            $args['schedule_title'] = $args['schedule_name'];
        }
        if ((!isset($args['schedule_name']) || trim((string)$args['schedule_name']) === '') && isset($args['schedule_title'])) {
            $args['schedule_name'] = $args['schedule_title'];
        }

        if (isset($args['signup_id']))   $args['signup_id']   = (int) $args['signup_id'];
        if (isset($args['person_id']))   $args['person_id']   = (int) $args['person_id'];
        if (isset($args['schedule_id'])) $args['schedule_id'] = (int) $args['schedule_id'];
        if (isset($args['slot_id']))     $args['slot_id']     = (int) $args['slot_id'];

        if (!isset($args['magic_url']) && isset($args['manage_url'])) {
            $args['magic_url'] = $args['manage_url'];
        }
        if (!isset($args['manage_url']) && isset($args['magic_url'])) {
            $args['manage_url'] = $args['magic_url'];
        }

        return $args;
    }

    /**
     * Preferred dedupe keys when we have deterministic IDs/tokens.
     */
    private static function build_dedupe_key(string $type, array $args): string
    {
        $explicit = trim((string)($args['dedupe_key'] ?? ''));
        if ($explicit !== '') return $explicit;

        if ($type === 'signup_confirmation' && !empty($args['signup_id'])) {
            return 'signup_confirm:' . (int)$args['signup_id'];
        }

        if ($type === 'reminder_24h' && !empty($args['signup_id'])) {
            return 'reminder_24h:' . (int)$args['signup_id'];
        }

        if ($type === 'magic_link') {
            $person_id = !empty($args['person_id']) ? (int)($args['person_id'] ?? 0) : 0;
            $token     = trim((string)($args['token'] ?? ''));

            if ($person_id > 0 && $token !== '') {
                return 'magic_link:' . $person_id . ':' . sha1($token);
            }

            if ($person_id > 0) {
                return 'magic_link:' . $person_id;
            }
        }

        return '';
    }

    /**
     * ✅ Fallback dedupe key when signup_id/token not present.
     * This prevents duplicate sends in “admin create signup” flows where the email
     * can be triggered twice with slightly different args.
     */
    private static function fallback_dedupe_key(string $type, string $to_email, array $args): string
    {
        $schedule_id = (int)($args['schedule_id'] ?? 0);
        if ($schedule_id <= 0) $schedule_id = self::resolve_schedule_id_from_signup($args);

        $person_id = (int)($args['person_id'] ?? 0);
        $slot_id   = (int)($args['slot_id'] ?? 0);

        $slot_date  = trim((string)($args['slot_date'] ?? $args['date'] ?? ''));
        $slot_start = trim((string)($args['slot_start'] ?? ''));
        $slot_end   = trim((string)($args['slot_end'] ?? ''));

        // Build a stable-ish fingerprint.
        $fingerprint = implode('|', [
            $type,
            strtolower(trim($to_email)),
            (string)$schedule_id,
            (string)$person_id,
            (string)$slot_id,
            $slot_date,
            $slot_start,
            $slot_end,
        ]);

        // Keep it compact and safe.
        return 'fallback:' . $type . ':' . sha1($fingerprint);
    }

    private static function sample_args_for_test(string $template_key, string $to_email, int $schedule_id = 0): array
    {
        $slot_date  = date_i18n('F j, Y');
        $slot_start = '10:00 AM';
        $slot_end   = '11:00 AM';

        return [
            'to_email'       => $to_email,
            'first_name'     => 'Test',
            'last_name'      => 'Person',
            'person_name'    => 'Test Person',
            'schedule_name'  => 'Weekly Adoration (Test)',
            'schedule_title' => 'Weekly Adoration (Test)',
            'slot_label'     => $slot_date . ' ' . $slot_start . '–' . $slot_end,
            'slot_date'      => $slot_date,
            'slot_start'     => $slot_start,
            'slot_end'       => $slot_end,
            'church_name'    => get_bloginfo('name'),
            'manage_url'     => home_url('/my-adoration/?magic=TESTTOKEN'),
            'magic_url'      => home_url('/my-adoration/?magic=TESTTOKEN'),

            // ✅ Sample data for the 4 newer template types (harmless extra
            // keys for the older 3 — render_body()/replace_tokens() only
            // pull the keys each type actually uses).
            'requester_name'  => 'Jane Requester',
            'requester_email' => 'jane@example.com',
            'review_url'      => admin_url('admin.php?page=adoration_scheduler_people&approval_status=pending'),
            'sign_in_url'     => home_url('/my-adoration/'),
            'gap_count'       => 3,
            'window_hours'    => 48,
            'gap_list'        => "• {$slot_date} {$slot_start}–{$slot_end} — Weekly Adoration (Test) (Main Chapel)",
            'signups_url'     => admin_url('admin.php?page=adoration_scheduler_signups'),
            'note'            => 'Family emergency, sorry for the short notice.',
            'target_name'     => 'John Target',
            'claim_url'       => home_url('/my-adoration/?magic=TESTTOKEN'),

            'context'        => 'admin',
            'send'           => true,
            'schedule_id'    => $schedule_id,
            'dedupe_key'     => 'test:' . $template_key . ':' . get_current_user_id() . ':' . microtime(true),
            'dedupe_ttl'     => 0,
        ];
    }
}
