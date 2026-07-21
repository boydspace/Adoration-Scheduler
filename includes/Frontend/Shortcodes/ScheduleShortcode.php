<?php
namespace AdorationScheduler\Shortcodes;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Services\PerpetualSlotGenerator;
use AdorationScheduler\Utils\NameFormatter;
use AdorationScheduler\Utils\ClergyTitles;
use AdorationScheduler\Utils\CapacityBadge;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Services\AccessGateService;
use AdorationScheduler\Services\MagicLinkService;

if (!defined('ABSPATH')) exit;

class ScheduleShortcode {

    /**
     * Must match AntiSpamSettingsPage::OPTION_NAME
     */
    private const OPT_ANTISPAM_OPTIONS = 'adoration_scheduler_antispam_options';

    public static function register(): void {
        add_shortcode('adoration_schedule', [__CLASS__, 'render']);
    }

    /**
     * Shortcode: [adoration_schedule slug="my-schedule"]
     */
    public static function render($atts = []): string {
        $atts = shortcode_atts([
            'slug' => '',
        ], (array)$atts, 'adoration_schedule');

        $slug = sanitize_title($atts['slug'] ?? '');
        if ($slug === '') {
            return '<div class="adoration-schedule"><em>Missing schedule slug.</em></div>';
        }

        // ✅ Optional site-wide approval gate (off by default). See
        // MyAdorationShortcode for the same check on the dashboard side.
        // Uses gated_html() (not do_shortcode() directly) so a page with
        // this shortcode AND other gated plugin shortcodes on it only
        // shows ONE Request Access form, not one per shortcode.
        if (!AccessGateService::visitor_is_allowed()) {
            return AccessGateService::gated_html();
        }

        // Unique instance id (prevents DOM id collisions if shortcode appears multiple times on a page)
        $uid = 'as_' . substr(wp_hash(uniqid('', true)), 0, 10);

        // Turnstile settings (from AntiSpamSettingsPage option array)
        $antispam_opts = get_option(self::OPT_ANTISPAM_OPTIONS, []);
        $antispam_opts = is_array($antispam_opts) ? $antispam_opts : [];

        $turnstile_enabled  = !empty($antispam_opts['turnstile_enabled']);
        $turnstile_site_key = trim((string)($antispam_opts['turnstile_site_key'] ?? ''));

        // Only enqueue Turnstile when enabled + key set
        if ($turnstile_enabled && $turnstile_site_key !== '') {
            wp_enqueue_script(
                'cf-turnstile',
                'https://challenges.cloudflare.com/turnstile/v0/api.js',
                [],
                null,
                false
            );
        }

        $schedulesRepo = new SchedulesRepository();
        $slotsRepo     = new SlotsRepository();
        $signupsRepo   = new SignupsRepository();

        $schedule = method_exists($schedulesRepo, 'find_by_slug')
            ? $schedulesRepo->find_by_slug($slug)
            : null;

        if (!$schedule) {
            return '<div class="adoration-schedule"><em>Schedule not found.</em></div>';
        }

        if (($schedule['status'] ?? 'draft') !== 'active') {
            return '<div class="adoration-schedule"><em>This schedule is not currently active.</em></div>';
        }

        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) {
            return '<div class="adoration-schedule"><em>Invalid schedule.</em></div>';
        }

        $privacy_mode = (string)($schedule['privacy_mode'] ?? 'counts_only');
        $is_perpetual = ((string)($schedule['type'] ?? 'event') === 'perpetual');

        // WP formats (AM/PM etc)
        $time_format = (string)get_option('time_format'); // e.g. g:i A

        $action_url = esc_url(admin_url('admin-post.php'));

        // Honeypot field names (must match handlers)
        $hp_name = 'as_website';
        $ts_name = 'as_form_ts';

        // Instance-scoped IDs
        $wrap_id      = "adoration-schedule-{$uid}";
        $modal_id     = "adoration-public-modal-{$uid}";
        $title_id     = "adoration-public-modal-title-{$uid}";
        $slotlabel_id = "adoration-public-slot-label-{$uid}";
        $form_id      = "adoration-public-signup-form-{$uid}";
        $sched_id     = "adoration_public_schedule_id-{$uid}";
        $slot_id_el   = "adoration_public_slot_id-{$uid}";
        $join_wl_id   = "adoration_public_join_waitlist-{$uid}";
        $submit_id    = "adoration-public-submit-{$uid}";

        $person_title_id = "adoration_pub_title-{$uid}";
        $first_id     = "adoration_pub_first-{$uid}";
        $last_id      = "adoration_pub_last-{$uid}";
        $email_id     = "adoration_pub_email-{$uid}";
        $phone_id     = "adoration_pub_phone-{$uid}";

        $ts_container_id = "adoration-turnstile-container-{$uid}";

        // If this visitor is already signed in (custom person-session, not a
        // WP account), pre-fill the signup fields from their person record.
        // A WP admin browsing without a magic-link session still gets
        // prefilled from whatever person record matches their WP account
        // email, if any (see MagicLinkService::current_person_or_admin_match()).
        $current_person = MagicLinkService::current_person_or_admin_match();
        // Title field switched to ClergyTitles::render_field_html() (dropdown
        // + "Other" fallback) — it reads $current_person['title'] directly,
        // so no pre-escaped $cp_title variable is needed anymore.
        $cp_first = esc_attr((string)($current_person['first_name'] ?? ''));
        $cp_last  = esc_attr((string)($current_person['last_name'] ?? ''));
        $cp_email = esc_attr((string)($current_person['email'] ?? ''));
        $cp_phone = esc_attr((string)($current_person['phone'] ?? ''));

        $notice_html = self::render_notice_from_query();

        // ---------------------------------------------------------------
        // EVENT-SCHEDULE DATA (unchanged: one specific calendar date at a time)
        // ---------------------------------------------------------------
        $by_date = [];

        // ---------------------------------------------------------------
        // PERPETUAL-SCHEDULE DATA (weekly recurring pattern)
        // ---------------------------------------------------------------
        $weekly_pattern   = [];   // [dow] => [{start_time,end_time,label}]
        $row_times        = [];  // sorted list of distinct start_time strings (union across days)
        $commit_counts    = [];  // "dow|start_time" => int
        $commit_names     = [];  // "dow|start_time" => [pill label, ...]
        $default_max      = null;
        $upcoming_by_key  = [];  // "dow|start_time" => [{slot_id,label}, ...] (open dates only, capped)
        $modal_dates_json = '{}';

