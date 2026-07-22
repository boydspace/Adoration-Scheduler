<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\ChapelsRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;
use AdorationScheduler\Domain\Repositories\SegmentsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Repositories\ScheduleClosuresRepository;
use AdorationScheduler\Domain\Services\SlotGenerator;
use AdorationScheduler\Domain\Services\PerpetualSlotGenerator;
use AdorationScheduler\Domain\Services\MonthlySlotGenerator;
use AdorationScheduler\Services\NotificationService;

if ( ! defined( 'ABSPATH' ) ) exit;

class EditSchedulePage {

    /**
     * @var array<int,array{message:string,type:string,sticky:bool}>
     */
    private array $toasts = [];

    private function add_toast(string $message, string $type = 'info', bool $sticky = false): void {
        $message = trim(sanitize_text_field($message));
        if ($message === '') return;

        $type = sanitize_key($type);
        $allowed = ['success','error','warning','info'];
        if (!in_array($type, $allowed, true)) $type = 'info';

        $this->toasts[] = [
            'message' => $message,
            'type'    => $type,
            'sticky'  => (bool)$sticky,
        ];
    }

    private function toasts_from_query(): void {
        if (!isset($_GET['as_toast'])) return;

        $raw = (string) wp_unslash($_GET['as_toast']);
        $raw = rawurldecode($raw);
        $msg = trim(sanitize_text_field($raw));
        if ($msg === '') return;

        $type = isset($_GET['as_toast_type'])
            ? sanitize_key((string) wp_unslash($_GET['as_toast_type']))
            : 'info';

        $sticky = !empty($_GET['as_toast_sticky']) && (string)$_GET['as_toast_sticky'] === '1';

        $this->add_toast($msg, $type, $sticky);
    }

    private function render_toasts(): void {
        if (empty($this->toasts)) return;

        $payload = wp_json_encode($this->toasts);
        if (!is_string($payload) || $payload === '') return;

        ?>
        <script>
        (function(){
            var toasts = <?php echo $payload; ?>;

            function ensureWrap(){
                return document.querySelector('.wrap') || document.body;
            }

            function fallbackNotice(t){
                var wrap = ensureWrap();
                var div = document.createElement('div');

                var cls = 'notice notice-info';
                if (t.type === 'success') cls = 'notice notice-success';
                if (t.type === 'error') cls = 'notice notice-error';
                if (t.type === 'warning') cls = 'notice notice-warning';

                div.className = cls;
                var p = document.createElement('p');
                p.textContent = (t.message || '').toString();
                div.appendChild(p);

                var h1 = wrap.querySelector('h1');
                if (h1 && h1.parentNode) {
                    h1.parentNode.insertBefore(div, h1.nextSibling);
                } else {
                    wrap.insertBefore(div, wrap.firstChild);
                }
            }

            function show(){
                var hasToast = window.AdorationScheduler && typeof window.AdorationScheduler.toast === 'function';

                toasts.forEach(function(t){
                    if (hasToast) {
                        window.AdorationScheduler.toast(t);
                    } else {
                        fallbackNotice(t);
                    }
                });

                try {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('as_toast');
                    url.searchParams.delete('as_toast_type');
                    url.searchParams.delete('as_toast_sticky');
                    window.history.replaceState({}, document.title, url.toString());
                } catch(e){}
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', show);
            } else {
                show();
            }
        })();
        </script>
        <?php
    }

