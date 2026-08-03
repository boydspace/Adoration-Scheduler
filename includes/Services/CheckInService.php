<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

/**
 * Attendance / check-in — three no-login, token-gated entry points, all
 * following the same "read-only-feeling, unauthenticated-by-design" pattern
 * CalendarFeedService already established for links that have to work from
 * a plain email tap or a printed QR code, where a WordPress session/nonce
 * isn't available:
 *
 * - Self-report link (ACTION_CHECKIN): a per-signup bearer token
 *   (signups.checkin_token) embedded in the confirmation/reminder email and
 *   the My Adoration portal's "I'm here" / "I'm leaving" buttons. Works
 *   identically whether the adorer is signed in or not — the token IS the
 *   authorization, exactly like the calendar feed links.
 * - Kiosk page (ACTION_KIOSK_PAGE): a per-chapel bearer token
 *   (chapels.kiosk_token) a parish can print as a QR code for the chapel
 *   entrance. Shows who's actually on the clock right now and lets a
 *   walk-up adorer tap their own name — physical presence at the chapel is
 *   the authorization, the same trust model as a paper sign-in sheet.
 * - Kiosk check-in (ACTION_KIOSK_CHECKIN): the POST target for the kiosk
 *   page's tap buttons; re-validates the tapped signup is still in the
 *   "current" list for that chapel before recording it, so the kiosk can't
 *   be used to check in a signup for a different hour or chapel.
 */
class CheckInService
{
    public const ACTION_CHECKIN       = 'adoration_checkin';
    public const ACTION_KIOSK_PAGE    = 'adoration_kiosk';
    public const ACTION_KIOSK_CHECKIN = 'adoration_kiosk_checkin';

    /**
     * How early an adorer can self-report "I'm here" before their hour
     * actually starts — generous on purpose (people arrive early to pray),
     * but still catches someone tapping a stale link from weeks ago.
     */
    private const EARLY_GRACE_MINUTES = 30;

    public static function register(): void
    {
        add_action('admin_post_nopriv_' . self::ACTION_CHECKIN, [__CLASS__, 'handle_checkin']);
        add_action('admin_post_' . self::ACTION_CHECKIN,        [__CLASS__, 'handle_checkin']);

        add_action('admin_post_nopriv_' . self::ACTION_KIOSK_PAGE, [__CLASS__, 'handle_kiosk_page']);
        add_action('admin_post_' . self::ACTION_KIOSK_PAGE,        [__CLASS__, 'handle_kiosk_page']);

        add_action('admin_post_nopriv_' . self::ACTION_KIOSK_CHECKIN, [__CLASS__, 'handle_kiosk_checkin']);
        add_action('admin_post_' . self::ACTION_KIOSK_CHECKIN,        [__CLASS__, 'handle_kiosk_checkin']);
    }

    // -------------------------------------------------------------------------
    // URL BUILDERS
    // -------------------------------------------------------------------------

    /**
     * Build a no-login "I'm here" ($mode='in') or "I'm leaving" ($mode='out')
     * link for one signup. Used by both the confirmation/reminder emails and
     * the My Adoration portal buttons — the same URL works from either place.
     */
    public static function build_checkin_url(int $signup_id, string $mode = 'in'): ?string
    {
        $signup_id = (int)$signup_id;
        if ($signup_id <= 0) return null;

        $signups_repo = new SignupsRepository();
        $token = $signups_repo->get_or_create_checkin_token($signup_id);
        if ($token === null) return null;

        $mode = ($mode === 'out') ? 'out' : 'in';

        return add_query_arg([
            'action' => self::ACTION_CHECKIN,
            'token'  => $token,
            'mode'   => $mode,
        ], admin_url('admin-post.php'));
    }