        if ($is_perpetual) {
            $dateRepo        = new DatePatternsRepository();
            $segmentsRepo    = new SegmentsRepository();
            $commitmentsRepo = new StandingCommitmentsRepository();
            $perpGenerator   = new PerpetualSlotGenerator($dateRepo, $segmentsRepo, $slotsRepo, $commitmentsRepo, $signupsRepo);

            $weekly_pattern = $perpGenerator->describe_weekly_pattern($schedule);

            if (empty($weekly_pattern)) {
                return '<div class="adoration-schedule"><em>No adoration times available.</em></div>';
            }

            $default_max = ($schedule['default_max_adorers'] ?? '') !== '' && $schedule['default_max_adorers'] !== null
                ? (int)$schedule['default_max_adorers']
                : null;

            // Union of distinct start_times across all days, sorted chronologically.
            $seen_times = [];
            foreach ($weekly_pattern as $dow => $opts) {
                foreach ($opts as $opt) {
                    $st = (string)($opt['start_time'] ?? '');
                    if ($st === '' || isset($seen_times[$st])) continue;
                    $seen_times[$st] = true;
                }
            }
            $row_times = array_keys($seen_times);
            sort($row_times);

            // Who already holds each weekly hour (for both capacity + name pills).
            $commit_rows = $commitmentsRepo->list_for_schedule($schedule_id, true);
            foreach ($commit_rows as $row) {
                $dow = (int)($row['day_of_week'] ?? -1);
                $st  = substr((string)($row['start_time'] ?? ''), 0, 8);
                if ($dow < 0 || $st === '') continue;

                $key = $dow . '|' . $st;
                $commit_counts[$key] = ($commit_counts[$key] ?? 0) + 1;

                if ($privacy_mode !== 'counts_only') {
                    $commit_name = NameFormatter::format(
                        $privacy_mode,
                        (string)($row['first_name'] ?? ''),
                        (string)($row['last_name'] ?? '')
                    );
                    $commit_title = ClergyTitles::abbreviate((string)($row['title'] ?? ''));
                    if ($commit_title !== '') $commit_name = $commit_title . ' ' . $commit_name;
                    $commit_names[$key][] = $commit_name;
                }
            }

            // "Cover a specific date" candidates: one bounded query for every
            // upcoming active slot on this schedule, grouped in PHP by
            // (weekday, start_time) and capped — avoids one query per grid cell.
            $today_ymd = current_time('Y-m-d');
            $all_slots = $slotsRepo->list_for_schedule($schedule_id);

            $raw_candidates = []; // "dow|start_time" => [slot rows], capped at 10 raw before filtering full ones
            foreach ($all_slots as $s) {
                if ((int)($s['is_active'] ?? 0) !== 1) continue;
                $date = (string)($s['date'] ?? '');
                if ($date === '' || $date < $today_ymd) continue;

                $ts = strtotime($date);
                if ($ts === false) continue;
                $dow = (int)date('w', $ts);
                $st  = substr((string)($s['start_time'] ?? ''), 0, 8);
                if ($st === '') continue;

                $key = $dow . '|' . $st;
                if (!isset($raw_candidates[$key])) $raw_candidates[$key] = [];
                if (count($raw_candidates[$key]) >= 10) continue; // list_for_schedule() is already date-ordered
                $raw_candidates[$key][] = $s;
            }

            $all_candidate_ids = [];
            foreach ($raw_candidates as $rows) {
                foreach ($rows as $s) {
                    $sid = (int)($s['id'] ?? 0);
                    if ($sid > 0) $all_candidate_ids[] = $sid;
                }
            }
            $candidate_counts = !empty($all_candidate_ids) ? $signupsRepo->counts_by_slot_ids($all_candidate_ids) : [];

            foreach ($raw_candidates as $key => $rows) {
                $open = [];
                foreach ($rows as $s) {
                    $sid = (int)($s['id'] ?? 0);
                    if ($sid <= 0) continue;

                    $max_raw = $s['max_adorers'] ?? null;
                    $max = ($max_raw === null || $max_raw === '') ? $default_max : (int)$max_raw;
                    $confirmed = (int)($candidate_counts[$sid] ?? 0);

                    if ($max !== null && $confirmed >= $max) continue; // full, skip

                    $date_ts = strtotime((string)($s['date'] ?? ''));
                    $label = $date_ts !== false ? date_i18n('D, M j', $date_ts) : (string)($s['date'] ?? '');

                    $open[] = ['slot_id' => $sid, 'label' => $label];
                    if (count($open) >= 6) break;
                }
                if (!empty($open)) $upcoming_by_key[$key] = $open;
            }

            $modal_dates_json = wp_json_encode($upcoming_by_key);
            if (!is_string($modal_dates_json)) $modal_dates_json = '{}';

        } else {
            // Load all slots for this schedule (keep inactive rows visible; disable signups for them)
            $slots = $slotsRepo->list_for_schedule($schedule_id);
            if (empty($slots)) {
                return '<div class="adoration-schedule"><em>No adoration times available.</em></div>';
            }

            /**
             * ✅ IMPORTANT: use display_date when present (overnight segments),
             * otherwise fall back to stored date.
             */
            $get_display_date = function(array $slot): string {
                $d = trim((string)($slot['display_date'] ?? ''));
                if ($d !== '') return $d;
                return trim((string)($slot['date'] ?? ''));
            };

            // Group slots by date (keep inactive rows visible)
            foreach ($slots as $slot) {
                $date = $get_display_date((array)$slot);
                if ($date === '') continue;
                $by_date[$date][] = $slot;
            }

            if (empty($by_date)) {
                return '<div class="adoration-schedule"><em>No adoration times available.</em></div>';
            }

            ksort($by_date);

            // Sort each day by start_time
            foreach ($by_date as $d => $day_slots) {
                usort($day_slots, function($a, $b) {
                    return strcmp((string)($a['start_time'] ?? ''), (string)($b['start_time'] ?? ''));
                });
                $by_date[$d] = $day_slots;
            }
        }

        // Signup counts by slot id (confirmed) — used by the event-schedule view.
        $counts = (array)$signupsRepo->counts_by_slot_for_schedule($schedule_id);

        $day_of_week_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $day_of_week_full   = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        ob_start();
        ?>
        <div class="adoration-schedule" id="<?php echo esc_attr($wrap_id); ?>">

            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <style>
                .adoration-schedule { width: 100%; }
                .adoration-schedule h2.adoration-title { margin: 0 0 12px 0; }