    private function normalize_phone_us(?string $raw): ?string {
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

    private function is_basic_info_post(): bool {
        if ( ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ) return false;
        if ( empty($_POST['_wpnonce']) ) return false;

        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        return (bool) wp_verify_nonce($nonce, 'adoration_save_basic_info');
    }

    /**
     * Helper: Extract a reliable Y-m-d for a slot (uses start_at if present).
     */
    private function slot_true_ymd(array $slot): string {
        $start_at = (string)($slot['start_at'] ?? '');
        if ($start_at !== '') {
            $ts = strtotime($start_at);
            if ($ts !== false) {
                $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
                try {
                    $dt = new \DateTime('@' . $ts);
                    $dt->setTimezone($tz);
                    return $dt->format('Y-m-d');
                } catch (\Exception $e) {}
            }
        }

        $d = (string)($slot['date'] ?? '');
        return $d !== '' ? $d : '';
    }

    /**
     * ✅ NEW: Normalize time strings to HH:MM:SS so slot keys match reliably.
     * This fixes hydration misses when one side uses HH:MM and the other uses HH:MM:SS.
     */
    private function normalize_his(string $t): string {
        $t = trim($t);
        if ($t === '') return '';

        // Common formats from UI/forms
        if (strlen($t) === 5) return $t . ':00'; // HH:MM -> HH:MM:SS
        if (strlen($t) === 8) return $t;         // already HH:MM:SS

        // Last-resort normalize odd formats
        $ts = strtotime('1970-01-01 ' . $t);
        return ($ts !== false) ? gmdate('H:i:s', $ts) : substr($t, 0, 8);
    }

    /**
     * ✅ Stable key for matching generated slots to existing slots.
     * Must match SlotGenerator::slot_key() logic (legacy fields only),
     * BUT we normalize times to HH:MM:SS so keys match even if one side is HH:MM.
     */
    private function slot_legacy_key(array $row): string {
        $schedule_id = (int)($row['schedule_id'] ?? 0);

        $date = (string)($row['date'] ?? '');
        $st   = $this->normalize_his((string)($row['start_time'] ?? ''));
        $et   = $this->normalize_his((string)($row['end_time'] ?? ''));
        $seg  = (int)($row['segment_id'] ?? 0);

        return $schedule_id . '|' . $date . '|' . $st . '|' . $et . '|' . $seg;
    }

    /**
     * ✅ NEW: After Safe Sync (or Generate), hydrate start_at/end_at for any kept legacy rows.
     * This is what makes overnight schedules sort correctly even when existing rows were created
     * before canonical columns existed (or were NULL).
     *
     * Returns # of rows updated.
     */
    private function hydrate_canonical_datetimes_for_schedule(
        array $schedule,
        DatePatternsRepository $dateRepo,
        SegmentsRepository $segmentsRepo,
        SlotsRepository $slotsRepo
    ): int {
        $schedule_id = (int)($schedule['id'] ?? 0);
        if ($schedule_id <= 0) return 0;

        if (!method_exists($slotsRepo, 'update_canonical_datetimes')) {
            return 0;
        }

        // Build the canonical truth from the schedule definition.
        $generator = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
        $generated = $generator->preview_for_event_schedule($schedule);
        if (empty($generated)) return 0;

        $gen_map = [];
        foreach ($generated as $g) {
            $k = $this->slot_legacy_key($g);
            $sa = (string)($g['start_at'] ?? '');
            $ea = (string)($g['end_at'] ?? '');
            if ($sa === '' || $ea === '') continue;
            $gen_map[$k] = ['start_at' => $sa, 'end_at' => $ea];
        }
        if (empty($gen_map)) return 0;

        // Fetch existing slots (active + inactive) and update any missing/NULL canonical fields.
        $existing = $slotsRepo->list_for_schedule($schedule_id);
        if (empty($existing)) return 0;

        $updated = 0;

        foreach ($existing as $ex) {
            $id = (int)($ex['id'] ?? 0);
            if ($id <= 0) continue;

            $k = $this->slot_legacy_key($ex);
            if (!isset($gen_map[$k])) continue;

            $cur_sa = trim((string)($ex['start_at'] ?? ''));
            $cur_ea = trim((string)($ex['end_at'] ?? ''));

            // Only touch rows that are missing canonical datetimes (or clearly blank).
            if ($cur_sa !== '' && $cur_ea !== '') {
                continue;
            }

            $want = $gen_map[$k];

            $ok = $slotsRepo->update_canonical_datetimes(
                $id,
                $want['start_at'] ?? null,
                $want['end_at'] ?? null
            );

            if ($ok) $updated++;
        }

        return $updated;
    }

    /**
     * ✅ NEW: safe helper for redirect-with-toast (prevents double-post + keeps URL clean)
     */
    private function redirect_with_toast(string $url, string $message, string $type = 'info', bool $sticky = false): void {
        $url = add_query_arg([
            'as_toast'        => rawurlencode($message),
            'as_toast_type'   => $type,
            'as_toast_sticky' => $sticky ? '1' : '0',
        ], $url);

        wp_safe_redirect($url);
        exit;
    }

    /**
     * ✅ NEW (Step 1): Persist per-schedule flags without requiring a DB migration.
     * Stored as a single option array keyed by schedule_id.
     */
    private function get_schedule_flag(int $schedule_id, string $key, $default = null) {
        if ($schedule_id <= 0) return $default;

        $all = get_option('adoration_scheduler_schedule_flags', []);
        if (!is_array($all)) $all = [];

        $sid = (string)$schedule_id;
        if (!isset($all[$sid]) || !is_array($all[$sid])) return $default;

        return $all[$sid][$key] ?? $default;
    }

    private function set_schedule_flag(int $schedule_id, string $key, $value): void {
        if ($schedule_id <= 0) return;

        $all = get_option('adoration_scheduler_schedule_flags', []);
        if (!is_array($all)) $all = [];

        $sid = (string)$schedule_id;
        if (!isset($all[$sid]) || !is_array($all[$sid])) $all[$sid] = [];

        $all[$sid][$key] = $value;

        update_option('adoration_scheduler_schedule_flags', $all, false);
    }

    /**
     * ✅ Guardrail helper: count ANY signups for a schedule.
     * We block destructive slot rebuilds when this is > 0.
     */
    private function signups_count_for_schedule(int $schedule_id): int {
        $schedule_id = (int)$schedule_id;
        if ($schedule_id <= 0) return 0;

        global $wpdb;
        $table = $wpdb->prefix . 'adoration_signups';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE schedule_id = %d", $schedule_id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $n = (int) $wpdb->get_var($sql);
        return max(0, $n);
    }

    private function schedule_has_any_signups(int $schedule_id): bool {
        return $this->signups_count_for_schedule($schedule_id) > 0;
    }

    /**
     * ✅ NEW: Validate requested chapel id against list_active.
     * Falls back to $default_id if not present/active.
     */
    private function normalize_chapel_id_from_active_list(int $requested_id, array $active_chapels, int $default_id): int {
        $requested_id = (int)$requested_id;
        if ($requested_id <= 0) return (int)$default_id;

        foreach ($active_chapels as $c) {
            if ((int)($c['id'] ?? 0) === $requested_id) {
                return $requested_id;
            }
        }

        return (int)$default_id;
    }

    public function render(): void {

        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        // NOTE:
        // This page originally required schedule_id > 0 (edit-only).
        // ✅ We now support "create then edit" on the same page so defaults are saved immediately.
        $schedule_id = (int) ($_GET['schedule_id'] ?? 0);

        $tab = sanitize_key($_GET['tab'] ?? 'overview');
        $allowed_tabs = ['overview','basic','dates','weekly_hours','commitments','coverage','monthly_occurrence','slots','signups','email'];
        if (!in_array($tab, $allowed_tabs, true)) {
            $tab = 'overview';
        }

        $schedulesRepo    = new SchedulesRepository();
        $chapelsRepo      = new ChapelsRepository();
        $dateRepo         = new DatePatternsRepository();
        $segmentsRepo     = new SegmentsRepository();
        $slotsRepo        = new SlotsRepository();
        $signupsRepo      = new SignupsRepository();
        $personsRepo      = new PersonsRepository();
        $commitmentsRepo  = new StandingCommitmentsRepository();
        $closuresRepo     = new ScheduleClosuresRepository();

        // ✅ Ensure at least one chapel exists and get default id
        $default_chapel_id = (int) $chapelsRepo->ensure_default_chapel_exists();
        $chapels_active    = (array) $chapelsRepo->list_active();

        // If schedule_id is missing, we treat this as "create mode"
        $is_create_mode = ($schedule_id <= 0);

        // Default schedule array for create-mode rendering + generator usage
        $schedule = [
            'id'                  => 0,
            'chapel_id'           => $default_chapel_id,
            'name'                => '',
            'slug'                => '',
            'type'                => 'event',
            'status'              => 'draft',
            'privacy_mode'        => 'counts_only',
            'start_date'          => null,
            'end_date'            => null,
            'default_slot_length' => 60,
            'default_min_adorers' => 1,
            'default_max_adorers' => null,
            // ✅ Step 1: UI + flag persistence (no behavior change yet)
            'is_overnight'        => 0,
            // ✅ Perpetual adoration
            'rolling_window_days' => 60,
        ];

        if (!$is_create_mode) {
            $found = $schedulesRepo->find($schedule_id);
            if (!$found) {
                echo '<div class="wrap"><h1>Edit Schedule</h1><p>Schedule not found.</p></div>';
                return;
            }
            $schedule = array_merge($schedule, $found);

            // ✅ Normalize schedule chapel_id if empty/invalid
            $schedule['chapel_id'] = $this->normalize_chapel_id_from_active_list(
                (int)($schedule['chapel_id'] ?? 0),
                $chapels_active,
                $default_chapel_id
            );

            // ✅ Step 1: pull persisted flag (option-backed)
            $schedule['is_overnight'] = (int) $this->get_schedule_flag($schedule_id, 'is_overnight', (int)($schedule['is_overnight'] ?? 0));
        }

        $this->toasts_from_query();

        $preview_slots = [];
        $slots_rows = [];

        $signup_counts = [];
        $signups_by_slot = [];
        $waitlist_by_slot = [];

        $current_page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_schedules');
        if ($current_page_slug === '') $current_page_slug = 'adoration_scheduler_schedules';

        $base_args = [
            'page'        => $current_page_slug,
            'action'      => 'edit',
        ];

        if (!$is_create_mode) {
            $base_args['schedule_id'] = (int)$schedule_id;
        }

        $back_url = add_query_arg(['page' => 'adoration_scheduler_schedules'], admin_url('admin.php'));

        /**
         * BASIC INFO SAVE (Edit OR Create)
         */
        if ( $this->is_basic_info_post() ) {

            check_admin_referer('adoration_save_basic_info');

            $name         = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $slug         = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
            $status       = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'draft';
            $privacy_mode = isset($_POST['privacy_mode']) ? sanitize_text_field(wp_unslash($_POST['privacy_mode'])) : 'counts_only';

            $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
            $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

            $start_date = ($start_date !== '') ? $start_date : null;
            $end_date   = ($end_date !== '') ? $end_date : null;

            // ✅ NEW: Chapel selection
            $chapel_id_raw = isset($_POST['chapel_id']) ? (int) wp_unslash($_POST['chapel_id']) : 0;
            $chapel_id = $this->normalize_chapel_id_from_active_list($chapel_id_raw, $chapels_active, $default_chapel_id);
            if ($chapel_id !== (int)$chapel_id_raw && $chapel_id_raw > 0) {
                $this->add_toast('Selected chapel is not available. Reverted to default chapel.', 'warning', false);
            }

            // ✅ Step 1: Overnight flag (UI + persistence only)
            $is_overnight = !empty($_POST['is_overnight']) ? 1 : 0;

            // ✅ Always read from POST first.
            $default_slot_length = isset($_POST['default_slot_length'])
                ? (int) wp_unslash($_POST['default_slot_length'])
                : (int)($schedule['default_slot_length'] ?? 60);
            if ($default_slot_length <= 0) $default_slot_length = 60;

            $default_min_adorers = isset($_POST['default_min_adorers'])
                ? (int) wp_unslash($_POST['default_min_adorers'])
                : (int)($schedule['default_min_adorers'] ?? 1);
            if ($default_min_adorers < 0) $default_min_adorers = 0;

            $default_max_raw = isset($_POST['default_max_adorers'])
                ? wp_unslash($_POST['default_max_adorers'])
                : ($schedule['default_max_adorers'] ?? '');
            // IMPORTANT: blank => NULL
            $default_max_adorers = ($default_max_raw === '' || $default_max_raw === null) ? null : (int) $default_max_raw;
            if ($default_max_adorers !== null && $default_max_adorers < 0) $default_max_adorers = null;

            // ✅ Perpetual adoration: rolling window (only meaningful for type=perpetual,
            // but harmless to store either way).
            $rolling_window_days = isset($_POST['rolling_window_days'])
                ? (int) wp_unslash($_POST['rolling_window_days'])
                : (int)($schedule['rolling_window_days'] ?? 60);
            if ($rolling_window_days <= 0) $rolling_window_days = 60;
            if ($rolling_window_days > 366) $rolling_window_days = 366;

            if ($start_date && $end_date && $end_date < $start_date) {
                $this->add_toast('End Date cannot be before Start Date.', 'error', false);
                $tab = 'basic';
                goto adoration_basic_info_done;
            }

            $allowed_status = ['draft', 'active'];
            if (!in_array($status, $allowed_status, true)) $status = 'draft';

            $allowed_privacy = ['counts_only', 'first_name_only', 'first_last_initial', 'names'];
            if (!in_array($privacy_mode, $allowed_privacy, true)) $privacy_mode = 'counts_only';

            if ($name === '') {
                $this->add_toast('Name is required.', 'error', false);
                $tab = 'basic';
            } else {

                if ($slug === '') $slug = sanitize_title($name);

                // Slug uniqueness check for edit-only
                if (!$is_create_mode && method_exists($schedulesRepo, 'exists_slug_except_id')
                    && $schedulesRepo->exists_slug_except_id($slug, $schedule_id) ) {

                    $base = $slug;
                    $i = 2;
                    while ( $schedulesRepo->exists_slug_except_id($slug, $schedule_id) ) {
                        $slug = $base . '-' . $i;
                        $i++;
                    }
                    $this->add_toast('Slug was already used. Updated to: ' . $slug, 'warning', false);
                }

                $payload = [
                    'chapel_id'           => $chapel_id, // ✅ NEW
                    'name'                => $name,
                    'slug'                => $slug,
                    'status'              => $status,
                    'privacy_mode'        => $privacy_mode,
                    'start_date'          => $start_date,
                    'end_date'            => $end_date,
                    'default_slot_length' => $default_slot_length,
                    'default_min_adorers' => $default_min_adorers,
                    'default_max_adorers' => $default_max_adorers,
                    'is_overnight'        => $is_overnight,
                    'rolling_window_days' => $rolling_window_days,
                ];

                if ($is_create_mode) {
                    // ✅ CREATE PATH
                    $new_id = 0;

                    if (method_exists($schedulesRepo, 'create_basic_info')) {
                        $new_id = (int) $schedulesRepo->create_basic_info($payload);
                    } elseif (method_exists($schedulesRepo, 'create')) {
                        $new_id = (int) $schedulesRepo->create($payload);
                    } else {
                        $this->add_toast('Cannot create: SchedulesRepository::create_basic_info() / ::create() is missing.', 'error', true);
                        $tab = 'basic';
                        goto adoration_basic_info_done;
                    }

                    if ($new_id > 0) {
                        // ✅ Step 1: persist overnight flag now that we have an ID
                        $this->set_schedule_flag($new_id, 'is_overnight', $is_overnight);

                        $edit_url = add_query_arg([
                            'page'        => $current_page_slug,
                            'action'      => 'edit',
                            'schedule_id' => $new_id,
                            'tab'         => 'basic',
                        ], admin_url('admin.php'));

                        $this->redirect_with_toast($edit_url, 'Schedule created.', 'success', false);
                    } else {
                        $this->add_toast('Failed to create schedule.', 'error', false);
                        $tab = 'basic';
                    }
                } else {
                    // ✅ UPDATE PATH
                    if ( ! method_exists($schedulesRepo, 'update_basic_info') ) {
                        $this->add_toast('Cannot save: SchedulesRepository::update_basic_info() is missing.', 'error', true);
                        $tab = 'basic';
                        goto adoration_basic_info_done;
                    }

                    $ok = $schedulesRepo->update_basic_info($schedule_id, $payload);

                    $this->set_schedule_flag($schedule_id, 'is_overnight', $is_overnight);

                    $this->add_toast($ok ? 'Basic info saved.' : 'Failed to save basic info.', $ok ? 'success' : 'error', false);

                    $schedule = $schedulesRepo->find($schedule_id) ?: $schedule;

                    // keep chapel_id + overnight in-memory for immediate render
                    $schedule['chapel_id'] = $chapel_id;
                    $schedule['is_overnight'] = (int) $this->get_schedule_flag($schedule_id, 'is_overnight', $is_overnight);

                    $tab = 'basic';
                }
            }
        }

        adoration_basic_info_done:

        // If we're still in create mode after POST (or on GET), we can only show the Basic tab safely.
        if ($is_create_mode) {
            $tab = 'basic';
        }

        /**
         * SLOT UPDATE (from modal)
         */
        if ( !$is_create_mode && isset($_POST['adoration_update_slot']) ) {
            check_admin_referer('adoration_update_slot');

            $slot_id = (int) ($_POST['slot_id'] ?? 0);

            $slot = $slotsRepo->find($slot_id);
            if (!$slot || (int)($slot['schedule_id'] ?? 0) !== $schedule_id) {
                $this->add_toast('Invalid slot.', 'error', false);
                $tab = 'slots';
            } else {
                $min_raw = isset($_POST['min_adorers']) ? wp_unslash($_POST['min_adorers']) : null;
                $min_adorers = ($min_raw === '' || $min_raw === null) ? null : (int) $min_raw;

                $max_raw = isset($_POST['max_adorers']) ? wp_unslash($_POST['max_adorers']) : null;
                $max_adorers = ($max_raw === '' || $max_raw === null) ? null : (int) $max_raw;

                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $public_note = sanitize_text_field(wp_unslash($_POST['public_note'] ?? ''));

                $ok = $slotsRepo->update_editable_fields($slot_id, $min_adorers, $max_adorers, $is_active, $public_note);
                $this->add_toast($ok ? 'Slot updated.' : 'Failed to update slot.', $ok ? 'success' : 'error', false);
                $tab = 'slots';
            }
        }

        /**
         * ADMIN: ADD SIGNUP
         */
        if ( !$is_create_mode && isset($_POST['adoration_admin_add_signup']) ) {
            check_admin_referer('adoration_admin_add_signup');

            // ✅ Coverage Calendar reuses this same handler to assign a substitute
            // for a specific date; it just wants to land back on the coverage tab
            // (with the same date selected) instead of the Signups tab.
            $add_signup_return_tab = isset($_POST['return_tab']) ? sanitize_key(wp_unslash($_POST['return_tab'])) : 'signups';
            if (!in_array($add_signup_return_tab, $allowed_tabs, true)) $add_signup_return_tab = 'signups';
            $cal_redirect_date = isset($_POST['cal_date']) ? sanitize_text_field(wp_unslash($_POST['cal_date'])) : '';

            $slot_id = (int) ($_POST['slot_id'] ?? 0);
            if ($slot_id <= 0) {
                $this->add_toast('Please click a time slot before adding a signup.', 'error', false);
                $tab = $add_signup_return_tab;
            } else {
                $slot = $slotsRepo->find($slot_id);
                if (!$slot || (int)($slot['schedule_id'] ?? 0) !== $schedule_id) {
                    $this->add_toast('Invalid slot selected.', 'error', false);
                    $tab = $add_signup_return_tab;
                } else {
                    // ✅ No-account adorers (2026-07-21): "Existing person" mode
                    // (see signups.php's search picker, backed by
                    // AdminPersonSearchAjax) submits a person_id directly —
                    // skips name/email/phone entirely, most importantly for
                    // reusing an already-created no-email person instead of
                    // creating a duplicate.
                    $existing_person_id = (int) ($_POST['person_id'] ?? 0);
                    $person_id = 0;

                    if ($existing_person_id > 0) {
                        $picked = $personsRepo->find($existing_person_id);
                        if (!$picked) {
                            $this->add_toast('That person could not be found. Please search again.', 'error', false);
                            $tab = $add_signup_return_tab;
                            goto adoration_admin_add_signup_done;
                        }
                        $person_id = $existing_person_id;
                    } else {
                        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
                        $last  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
                        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
                        $phone_raw = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
                        $phone = ($phone_raw !== '') ? $this->normalize_phone_us($phone_raw) : '';

                        // Email and phone are optional (no-account adorers) —
                        // only first/last name are required. Either one, if
                        // provided at all, must still be valid.
                        if ($first === '' || $last === '') {
                            $this->add_toast('First and last name are required.', 'error', false);
                            $tab = $add_signup_return_tab;
                            goto adoration_admin_add_signup_done;
                        }
                        if ($email !== '' && !is_email($email)) {
                            $this->add_toast('Please enter a valid email address, or leave it blank.', 'error', false);
                            $tab = $add_signup_return_tab;
                            goto adoration_admin_add_signup_done;
                        }
                        if ($phone_raw !== '' && $phone === null) {
                            $this->add_toast('Please enter a valid US phone number (10 digits), or leave it blank.', 'error', false);
                            $tab = $add_signup_return_tab;
                            goto adoration_admin_add_signup_done;
                        }

                        $email_norm = ($email !== '') ? strtolower(trim($email)) : '';

                        if ($email_norm !== '') {
                            $existing_person = $personsRepo->find_by_email($email_norm);
                            if ($existing_person) {
                                $ex_first = trim((string)($existing_person['first_name'] ?? ''));
                                $ex_last  = trim((string)($existing_person['last_name'] ?? ''));

                                $first_conflict = ($ex_first !== '' && $first !== '' && strcasecmp($ex_first, $first) !== 0);
                                $last_conflict  = ($ex_last  !== '' && $last  !== '' && strcasecmp($ex_last,  $last)  !== 0);

                                if ($first_conflict || $last_conflict) {
                                    $display = trim($ex_first . ($ex_last !== '' ? ' ' . $ex_last : ''));
                                    if ($display === '') $display = 'an existing adorer';

                                    $this->add_toast('That email address is already used by ' . $display . '.', 'error', false);
                                    $tab = $add_signup_return_tab;
                                    goto adoration_admin_add_signup_done;
                                }
                            }
                        }

                        $person_id = $personsRepo->upsert_by_email([
                            'first_name' => $first,
                            'last_name'  => $last,
                            'email'      => $email_norm,
                            'phone'      => ($phone !== null ? $phone : ''),
                        ]);
                    }

                    if ($person_id <= 0) {
                        $this->add_toast('Failed to save person record.', 'error', false);
                        $tab = $add_signup_return_tab;
                    } else {
                        $signup_date = $this->slot_true_ymd($slot);

                        $insert_id = $signupsRepo->create([
                            'person_id'   => $person_id,
                            'schedule_id' => $schedule_id,
                            'slot_id'     => $slot_id,
                            'date'        => $signup_date,
                            'status'      => 'confirmed',
                            'type'        => 'one_time',
                            'created_via' => 'admin',
                        ]);

                        global $wpdb;
                        if ($insert_id) {
                            $this->add_toast('Signup added.', 'success', false);
                        } else {
                            $this->add_toast('Failed to add signup (duplicate or DB error).', 'error', false);
                            if (!empty($wpdb->last_error)) {
                                error_log('[AdorationScheduler] Admin add signup failed: ' . $wpdb->last_error);
                            }
                        }

                        $tab = $add_signup_return_tab;
                    }
                }
            }
        }

        adoration_admin_add_signup_done:

        /**
         * DATES / SEGMENTS actions
         */
        if ( !$is_create_mode && isset($_POST['adoration_add_date']) ) {
            check_admin_referer('adoration_add_date');
            $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
            if (!$date) {
                $this->add_toast('Date is required.', 'error', false);
            } else {
                $id = $dateRepo->create($schedule_id, $date);
                $this->add_toast($id ? 'Date added.' : 'Failed to add date.', $id ? 'success' : 'error', false);
                $tab = 'dates';
            }
        }

        if ( !$is_create_mode && isset($_POST['adoration_add_segment']) ) {
            check_admin_referer('adoration_add_segment');
            $date_pattern_id = (int) ($_POST['date_pattern_id'] ?? 0);
            $start_time = sanitize_text_field(wp_unslash($_POST['start_time'] ?? ''));
            $end_time   = sanitize_text_field(wp_unslash($_POST['end_time'] ?? ''));
            $slot_len   = (isset($_POST['slot_length']) && wp_unslash($_POST['slot_length']) !== '') ? (int) wp_unslash($_POST['slot_length']) : null;

            if (!$date_pattern_id || !$start_time || !$end_time) {
                $this->add_toast('Date, start time, and end time are required.', 'error', false);
            } else {
                $id = $segmentsRepo->create([
                    'schedule_id' => $schedule_id,
                    'date_pattern_id' => $date_pattern_id,
                    'start_time' => $start_time,
                    'end_time'   => $end_time,
                    'slot_length'=> $slot_len,
                ]);
                $this->add_toast($id ? 'Segment added.' : 'Failed to add segment.', $id ? 'success' : 'error', false);
                $tab = 'dates';
            }
        }

        if ( !$is_create_mode && isset($_POST['adoration_clear_segments']) ) {
            check_admin_referer('adoration_clear_segments');

            $date_pattern_id = (int) ($_POST['date_pattern_id'] ?? 0);
            if ($date_pattern_id <= 0) {
                $this->add_toast('Missing date pattern.', 'error', false);
                $tab = 'dates';
            } else {
                $dates_check = $dateRepo->list_for_schedule($schedule_id);
                $owned = false;
                foreach ($dates_check as $dd) {
                    if ((int)($dd['id'] ?? 0) === $date_pattern_id) { $owned = true; break; }
                }

                if (!$owned) {
                    $this->add_toast('Invalid date pattern.', 'error', false);
                } else {
                    if (method_exists($segmentsRepo, 'delete_for_date_pattern')) {
                        $n = (int) $segmentsRepo->delete_for_date_pattern($date_pattern_id);
                        $this->add_toast('Cleared ' . $n . ' segment(s).', 'success', false);
                    } else {
                        $this->add_toast('SegmentsRepository::delete_for_date_pattern() is missing.', 'error', true);
                    }
                }

                $tab = 'dates';
            }
        }

        if ( !$is_create_mode && isset($_POST['adoration_delete_segment']) ) {
            check_admin_referer('adoration_delete_segment');

            $segment_id = (int) ($_POST['segment_id'] ?? 0);
            $date_pattern_id = (int) ($_POST['date_pattern_id'] ?? 0);

            if ($segment_id <= 0) {
                $this->add_toast('Missing segment.', 'error', false);
                $tab = 'dates';
            } else {
                $owned = false;
                if ($date_pattern_id > 0) {
                    $dates_check = $dateRepo->list_for_schedule($schedule_id);
                    foreach ($dates_check as $dd) {
                        if ((int)($dd['id'] ?? 0) === $date_pattern_id) { $owned = true; break; }
                    }
                }

                if (!$owned) {
                    $this->add_toast('Invalid date pattern for this schedule.', 'error', false);
                } else {
                    if (method_exists($segmentsRepo, 'delete')) {
                        $n = (int) $segmentsRepo->delete($segment_id);
                        $this->add_toast($n ? 'Segment deleted.' : 'Segment not found (or already deleted).', $n ? 'success' : 'warning', false);
                    } else {
                        $this->add_toast('SegmentsRepository::delete() is missing.', 'error', true);
                    }
                }

                $tab = 'dates';
            }
        }

        /**
         * WEEKLY HOURS (perpetual schedules): add a weekday template row.
         * Segments then attach to it via the existing adoration_add_segment /
         * adoration_clear_segments / adoration_delete_segment handlers above,
         * which operate generically on date_pattern_id.
         */
        if ( !$is_create_mode && isset($_POST['adoration_add_weekday']) ) {
            check_admin_referer('adoration_add_weekday');

            $day_of_week = isset($_POST['day_of_week']) ? (int) wp_unslash($_POST['day_of_week']) : -1;

            if ($day_of_week < 0 || $day_of_week > 6) {
                $this->add_toast('Please choose a day of the week.', 'error', false);
            } else {
                $id = $dateRepo->create_weekday_template($schedule_id, $day_of_week);
                $this->add_toast($id ? 'Weekday added.' : 'Failed to add weekday (or already added).', $id ? 'success' : 'warning', false);
            }
            $tab = 'weekly_hours';
        }

        /**
         * WEEKLY HOURS Quick Setup: apply the same hours to several days of the
         * week in one step (e.g. "24 hours a day, every day" for a true 24/7
         * chapel) instead of adding each day one at a time.
         *
         * This REPLACES each selected day's hours (clears existing segments for
         * that weekday template first) rather than appending, so re-running Quick
         * Setup doesn't stack duplicate segments. It never touches slots that
         * have already been generated — same "only affects future generation"
         * principle as Safe Sync for event schedules.
         */
        if ( !$is_create_mode && isset($_POST['adoration_quick_setup_weekly_hours']) ) {
            check_admin_referer('adoration_quick_setup_weekly_hours');

            $day_keys = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
            $selected_days = [];
            foreach ($day_keys as $key => $dow) {
                if (!empty($_POST['qs_day_' . $key])) $selected_days[] = $dow;
            }

            $full_day = !empty($_POST['qs_full_day']);

            if ($full_day) {
                $start_time = '00:00';
                $end_time   = '00:00';
            } else {
                $start_time = sanitize_text_field(wp_unslash($_POST['qs_start_time'] ?? ''));
                $end_time   = sanitize_text_field(wp_unslash($_POST['qs_end_time'] ?? ''));
            }

            $slot_len_raw = isset($_POST['qs_slot_length']) ? wp_unslash($_POST['qs_slot_length']) : '';
            $slot_len = ($slot_len_raw !== '' && $slot_len_raw !== null) ? (int) $slot_len_raw : null;
            if ($slot_len !== null && $slot_len <= 0) $slot_len = null;

            if (empty($selected_days)) {
                $this->add_toast('Choose at least one day.', 'error', false);
            } elseif ($start_time === '' || $end_time === '') {
                $this->add_toast('Start and end time are required (or check "Open 24 hours").', 'error', false);
            } else {
                // A segment where end <= start (including a full 24-hour day,
                // start === end) needs Overnight enabled, or the generator
                // silently skips it — the exact "confusing" trap we want to avoid.
                $start_ts = strtotime('1970-01-01 ' . $start_time);
                $end_ts   = strtotime('1970-01-01 ' . $end_time);
                $needs_overnight = ($start_ts !== false && $end_ts !== false && $end_ts <= $start_ts);

                if ($needs_overnight && empty($schedule['is_overnight'])) {
                    $schedulesRepo->set_overnight($schedule_id, true);
                    $this->set_schedule_flag($schedule_id, 'is_overnight', 1);
                    $schedule['is_overnight'] = 1;
                    $this->add_toast('Overnight was turned on automatically so a full 24-hour day actually generates slots.', 'info', false);
                }

                $days_done = 0;
                foreach ($selected_days as $dow) {
                    $date_pattern_id = $dateRepo->create_weekday_template($schedule_id, $dow);
                    if ($date_pattern_id <= 0) continue;

                    // Replace, don't stack, so re-running Quick Setup is safe.
                    if (method_exists($segmentsRepo, 'delete_for_date_pattern')) {
                        $segmentsRepo->delete_for_date_pattern($date_pattern_id);
                    }

                    $segmentsRepo->create([
                        'schedule_id'     => $schedule_id,
                        'date_pattern_id' => $date_pattern_id,
                        'start_time'      => $start_time,
                        'end_time'        => $end_time,
                        'slot_length'     => $slot_len,
                    ]);
                    $days_done++;
                }

                $this->add_toast(
                    'Applied hours to ' . (int)$days_done . ' day(s). Click "Sync Slots Now" below to generate slots right away.',
                    $days_done > 0 ? 'success' : 'warning',
                    false
                );
            }
            $tab = 'weekly_hours';
        }

        /**
         * PERPETUAL: manual "Sync Now" — materialize this schedule's rolling window
         * immediately instead of waiting for the daily background job.
         */
        if ( !$is_create_mode && isset($_POST['adoration_sync_perpetual_now']) ) {
            check_admin_referer('adoration_sync_perpetual_now');

            $days_ahead = (int)($schedule['rolling_window_days'] ?? 60);
            if ($days_ahead <= 0) $days_ahead = 60;

            $perpGenerator = new PerpetualSlotGenerator($dateRepo, $segmentsRepo, $slotsRepo, $commitmentsRepo, $signupsRepo);
            $result = $perpGenerator->sync_window($schedule, $days_ahead);

            $this->add_toast(
                'Sync complete. Checked: ' . (int)($result['generated'] ?? 0)
                . ', New slots: ' . (int)($result['inserted'] ?? 0)
                . ', Standing signups created: ' . (int)($result['signups_created'] ?? 0) . '.',
                'success',
                false
            );
            $tab = isset($_POST['return_tab']) ? sanitize_key(wp_unslash($_POST['return_tab'])) : 'weekly_hours';
            if (!in_array($tab, $allowed_tabs, true)) $tab = 'weekly_hours';
        }

        /**
         * MONTHLY OCCURRENCE (monthly schedules): add an nth-weekday-of-month
         * template row (e.g. "1st Friday"). Segments then attach to it via the
         * existing adoration_add_segment / adoration_clear_segments /
         * adoration_delete_segment handlers above, which operate generically
         * on date_pattern_id.
         */
        if ( !$is_create_mode && isset($_POST['adoration_add_monthly_pattern']) ) {
            check_admin_referer('adoration_add_monthly_pattern');

            $week_of_month = isset($_POST['week_of_month']) ? (int) wp_unslash($_POST['week_of_month']) : 0;
            $day_of_week   = isset($_POST['day_of_week']) ? (int) wp_unslash($_POST['day_of_week']) : -1;

            if ($day_of_week < 0 || $day_of_week > 6) {
                $this->add_toast('Please choose a day of the week.', 'error', false);
            } elseif ($week_of_month < 1 || $week_of_month > 6) {
                $this->add_toast('Please choose which occurrence of the month.', 'error', false);
            } else {
                $id = $dateRepo->create_monthly_template($schedule_id, $week_of_month, $day_of_week);
                $this->add_toast($id ? 'Pattern added.' : 'Failed to add pattern (or already added).', $id ? 'success' : 'warning', false);
            }
            $tab = 'monthly_occurrence';
        }

        /**
         * MONTHLY: manual "Sync Now" — materialize this schedule's rolling window
         * immediately instead of waiting for the daily background job.
         */
        if ( !$is_create_mode && isset($_POST['adoration_sync_monthly_now']) ) {
            check_admin_referer('adoration_sync_monthly_now');

            $days_ahead = (int)($schedule['rolling_window_days'] ?? 60);
            if ($days_ahead <= 0) $days_ahead = 60;

            $monthlyGenerator = new MonthlySlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
            $result = $monthlyGenerator->sync_window($schedule, $days_ahead);

            $this->add_toast(
                'Sync complete. Checked: ' . (int)($result['generated'] ?? 0)
                . ', New slots: ' . (int)($result['inserted'] ?? 0) . '.',
                'success',
                false
            );
            $tab = 'monthly_occurrence';
        }

        /**
         * STANDING COMMITMENTS: assign a person to an open weekly hour.
         * Mirrors the admin-add-signup flow's person lookup/creation logic.
         */
        if ( !$is_create_mode && isset($_POST['adoration_add_commitment']) ) {
            check_admin_referer('adoration_add_commitment');

            $day_of_week = isset($_POST['day_of_week']) ? (int) wp_unslash($_POST['day_of_week']) : -1;
            $start_time  = sanitize_text_field(wp_unslash($_POST['start_time'] ?? ''));
            $end_time    = sanitize_text_field(wp_unslash($_POST['end_time'] ?? ''));

            // ✅ No-account adorers (2026-07-21): same "Existing person"
            // search vs. free-text new-person shape as the Add Signup
            // handler above — see that block's comment for the rationale.
            $existing_person_id = (int) ($_POST['person_id'] ?? 0);

            $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
            $last  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $phone_raw = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
            $phone = ($phone_raw !== '') ? $this->normalize_phone_us($phone_raw) : '';
            $email_norm = '';

            if ($day_of_week < 0 || $day_of_week > 6 || $start_time === '' || $end_time === '') {
                $this->add_toast('Please choose a day and hour before assigning an adorer.', 'error', false);
                $tab = 'commitments';
            } elseif ($existing_person_id <= 0 && ($first === '' || $last === '')) {
                $this->add_toast('First and last name are required.', 'error', false);
                $tab = 'commitments';
            } elseif ($existing_person_id <= 0 && $email !== '' && !is_email($email)) {
                $this->add_toast('Please enter a valid email address, or leave it blank.', 'error', false);
                $tab = 'commitments';
            } elseif ($existing_person_id <= 0 && $phone_raw !== '' && $phone === null) {
                $this->add_toast('Please enter a valid US phone number (10 digits), or leave it blank.', 'error', false);
                $tab = 'commitments';
            } else {
                if ($existing_person_id > 0) {
                    $picked = $personsRepo->find($existing_person_id);
                    $person_id = $picked ? $existing_person_id : 0;
                    if ($picked) {
                        // Used below for the confirmation email / display —
                        // reflect the picked person's actual name on file
                        // rather than whatever (blank) name fields rode along.
                        $first = trim((string)($picked['first_name'] ?? ''));
                        $last  = trim((string)($picked['last_name'] ?? ''));
                        $picked_email = trim((string)($picked['email'] ?? ''));
                        if ($picked_email !== '' && is_email($picked_email) && strpos($picked_email, '@adoration.invalid') === false) {
                            $email_norm = strtolower($picked_email);
                        }
                    }
                } else {
                    $email_norm = ($email !== '') ? strtolower(trim($email)) : '';

                    $person_id = $personsRepo->upsert_by_email([
                        'first_name' => $first,
                        'last_name'  => $last,
                        'email'      => $email_norm,
                        'phone'      => ($phone !== null ? $phone : ''),
                    ]);
                }

                if ($person_id <= 0) {
                    $this->add_toast('Failed to save person record.', 'error', false);
                } else {
                    // ✅ StandingCommitmentsRepository::create() intentionally does NOT
                    // check capacity itself (see its docblock) — callers must. This was
                    // previously missing here, so an admin could over-assign a weekly
                    // hour past the schedule's default_max_adorers.
                    $default_max = ($schedule['default_max_adorers'] ?? '') !== '' && $schedule['default_max_adorers'] !== null
                        ? (int)$schedule['default_max_adorers']
                        : null;

                    $current_count = $commitmentsRepo->count_active_for_day_time($schedule_id, $day_of_week, $start_time);

                    if ($default_max !== null && $current_count >= $default_max) {
                        $this->add_toast('That hour is already fully committed (' . $current_count . '/' . $default_max . '). End an existing commitment first.', 'error', false);
                        $commitment_id = 0;
                    } else {
                        $commitment_id = $commitmentsRepo->create([
                            'schedule_id' => $schedule_id,
                            'chapel_id'   => (int)($schedule['chapel_id'] ?? 0),
                            'person_id'   => $person_id,
                            'day_of_week' => $day_of_week,
                            'start_time'  => $start_time,
                            'end_time'    => $end_time,
                        ]);

                        $this->add_toast(
                            $commitment_id ? 'Standing commitment added.' : 'Failed to add commitment (it may already exist).',
                            $commitment_id ? 'success' : 'error',
                            false
                        );
                    }

                    // Immediately fill already-generated slots in the window for this hour.
                    if ($commitment_id) {
                        $days_ahead = (int)($schedule['rolling_window_days'] ?? 60);
                        if ($days_ahead <= 0) $days_ahead = 60;
                        $perpGenerator = new PerpetualSlotGenerator($dateRepo, $segmentsRepo, $slotsRepo, $commitmentsRepo, $signupsRepo);
                        $perpGenerator->sync_window($schedule, $days_ahead);

                        // ✅ One dedicated "your weekly commitment is confirmed" email here,
                        // mirroring StandingSignupHandler's public flow — sync_window() above
                        // silently auto-fills every matching future date (see the created_via
                        // === 'standing_commitment' guard in SignupsRepository::create()), so
                        // without this the adorer an admin assigns here would get no email at all.
                        // ✅ No-account adorers (2026-07-21): skip entirely when there's no
                        // real email — NotificationService would no-op on it anyway
                        // (is_email() guard), this just avoids the pointless attempt.
                        if ($email_norm !== '') {
                            try {
                                $day_labels = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                $day_label = $day_labels[$day_of_week] ?? '';
                                $schedule_title = trim((string)($schedule['name'] ?? 'Adoration'));
                                $ts = strtotime('1970-01-01 ' . $start_time);
                                $ets = strtotime('1970-01-01 ' . $end_time);
                                $time_label = $ts !== false ? date_i18n('g:i A', $ts) : $start_time;
                                if ($ets !== false) $time_label .= ' – ' . date_i18n('g:i A', $ets);

                                // Title isn't collected on this admin form, but
                                // upsert_by_email() preserves any title already on
                                // file — re-fetch so an existing "Father"/"Deacon"
                                // still shows up in the confirmation email.
                                $commitment_person = $personsRepo->find($person_id);
                                $commitment_title  = trim((string)($commitment_person['title'] ?? ''));

                                NotificationService::send_signup_confirmation([
                                    'to_email'       => $email_norm,
                                    'title'          => $commitment_title,
                                    'first_name'     => $first,
                                    'last_name'      => $last,
                                    'person_name'    => trim($first . ' ' . $last),
                                    'schedule_title' => $schedule_title,
                                    'schedule_name'  => $schedule_title,
                                    'slot_date'      => '',
                                    'slot_start'     => $start_time,
                                    'slot_end'       => $end_time,
                                    'slot_label'     => 'Every ' . $day_label . ', ' . $time_label,
                                    'manage_url'     => home_url('/my-adoration/'),
                                    'context'        => 'admin_standing',
                                    'send'           => true,
                                    'signup_id'      => 0,
                                    'person_id'      => (int)$person_id,
                                ]);
                            } catch (\Throwable $e) {
                                error_log('[AdorationScheduler] Admin add-commitment confirmation email failed: ' . $e->getMessage());
                            }
                        }
                    }
                }
                $tab = 'commitments';
            }
        }

        if ( !$is_create_mode && isset($_POST['adoration_end_commitment']) ) {
            check_admin_referer('adoration_end_commitment');

            $commitment_id = (int) ($_POST['commitment_id'] ?? 0);
            $commitment = $commitment_id > 0 ? $commitmentsRepo->find($commitment_id) : null;

            if (!$commitment || (int)($commitment['schedule_id'] ?? 0) !== $schedule_id) {
                $this->add_toast('Invalid commitment.', 'error', false);
            } else {
                $ok = $commitmentsRepo->end($commitment_id);

                // ✅ FIX: ending a commitment only deactivated the commitment
                // row — it never cancelled the future dated signups that
                // PerpetualSlotGenerator::apply_standing_commitments() had
                // already auto-created for it. Left as-is, the person kept
                // showing confirmed "Recurring" hours on every future date
                // even though the commitment that generated them was gone.
                // Cancel those too, same "cancel don't delete" pattern used
                // everywhere else (frees the slot, promotes any waitlist,
                // unschedules reminders — see cancel_signup_internal()).
                $cancelled_count = 0;
                if ($ok && class_exists('\\AdorationScheduler\\Domain\\Repositories\\SignupsRepository')) {
                    $signupsRepo = new \AdorationScheduler\Domain\Repositories\SignupsRepository();
                    if (method_exists($signupsRepo, 'list_ids_for_commitment')) {
                        $future_ids = $signupsRepo->list_ids_for_commitment(
                            $schedule_id,
                            (int)($commitment['person_id'] ?? 0),
                            (int)($commitment['day_of_week'] ?? -1),
                            (string)($commitment['start_time'] ?? ''),
                            current_time('Y-m-d')
                        );

                        if (!empty($future_ids)
                            && class_exists('\\AdorationScheduler\\Services\\AdminSignupActionsService')
                            && method_exists('\\AdorationScheduler\\Services\\AdminSignupActionsService', 'cancel_signup_internal')
                        ) {
                            foreach ($future_ids as $future_id) {
                                if (\AdorationScheduler\Services\AdminSignupActionsService::cancel_signup_internal((int)$future_id)) {
                                    $cancelled_count++;
                                }
                            }
                        }
                    }
                }

                if ($ok && $cancelled_count > 0) {
                    $this->add_toast(
                        sprintf(
                            /* translators: %d: number of future signups cancelled */
                            _n(
                                'Commitment ended and %d future signup was cancelled.',
                                'Commitment ended and %d future signups were cancelled.',
                                $cancelled_count,
                                'adoration-scheduler'
                            ),
                            $cancelled_count
                        ),
                        'success',
                        false
                    );
                } else {
                    $this->add_toast($ok ? 'Commitment ended.' : 'Failed to end commitment.', $ok ? 'success' : 'error', false);
                }
            }
            $tab = 'commitments';
        }

        /**
         * COVERAGE CALENDAR: cancel just one date's signup for a standing (or
         * one-time) slot — frees that specific date without touching the
         * underlying standing commitment, which keeps generating future weeks.
         */
        if ( !$is_create_mode && isset($_POST['adoration_coverage_cancel_signup']) ) {
            check_admin_referer('adoration_coverage_cancel_signup');

            $signup_id = (int) ($_POST['signup_id'] ?? 0);
            $signup = $signup_id > 0 ? $signupsRepo->find($signup_id) : null;

            if (!$signup || (int)($signup['schedule_id'] ?? 0) !== $schedule_id) {
                $this->add_toast('Invalid signup.', 'error', false);
            } elseif (class_exists('\\AdorationScheduler\\Services\\AdminSignupActionsService')
                && method_exists('\\AdorationScheduler\\Services\\AdminSignupActionsService', 'cancel_signup_internal')) {
                $ok = \AdorationScheduler\Services\AdminSignupActionsService::cancel_signup_internal($signup_id);
                $this->add_toast(
                    $ok ? 'Cancelled for that date. The standing commitment (if any) continues on future weeks.' : 'Failed to cancel.',
                    $ok ? 'success' : 'error',
                    false
                );
            } else {
                $this->add_toast('Cancellation service unavailable.', 'error', true);
            }

            $tab = 'coverage';
            $cal_redirect_date = sanitize_text_field(wp_unslash($_POST['cal_date'] ?? ''));
        }

        /**
         * CLOSURES: block out a date/time range (e.g. "Christmas: Dec 24 4pm –
         * Dec 26 4pm") — cancels every confirmed signup and deactivates every
         * slot whose window overlaps the range, without touching the weekly
         * hours template or standing commitments. PerpetualSlotGenerator also
         * consults active closures on every future sync so the range stays
         * shut even for dates not generated yet. Admin-only.
         */
        if ( !$is_create_mode && isset($_POST['adoration_add_closure']) ) {
            check_admin_referer('adoration_add_closure');

            $start_date = sanitize_text_field(wp_unslash($_POST['closure_start_date'] ?? ''));
            $start_time = sanitize_text_field(wp_unslash($_POST['closure_start_time'] ?? ''));
            $end_date   = sanitize_text_field(wp_unslash($_POST['closure_end_date'] ?? ''));
            $end_time   = sanitize_text_field(wp_unslash($_POST['closure_end_time'] ?? ''));
            $reason     = sanitize_text_field(wp_unslash($_POST['closure_reason'] ?? ''));

            $start_time_norm = (strlen($start_time) === 5) ? ($start_time . ':00') : $start_time;
            $end_time_norm   = (strlen($end_time) === 5) ? ($end_time . ':00') : $end_time;

            $start_at = ($start_date !== '' && $start_time_norm !== '') ? trim($start_date . ' ' . $start_time_norm) : '';
            $end_at   = ($end_date !== '' && $end_time_norm !== '') ? trim($end_date . ' ' . $end_time_norm) : '';

            if ($start_at === '' || $end_at === '' || strtotime($start_at) === false || strtotime($end_at) === false) {
                $this->add_toast('Please enter a valid start and end date/time.', 'error', false);
            } elseif ($start_at >= $end_at) {
                $this->add_toast('The closure end must be after the start.', 'error', false);
            } else {
                $closure_id = $closuresRepo->create([
                    'schedule_id' => $schedule_id,
                    'chapel_id'   => (int)($schedule['chapel_id'] ?? 0),
                    'start_at'    => $start_at,
                    'end_at'      => $end_at,
                    'reason'      => $reason,
                    'created_by'  => get_current_user_id(),
                ]);

                if (!$closure_id) {
                    $this->add_toast('Failed to save the closure.', 'error', false);
                } else {
                    $affected_slots = $slotsRepo->list_active_overlapping($schedule_id, $start_at, $end_at);
                    $slot_ids = array_map(function ($s) { return (int)($s['id'] ?? 0); }, $affected_slots);
                    $slot_ids = array_values(array_filter($slot_ids));

                    $cancelled = 0;
                    if (!empty($slot_ids)
                        && class_exists('\\AdorationScheduler\\Services\\AdminSignupActionsService')
                        && method_exists('\\AdorationScheduler\\Services\\AdminSignupActionsService', 'cancel_signup_internal')
                    ) {
                        $confirmed_ids = $signupsRepo->list_confirmed_ids_for_slot_ids($slot_ids);
                        foreach ($confirmed_ids as $sid) {
                            if (\AdorationScheduler\Services\AdminSignupActionsService::cancel_signup_internal((int)$sid)) {
                                $cancelled++;
                            }
                        }
                    }

                    $closed = !empty($slot_ids) ? $slotsRepo->deactivate_by_ids($slot_ids) : 0;

                    $this->add_toast(
                        'Closure added. Cancelled ' . $cancelled . ' signup(s) and closed ' . $closed . ' slot(s) for that window.',
                        'success',
                        false
                    );
                }
            }

            $tab = 'coverage';
            $cal_redirect_date = ($start_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) ? $start_date : '';
        }

        if ( !$is_create_mode && isset($_POST['adoration_remove_closure']) ) {
            check_admin_referer('adoration_remove_closure');

            $closure_id = (int) ($_POST['closure_id'] ?? 0);
            $closure = $closure_id > 0 ? $closuresRepo->find($closure_id) : null;

            if (!$closure || (int)($closure['schedule_id'] ?? 0) !== $schedule_id) {
                $this->add_toast('Invalid closure.', 'error', false);
            } else {
                $start_at = (string)($closure['start_at'] ?? '');
                $end_at   = (string)($closure['end_at'] ?? '');

                $ok = $closuresRepo->delete($closure_id);

                $reopened = 0;
                if ($ok && $start_at !== '' && $end_at !== '') {
                    $inactive_slots = $slotsRepo->list_inactive_overlapping($schedule_id, $start_at, $end_at);
                    $slot_ids = array_map(function ($s) { return (int)($s['id'] ?? 0); }, $inactive_slots);
                    $slot_ids = array_values(array_filter($slot_ids));
                    if (!empty($slot_ids)) {
                        $reopened = $slotsRepo->reactivate_by_ids($slot_ids);
                    }
                }

                $this->add_toast(
                    $ok
                        ? ('Closure removed. Reopened ' . $reopened . ' slot(s). Note: cancelled signups from the closure are not automatically restored.')
                        : 'Failed to remove closure.',
                    $ok ? 'success' : 'error',
                    false
                );
            }

            $tab = 'coverage';
        }

        if ( !$is_create_mode && isset($_POST['adoration_preview_slots']) ) {
            check_admin_referer('adoration_preview_slots');

            $generator = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
            $preview_slots = $generator->preview_for_event_schedule($schedule);

            $this->add_toast('Preview generated ' . (int) count($preview_slots) . ' slots (no changes saved).', 'info', false);
            $tab = 'slots';
        }

        if ( !$is_create_mode && isset($_POST['adoration_sync_slots']) ) {
            check_admin_referer('adoration_sync_slots');

            $generator = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
            $result = $generator->sync_for_event_schedule($schedule);

            // ✅ Hydrate canonical datetimes for kept legacy rows so ordering becomes correct immediately.
            $hydrated = $this->hydrate_canonical_datetimes_for_schedule($schedule, $dateRepo, $segmentsRepo, $slotsRepo);

            $msg = 'Safe Sync complete. Kept: ' . (int)($result['kept'] ?? 0)
                 . ', Inserted: ' . (int)($result['inserted'] ?? 0)
                 . ', Deactivated: ' . (int)($result['deactivated'] ?? 0) . '.';

            if ($hydrated > 0) {
                $msg .= ' Fixed ordering on ' . (int)$hydrated . ' slot(s).';
            }

            $this->add_toast($msg, 'success', false);
            $tab = 'slots';
        }

        /**
         * ✅ DESTRUCTIVE REBUILD GUARDRAIL:
         * Block Delete+Recreate when ANY signups exist.
         */
        if ( !$is_create_mode && isset($_POST['adoration_generate_slots']) ) {
            check_admin_referer('adoration_generate_slots');

            $signups_total = $this->signups_count_for_schedule($schedule_id);
            if ($signups_total > 0) {
                $this->add_toast(
                    'Rebuild blocked: this schedule has ' . (int)$signups_total . ' signup(s). Use Safe Sync instead, or create a new schedule if you truly need a rebuild.',
                    'error',
                    true
                );
                $tab = 'slots';
            } else {
                $slotsRepo->delete_by_schedule($schedule_id);

                $generator = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
                $count = $generator->generate_for_event_schedule($schedule);

                // ✅ New rows should already have start_at/end_at (if schema supports),
                // but this keeps things robust if any inserts were legacy-only.
                $hydrated = $this->hydrate_canonical_datetimes_for_schedule($schedule, $dateRepo, $segmentsRepo, $slotsRepo);

                $msg = 'Generated ' . (int)$count . ' slots.';
                if ($hydrated > 0) {
                    $msg .= ' Fixed ordering on ' . (int)$hydrated . ' slot(s).';
                }

                $this->add_toast($msg, 'success', false);
                $tab = 'slots';
            }
        }

        // Data for tabs
        $dates = !$is_create_mode ? $dateRepo->list_for_schedule($schedule_id) : [];
        $segments_by_date = [];
        foreach ($dates as $d) {
            $segments_by_date[(int)$d['id']] = $segmentsRepo->list_for_date_pattern((int)$d['id']);
        }

        // ✅ Perpetual adoration: weekday templates + standing commitments
        $is_perpetual = (!$is_create_mode && (string)($schedule['type'] ?? 'event') === 'perpetual');

        $weekday_templates = [];
        $segments_by_weekday_template = [];
        $commitments_grid = [];
        $commitments_list = [];
        $weekday_slot_starts = []; // [day_of_week] => [['start_time'=>'HH:MM:SS','label'=>'...'], ...]

        if ($is_perpetual) {
            $weekday_templates = $dateRepo->list_weekday_templates_for_schedule($schedule_id);
            foreach ($weekday_templates as $wt) {
                $segments_by_weekday_template[(int)$wt['id']] = $segmentsRepo->list_for_date_pattern((int)$wt['id']);
            }

            $commitments_grid = $commitmentsRepo->grid_for_schedule($schedule_id);
            $commitments_list = $commitmentsRepo->list_for_schedule($schedule_id, true);

            // Expand each weekday's segments into real slot start times (using the actual
            // generator logic, so overnight rollover / slot length are handled correctly)
            // so the "assign an adorer" form can offer real, bookable hour options.
            $slotPreviewGenerator = new SlotGenerator($dateRepo, $segmentsRepo, $slotsRepo);
            $tz_for_preview = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $today_dt = new \DateTime('today', $tz_for_preview);

            foreach ($weekday_templates as $wt) {
                $dow = (int)($wt['day_of_week'] ?? -1);
                if ($dow < 0 || $dow > 6) continue;

                $segs = $segments_by_weekday_template[(int)$wt['id']] ?? [];
                if (empty($segs)) continue;

                // Find the next date (today or later) that falls on this weekday.
                $probe = clone $today_dt;
                for ($i = 0; $i < 7; $i++) {
                    if ((int)$probe->format('w') === $dow) break;
                    $probe->modify('+1 day');
                }

                $rows = $slotPreviewGenerator->build_slots_for_date($schedule, $probe->format('Y-m-d'), $segs);

                $seen = [];
                $options = [];
                foreach ($rows as $row) {
                    $st = substr((string)($row['start_time'] ?? ''), 0, 8);
                    if ($st === '' || isset($seen[$st])) continue;
                    $seen[$st] = true;

                    $ts = strtotime('1970-01-01 ' . $st);
                    $label = $ts !== false ? date_i18n('g:i A', $ts) : $st;

                    $et = substr((string)($row['end_time'] ?? ''), 0, 8);
                    $ets = strtotime('1970-01-01 ' . $et);
                    if ($ets !== false) {
                        $label .= ' – ' . date_i18n('g:i A', $ets);
                    }

                    $options[] = ['start_time' => $st, 'end_time' => $et, 'label' => $label];
                }

                $weekday_slot_starts[$dow] = $options;
            }
        }

        // ✅ Monthly recurrence: nth-weekday-of-month templates.
        $is_monthly = (!$is_create_mode && (string)($schedule['type'] ?? 'event') === 'monthly');

        $monthly_templates = [];
        $segments_by_monthly_template = [];

        if ($is_monthly) {
            $monthly_templates = $dateRepo->list_monthly_templates_for_schedule($schedule_id);
            foreach ($monthly_templates as $mt) {
                $segments_by_monthly_template[(int)$mt['id']] = $segmentsRepo->list_for_date_pattern((int)$mt['id']);
            }
        }

        // ✅ Guard against a stale/bookmarked tab that doesn't match this schedule's
        // type — each of these tabs belongs to exactly one schedule type; if $tab
        // belongs to a type other than this schedule's, redirect to that type's
        // own "primary" content tab instead of rendering a mismatched view.
        $tab_requires_type = [
            'dates'              => 'event',
            'weekly_hours'       => 'perpetual',
            'commitments'        => 'perpetual',
            'coverage'           => 'perpetual',
            'monthly_occurrence' => 'monthly',
        ];
        $current_type = $is_perpetual ? 'perpetual' : ($is_monthly ? 'monthly' : 'event');

        if (isset($tab_requires_type[$tab]) && $tab_requires_type[$tab] !== $current_type) {
            $tab = $is_perpetual ? 'weekly_hours' : ($is_monthly ? 'monthly_occurrence' : 'dates');
        }

        // ✅ Coverage Calendar: month grid + selected-date detail.
        $cal_year = 0;
        $cal_month = 0;
        $cal_date = '';
        $cal_weeks = [];
        $cal_day_slots = [];
        $cal_prev_url = '';
        $cal_next_url = '';
        $closures_list = [];

        if ($is_perpetual && $tab === 'coverage') {
            // All closures for this schedule (small, occasional list) — used both
            // for the "manage closures" panel and to shade affected calendar days.
            $closures_list = $closuresRepo->list_for_schedule($schedule_id);
            $closure_ts_ranges = [];
            foreach ($closures_list as $c) {
                $cs = strtotime((string)($c['start_at'] ?? ''));
                $ce = strtotime((string)($c['end_at'] ?? ''));
                if ($cs === false || $ce === false) continue;
                $closure_ts_ranges[] = ['start' => $cs, 'end' => $ce, 'reason' => (string)($c['reason'] ?? '')];
            }

            $tz_cal = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $today_cal = new \DateTime('today', $tz_cal);

            $cal_year  = isset($_GET['cal_year'])  ? (int) $_GET['cal_year']  : (int)$today_cal->format('Y');
            $cal_month = isset($_GET['cal_month']) ? (int) $_GET['cal_month'] : (int)$today_cal->format('n');
            if ($cal_month < 1 || $cal_month > 12) $cal_month = (int)$today_cal->format('n');

            // Selected date: prefer a just-completed POST action's date, else GET, else none.
            $cal_date = isset($cal_redirect_date) && $cal_redirect_date !== ''
                ? $cal_redirect_date
                : sanitize_text_field((string)($_GET['cal_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cal_date)) $cal_date = '';

            $month_start = sprintf('%04d-%02d-01', $cal_year, $cal_month);
            $first_of_month = new \DateTime($month_start, $tz_cal);
            $days_in_month = (int)$first_of_month->format('t');
            $month_end = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $days_in_month);

            $slot_counts   = $slotsRepo->count_by_date_in_range($schedule_id, $month_start, $month_end);
            $filled_counts = $signupsRepo->count_filled_slots_by_date_in_range($schedule_id, $month_start, $month_end);

            // Build a Sunday-first week grid covering the whole month (exactly
            // enough rows — 4, 5, or 6 depending on the month/weekday alignment).
            $lead_in = (int)$first_of_month->format('w'); // 0=Sunday
            $total_cells = $lead_in + $days_in_month;
            $total_weeks = (int)ceil($total_cells / 7);

            $cursor = clone $first_of_month;
            $cursor->modify('-' . $lead_in . ' days');

            $cal_weeks = [];
            for ($w = 0; $w < $total_weeks; $w++) {
                $week = [];
                for ($d = 0; $d < 7; $d++) {
                    $ymd = $cursor->format('Y-m-d');

                    // Is this day (any part of it) inside an active closure?
                    $day_start_ts = strtotime($ymd . ' 00:00:00');
                    $day_end_ts   = strtotime($ymd . ' 23:59:59');
                    $closed_reason = null;
                    foreach ($closure_ts_ranges as $cr) {
                        if ($day_start_ts <= $cr['end'] && $day_end_ts >= $cr['start']) {
                            $closed_reason = ($cr['reason'] !== '' ? $cr['reason'] : 'Closed');
                            break;
                        }
                    }

                    $week[] = [
                        'ymd'         => $ymd,
                        'day_num'     => (int)$cursor->format('j'),
                        'in_month'    => ((int)$cursor->format('n') === $cal_month),
                        'slot_count'  => (int)($slot_counts[$ymd] ?? 0),
                        'filled_count'=> (int)($filled_counts[$ymd] ?? 0),
                        'closed_reason' => $closed_reason,
                    ];
                    $cursor->modify('+1 day');
                }
                $cal_weeks[] = $week;
            }

            if ($cal_date !== '') {
                $day_signups = $signupsRepo->list_for_schedule_on_date($schedule_id, $cal_date);
                $signups_by_slot_for_day = [];
                foreach ($day_signups as $su) {
                    $sid = (int)($su['slot_id'] ?? 0);
                    if ($sid <= 0) continue;
                    if (!isset($signups_by_slot_for_day[$sid])) $signups_by_slot_for_day[$sid] = [];
                    $signups_by_slot_for_day[$sid][] = $su;
                }

                $day_slot_rows = $slotsRepo->list_for_schedule_on_date($schedule_id, $cal_date);
                foreach ($day_slot_rows as $slot_row) {
                    $sid = (int)($slot_row['id'] ?? 0);
                    $cal_day_slots[] = [
                        'slot'    => $slot_row,
                        'signups' => $signups_by_slot_for_day[$sid] ?? [],
                    ];
                }
            }

            $prev_month = $cal_month - 1; $prev_year = $cal_year;
            if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
            $next_month = $cal_month + 1; $next_year = $cal_year;
            if ($next_month > 12) { $next_month = 1; $next_year++; }

            $cal_prev_url = add_query_arg(array_merge($base_args, ['tab' => 'coverage', 'cal_year' => $prev_year, 'cal_month' => $prev_month]), admin_url('admin.php'));
            $cal_next_url = add_query_arg(array_merge($base_args, ['tab' => 'coverage', 'cal_year' => $next_year, 'cal_month' => $next_month]), admin_url('admin.php'));
        }

        $slots_total    = !$is_create_mode ? $slotsRepo->count_by_schedule($schedule_id) : 0;
        $slots_active   = !$is_create_mode ? $slotsRepo->count_active_by_schedule($schedule_id) : 0;
        $slots_inactive = !$is_create_mode ? $slotsRepo->count_inactive_by_schedule($schedule_id) : 0;

        // ✅ For UI guardrails in the Slots tab
        $signups_total = !$is_create_mode ? $this->signups_count_for_schedule($schedule_id) : 0;
        $has_signups   = ($signups_total > 0);

        /**
         * Slots tab: limited list
         * Signups tab: canonical SQL ordering list_for_signups_tab()
         */
        if (!$is_create_mode) {
            if ($tab === 'slots') {
                $slots_rows = $slotsRepo->list_for_schedule_limited($schedule_id, 100);
            } elseif ($tab === 'signups') {
                $slots_rows = method_exists($slotsRepo, 'list_for_signups_tab')
                    ? $slotsRepo->list_for_signups_tab($schedule_id, 2000)
                    : $slotsRepo->list_for_schedule_limited($schedule_id, 500);
            }

            if ($tab === 'signups') {
                $signup_counts = $signupsRepo->counts_by_slot_for_schedule($schedule_id);

                $all_confirmed = $signupsRepo->list_for_schedule($schedule_id, true);
                foreach ($all_confirmed as $su) {
                    $sid = (int)($su['slot_id'] ?? 0);
                    if ($sid <= 0) continue;
                    if (!isset($signups_by_slot[$sid])) $signups_by_slot[$sid] = [];
                    $signups_by_slot[$sid][] = $su;
                }

                // ✅ Waitlist (2026-07-17): who's waiting per slot, for admin visibility.
                if (class_exists(\AdorationScheduler\Domain\Repositories\WaitlistRepository::class)) {
                    $waitlist_repo_for_tab = new \AdorationScheduler\Domain\Repositories\WaitlistRepository();
                    $all_waiting = $waitlist_repo_for_tab->list_for_schedule($schedule_id, true);
                    foreach ($all_waiting as $wl) {
                        $sid = (int)($wl['slot_id'] ?? 0);
                        if ($sid <= 0) continue;
                        if (!isset($waitlist_by_slot[$sid])) $waitlist_by_slot[$sid] = [];
                        $waitlist_by_slot[$sid][] = $wl;
                    }
                }
            }
        }

        $views_base   = __DIR__ . '/EditSchedulePage';
        $tabs_dir     = $views_base . '/Tabs';
        $partials_dir = $views_base . '/Partials';

        ?>
        <div class="wrap">
            <h1>
                <?php
                if ($is_create_mode) {
                    echo esc_html__('Add Schedule', 'adoration-scheduler');
                } else {
                    echo esc_html('Edit Schedule: ' . (string)($schedule['name'] ?? ''));
                }
                ?>
            </h1>

            <?php $this->render_toasts(); ?>

            <p>
                <a class="button" href="<?php echo esc_url($back_url); ?>">← Back to Schedules</a>
            </p>

            <?php
            if ($is_create_mode) {
                $url = add_query_arg(array_merge($base_args, ['tab' => 'basic']), admin_url('admin.php'));
                echo '<h2 class="nav-tab-wrapper">';
                echo '<a href="' . esc_url($url) . '" class="nav-tab nav-tab-active">' . esc_html__('Basic Info', 'adoration-scheduler') . '</a>';
                echo '</h2>';
            } else {
                $tabs_nav = $partials_dir . '/tabs-nav.php';
                if (file_exists($tabs_nav)) {
                    include $tabs_nav;
                } else {
                    $tabs = [
                        'overview' => __('Overview', 'adoration-scheduler'),
                        'basic'    => __('Basic Info', 'adoration-scheduler'),
                        'dates'    => __('Dates & Hours', 'adoration-scheduler'),
                        'slots'    => __('Slots', 'adoration-scheduler'),
                        'signups'  => __('Signups', 'adoration-scheduler'),
                    ];
                    echo '<h2 class="nav-tab-wrapper">';
                    foreach ($tabs as $key => $label) {
                        $url = add_query_arg(array_merge($base_args, ['tab' => $key, 'schedule_id' => $schedule_id]), admin_url('admin.php'));
                        $cls = 'nav-tab' . ($tab === $key ? ' nav-tab-active' : '');
                        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($cls) . '">' . esc_html($label) . '</a>';
                    }
                    echo '</h2>';
                }
            }

            $tab_file = $tabs_dir . '/' . $tab . '.php';
            if (!file_exists($tab_file)) {
                $tab_file = $tabs_dir . '/overview.php';
            }

            if ($is_create_mode) {
                $tab_file = $tabs_dir . '/basic.php';
            }

            if (file_exists($tab_file)) {
                include $tab_file;
            } else {
                echo '<div class="notice notice-error"><p>Missing tab view files. Create: <code>' . esc_html($tabs_dir) . '/basic.php</code></p></div>';
            }
            ?>

        </div>
        <?php
    }
}