    public static function build_kiosk_url(int $chapel_id): ?string
    {
        $chapel_id = (int)$chapel_id;
        if ($chapel_id <= 0) return null;

        $chapels_repo = new ChapelsRepository();
        $token = $chapels_repo->get_or_create_kiosk_token($chapel_id);
        if ($token === null) return null;

        return add_query_arg([
            'action' => self::ACTION_KIOSK_PAGE,
            'token'  => $token,
        ], admin_url('admin-post.php'));
    }

    // -------------------------------------------------------------------------
    // SELF-REPORT CHECK-IN / CHECK-OUT (email link or portal button)
    // -------------------------------------------------------------------------

    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the expiring, signup-specific check-in token is the credential for this public email-link endpoint; visitors may not have a WordPress session for a conventional nonce.
    public static function handle_checkin(): void
    {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== self::ACTION_CHECKIN) return;

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $mode  = isset($_GET['mode']) ? sanitize_key(wp_unslash($_GET['mode'])) : 'in';
        $mode  = self::normalize_mode($mode);

        if ($token === '') {
            self::output_html('Link not found', 'This check-in link is missing information. Please use the link from your confirmation email again.');
        }

        $signups_repo = new SignupsRepository();
        $signup = $signups_repo->find_by_checkin_token($token);
        if (!$signup) {
            self::output_html('Link no longer valid', 'This check-in link is no longer valid. If you still need to check in, sign in to My Adoration and look for your upcoming hour there.');
        }

        $signup_id = (int)($signup['id'] ?? 0);
        $slot_id   = (int)($signup['slot_id'] ?? 0);

        $slots_repo = new SlotsRepository();
        $slot = $slot_id > 0 ? $slots_repo->find($slot_id) : null;

        $schedule_name = '';
        $schedule_id = (int)($signup['schedule_id'] ?? 0);
        if ($schedule_id > 0) {
            $schedules_repo = new SchedulesRepository();
            $schedule = $schedules_repo->find($schedule_id);
            $schedule_name = trim((string)($schedule['name'] ?? ''));
        }

        $when_label = self::format_slot_label($signup, $slot);

        if ($mode === 'in') {
            // ✅ Too-early guard: a stale link (or a curious early click) more
            // than EARLY_GRACE_MINUTES before the hour starts gets a friendly
            // explanation instead of silently recording an early check-in.
            if (self::is_checkin_too_early($slot)) {
                $when_suffix = ($when_label !== '') ? " ({$when_label})" : '';
                self::output_html(
                    'A little early',
                    sprintf(
                        'Your Adoration hour%s hasn\'t started yet. This link will work starting %d minutes before your hour begins — come back and tap it once you\'ve arrived.',
                        $when_suffix,
                        self::EARLY_GRACE_MINUTES
                    )
                );
            }

            $signups_repo->check_in($signup_id, 'self');

            self::output_html(
                'You\'re checked in!',
                sprintf(
                    'Thank you for your faithful presence in prayer%s. When you leave, you can tap the "I\'m leaving" link in the same email, or just close this page — checking out is optional.',
                    $schedule_name !== '' ? " for {$schedule_name}" : ''
                )
            );
        } else {
            $signups_repo->check_out($signup_id);

            self::output_html(
                'Thanks for your time in prayer',
                'You\'re marked as checked out. God bless.'
            );
        }
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    // -------------------------------------------------------------------------
    // KIOSK PAGE (public, per-chapel, "who's on now")
    // -------------------------------------------------------------------------

    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- this public read-only kiosk page is authorized by its per-chapel random token and does not require a WordPress login session.
    public static function handle_kiosk_page(): void
    {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== self::ACTION_KIOSK_PAGE) return;

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if ($token === '') {
            self::output_html('Kiosk not found', 'This check-in page link is missing information.');
        }

        $chapels_repo = new ChapelsRepository();
        $chapel = $chapels_repo->find_by_kiosk_token($token);
        if (!$chapel) {
            self::output_html('Kiosk not found', 'This check-in page is no longer valid. Please contact the parish office.');
        }

        $chapel_id   = (int)($chapel['id'] ?? 0);
        $chapel_name = trim((string)($chapel['name'] ?? 'Chapel'));