                .adoration-notice {
                    padding: 10px 12px;
                    margin: 10px 0 14px 0;
                    border-radius: 8px;
                    border: 1px solid #dcdcde;
                    background: #fff;
                }
                .adoration-notice-success { border-left: 4px solid #00a32a; }
                .adoration-notice-info    { border-left: 4px solid #2271b1; }
                .adoration-notice-error   { border-left: 4px solid #d63638; }
                .adoration-notice-warning { border-left: 4px solid #dba617; }

                .adoration-day-title {
                    margin: 1.25rem 0 .5rem;
                    font-size: 1.6rem;
                    line-height: 1.2;
                }

                table.adoration-table {
                    width: 100%;
                    border-collapse: collapse;
                    table-layout: auto;
                    margin: 0 0 10px 0;
                }
                table.adoration-table th,
                table.adoration-table td {
                    border: 1px solid #dcdcde;
                    padding: 10px 12px;
                    vertical-align: top;
                }
                table.adoration-table th {
                    background: #f6f7f7;
                    text-align: left;
                    font-weight: 600;
                }

                .adoration-col-time { width: 22%; }
                .adoration-col-adorers { width: auto; }
                .adoration-col-action { width: 1%; white-space: nowrap; text-align: right; }

                .adoration-time { white-space: nowrap; }

                .adoration-signups {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 6px 10px;
                    margin-top: 6px;
                }
                .adoration-signup-pill {
                    background: #f0f0f1;
                    border: 1px solid #dcdcde;
                    border-radius: 999px;
                    padding: 2px 8px;
                    font-size: 13px;
                    line-height: 1.6;
                    white-space: nowrap;
                }

                .adoration-btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding: 6px 10px;
                    border-radius: 4px;
                    border: 1px solid #2271b1;
                    background: #2271b1;
                    color: #fff;
                    cursor: pointer;
                    font-size: 13px;
                    line-height: 1.4;
                    text-decoration: none;
                    /* ✅ Accessibility (2026-07-18): a reasonable tap-target
                       floor outside the weekly grid's own compact rules
                       (which set their own smaller min-height for the
                       7-column layout — see .adoration-weekly-cell-actions
                       below and its mobile media query). */
                    min-height: 36px;
                    box-sizing: border-box;
                }
                .adoration-btn[disabled],
                .adoration-btn.is-disabled {
                    opacity: .55;
                    cursor: not-allowed;
                }
                .adoration-btn-secondary {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding: 6px 10px;
                    border-radius: 4px;
                    border: 1px solid #dcdcde;
                    background: #f6f7f7;
                    color: #1d2327;
                    cursor: pointer;
                    font-size: 13px;
                    line-height: 1.4;
                    text-decoration: none;
                    min-height: 36px;
                    box-sizing: border-box;
                }

                .adoration-btn-secondary:hover { background: #f0f0f1; }

                .adoration-btn-secondary[disabled],
                .adoration-btn-secondary.is-disabled {
                    opacity: .55;
                    cursor: not-allowed;
                }

                /* Weekly grid (perpetual schedules) — fluid: always fits the
                   content column's width, never forces left-right scrolling. */
                .adoration-weekly-wrap { width: 100%; overflow-x: auto; }
                table.adoration-weekly-table {
                    width: 100%;
                    table-layout: fixed;
                    border-collapse: collapse;
                    margin: 0 0 14px 0;
                }
                table.adoration-weekly-table th:first-child,
                table.adoration-weekly-table td:first-child {
                    width: 13%;
                }
                table.adoration-weekly-table th,
                table.adoration-weekly-table td {
                    border: 1px solid #dcdcde;
                    padding: 4px 3px;
                    vertical-align: top;
                    text-align: center;
                    overflow-wrap: break-word;
                }
                table.adoration-weekly-table th {
                    background: #f6f7f7;
                    font-weight: 600;
                    font-size: 15px;
                }
                table.adoration-weekly-table th.adoration-weekly-time-col {
                    text-align: right;
                    white-space: nowrap;
                    font-weight: 600;
                    font-size: 14px;
                    background: #fafafa;
                }
                table.adoration-weekly-table td.adoration-weekly-empty {
                    background: #fbfbfb;
                    color: #c3c4c7;
                }
                .adoration-weekly-cell-status {
                    font-size: 13px;
                    line-height: 1.35;
                    margin-bottom: 4px;
                }
                .adoration-weekly-cell-status .adoration-signups {
                    justify-content: center;
                }
                .adoration-weekly-cell-actions {
                    display: flex;
                    flex-direction: column;
                    gap: 4px;
                    align-items: stretch;
                }
                .adoration-weekly-cell-actions .adoration-btn,
                .adoration-weekly-cell-actions .adoration-btn-secondary {
                    font-size: 13px;
                    line-height: 1.3;
                    padding: 4px 3px;
                    white-space: normal;
                }

                @media (max-width: 700px) {
                    table.adoration-weekly-table th,
                    table.adoration-weekly-table td {
                        padding: 3px;
                    }
                    table.adoration-weekly-table th { font-size: 12px; }
                    table.adoration-weekly-table th.adoration-weekly-time-col { font-size: 11px; }
                    .adoration-weekly-cell-status { font-size: 11px; }
                    .adoration-weekly-cell-actions .adoration-btn,
                    .adoration-weekly-cell-actions .adoration-btn-secondary {
                        font-size: 11px;
                        padding: 6px 3px;
                        /* ✅ Accessibility (2026-07-18): keep a reasonable tap
                           target on phones even at this compact size — full
                           44px isn't realistic in a 7-column weekly grid, but
                           the extra vertical padding meaningfully widens the
                           tappable area over the original 3px. */
                        min-height: 30px;
                    }
                }

                /* Modal mode toggle */
                .adoration-mode-toggle {
                    display: flex;
                    gap: 8px;
                    margin: 10px 0 14px;
                }
                .adoration-mode-toggle button {
                    flex: 1;
                    padding: 8px;
                    border-radius: 6px;
                    border: 1px solid #dcdcde;
                    background: #f6f7f7;
                    cursor: pointer;
                    font-size: 13px;
                }
                .adoration-mode-toggle button.is-active {
                    background: #2271b1;
                    border-color: #2271b1;
                    color: #fff;
                }

                /* Honeypot: keep it off-screen but present in DOM */
                .as-honeypot {
                    position: absolute !important;
                    left: -10000px !important;
                    top: auto !important;
                    width: 1px !important;
                    height: 1px !important;
                    overflow: hidden !important;
                }

                /* Turnstile container spacing */
                .adoration-turnstile-wrap {
                    margin: 10px 0 0 0;
                }

                /* --- Fallback (no UIkit) modal styling only --- */
                .as-fallback-backdrop {
                    display:none;
                    position:fixed;
                    inset:0;
                    background:rgba(0,0,0,.45);
                    z-index:999999;
                }
                .as-fallback-modal {
                    display:none;
                    position:fixed;
                    left:50%;
                    top:50%;
                    transform:translate(-50%,-50%);
                    width:min(640px, 92vw);
                    max-height: 85vh;
                    overflow:auto;
                    background:#fff;
                    border:1px solid #c3c4c7;
                    box-shadow:0 10px 30px rgba(0,0,0,.25);
                    border-radius:10px;
                    z-index:1000000;
                }
                .as-fallback-modal header {
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    padding:12px 14px;
                    border-bottom:1px solid #dcdcde;
                    position: sticky;
                    top: 0;
                    background: #fff;
                    z-index: 1;
                }
                .as-fallback-modal header strong { font-size:14px; }
                .as-fallback-modal .adoration-modal-body { padding:14px; }
            </style>

            <h2 class="adoration-title"><?php echo esc_html($schedule['name'] ?? 'Adoration'); ?></h2>

            <?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php if ($is_perpetual): ?>

                <p class="description" style="margin: 0 0 14px;">
                    <?php esc_html_e('This is a recurring weekly schedule — the same hours repeat every week. Take an open hour as your standing weekly commitment, or just cover a single date if you’d rather not commit weekly.', 'adoration-scheduler'); ?>
                </p>

                <div class="adoration-weekly-wrap">
                    <table class="adoration-weekly-table">
                        <thead>
                            <tr>
                                <th scope="col" style="width:90px;"><?php esc_html_e('Time', 'adoration-scheduler'); ?></th>
                                <?php foreach ($day_of_week_labels as $lbl): ?>
                                    <th scope="col"><?php echo esc_html($lbl); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($row_times as $st): ?>
                                <?php
                                $row_ts = strtotime('1970-01-01 ' . $st);
                                $row_label = $row_ts !== false ? date_i18n($time_format, $row_ts) : $st;
                                ?>
                                <tr>
                                    <th scope="row" class="adoration-weekly-time-col"><?php echo esc_html($row_label); ?></th>
                                    <?php for ($dow = 0; $dow <= 6; $dow++): ?>
                                        <?php
                                        $opt = null;
                                        foreach (($weekly_pattern[$dow] ?? []) as $candidate) {
                                            if ((string)($candidate['start_time'] ?? '') === $st) { $opt = $candidate; break; }
                                        }
                                        ?>
                                        <?php if ($opt === null): ?>
                                            <td class="adoration-weekly-empty">—</td>
                                        <?php else: ?>
                                            <?php
                                            $key = $dow . '|' . $st;
                                            $count = (int)($commit_counts[$key] ?? 0);
                                            $is_full = ($default_max !== null && $count >= $default_max);
                                            $has_open_dates = !empty($upcoming_by_key[$key]);

                                            $cell_label = $day_of_week_full[$dow] . ', ' . $opt['label'];

                                            // ✅ Coverage-at-a-glance (2026-07-20): the badge (and the
                                            // cell's background tint below) now render regardless of
                                            // privacy_mode — a wall of identical blue "Take" buttons was
                                            // unreadable at a glance; the red/amber/green from
                                            // CapacityBadge (see [adoration_open_hours]) lets you scan
                                            // the whole grid for open hours without reading every cell.
                                            [$cov_label, $cov_bg, $cov_fg, $cov_border] = CapacityBadge::parts($count, $default_max, $is_full);
                                            ?>
                                            <td style="background:<?php echo esc_attr($cov_bg); ?>; border-left:3px solid <?php echo esc_attr($cov_border); ?>;">
                                                <div class="adoration-weekly-cell-status">
                                                    <?php echo CapacityBadge::html_parts($cov_label, $cov_bg, $cov_fg, $cov_border); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                    <?php if ($privacy_mode !== 'counts_only'):
                                                        $names = $commit_names[$key] ?? [];
                                                        if (!empty($names)): ?>
                                                            <div class="adoration-signups">
                                                                <?php foreach ($names as $nm): ?>
                                                                    <span class="adoration-signup-pill"><?php echo esc_html($nm); ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif;
                                                    endif; ?>
                                                </div>
                                                <div class="adoration-weekly-cell-actions">
                                                    <?php if ($is_full): ?>
                                                        <span class="adoration-btn is-disabled" aria-disabled="true" aria-label="<?php echo esc_attr(sprintf(
                                                            /* translators: %s: day + time, e.g. "Wednesday, 2:00 AM" */
                                                            __('%s is full', 'adoration-scheduler'),
                                                            $cell_label
                                                        )); ?>"><?php esc_html_e('Full', 'adoration-scheduler'); ?></span>
                                                    <?php else: ?>
                                                        <button
                                                            type="button"
                                                            class="adoration-btn adoration-open-signup"
                                                            data-schedule-id="<?php echo (int)$schedule_id; ?>"
                                                            data-mode="standing"
                                                            data-day-of-week="<?php echo (int)$dow; ?>"
                                                            data-start-time="<?php echo esc_attr($st); ?>"
                                                            data-label="<?php echo esc_attr($cell_label); ?>"
                                                            title="<?php esc_attr_e('Take this as my standing weekly hour', 'adoration-scheduler'); ?>"
                                                            aria-label="<?php echo esc_attr(sprintf(
                                                                /* translators: %s: day + time, e.g. "Wednesday, 2:00 AM" */
                                                                __('Take %s as my standing weekly hour', 'adoration-scheduler'),
                                                                $cell_label
                                                            )); ?>"
                                                        >
                                                            <?php esc_html_e('Take', 'adoration-scheduler'); ?>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($has_open_dates): ?>
                                                        <button
                                                            type="button"
                                                            class="adoration-btn-secondary adoration-open-signup"
                                                            data-schedule-id="<?php echo (int)$schedule_id; ?>"
                                                            data-mode="onetime"
                                                            data-day-of-week="<?php echo (int)$dow; ?>"
                                                            data-start-time="<?php echo esc_attr($st); ?>"
                                                            data-label="<?php echo esc_attr($cell_label); ?>"
                                                            title="<?php esc_attr_e('Cover just one date', 'adoration-scheduler'); ?>"
                                                            aria-label="<?php echo esc_attr(sprintf(
                                                                /* translators: %s: day + time, e.g. "Wednesday, 2:00 AM" */
                                                                __('Cover a date for %s', 'adoration-scheduler'),
                                                                $cell_label
                                                            )); ?>"
                                                        >
                                                            <?php esc_html_e('Cover a date', 'adoration-scheduler'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- UIkit Modal (dual-mode: standing weekly commitment OR cover one date) -->
                <div id="<?php echo esc_attr($modal_id); ?>" class="uk-flex-top" uk-modal>
                    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
                        <button class="uk-modal-close-default" type="button" uk-close></button>

                        <h2 class="uk-modal-title" id="<?php echo esc_attr($title_id); ?>">Sign up for Adoration</h2>

                        <p class="uk-text-muted uk-margin-small-top">
                            <?php esc_html_e('Hour:', 'adoration-scheduler'); ?> <strong id="<?php echo esc_attr($slotlabel_id); ?>">—</strong>
                        </p>

                        <div class="adoration-mode-toggle">
                            <button type="button" data-as-mode-btn="standing" data-as-modal="uk"><?php esc_html_e('Every week', 'adoration-scheduler'); ?></button>
                            <button type="button" data-as-mode-btn="onetime" data-as-modal="uk"><?php esc_html_e('Just this date', 'adoration-scheduler'); ?></button>
                        </div>

                        <form method="post" action="<?php echo $action_url; ?>" id="<?php echo esc_attr($form_id); ?>">
                            <input type="hidden" name="action" value="adoration_public_claim_standing" data-as-action="1">
                            <?php wp_nonce_field('adoration_public_signup', 'adoration_public_nonce'); ?>
                            <?php wp_nonce_field('adoration_public_claim_standing', 'adoration_public_nonce_standing'); ?>

                            <div class="as-honeypot" aria-hidden="true">
                                <label for="<?php echo esc_attr($hp_name . '_' . $uid); ?>">Leave this field blank</label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr($hp_name); ?>"
                                    id="<?php echo esc_attr($hp_name . '_' . $uid); ?>"
                                    value=""
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                            <input type="hidden" name="<?php echo esc_attr($ts_name); ?>" value="<?php echo esc_attr(time()); ?>" />

                            <input type="hidden" name="schedule_id" id="<?php echo esc_attr($sched_id); ?>" value="">
                            <input type="hidden" name="day_of_week" data-as-dow="1" value="">
                            <input type="hidden" name="start_time" data-as-start-time="1" value="">
                            <input type="hidden" name="slot_id" id="<?php echo esc_attr($slot_id_el); ?>" data-as-slot="1" value="">

                            <p data-as-onetime-wrap="1" style="display:none; margin: 8px 0;">
                                <label class="uk-form-label"><?php esc_html_e('Choose a date', 'adoration-scheduler'); ?></label>
                                <select class="uk-select" data-as-onetime-select="1" style="width:100%;"></select>
                            </p>

                            <div class="uk-grid-small" uk-grid>
                                <div class="uk-width-1-1">
                                    <label class="uk-form-label" for="<?php echo esc_attr($person_title_id); ?>">Title <span class="uk-text-meta">(optional)</span></label>
                                    <div class="uk-form-controls">
                                        <?php ClergyTitles::render_field_html('title', $person_title_id, (string)($current_person['title'] ?? ''), 'uk-select'); ?>
                                    </div>
                                </div>
                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($first_id); ?>">First name</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="text" name="first_name" id="<?php echo esc_attr($first_id); ?>" required value="<?php echo $cp_first; ?>">
                                    </div>
                                </div>
                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($last_id); ?>">Last name</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="text" name="last_name" id="<?php echo esc_attr($last_id); ?>" required value="<?php echo $cp_last; ?>">
                                    </div>
                                </div>
                                <div class="uk-width-1-1">
                                    <label class="uk-form-label" for="<?php echo esc_attr($email_id); ?>">Email</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="email" name="email" id="<?php echo esc_attr($email_id); ?>" required value="<?php echo $cp_email; ?>">
                                    </div>
                                </div>
                                <div class="uk-width-1-1">
                                    <label class="uk-form-label" for="<?php echo esc_attr($phone_id); ?>">Phone</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="text" name="phone" id="<?php echo esc_attr($phone_id); ?>" required placeholder="(555) 123-4567" value="<?php echo $cp_phone; ?>">
                                    </div>
                                </div>
                            </div>

                            <?php if ($turnstile_enabled && $turnstile_site_key !== '') : ?>
                                <div class="adoration-turnstile-wrap">
                                    <div
                                        id="<?php echo esc_attr($ts_container_id); ?>"
                                        data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"
                                    ></div>
                                </div>
                            <?php elseif ($turnstile_enabled && $turnstile_site_key === ''): ?>
                                <p><em>Anti-spam is enabled but missing site key.</em></p>
                            <?php endif; ?>

                            <p class="uk-text-right uk-margin-medium-top">
                                <button type="button" class="uk-button uk-button-default uk-modal-close">Cancel</button>
                                <button type="submit" class="uk-button uk-button-primary">Confirm</button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Fallback Modal (only used if UIkit is NOT present) -->
                <div class="as-fallback-backdrop" id="<?php echo esc_attr($modal_id); ?>-fb-backdrop" aria-hidden="true"></div>
                <div class="as-fallback-modal" id="<?php echo esc_attr($modal_id); ?>-fb" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>-fb">
                    <header>
                        <strong id="<?php echo esc_attr($title_id); ?>-fb">Sign up for Adoration</strong>
                        <button type="button" class="adoration-btn-secondary" data-as-fb-close="1">Close</button>
                    </header>
                    <div class="adoration-modal-body">
                        <p style="margin-top:0;">
                            <span style="color:#646970;">Hour:</span>
                            <strong data-as-fb-slotlabel="1">—</strong>
                        </p>

                        <div class="adoration-mode-toggle">
                            <button type="button" data-as-mode-btn="standing" data-as-modal="fb"><?php esc_html_e('Every week', 'adoration-scheduler'); ?></button>
                            <button type="button" data-as-mode-btn="onetime" data-as-modal="fb"><?php esc_html_e('Just this date', 'adoration-scheduler'); ?></button>
                        </div>

                        <form method="post" action="<?php echo $action_url; ?>" data-as-fb-form="1">
                            <input type="hidden" name="action" value="adoration_public_claim_standing" data-as-action="1">
                            <?php wp_nonce_field('adoration_public_signup', 'adoration_public_nonce'); ?>
                            <?php wp_nonce_field('adoration_public_claim_standing', 'adoration_public_nonce_standing'); ?>

                            <div class="as-honeypot" aria-hidden="true">
                                <label for="<?php echo esc_attr($hp_name . '_' . $uid); ?>_fb">Leave this field blank</label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr($hp_name); ?>"
                                    id="<?php echo esc_attr($hp_name . '_' . $uid); ?>_fb"
                                    value=""
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                            <input type="hidden" name="<?php echo esc_attr($ts_name); ?>" value="<?php echo esc_attr(time()); ?>" />

                            <input type="hidden" name="schedule_id" value="" data-as-fb-schedule="1">
                            <input type="hidden" name="day_of_week" data-as-dow="1" value="">
                            <input type="hidden" name="start_time" data-as-start-time="1" value="">
                            <input type="hidden" name="slot_id" value="" data-as-fb-slot="1" data-as-slot="1">

                            <p data-as-onetime-wrap="1" style="display:none;">
                                <label for="<?php echo esc_attr($uid); ?>_fb_onetime_date"><?php esc_html_e('Choose a date', 'adoration-scheduler'); ?></label>
                                <select class="regular-text" id="<?php echo esc_attr($uid); ?>_fb_onetime_date" data-as-onetime-select="1" style="width:100%;"></select>
                            </p>

                            <table class="form-table" role="presentation">
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb_title">Title <span class="description">(optional)</span></label></th>
                                    <td><?php ClergyTitles::render_field_html('title', $uid . '_fb_title', (string)($current_person['title'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb_first">First name</label></th>
                                    <td><input type="text" name="first_name" id="<?php echo esc_attr($uid); ?>_fb_first" class="regular-text" required value="<?php echo $cp_first; ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb_last">Last name</label></th>
                                    <td><input type="text" name="last_name" id="<?php echo esc_attr($uid); ?>_fb_last" class="regular-text" required value="<?php echo $cp_last; ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb_email">Email</label></th>
                                    <td><input type="email" name="email" id="<?php echo esc_attr($uid); ?>_fb_email" class="regular-text" required value="<?php echo $cp_email; ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb_phone">Phone</label></th>
                                    <td><input type="text" name="phone" id="<?php echo esc_attr($uid); ?>_fb_phone" class="regular-text" required placeholder="(555) 123-4567" data-as-fb-phone="1" value="<?php echo $cp_phone; ?>"></td>
                                </tr>
                            </table>

                            <?php if ($turnstile_enabled && $turnstile_site_key !== '') : ?>
                                <div class="adoration-turnstile-wrap">
                                    <div data-as-fb-ts="1" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
                                <button type="button" class="adoration-btn-secondary" data-as-fb-close="1">Cancel</button>
                                <button type="submit" class="adoration-btn">Confirm</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                (function() {
                    const root = document.getElementById(<?php echo json_encode($wrap_id); ?>);
                    if (!root) return;

                    const DATES_MAP = <?php echo $modal_dates_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

                    // Re-checked at the moment it's needed (click / Escape), not once
                    // at script-parse time — UIkit may still be loading (either the
                    // theme's own copy, or the one this plugin injects) when this
                    // script first runs.
                    function hasUIkitNow() {
                        return !!(window.UIkit && typeof window.UIkit.modal === 'function');
                    }

                    const uikitModal = document.getElementById(<?php echo json_encode($modal_id); ?>);
                    const fbBackdrop = document.getElementById(<?php echo json_encode($modal_id . '-fb-backdrop'); ?>);
                    const fbModal    = document.getElementById(<?php echo json_encode($modal_id . '-fb'); ?>);

                    const labelEl      = document.getElementById(<?php echo json_encode($slotlabel_id); ?>);
                    const scheduleIdEl = document.getElementById(<?php echo json_encode($sched_id); ?>);
                    const form         = document.getElementById(<?php echo json_encode($form_id); ?>);
                    const firstEl      = document.getElementById(<?php echo json_encode($first_id); ?>);
                    const phoneEl      = document.getElementById(<?php echo json_encode($phone_id); ?>);
                    const tsContainer  = document.getElementById(<?php echo json_encode($ts_container_id); ?>);

                    let tsWidgetId = null;
                    let currentDow = '';
                    let currentStartTime = '';

                    function normalizePhoneToDigits(raw) { return (raw || '').replace(/\D+/g, ''); }
                    function formatPhoneUS(raw) {
                        let d = normalizePhoneToDigits(raw);
                        if (d.length === 11 && d[0] === '1') d = d.slice(1);
                        if (d.length !== 10) return null;
                        return '(' + d.slice(0,3) + ') ' + d.slice(3,6) + '-' + d.slice(6);
                    }

                    function ensureTurnstileRendered(container) {
                        if (!container) return;
                        const siteKey = container.getAttribute('data-sitekey') || '';
                        if (!siteKey) return;

                        if (!window.turnstile || typeof window.turnstile.render !== 'function') {
                            let tries = 0;
                            const t = setInterval(() => {
                                tries++;
                                if (window.turnstile && typeof window.turnstile.render === 'function') {
                                    clearInterval(t);
                                    ensureTurnstileRendered(container);
                                } else if (tries >= 25) {
                                    clearInterval(t);
                                }
                            }, 120);
                            return;
                        }

                        if (tsWidgetId === null) {
                            try { tsWidgetId = window.turnstile.render(container, { sitekey: siteKey }); } catch(e) {}
                        } else {
                            try { window.turnstile.reset(tsWidgetId); } catch(e) {}
                        }
                    }

                    function applyMode(scope, mode) {
                        const actionEl   = scope.querySelector('[data-as-action="1"]');
                        const dowEl      = scope.querySelector('[data-as-dow="1"]');
                        const stEl       = scope.querySelector('[data-as-start-time="1"]');
                        const slotEl     = scope.querySelector('[data-as-slot="1"]');
                        const onetimeWrap = scope.querySelector('[data-as-onetime-wrap="1"]');
                        const onetimeSel  = scope.querySelector('[data-as-onetime-select="1"]');
                        const modeBtns    = scope.querySelectorAll('[data-as-mode-btn]');

                        modeBtns.forEach(function(btn) {
                            btn.classList.toggle('is-active', btn.getAttribute('data-as-mode-btn') === mode);
                        });

                        if (mode === 'onetime') {
                            if (actionEl) actionEl.value = 'adoration_public_signup';
                            if (onetimeWrap) onetimeWrap.style.display = 'block';

                            if (onetimeSel) {
                                const key = currentDow + '|' + currentStartTime;
                                const options = DATES_MAP[key] || [];
                                onetimeSel.innerHTML = '';
                                options.forEach(function(o) {
                                    const el = document.createElement('option');
                                    el.value = o.slot_id;
                                    el.textContent = o.label;
                                    onetimeSel.appendChild(el);
                                });
                                if (slotEl) slotEl.value = options.length ? String(options[0].slot_id) : '';

                                onetimeSel.onchange = function() {
                                    if (slotEl) slotEl.value = onetimeSel.value;
                                };
                            }

                            if (dowEl) dowEl.value = '';
                            if (stEl) stEl.value = '';
                        } else {
                            if (actionEl) actionEl.value = 'adoration_public_claim_standing';
                            if (onetimeWrap) onetimeWrap.style.display = 'none';
                            if (slotEl) slotEl.value = '';
                            if (dowEl) dowEl.value = currentDow;
                            if (stEl) stEl.value = currentStartTime;
                        }
                    }

                    // Each toggle button carries data-as-modal="uk"|"fb" so the click
                    // handler operates on the correct modal's fields (there are two
                    // near-identical forms in the DOM — UIkit's and the fallback's).
                    document.querySelectorAll('[data-as-mode-btn]').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            const which = btn.getAttribute('data-as-modal');
                            const scope = (which === 'fb') ? fbModal : uikitModal;
                            if (!scope) return;
                            applyMode(scope, btn.getAttribute('data-as-mode-btn'));
                        });
                    });

                    function openWithUIkit(scheduleId, dow, startTime, label, mode) {
                        if (form) form.reset();
                        currentDow = dow;
                        currentStartTime = startTime;
                        if (scheduleIdEl) scheduleIdEl.value = scheduleId || '';
                        if (labelEl) labelEl.textContent = label || '—';

                        try {
                            const inst = window.UIkit.modal(uikitModal);
                            inst.show();
                        } catch(e) {
                            openWithFallback(scheduleId, dow, startTime, label, mode);
                            return;
                        }

                        applyMode(uikitModal, mode);
                        setTimeout(() => ensureTurnstileRendered(tsContainer), 50);
                        setTimeout(() => { try { if (firstEl) firstEl.focus(); } catch(e) {} }, 80);
                    }

                    function openWithFallback(scheduleId, dow, startTime, label, mode) {
                        if (!fbModal || !fbBackdrop) return;

                        currentDow = dow;
                        currentStartTime = startTime;

                        const fbLabel = fbModal.querySelector('[data-as-fb-slotlabel="1"]');
                        const fbSched = fbModal.querySelector('[data-as-fb-schedule="1"]');
                        const fbForm  = fbModal.querySelector('[data-as-fb-form="1"]');
                        const fbPhone = fbModal.querySelector('[data-as-fb-phone="1"]');
                        const fbTs    = fbModal.querySelector('[data-as-fb-ts="1"]');

                        if (fbForm) fbForm.reset();
                        if (fbSched) fbSched.value = scheduleId || '';
                        if (fbLabel) fbLabel.textContent = label || '—';

                        fbBackdrop.style.display = 'block';
                        fbModal.style.display = 'block';
                        if (window.AdorationA11y) window.AdorationA11y.trap(fbModal);

                        applyMode(fbModal, mode);
                        setTimeout(() => ensureTurnstileRendered(fbTs), 50);
                        setTimeout(() => {
                            try {
                                const first = fbModal.querySelector('input[name="first_name"]');
                                if (first) first.focus();
                            } catch(e) {}
                        }, 80);

                        if (fbPhone) {
                            fbPhone.addEventListener('blur', function() {
                                const f = formatPhoneUS(fbPhone.value);
                                if (f) fbPhone.value = f;
                            }, { once: true });
                        }
                    }

                    function closeFallback() {
                        if (fbModal) fbModal.style.display = 'none';
                        if (fbBackdrop) fbBackdrop.style.display = 'none';
                        if (window.AdorationA11y) window.AdorationA11y.release(fbModal);
                    }

                    root.querySelectorAll('.adoration-open-signup').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const scheduleId = btn.getAttribute('data-schedule-id');
                            const dow        = btn.getAttribute('data-day-of-week');
                            const startTime   = btn.getAttribute('data-start-time');
                            const label       = btn.getAttribute('data-label');
                            const mode        = btn.getAttribute('data-mode') || 'standing';

                            if (hasUIkitNow() && uikitModal) {
                                openWithUIkit(scheduleId, dow, startTime, label, mode);
                            } else {
                                openWithFallback(scheduleId, dow, startTime, label, mode);
                            }
                        });
                    });

                    if (phoneEl) {
                        phoneEl.addEventListener('blur', function() {
                            const f = formatPhoneUS(phoneEl.value);
                            if (f) phoneEl.value = f;
                        });
                    }

                    if (fbBackdrop) fbBackdrop.addEventListener('click', closeFallback);
                    if (fbModal) {
                        fbModal.querySelectorAll('[data-as-fb-close="1"]').forEach(el => {
                            el.addEventListener('click', closeFallback);
                        });
                    }

                    document.addEventListener('keydown', function(ev) {
                        if (ev.key === 'Escape' && !hasUIkitNow()) {
                            if (fbModal && fbModal.style.display === 'block') closeFallback();
                        }
                    });
                })();
                </script>

            <?php else: ?>

                <?php foreach ($by_date as $date => $day_slots): ?>
                    <?php
                    // ✅ STRICT strtotime check so weird falsey behavior can't break headings
                    $day_ts = strtotime($date);
                    $day_heading = ($day_ts === false) ? $date : date_i18n('l, F j, Y', $day_ts);
                    ?>
                    <h2 class="adoration-day-title">
                        <?php echo esc_html($day_heading); ?>
                    </h2>

                    <table class="adoration-table">
                        <thead>
                            <tr>
                                <th class="adoration-col-time" scope="col">Time</th>
                                <th class="adoration-col-adorers" scope="col">
                                    <?php echo ($privacy_mode === 'counts_only') ? 'Availability' : 'Adorers'; ?>
                                </th>
                                <th class="adoration-col-action" scope="col"><span class="uk-margin-remove" style="position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($day_slots as $slot): ?>
                            <?php
                            $slot_id_val = (int)($slot['id'] ?? 0);
                            if ($slot_id_val <= 0) continue;

                            $is_row_active = ((int)($slot['is_active'] ?? 1) === 1);

                            $slot_status = strtolower((string)($slot['status'] ?? 'active'));
                            $signups_disabled_statuses = ['disabled','closed','no_signups','signups_disabled','inactive'];

                            $signups_enabled = $is_row_active && !in_array($slot_status, $signups_disabled_statuses, true);

                            $public_note = trim((string)($slot['public_note'] ?? ''));

                            // ✅ MIDNIGHT FIX: strtotime() can return 0 for 00:00:00; only treat failure as === false
                            $start_ts = strtotime('1970-01-01 ' . (string)($slot['start_time'] ?? ''));
                            $end_ts   = strtotime('1970-01-01 ' . (string)($slot['end_time'] ?? ''));

                            $start_label = ($start_ts === false) ? '' : date_i18n($time_format, $start_ts);
                            $end_label   = ($end_ts === false) ? '' : date_i18n($time_format, $end_ts);

                            // Build label without leaving dangling dash when end is blank
                            if ($start_label !== '' && $end_label !== '') {
                                $time_label = $start_label . ' – ' . $end_label;
                            } else {
                                $time_label = $start_label !== '' ? $start_label : ($end_label !== '' ? $end_label : '');
                            }

                            $confirmed = (int)($counts[$slot_id_val] ?? 0);
                            $max_raw   = $slot['max_adorers'] ?? null;
                            $max       = ($max_raw === null || $max_raw === '') ? null : (int)$max_raw;
                            $is_full   = ($max !== null && $confirmed >= $max);

                            $hide_signup_display = !$signups_enabled;

                            $signup_names = [];
                            if ($privacy_mode !== 'counts_only' && !$hide_signup_display) {
                                $rows = $signupsRepo->list_for_slot($slot_id_val, true);
                                foreach ((array)$rows as $r) {
                                    $signup_name = NameFormatter::format(
                                        $privacy_mode,
                                        (string)($r['first_name'] ?? ''),
                                        (string)($r['last_name'] ?? '')
                                    );
                                    $signup_title = ClergyTitles::abbreviate((string)($r['title'] ?? ''));
                                    if ($signup_title !== '') $signup_name = $signup_title . ' ' . $signup_name;
                                    $signup_names[] = $signup_name;
                                }
                            }

                            // ✅ Use day heading date (already strict-checked) for slot label too
                            $slot_label = $day_heading . ' ' . ($time_label !== '' ? $time_label : '—');

                            // ✅ Coverage-at-a-glance (2026-07-20): badge renders
                            // regardless of privacy_mode — see the weekly grid's
                            // matching change above for the full rationale.
                            [$cov_label, $cov_bg, $cov_fg, $cov_border] = CapacityBadge::parts($confirmed, $max, $is_full);
                            ?>
                            <tr style="<?php echo !$signups_enabled ? 'opacity:.9;' : ''; ?>">
                                <th scope="row" class="adoration-time">
                                    <strong><?php echo esc_html($time_label !== '' ? $time_label : '—'); ?></strong>
                                </th>

                                <td<?php echo !$hide_signup_display ? ' style="background:' . esc_attr($cov_bg) . '; border-left:3px solid ' . esc_attr($cov_border) . ';"' : ''; ?>>
                                    <?php
                                    if ($hide_signup_display) {
                                        $note = ($public_note !== '') ? $public_note : 'Signups disabled';
                                        echo '<em>' . esc_html($note) . '</em>';
                                    } else {
                                        echo CapacityBadge::html_parts($cov_label, $cov_bg, $cov_fg, $cov_border); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        if ($privacy_mode !== 'counts_only' && !empty($signup_names)) {
                                            echo '<div class="adoration-signups">';
                                            foreach ($signup_names as $nm) {
                                                echo '<span class="adoration-signup-pill">' . esc_html($nm) . '</span>';
                                            }
                                            echo '</div>';
                                        }
                                    }
                                    ?>
                                </td>

                                <td class="adoration-col-action">
                                    <?php if ($is_full && !$signups_enabled): ?>
                                        <span class="adoration-btn is-disabled" aria-disabled="true" aria-label="<?php echo esc_attr(sprintf(
                                            /* translators: %s: date + time label */
                                            __('%s is full', 'adoration-scheduler'),
                                            $slot_label
                                        )); ?>">Full</span>
                                    <?php elseif ($is_full): ?>
                                        <button
                                            type="button"
                                            class="adoration-btn adoration-btn-secondary adoration-open-signup"
                                            data-schedule-id="<?php echo (int)$schedule_id; ?>"
                                            data-slot-id="<?php echo (int)$slot_id_val; ?>"
                                            data-slot-label="<?php echo esc_attr($slot_label); ?>"
                                            data-join-waitlist="1"
                                            aria-label="<?php echo esc_attr(sprintf(
                                                /* translators: %s: date + time label */
                                                __('Join waitlist for %s', 'adoration-scheduler'),
                                                $slot_label
                                            )); ?>"
                                        >
                                            Join Waitlist
                                        </button>
                                    <?php elseif (!$signups_enabled): ?>
                                        <span class="adoration-btn is-disabled" aria-disabled="true" aria-label="<?php echo esc_attr(sprintf(
                                            /* translators: %s: date + time label */
                                            __('Signups disabled for %s', 'adoration-scheduler'),
                                            $slot_label
                                        )); ?>">Sign up</span>
                                    <?php else: ?>
                                        <button
                                            type="button"
                                            class="adoration-btn adoration-open-signup"
                                            data-schedule-id="<?php echo (int)$schedule_id; ?>"
                                            data-slot-id="<?php echo (int)$slot_id_val; ?>"
                                            data-slot-label="<?php echo esc_attr($slot_label); ?>"
                                            aria-label="<?php echo esc_attr(sprintf(
                                                /* translators: %s: date + time label */
                                                __('Sign up for %s', 'adoration-scheduler'),
                                                $slot_label
                                            )); ?>"
                                        >
                                            Sign up
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>

                <!-- UIkit Modal (preferred when UIkit is present) -->
                <div id="<?php echo esc_attr($modal_id); ?>" class="uk-flex-top" uk-modal>
                    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
                        <button class="uk-modal-close-default" type="button" uk-close></button>

                        <h2 class="uk-modal-title" id="<?php echo esc_attr($title_id); ?>">Sign up for Adoration</h2>

                        <p class="uk-text-muted uk-margin-small-top">
                            Time: <strong id="<?php echo esc_attr($slotlabel_id); ?>">—</strong>
                        </p>

                        <form method="post" action="<?php echo $action_url; ?>" id="<?php echo esc_attr($form_id); ?>">
                            <input type="hidden" name="action" value="adoration_public_signup">
                            <?php wp_nonce_field('adoration_public_signup', 'adoration_public_nonce'); ?>

                            <div class="as-honeypot" aria-hidden="true">
                                <label for="<?php echo esc_attr($hp_name . '_' . $uid); ?>">Leave this field blank</label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr($hp_name); ?>"
                                    id="<?php echo esc_attr($hp_name . '_' . $uid); ?>"
                                    value=""
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                            <input type="hidden" name="<?php echo esc_attr($ts_name); ?>" value="<?php echo esc_attr(time()); ?>" />

                            <input type="hidden" name="schedule_id" id="<?php echo esc_attr($sched_id); ?>" value="">
                            <input type="hidden" name="slot_id" id="<?php echo esc_attr($slot_id_el); ?>" value="">
                            <input type="hidden" name="join_waitlist" id="<?php echo esc_attr($join_wl_id); ?>" value="0">

                            <div class="uk-grid-small" uk-grid>
                                <div class="uk-width-1-1">
                                    <label class="uk-form-label" for="<?php echo esc_attr($person_title_id); ?>">Title <span class="uk-text-meta">(optional)</span></label>
                                    <div class="uk-form-controls">
                                        <?php ClergyTitles::render_field_html('title', $person_title_id, (string)($current_person['title'] ?? ''), 'uk-select'); ?>
                                    </div>
                                </div>
                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($first_id); ?>">First name</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="text" name="first_name" id="<?php echo esc_attr($first_id); ?>" required value="<?php echo $cp_first; ?>">
                                    </div>
                                </div>
                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($last_id); ?>">Last name</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="text" name="last_name" id="<?php echo esc_attr($last_id); ?>" required value="<?php echo $cp_last; ?>">
                                    </div>
                                </div>
                                <div class="uk-width-1-1">
                                    <label class="uk-form-label" for="<?php echo esc_attr($email_id); ?>">Email</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="email" name="email" id="<?php echo esc_attr($email_id); ?>" required value="<?php echo $cp_email; ?>">
                                    </div>
                                </div>
                                <div class="uk-width-1-1">
                                    <label class="uk-form-label" for="<?php echo esc_attr($phone_id); ?>">Phone</label>
                                    <div class="uk-form-controls">
                                        <input class="uk-input" type="text" name="phone" id="<?php echo esc_attr($phone_id); ?>" required placeholder="(555) 123-4567" value="<?php echo $cp_phone; ?>">
                                    </div>
                                </div>
                            </div>

                            <?php if ($turnstile_enabled && $turnstile_site_key !== '') : ?>
                                <div class="adoration-turnstile-wrap">
                                    <div
                                        id="<?php echo esc_attr($ts_container_id); ?>"
                                        data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"
                                    ></div>
                                </div>
                            <?php elseif ($turnstile_enabled && $turnstile_site_key === ''): ?>
                                <p><em>Anti-spam is enabled but missing site key.</em></p>
                            <?php endif; ?>

                            <p class="uk-text-right uk-margin-medium-top">
                                <button type="button" class="uk-button uk-button-default uk-modal-close">Cancel</button>
                                <button type="submit" class="uk-button uk-button-primary" id="<?php echo esc_attr($submit_id); ?>">Confirm Signup</button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Fallback Modal (only used if UIkit is NOT present) -->
                <div class="as-fallback-backdrop" id="<?php echo esc_attr($modal_id); ?>-fb-backdrop" aria-hidden="true"></div>
                <div class="as-fallback-modal" id="<?php echo esc_attr($modal_id); ?>-fb" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>-fb">
                    <header>
                        <strong id="<?php echo esc_attr($title_id); ?>-fb">Sign up for Adoration</strong>
                        <button type="button" class="adoration-btn-secondary" data-as-fb-close="1">Close</button>
                    </header>
                    <div class="adoration-modal-body">
                        <p style="margin-top:0;">
                            <span style="color:#646970;">Time:</span>
                            <strong data-as-fb-slotlabel="1">—</strong>
                        </p>

                        <form method="post" action="<?php echo $action_url; ?>" data-as-fb-form="1">
                            <input type="hidden" name="action" value="adoration_public_signup">
                            <?php wp_nonce_field('adoration_public_signup', 'adoration_public_nonce'); ?>

                            <div class="as-honeypot" aria-hidden="true">
                                <label for="<?php echo esc_attr($hp_name . '_' . $uid); ?>_fb">Leave this field blank</label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr($hp_name); ?>"
                                    id="<?php echo esc_attr($hp_name . '_' . $uid); ?>_fb"
                                    value=""
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                            <input type="hidden" name="<?php echo esc_attr($ts_name); ?>" value="<?php echo esc_attr(time()); ?>" />

                            <input type="hidden" name="schedule_id" value="" data-as-fb-schedule="1">
                            <input type="hidden" name="slot_id" value="" data-as-fb-slot="1">
                            <input type="hidden" name="join_waitlist" value="0" data-as-fb-joinwaitlist="1">

                            <table class="form-table" role="presentation">
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb2_title">Title <span class="description">(optional)</span></label></th>
                                    <td><?php ClergyTitles::render_field_html('title', $uid . '_fb2_title', (string)($current_person['title'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb2_first">First name</label></th>
                                    <td><input type="text" name="first_name" id="<?php echo esc_attr($uid); ?>_fb2_first" class="regular-text" required value="<?php echo $cp_first; ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb2_last">Last name</label></th>
                                    <td><input type="text" name="last_name" id="<?php echo esc_attr($uid); ?>_fb2_last" class="regular-text" required value="<?php echo $cp_last; ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb2_email">Email</label></th>
                                    <td><input type="email" name="email" id="<?php echo esc_attr($uid); ?>_fb2_email" class="regular-text" required value="<?php echo $cp_email; ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="<?php echo esc_attr($uid); ?>_fb2_phone">Phone</label></th>
                                    <td><input type="text" name="phone" id="<?php echo esc_attr($uid); ?>_fb2_phone" class="regular-text" required placeholder="(555) 123-4567" data-as-fb-phone="1" value="<?php echo $cp_phone; ?>"></td>
                                </tr>
                            </table>

                            <?php if ($turnstile_enabled && $turnstile_site_key !== '') : ?>
                                <div class="adoration-turnstile-wrap">
                                    <div data-as-fb-ts="1" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
                                <button type="button" class="adoration-btn-secondary" data-as-fb-close="1">Cancel</button>
                                <button type="submit" class="adoration-btn" data-as-fb-submit="1">Confirm Signup</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                (function() {
                    const root = document.getElementById(<?php echo json_encode($wrap_id); ?>);
                    if (!root) return;

                    // Re-checked at the moment it's needed (click / Escape), not once
                    // at script-parse time — UIkit may still be loading (either the
                    // theme's own copy, or the one this plugin injects) when this
                    // script first runs.
                    function hasUIkitNow() {
                        return !!(window.UIkit && typeof window.UIkit.modal === 'function');
                    }

                    // UIkit modal element
                    const uikitModal = document.getElementById(<?php echo json_encode($modal_id); ?>);

                    // Fallback elements
                    const fbBackdrop = document.getElementById(<?php echo json_encode($modal_id . '-fb-backdrop'); ?>);
                    const fbModal    = document.getElementById(<?php echo json_encode($modal_id . '-fb'); ?>);

                    // Shared fields (UIkit version)
                    const labelEl      = document.getElementById(<?php echo json_encode($slotlabel_id); ?>);
                    const scheduleIdEl = document.getElementById(<?php echo json_encode($sched_id); ?>);
                    const slotIdEl     = document.getElementById(<?php echo json_encode($slot_id_el); ?>);
                    const joinWlEl     = document.getElementById(<?php echo json_encode($join_wl_id); ?>);
                    const titleEl      = document.getElementById(<?php echo json_encode($title_id); ?>);
                    const submitEl     = document.getElementById(<?php echo json_encode($submit_id); ?>);
                    const form         = document.getElementById(<?php echo json_encode($form_id); ?>);
                    const firstEl      = document.getElementById(<?php echo json_encode($first_id); ?>);
                    const phoneEl      = document.getElementById(<?php echo json_encode($phone_id); ?>);
                    const tsContainer  = document.getElementById(<?php echo json_encode($ts_container_id); ?>);

                    let tsWidgetId = null;

                    function normalizePhoneToDigits(raw) {
                        return (raw || '').replace(/\D+/g, '');
                    }
                    function formatPhoneUS(raw) {
                        let d = normalizePhoneToDigits(raw);
                        if (d.length === 11 && d[0] === '1') d = d.slice(1);
                        if (d.length !== 10) return null;
                        return '(' + d.slice(0,3) + ') ' + d.slice(3,6) + '-' + d.slice(6);
                    }

                    function ensureTurnstileRendered(container) {
                        if (!container) return;
                        const siteKey = container.getAttribute('data-sitekey') || '';
                        if (!siteKey) return;

                        if (!window.turnstile || typeof window.turnstile.render !== 'function') {
                            let tries = 0;
                            const t = setInterval(() => {
                                tries++;
                                if (window.turnstile && typeof window.turnstile.render === 'function') {
                                    clearInterval(t);
                                    ensureTurnstileRendered(container);
                                } else if (tries >= 25) {
                                    clearInterval(t);
                                }
                            }, 120);
                            return;
                        }

                        if (tsWidgetId === null) {
                            try { tsWidgetId = window.turnstile.render(container, { sitekey: siteKey }); } catch(e) {}
                        } else {
                            try { window.turnstile.reset(tsWidgetId); } catch(e) {}
                        }
                    }

                    function openWithUIkit(scheduleId, slotId, label, joinWaitlist) {
                        if (form) form.reset();
                        if (scheduleIdEl) scheduleIdEl.value = scheduleId || '';
                        if (slotIdEl) slotIdEl.value = slotId || '';
                        if (joinWlEl) joinWlEl.value = joinWaitlist ? '1' : '0';
                        if (labelEl) labelEl.textContent = label || '—';
                        if (titleEl) titleEl.textContent = joinWaitlist ? 'Join Waitlist' : 'Sign up for Adoration';
                        if (submitEl) submitEl.textContent = joinWaitlist ? 'Join Waitlist' : 'Confirm Signup';

                        try {
                            const inst = window.UIkit.modal(uikitModal);
                            inst.show();
                        } catch(e) {
                            openWithFallback(scheduleId, slotId, label, joinWaitlist);
                            return;
                        }

                        setTimeout(() => ensureTurnstileRendered(tsContainer), 50);
                        setTimeout(() => { try { if (firstEl) firstEl.focus(); } catch(e) {} }, 80);
                    }

                    function openWithFallback(scheduleId, slotId, label, joinWaitlist) {
                        if (!fbModal || !fbBackdrop) return;

                        const fbLabel  = fbModal.querySelector('[data-as-fb-slotlabel="1"]');
                        const fbSched  = fbModal.querySelector('[data-as-fb-schedule="1"]');
                        const fbSlot   = fbModal.querySelector('[data-as-fb-slot="1"]');
                        const fbJoinWl = fbModal.querySelector('[data-as-fb-joinwaitlist="1"]');
                        const fbForm   = fbModal.querySelector('[data-as-fb-form="1"]');
                        const fbPhone  = fbModal.querySelector('[data-as-fb-phone="1"]');
                        const fbTs     = fbModal.querySelector('[data-as-fb-ts="1"]');
                        const fbTitle  = document.getElementById(<?php echo json_encode($title_id . '-fb'); ?>);
                        const fbSubmit = fbModal.querySelector('[data-as-fb-submit="1"]');

                        if (fbForm) fbForm.reset();
                        if (fbSched) fbSched.value = scheduleId || '';
                        if (fbSlot)  fbSlot.value  = slotId || '';
                        if (fbJoinWl) fbJoinWl.value = joinWaitlist ? '1' : '0';
                        if (fbLabel) fbLabel.textContent = label || '—';
                        if (fbTitle) fbTitle.textContent = joinWaitlist ? 'Join Waitlist' : 'Sign up for Adoration';
                        if (fbSubmit) fbSubmit.textContent = joinWaitlist ? 'Join Waitlist' : 'Confirm Signup';

                        fbBackdrop.style.display = 'block';
                        fbModal.style.display = 'block';
                        if (window.AdorationA11y) window.AdorationA11y.trap(fbModal);

                        setTimeout(() => ensureTurnstileRendered(fbTs), 50);
                        setTimeout(() => {
                            try {
                                const first = fbModal.querySelector('input[name="first_name"]');
                                if (first) first.focus();
                            } catch(e) {}
                        }, 80);

                        if (fbPhone) {
                            fbPhone.addEventListener('blur', function() {
                                const f = formatPhoneUS(fbPhone.value);
                                if (f) fbPhone.value = f;
                            }, { once: true });
                        }
                    }

                    function closeFallback() {
                        if (fbModal) fbModal.style.display = 'none';
                        if (fbBackdrop) fbBackdrop.style.display = 'none';
                        if (window.AdorationA11y) window.AdorationA11y.release(fbModal);
                    }

                    root.querySelectorAll('.adoration-open-signup').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const scheduleId   = btn.getAttribute('data-schedule-id');
                            const slotId       = btn.getAttribute('data-slot-id');
                            const label        = btn.getAttribute('data-slot-label');
                            const joinWaitlist = btn.getAttribute('data-join-waitlist') === '1';

                            if (hasUIkitNow() && uikitModal) {
                                openWithUIkit(scheduleId, slotId, label, joinWaitlist);
                            } else {
                                openWithFallback(scheduleId, slotId, label, joinWaitlist);
                            }
                        });
                    });

                    if (phoneEl) {
                        phoneEl.addEventListener('blur', function() {
                            const f = formatPhoneUS(phoneEl.value);
                            if (f) phoneEl.value = f;
                        });
                    }

                    if (fbBackdrop) fbBackdrop.addEventListener('click', closeFallback);
                    if (fbModal) {
                        fbModal.querySelectorAll('[data-as-fb-close="1"]').forEach(el => {
                            el.addEventListener('click', closeFallback);
                        });
                    }

                    document.addEventListener('keydown', function(ev) {
                        if (ev.key === 'Escape' && !hasUIkitNow()) {
                            if (fbModal && fbModal.style.display === 'block') closeFallback();
                        }
                    });
                })();
                </script>

            <?php endif; ?>

        </div>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * NEW METHOD ONLY:
     * Render a notice banner based on:
     *   ?as_toast=...&as_toast_type=success|error|warning|info&as_toast_sticky=1
     */
    private static function render_notice_from_query(): string
    {
        $toast = isset($_GET['as_toast']) ? (string) wp_unslash($_GET['as_toast']) : '';
        $toast = trim($toast);
        if ($toast === '') return '';

        $type = isset($_GET['as_toast_type']) ? sanitize_key((string) wp_unslash($_GET['as_toast_type'])) : 'info';
        $allowed = ['success','error','warning','info'];
        if (!in_array($type, $allowed, true)) $type = 'info';

        $class = 'adoration-notice-info';
        if ($type === 'success') $class = 'adoration-notice-success';
        if ($type === 'error')   $class = 'adoration-notice-error';
        if ($type === 'warning') $class = 'adoration-notice-warning';

        // UIkit alert classes too (harmless if UIkit isn't loaded)
        $uk = 'uk-alert uk-alert-primary';
        if ($type === 'success') $uk = 'uk-alert uk-alert-success';
        if ($type === 'error')   $uk = 'uk-alert uk-alert-danger';
        if ($type === 'warning') $uk = 'uk-alert uk-alert-warning';

        $toast = sanitize_text_field(wp_strip_all_tags($toast));
        if (strlen($toast) > 300) $toast = substr($toast, 0, 300);

        // ✅ Accessibility (2026-07-18): error/warning notices use role="alert"
        // (assertive — announced immediately, interrupting the screen reader)
        // since they mean something needs the visitor's attention right now;
        // success/info use role="status" (polite — announced without
        // interrupting), matching how each type is meant to read.
        $role = ($type === 'error' || $type === 'warning') ? 'alert' : 'status';

        return '<div class="' . esc_attr($uk) . ' adoration-notice ' . esc_attr($class) . '" role="' . esc_attr($role) . '" uk-alert>'
            . '<p class="uk-margin-remove">' . esc_html($toast) . '</p>'
            . '</div>';
    }
}