        $signups_repo = new SignupsRepository();
        $rows = $signups_repo->list_current_for_chapel($chapel_id);

        $checkin_action_url = admin_url('admin-post.php');
        $notice = isset($_GET['done']) ? sanitize_key((string) wp_unslash($_GET['done'])) : '';

        // ✅ Kiosk polish (2026-07-30): the URL a printed QR code points at
        // never has ?done=... — that only appears right after this device's
        // own tap-through redirect. Auto-refresh (so a tablet left mounted
        // at the chapel entrance stays current without staff touching it)
        // targets this clean URL, and the address bar is swapped to it via
        // replaceState as soon as the page loads, so the confirmation
        // banner doesn't reappear on every refresh.
        $clean_kiosk_url = add_query_arg(
            ['action' => self::ACTION_KIOSK_PAGE, 'token' => $token],
            admin_url('admin-post.php')
        );
        $refresh_ms = 45000;

        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($chapel_name); ?> — Check In</title>
            <style>
                * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 18px; background:#f2f3f4; margin:0; padding:28px 18px 60px; color:#1d2327; }
                .wrap { max-width: 560px; margin: 0 auto; }
                h1 { font-size: 26px; margin: 0 0 4px; }
                p.sub { color:#646970; margin: 0 0 20px; font-size: 16px; }
                .notice { background:#e4f5e9; border:1px solid #00a32a; color:#10521c; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-size:16px; }
                ul { list-style:none; margin:0; padding:0; }
                li { background:#fff; border:1px solid #dcdcde; border-left:6px solid #dcdcde; border-radius:10px; margin-bottom:14px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:14px; }
                li.state-in { border-left-color:#2271b1; }
                li.state-out { border-left-color:#dba617; }
                li.state-done { border-left-color:#00a32a; opacity:.75; }
                .name { font-size:19px; font-weight:600; }
                .substitute { display:block; font-size:13px; color:#50575e; font-weight:500; margin-top:3px; }
                .time { display:block; font-size:14px; color:#646970; font-weight:400; margin-top:2px; }
                button { font-size:17px; font-weight:600; padding:16px 20px; border-radius:10px; border:1px solid #2271b1; background:#2271b1; color:#fff; cursor:pointer; min-height:52px; min-width:120px; touch-action: manipulation; }
                button.mode-out { border-color:#dba617; background:#dba617; }
                button[disabled] { opacity:.6; cursor:default; border-color:#00a32a; background:#00a32a; }
                .empty { background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:28px 18px; text-align:center; color:#646970; font-size:17px; }
                .footnote { text-align:center; color:#8c8f94; font-size:12px; margin-top:24px; }
            </style>
        </head>
        <body>
            <div class="wrap">
                <h1><?php echo esc_html($chapel_name); ?></h1>
                <p class="sub"><?php esc_html_e('Tap your name to check in.', 'adoration-scheduler'); ?></p>

                <?php if ($notice === '1'): ?>
                    <div class="notice"><?php esc_html_e("You're checked in. Thank you for your time in prayer.", 'adoration-scheduler'); ?></div>
                <?php elseif ($notice === '2'): ?>
                    <div class="notice"><?php esc_html_e("You're checked out. Thank you for your time in prayer.", 'adoration-scheduler'); ?></div>
                <?php endif; ?>

                <?php if (empty($rows)): ?>
                    <div class="empty"><?php esc_html_e('Nobody is scheduled here right now.', 'adoration-scheduler'); ?></div>
                <?php else: ?>
                    <ul>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $signup_id = (int)($row['id'] ?? 0);
                            $first = trim((string)($row['person_first_name'] ?? ''));
                            $last  = trim((string)($row['person_last_name'] ?? ''));
                            $name  = trim($first . ' ' . substr($last, 0, 1)) . (($last !== '') ? '.' : '');
                            if ($name === '') $name = __('(unnamed)', 'adoration-scheduler');
                            $substitute_for = '';
                            if (!empty($row['replacement_claimed_by'])) {
                                $scheduled_first = trim((string)($row['scheduled_first_name'] ?? ''));
                                $scheduled_last = trim((string)($row['scheduled_last_name'] ?? ''));
                                $scheduled_name = trim($scheduled_first . ' ' . substr($scheduled_last, 0, 1)) . (($scheduled_last !== '') ? '.' : '');
                                if ($scheduled_name !== '') {
                                    $substitute_for = sprintf(__('Substitute for %s', 'adoration-scheduler'), $scheduled_name);
                                }
                            }
                            $checked_in  = !empty($row['checked_in_at']);
                            $checked_out = !empty($row['checked_out_at']);
                            $time_lbl = self::format_slot_label($row, $row);

                            if ($checked_out) {
                                $mode = '';
                                $label = __("Done ✓", 'adoration-scheduler');
                                $state_class = 'state-done';
                            } elseif ($checked_in) {
                                $mode = 'out';
                                $label = __("I'm leaving", 'adoration-scheduler');
                                $state_class = 'state-out';
                            } else {
                                $mode = 'in';
                                $label = __("I'm here", 'adoration-scheduler');
                                $state_class = 'state-in';
                            }
                            ?>
                            <li class="<?php echo esc_attr($state_class); ?>">
                                <span>
                                    <span class="name"><?php echo esc_html($name); ?></span>
                                    <?php if ($substitute_for !== ''): ?><span class="substitute"><?php echo esc_html($substitute_for); ?></span><?php endif; ?>
                                    <?php if ($time_lbl !== ''): ?><span class="time"><?php echo esc_html($time_lbl); ?></span><?php endif; ?>
                                </span>
                                <form method="post" action="<?php echo esc_url($checkin_action_url); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_KIOSK_CHECKIN); ?>">
                                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                    <input type="hidden" name="signup_id" value="<?php echo (int)$signup_id; ?>">
                                    <input type="hidden" name="mode" value="<?php echo esc_attr($mode); ?>">
                                    <button type="submit" class="mode-<?php echo esc_attr($mode !== '' ? $mode : 'in'); ?>" <?php disabled($checked_out); ?>>
                                        <?php echo esc_html($label); ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="footnote"><?php esc_html_e('This page refreshes automatically.', 'adoration-scheduler'); ?></p>
            </div>
            <script>
                (function () {
                    var CLEAN_URL = <?php echo wp_json_encode($clean_kiosk_url); ?>;
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', CLEAN_URL);
                    }
                    window.setTimeout(function () {
                        window.location.href = CLEAN_URL;
                    }, <?php echo (int) $refresh_ms; ?>);
                })();
            </script>
        </body>
        </html>
        <?php
        $html = (string) ob_get_clean();

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped per-field above
        exit;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- deliberately unauthenticated by design (see class docblock): this is a nopriv kiosk-device endpoint with no WP session to tie a nonce to. The per-chapel kiosk_token (printed as a QR code) is the authorization, same trust model as a paper sign-in sheet; the tapped signup is re-validated against the live "current" list before being recorded. Accepted risk: knowing a chapel's token plus an active signup_id lets someone remotely flip an attendance flag — low impact (no PII/financial/account exposure).
    public static function handle_kiosk_checkin(): void
    {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== self::ACTION_KIOSK_CHECKIN) return;

        $token     = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $signup_id = isset($_POST['signup_id']) ? absint(wp_unslash($_POST['signup_id'])) : 0;
        $mode      = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'in';
        $mode      = self::normalize_mode($mode);

        if ($token === '' || $signup_id <= 0) {
            wp_die(esc_html__('Missing check-in information.', 'adoration-scheduler'), 400);
        }

        $chapels_repo = new ChapelsRepository();
        $chapel = $chapels_repo->find_by_kiosk_token($token);
        if (!$chapel) {
            wp_die(esc_html__('This check-in page is no longer valid.', 'adoration-scheduler'), 404);
        }

        $chapel_id = (int)($chapel['id'] ?? 0);

        // ✅ Re-validate the tapped signup is genuinely on the clock at THIS
        // chapel right now — never trust signup_id alone, since it arrives
        // as a plain POST field from a public, unauthenticated page.
        $signups_repo = new SignupsRepository();
        $current = $signups_repo->list_current_for_chapel($chapel_id);

        $is_current = self::is_current_signup($current, $signup_id);

        $kiosk_url = self::build_kiosk_url($chapel_id) ?? admin_url('admin-post.php?action=' . self::ACTION_KIOSK_PAGE);

        if ($is_current) {
            if ($mode === 'out') {
                $signups_repo->check_out($signup_id);
                $kiosk_url = add_query_arg('done', '2', $kiosk_url);
            } else {
                $signups_repo->check_in($signup_id, 'kiosk');
                $kiosk_url = add_query_arg('done', '1', $kiosk_url);
            }
        }

        wp_safe_redirect($kiosk_url);
        exit;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Treat every unsupported value as check-in, matching the public links'
     * long-standing fail-safe behavior.
     */
    public static function normalize_mode(string $mode): string
    {
        return $mode === 'out' ? 'out' : 'in';
    }

    /**
     * Whether a personal check-in tap precedes the allowed early-arrival
     * window. Invalid or missing dates fail open so a malformed slot cannot
     * prevent a person who is physically present from checking in.
     */
    public static function is_checkin_too_early(?array $slot, ?\DateTimeImmutable $now = null): bool
    {
        $start_at = trim((string)($slot['start_at'] ?? ''));
        if ($start_at === '') return false;

        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $start = new \DateTimeImmutable($start_at, $tz);
            $now = $now ?? new \DateTimeImmutable(current_time('mysql'), $tz);
            return $now < $start->modify('-' . self::EARLY_GRACE_MINUTES . ' minutes');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Re-validates a public kiosk POST against the chapel's live roster.
     */
    public static function is_current_signup(array $current_signups, int $signup_id): bool
    {
        if ($signup_id <= 0) return false;

        foreach ($current_signups as $row) {
            if ((int)($row['id'] ?? 0) === $signup_id) return true;
        }

        return false;
    }

    private static function format_slot_label(array $signup, ?array $slot): string
    {
        $date  = trim((string)($signup['date'] ?? ''));
        $start = trim((string)($slot['start_time'] ?? $signup['start_time'] ?? ''));
        $end   = trim((string)($slot['end_time'] ?? $signup['end_time'] ?? ''));

        $time_format = (string) get_option('time_format');
        $date_format = (string) get_option('date_format');

        $parts = [];

        if ($date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) $parts[] = date_i18n($date_format, $ts);
        }

        if ($start !== '') {
            $start_ts = strtotime('1970-01-01 ' . $start);
            $end_ts   = ($end !== '') ? strtotime('1970-01-01 ' . $end) : false;
            if ($start_ts !== false) {
                $time_str = date_i18n($time_format, $start_ts);
                if ($end_ts !== false) $time_str .= '–' . date_i18n($time_format, $end_ts);
                $parts[] = $time_str;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * A person tapping a check-in link from their phone expects a simple,
     * readable confirmation — not a redirect into a login gate they may not
     * have an active session for. Renders a minimal standalone page, the
     * same "unauthenticated-by-design" spirit as CalendarFeedService's
     * output_error_ics().
     */
    private static function output_html(string $title, string $message): void
    {
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f6f7f7; margin:0; padding:40px 16px; color:#1d2327; }
                .card { max-width: 420px; margin: 0 auto; background:#fff; border:1px solid #dcdcde; border-radius:12px; padding:28px 24px; text-align:center; }
                h1 { font-size: 20px; margin: 0 0 10px; }
                p { color:#3c434a; line-height:1.5; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php echo esc_html($message); ?></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
