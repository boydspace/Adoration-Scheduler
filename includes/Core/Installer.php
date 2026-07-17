<?php
namespace AdorationScheduler\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Installer {

    /**
     * Bump this whenever you change DB schema.
     * Keep it monotonic.
     */
    public const DB_VERSION = '2026-07-16-13';

    /**
     * Plugin capabilities (v1.0 guard rails).
     */
    private const CAPS = [
        'adoration_manage_signups',
        'adoration_manage_schedules',
        'adoration_manage_settings',
        'adoration_view_reports',
        'adoration_manage_people',
    ];

    /**
     * My Adoration page option + defaults.
     */
    private const OPT_MY_ADORATION_PAGE_ID = 'adoration_scheduler_my_adoration_page_id';
    private const MY_ADORATION_SLUG        = 'my-adoration';

    /**
     * ✅ 2026-07-16: [adoration_my_adoration] is retired in favor of the
     * modular dashboard shortcode family, so a fresh (or emptied-out) My
     * Adoration page is now provisioned with this stack instead. Existing
     * pages with real content are never touched (see ensure_my_adoration_page()).
     */
    private const MY_ADORATION_SHORTCODE = "[adoration_account_status]\n[adoration_profile_card]\n[adoration_next_adoration_hour]\n[adoration_announcements]\n[adoration_my_schedule]\n[adoration_my_replacement_requests]\n[adoration_needed_replacements]";

    /**
     * Request Access page option + defaults.
     *
     * ✅ 2026-07-16: a stable entry point for the approval gate — a visitor
     * doesn't have to first stumble onto a gated schedule/dashboard page to
     * see [adoration_request_access] (it already auto-shows there too via
     * AccessGateService/PersonDashboardTrait); this gives admins a URL they
     * can put in a bulletin, nav menu, or QR code. Same safe provisioning
     * pattern as the My Adoration page below — never overwrites real content.
     */
    private const OPT_REQUEST_ACCESS_PAGE_ID = 'adoration_scheduler_request_access_page_id';
    private const REQUEST_ACCESS_SLUG        = 'request-access';
    private const REQUEST_ACCESS_SHORTCODE   = "[adoration_request_access]";

    public static function install(): void {
        self::create_or_update_schema();
        self::ensure_caps();
        self::ensure_my_adoration_page(); // ✅ NEW
        self::ensure_request_access_page();
        update_option('adoration_scheduler_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade(): void {
        $current = (string) get_option('adoration_scheduler_db_version', '');

        if ($current === self::DB_VERSION && self::schema_looks_ok()) {
            // Still ensure caps exist (safe, quick).
            self::ensure_caps();
            self::schedule_ensure_my_adoration_page(); // ✅ repair if deleted (deferred — see below)
            self::schedule_ensure_request_access_page();
            return;
        }

        self::create_or_update_schema();
        self::ensure_caps();
        self::schedule_ensure_my_adoration_page(); // ✅ deferred — see below
        self::schedule_ensure_request_access_page();
        update_option('adoration_scheduler_db_version', self::DB_VERSION);

    }

    /** Guards against hooking the deferred page-check more than once per request. */
    private static bool $my_adoration_page_hooked = false;

    /** Same guard, for the Request Access page. */
    private static bool $request_access_page_hooked = false;

    /**
     * ✅ maybe_upgrade() runs at `plugins_loaded` time (called from
     * Plugin::init(), hooked to plugins_loaded). ensure_my_adoration_page()
     * can call wp_insert_post()/wp_update_post(), which internally call
     * get_permalink() for 'page' post types — and that needs the global
     * $wp_rewrite object, which WordPress doesn't create until AFTER
     * `plugins_loaded` fires (right before `init`). Calling it directly
     * from plugins_loaded is what caused the
     * "Call to a member function get_page_permastruct() on null" fatal.
     * Defer the actual repair to the `init` hook instead, where core's own
     * objects are guaranteed to exist.
     */
    private static function schedule_ensure_my_adoration_page(): void
    {
        if (self::$my_adoration_page_hooked) {
            return;
        }
        self::$my_adoration_page_hooked = true;

        if (did_action('init')) {
            // Called after 'init' already fired (e.g. late-loaded context) — safe now.
            self::ensure_my_adoration_page();
            return;
        }

        add_action('init', [__CLASS__, 'run_deferred_my_adoration_page_check'], 20);
    }

    /**
     * Public wrapper so add_action() can reach the otherwise-private
     * ensure_my_adoration_page() from outside class scope.
     */
    public static function run_deferred_my_adoration_page_check(): void
    {
        self::ensure_my_adoration_page();
    }

    /**
     * Same `init`-hook deferral as schedule_ensure_my_adoration_page() and
     * for the same reason (wp_insert_post()/wp_update_post() on a 'page'
     * post type needs $wp_rewrite, which isn't ready at plugins_loaded time).
     */
    private static function schedule_ensure_request_access_page(): void
    {
        if (self::$request_access_page_hooked) {
            return;
        }
        self::$request_access_page_hooked = true;

        if (did_action('init')) {
            self::ensure_request_access_page();
            return;
        }

        add_action('init', [__CLASS__, 'run_deferred_request_access_page_check'], 20);
    }

    /**
     * Public wrapper so add_action() can reach the otherwise-private
     * ensure_request_access_page() from outside class scope.
     */
    public static function run_deferred_request_access_page_check(): void
    {
        self::ensure_request_access_page();
    }

    /**
     * ✅ Add plugin caps to roles.
     * - Always adds all CAPS to Administrator.
     * - Adds non-settings caps to Editor (safe default for parish office use).
     * - Uses wp_roles() to avoid edge cases where get_role() returns null too early.
     */
    private static function ensure_caps(): void
    {
        if ( ! function_exists('wp_roles') ) {
            return;
        }

        $wp_roles = wp_roles();
        if ( ! $wp_roles ) {
            return;
        }

        // Administrator gets everything.
        $admin = $wp_roles->get_role('administrator');
        if ($admin) {
            foreach (self::CAPS as $cap) {
                if ( ! $admin->has_cap($cap) ) {
                    $admin->add_cap($cap);
                }
            }
        }

        // Editor gets operational caps (no settings).
        $editor = $wp_roles->get_role('editor');
        if ($editor) {
            foreach ([
                'adoration_manage_signups',
                'adoration_manage_schedules',
                'adoration_view_reports',
                'adoration_manage_people',
            ] as $cap) {
                if ( ! $editor->has_cap($cap) ) {
                    $editor->add_cap($cap);
                }
            }
        }

        if (function_exists('wp_cache_delete')) {
        wp_cache_delete('user_roles', 'options');
        }

    }

    /**
     * ✅ Defensive guard: some request contexts (notably a plain
     * `plugins_loaded`-time call, before WP's own default-constants pass has
     * run) can reach wp_untrash_post()/wp_update_post()'s revision-saving
     * code path with WP_POST_REVISIONS still undefined, which is a fatal
     * Error on PHP 8+. Define it the same way WP core's own default does if
     * it isn't set yet, right before any operation that might touch the
     * post-save pipeline.
     */
    private static function ensure_wp_post_revisions_constant(): void
    {
        if ( ! defined('WP_POST_REVISIONS') ) {
            define('WP_POST_REVISIONS', true);
        }
    }

    /**
     * Ensure the "My Adoration" page exists and store its ID.
     *
     * - If option points to a real page: keep it
     * - Else adopt existing published page at slug /my-adoration
     * - Else create it with the shortcode as content
     * - Never overwrites non-empty content
     * - ✅ Never auto-restores a page that's currently in Trash — if an
     *   admin trashed it, that's a deliberate action and this repair routine
     *   (which runs on every request via maybe_upgrade()) must not silently
     *   undo it. A trashed page is left alone; the "Pages & Shortcodes"
     *   admin page will surface its status instead of crashing/reviving it.
     */
    private static function ensure_my_adoration_page(): void
    {
        $opt_key = self::OPT_MY_ADORATION_PAGE_ID;
        $slug    = self::MY_ADORATION_SLUG;

        // 1) Option already set and valid?
        $saved_id = (int) get_option($opt_key, 0);
        if ($saved_id > 0) {
            $p = get_post($saved_id);
            if ($p && $p->post_type === 'page') {
                if (get_post_status($saved_id) === 'trash') {
                    // Deliberately trashed — leave it alone, don't resurrect it.
                    return;
                }

                // ✅ Optional: reaffirm the option points to this valid page
                update_option($opt_key, $saved_id);
                return;
            }
            // stale option
            delete_option($opt_key);
        }

        // 2) Page exists by path?
        $existing = get_page_by_path($slug, OBJECT, 'page');
        if ($existing && !empty($existing->ID)) {
            $existing_id = (int) $existing->ID;

            if (get_post_status($existing_id) === 'trash') {
                // Deliberately trashed — leave it alone, don't resurrect it,
                // and don't fall through to creating a duplicate at the same slug.
                update_option($opt_key, $existing_id);
                return;
            }

            update_option($opt_key, $existing_id);

            // If empty content, add shortcode. Otherwise leave content alone.
            $post = get_post($existing_id);
            if ($post && trim((string)$post->post_content) === '') {
                self::ensure_wp_post_revisions_constant();
                wp_update_post([
                    'ID'           => $existing_id,
                    'post_content' => self::MY_ADORATION_SHORTCODE,
                ]);
            }

            return;
        }

        // 3) Create page
        $new_id = wp_insert_post([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => __('My Adoration', 'adoration-scheduler'),
            'post_name'      => $slug,
            'post_content'   => self::MY_ADORATION_SHORTCODE,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if (!is_wp_error($new_id) && (int)$new_id > 0) {
            update_option($opt_key, (int)$new_id);
            return;
        }

        $msg = is_wp_error($new_id) ? $new_id->get_error_message() : 'Unknown error';
        error_log('[AdorationScheduler] Failed to create My Adoration page: ' . $msg);
    }

    /**
     * Ensure the "Request Access" page exists and store its ID.
     *
     * Identical safety rules to ensure_my_adoration_page() above:
     * - If option points to a real page: keep it
     * - Else adopt existing published page at slug /request-access
     * - Else create it with the shortcode as content
     * - Never overwrites non-empty content
     * - Never auto-restores a page an admin deliberately trashed
     *
     * Note: this page is only a convenience entry point. The approval gate
     * itself doesn't depend on it existing — [adoration_request_access]
     * already auto-shows in place of any gated schedule/dashboard shortcode
     * via AccessGateService/PersonDashboardTrait regardless of whether this
     * page is present.
     */
    private static function ensure_request_access_page(): void
    {
        $opt_key = self::OPT_REQUEST_ACCESS_PAGE_ID;
        $slug    = self::REQUEST_ACCESS_SLUG;

        // 1) Option already set and valid?
        $saved_id = (int) get_option($opt_key, 0);
        if ($saved_id > 0) {
            $p = get_post($saved_id);
            if ($p && $p->post_type === 'page') {
                if (get_post_status($saved_id) === 'trash') {
                    // Deliberately trashed — leave it alone, don't resurrect it.
                    return;
                }

                update_option($opt_key, $saved_id);
                return;
            }
            // stale option
            delete_option($opt_key);
        }

        // 2) Page exists by path?
        $existing = get_page_by_path($slug, OBJECT, 'page');
        if ($existing && !empty($existing->ID)) {
            $existing_id = (int) $existing->ID;

            if (get_post_status($existing_id) === 'trash') {
                update_option($opt_key, $existing_id);
                return;
            }

            update_option($opt_key, $existing_id);

            // If empty content, add shortcode. Otherwise leave content alone.
            $post = get_post($existing_id);
            if ($post && trim((string)$post->post_content) === '') {
                self::ensure_wp_post_revisions_constant();
                wp_update_post([
                    'ID'           => $existing_id,
                    'post_content' => self::REQUEST_ACCESS_SHORTCODE,
                ]);
            }

            return;
        }

        // 3) Create page
        $new_id = wp_insert_post([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => __('Request Access', 'adoration-scheduler'),
            'post_name'      => $slug,
            'post_content'   => self::REQUEST_ACCESS_SHORTCODE,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if (!is_wp_error($new_id) && (int)$new_id > 0) {
            update_option($opt_key, (int)$new_id);
            return;
        }

        $msg = is_wp_error($new_id) ? $new_id->get_error_message() : 'Unknown error';
        error_log('[AdorationScheduler] Failed to create Request Access page: ' . $msg);
    }


    private static function schema_looks_ok(): bool {
        global $wpdb;

        $prefix = $wpdb->prefix . 'adoration_';

        $magic_links_table   = $prefix . 'magic_links';
        $sessions_table      = $prefix . 'sessions';
        $email_log_table     = $wpdb->prefix . 'adoration_email_log';

        $chapels_table       = $prefix . 'chapels';
        $signups_table       = $prefix . 'signups';
        $slots_table         = $prefix . 'slots';
        $schedules_table     = $prefix . 'schedules';
        $persons_table       = $prefix . 'persons';

        // ✅ signup audit trail
        $signup_audit_table  = $prefix . 'signup_audit';

        if (!self::table_exists($magic_links_table) || !self::table_exists($sessions_table)) {
            return false;
        }

        if (!self::table_exists($email_log_table)) {
            return false;
        }

        // ✅ chapels must exist
        if (!self::table_exists($chapels_table)) {
            return false;
        }

        if (!self::table_exists($signups_table)) {
            return false;
        }

        if (!self::table_exists($slots_table)) {
            return false;
        }

        if (!self::table_exists($schedules_table)) {
            return false;
        }

        // ✅ audit table must exist
        if (!self::table_exists($signup_audit_table)) {
            return false;
        }

        if (!self::table_exists($persons_table)) {
            return false;
        }

        foreach (['ip', 'user_agent'] as $col) {
            if (!self::column_exists($magic_links_table, $col)) return false;
            if (!self::column_exists($sessions_table, $col)) return false;
        }

        // ✅ magic_links: selector/request_ip are written by MagicLinkService's
        // insert but were missing from the original CREATE TABLE + this check,
        // so an existing site's table could silently be missing them forever
        // (schema_looks_ok() returning true meant create_or_update_schema()'s
        // repair pass never ran). Fixed 2026-07-16.
        if (!self::column_exists($magic_links_table, 'selector'))   return false;
        if (!self::column_exists($magic_links_table, 'request_ip')) return false;

        if (!self::column_exists($sessions_table, 'session_token_hash')) return false;

        // ✅ sessions: same drift as magic_links above — handle_consume()'s
        // insert has always written `session_hash` (comment even says it's
        // "REQUIRED + UNIQUE"), but the CREATE TABLE only ever defined
        // `session_token_hash`, and this check never covered `session_hash`
        // either. Fixed 2026-07-16.
        if (!self::column_exists($sessions_table, 'session_hash')) return false;

        // ✅ current_person() has always queried `revoked_at`; never part of
        // the schema either. Fixed 2026-07-16.
        if (!self::column_exists($sessions_table, 'revoked_at')) return false;

        // ✅ Privacy/approval gate: persons.approval_status (2026-07-16).
        if (!self::column_exists($persons_table, 'approval_status')) return false;

        // ✅ Hybrid auth (Phase 2): optional password on top of magic-link (2026-07-16).
        if (!self::column_exists($persons_table, 'password_hash'))   return false;
        if (!self::column_exists($persons_table, 'password_set_at')) return false;

        // ✅ Replacement requests (Phase 3, 2026-07-16).
        if (!self::column_exists($persons_table, 'substitute_opt_in')) return false;

        // ✅ Clergy title + parish profile fields (2026-07-16).
        if (!self::column_exists($persons_table, 'title'))  return false;
        if (!self::column_exists($persons_table, 'parish')) return false;

        foreach ([
            'needs_replacement',
            'replacement_requested_at',
            'replacement_requested_by',
            'replacement_note',
            'replacement_claimed_by',
            'replacement_claimed_at',
        ] as $col) {
            if (!self::column_exists($signups_table, $col)) return false;
        }

        // ✅ Direct-to-person swap requests (2026-07-16): exclusive targeting.
        if (!self::column_exists($signups_table, 'replacement_target_person_id')) return false;

        foreach (['created_at','to_email','type','context','success','subject'] as $col) {
            if (!self::column_exists($email_log_table, $col)) return false;
        }

        // ✅ chapels minimal columns
        foreach (['name','slug','is_active','created_at'] as $col) {
            if (!self::column_exists($chapels_table, $col)) return false;
        }

        // ✅ schedules must have chapel_id + overnight support
        if (!self::column_exists($schedules_table, 'chapel_id')) return false;
        if (!self::column_exists($schedules_table, 'is_overnight')) return false;

        // ✅ signup uniqueness strategy
        if (!self::column_exists($signups_table, 'is_active')) return false;

        // ✅ critical for chronological ordering across midnight
        if (!self::column_exists($slots_table, 'chapel_id')) return false;
        if (!self::column_exists($slots_table, 'start_at')) return false;
        if (!self::column_exists($slots_table, 'end_at')) return false;

        // ✅ audit trail columns
        foreach (['signup_id','event_type','created_at'] as $col) {
            if (!self::column_exists($signup_audit_table, $col)) return false;
        }

        // ✅ Perpetual adoration schema
        $date_patterns_table = $prefix . 'date_patterns';
        $standing_commitments_table = $prefix . 'standing_commitments';

        if (!self::column_exists($date_patterns_table, 'day_of_week')) return false;
        if (!self::column_exists($schedules_table, 'rolling_window_days')) return false;
        if (!self::table_exists($standing_commitments_table)) return false;

        // ✅ Closure/blackout windows (e.g. "Christmas: Dec 24 4pm - Dec 26 4pm")
        $schedule_closures_table = $prefix . 'schedule_closures';
        if (!self::table_exists($schedule_closures_table)) return false;
        if (!self::column_exists($schedule_closures_table, 'start_at')) return false;
        if (!self::column_exists($schedule_closures_table, 'end_at')) return false;

        // ✅ Admin broadcast announcements (2026-07-16)
        $announcements_table = $prefix . 'announcements';
        if (!self::table_exists($announcements_table)) return false;
        if (!self::column_exists($announcements_table, 'is_active')) return false;

        // ✅ Coverage-gap alerting (2026-07-16): dedupe/last-sent stamp for the
        // "open hour within X" admin digest (CoverageAlertService).
        if (!self::column_exists($slots_table, 'coverage_alert_sent_at')) return false;

        // ✅ Monthly recurrence (2026-07-16): nth-weekday-of-month templates.
        if (!self::column_exists($date_patterns_table, 'week_of_month')) return false;

        return true;
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return $exists === $table;
    }

    private static function column_exists(string $table, string $column): bool {
        global $wpdb;
        $column = preg_replace('/[^A-Za-z0-9_]/', '', (string)$column);
        if ($column === '') return false;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column), ARRAY_A);
        return is_array($row) && !empty($row['Field']);
    }

    private static function create_or_update_schema(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'adoration_';

        $chapels_table       = $prefix . 'chapels';
        $schedules_table     = $prefix . 'schedules';
        $date_patterns_table = $prefix . 'date_patterns';
        $segments_table      = $prefix . 'segments';
        $slots_table         = $prefix . 'slots';
        $persons_table       = $prefix . 'persons';
        $signups_table       = $prefix . 'signups';

        $magic_links_table   = $prefix . 'magic_links';
        $sessions_table      = $prefix . 'sessions';

        $email_log_table     = $wpdb->prefix . 'adoration_email_log';

        // ✅ Signup audit trail table
        $signup_audit_table  = $prefix . 'signup_audit';

        // ✅ Perpetual adoration: standing (recurring weekly) commitments
        $standing_commitments_table = $prefix . 'standing_commitments';

        // ✅ Admin-declared closure/blackout windows (e.g. "Christmas: Dec 24 4pm - Dec 26 4pm").
        // A date-TIME range (spans multiple calendar days, arbitrary start/end times) that
        // cancels signups + deactivates slots for the duration, without touching the
        // underlying weekly-hours templates or standing commitments — normal generation
        // and signups resume automatically once the closure window ends.
        $schedule_closures_table = $prefix . 'schedule_closures';

        // ✅ Admin broadcast announcements shown on the front-end dashboard
        // (e.g. "Chapel closed for repairs Sat") — the "news" piece of the
        // modular shortcode family that replaced [adoration_my_adoration].
        $announcements_table = $prefix . 'announcements';

        /**
         * IMPORTANT:
         * dbDelta is very picky about indexes. Backtick everything.
         */

        $sql_chapels = "CREATE TABLE {$chapels_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            address TEXT NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY `slug` (`slug`)
        ) {$charset_collate};";

        $sql_schedules = "CREATE TABLE {$schedules_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            chapel_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'event',
            start_date DATE NULL,
            end_date DATE NULL,
            is_overnight TINYINT(1) NOT NULL DEFAULT 0,
            default_slot_length INT NOT NULL DEFAULT 60,
            default_min_adorers INT NOT NULL DEFAULT 1,
            default_max_adorers INT NULL,
            privacy_mode VARCHAR(30) NOT NULL DEFAULT 'counts_only',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            settings_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY `slug` (`slug`),
            KEY `idx_chapel_id` (`chapel_id`),
            KEY `idx_is_overnight` (`is_overnight`)
        ) {$charset_collate};";

        // ✅ Perpetual/Monthly schedules: date_patterns rows can be a specific calendar
        // date (event schedules, `date` set / `day_of_week`+`week_of_month` NULL), a
        // recurring weekday template (perpetual schedules, `day_of_week` 0-6 set /
        // `date`+`week_of_month` NULL), OR a recurring nth-weekday-of-month template
        // (monthly schedules, `day_of_week` 0-6 SET + `week_of_month` 1-5 (nth) or 6
        // ("last") set / `date` NULL). Segments attach to a date_patterns row either
        // way, so the existing generator plumbing (SegmentsRepository::list_for_date_pattern)
        // works unchanged for all three.
        $sql_date_patterns = "CREATE TABLE {$date_patterns_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(20) UNSIGNED NOT NULL,
            date DATE NULL,
            day_of_week TINYINT UNSIGNED NULL,
            week_of_month TINYINT UNSIGNED NULL,
            min_adorers INT NULL,
            max_adorers INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_date` (`date`),
            KEY `idx_day_of_week` (`day_of_week`),
            KEY `idx_week_of_month` (`week_of_month`)
        ) {$charset_collate};";

        $sql_segments = "CREATE TABLE {$segments_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(20) UNSIGNED NOT NULL,
            date_pattern_id BIGINT(20) UNSIGNED NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            slot_length INT NULL,
            min_adorers INT NULL,
            max_adorers INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_date_pattern_id` (`date_pattern_id`)
        ) {$charset_collate};";

        // ✅ Slots: add canonical datetimes for correct ordering across midnight
        $sql_slots = "CREATE TABLE {$slots_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(20) UNSIGNED NOT NULL,
            chapel_id BIGINT(20) UNSIGNED NOT NULL,
            date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            start_at DATETIME NULL,
            end_at   DATETIME NULL,
            min_adorers INT NOT NULL DEFAULT 1,
            max_adorers INT NULL,
            segment_id BIGINT(20) UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            public_note VARCHAR(255) NULL,
            coverage_alert_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_chapel_id` (`chapel_id`),
            KEY `idx_date` (`date`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_schedule_start_at` (`schedule_id`,`start_at`)
        ) {$charset_collate};";

        $sql_persons = "CREATE TABLE {$persons_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT(20) UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NULL,
            title VARCHAR(50) NULL,
            parish VARCHAR(190) NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            notes TEXT NULL,
            approval_status VARCHAR(20) NOT NULL DEFAULT 'approved',
            password_hash VARCHAR(255) NULL,
            password_set_at DATETIME NULL,
            substitute_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY `email` (`email`),
            KEY `idx_wp_user_id` (`wp_user_id`),
            KEY `idx_approval_status` (`approval_status`),
            KEY `idx_substitute_opt_in` (`substitute_opt_in`)
        ) {$charset_collate};";

        // ✅ Signups: add is_active + unique key includes is_active
        $sql_signups = "CREATE TABLE {$signups_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id BIGINT(20) UNSIGNED NOT NULL,
            schedule_id BIGINT(20) UNSIGNED NOT NULL,
            slot_id BIGINT(20) UNSIGNED NOT NULL,
            date DATE NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'one_time',
            status VARCHAR(30) NOT NULL DEFAULT 'confirmed',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_via VARCHAR(30) NOT NULL DEFAULT 'public_form',
            needs_replacement TINYINT(1) NOT NULL DEFAULT 0,
            replacement_requested_at DATETIME NULL,
            replacement_requested_by BIGINT(20) UNSIGNED NULL,
            replacement_note VARCHAR(500) NULL,
            replacement_claimed_by BIGINT(20) UNSIGNED NULL,
            replacement_claimed_at DATETIME NULL,
            replacement_target_person_id BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY `uniq_person_slot_active` (`person_id`,`slot_id`,`is_active`),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_slot_id` (`slot_id`),
            KEY `idx_date` (`date`),
            KEY `idx_status` (`status`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_needs_replacement` (`needs_replacement`),
            KEY `idx_replacement_target` (`replacement_target_person_id`)
        ) {$charset_collate};";

        $sql_email_log = "CREATE TABLE {$email_log_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            to_email VARCHAR(190) NOT NULL DEFAULT '',
            type VARCHAR(50) NOT NULL DEFAULT '',
            context VARCHAR(50) NOT NULL DEFAULT '',
            schedule_id BIGINT(20) UNSIGNED NULL,
            signup_id BIGINT(20) UNSIGNED NULL,
            subject TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            headers LONGTEXT NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            error_message TEXT NOT NULL,
            PRIMARY KEY  (id),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_to_email` (`to_email`),
            KEY `idx_type` (`type`),
            KEY `idx_success` (`success`),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_signup_id` (`signup_id`)
        ) {$charset_collate};";

        $sql_magic_links = "CREATE TABLE {$magic_links_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id BIGINT(20) UNSIGNED NOT NULL,
            selector CHAR(24) NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            request_ip VARCHAR(45) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY `token_hash` (`token_hash`),
            UNIQUE KEY `selector` (`selector`),
            KEY `idx_person_id` (`person_id`),
            KEY `idx_expires_at` (`expires_at`),
            KEY `idx_used_at` (`used_at`)
        ) {$charset_collate};";

        $sql_sessions = "CREATE TABLE {$sessions_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id BIGINT(20) UNSIGNED NOT NULL,
            session_token VARCHAR(80) NOT NULL,
            session_hash CHAR(64) NULL,
            session_token_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY `session_token` (`session_token`),
            UNIQUE KEY `session_hash` (`session_hash`),
            KEY `idx_session_token_hash` (`session_token_hash`),
            KEY `idx_person_id` (`person_id`),
            KEY `idx_expires_at` (`expires_at`),
            KEY `idx_revoked_at` (`revoked_at`)
        ) {$charset_collate};";

        // ✅ Signup audit trail (lightweight, append-only)
        $sql_signup_audit = "CREATE TABLE {$signup_audit_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            signup_id BIGINT(20) UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            actor_user_id BIGINT(20) UNSIGNED NULL,
            actor_label VARCHAR(191) NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY `idx_signup_id` (`signup_id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset_collate};";

        // ✅ Standing commitments: "who owns this hour every week" for perpetual schedules.
        // Reactivation pattern mirrors signups (is_active in the unique key) so ending and
        // later re-taking the same hour reuses the row instead of piling up duplicates.
        $sql_standing_commitments = "CREATE TABLE {$standing_commitments_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(20) UNSIGNED NOT NULL,
            chapel_id BIGINT(20) UNSIGNED NOT NULL,
            person_id BIGINT(20) UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            started_on DATE NULL,
            ended_on DATE NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY `uniq_commitment_active` (`schedule_id`,`day_of_week`,`start_time`,`person_id`,`is_active`),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_person_id` (`person_id`),
            KEY `idx_schedule_day_time` (`schedule_id`,`day_of_week`,`start_time`),
            KEY `idx_is_active` (`is_active`)
        ) {$charset_collate};";

        // ✅ Closure/blackout windows (holiday closures, etc.)
        $sql_schedule_closures = "CREATE TABLE {$schedule_closures_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(20) UNSIGNED NOT NULL,
            chapel_id BIGINT(20) UNSIGNED NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            reason VARCHAR(255) NULL,
            created_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY `idx_schedule_id` (`schedule_id`),
            KEY `idx_schedule_range` (`schedule_id`,`start_at`,`end_at`)
        ) {$charset_collate};";

        // ✅ Admin broadcast announcements (front-end "news" feed)
        $sql_announcements = "CREATE TABLE {$announcements_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset_collate};";

        dbDelta($sql_chapels);
        dbDelta($sql_schedules);
        dbDelta($sql_date_patterns);
        dbDelta($sql_segments);
        dbDelta($sql_slots);
        dbDelta($sql_persons);
        dbDelta($sql_signups);

        dbDelta($sql_email_log);

        dbDelta($sql_magic_links);
        dbDelta($sql_sessions);

        dbDelta($sql_signup_audit);
        dbDelta($sql_standing_commitments);
        dbDelta($sql_schedule_closures);
        dbDelta($sql_announcements);

        // Harden upgrades
        self::ensure_chapels_columns($chapels_table); // ✅ NEW
        $main_chapel_id = self::ensure_main_chapel_exists($chapels_table); // ✅ NEW

        self::ensure_persons_columns($persons_table);
        self::ensure_signups_columns($signups_table);
        self::ensure_slots_columns($slots_table);
        self::ensure_schedules_columns($schedules_table);

        // ✅ ensure audit schema for upgrades
        self::ensure_signup_audit_schema($signup_audit_table);

        // ✅ Perpetual adoration: weekday templates on date_patterns + standing commitments schema
        self::ensure_date_patterns_columns($date_patterns_table);
        self::ensure_standing_commitments_schema($standing_commitments_table);

        // ✅ backfill canonical datetimes so ordering works immediately (including existing rows)
        self::backfill_slots_datetimes($slots_table);

        self::ensure_email_log_columns($email_log_table);

        self::ensure_magic_links_columns($magic_links_table);
        self::ensure_sessions_columns($sessions_table);

        self::ensure_persons_email_unique($persons_table, $signups_table);

        // ✅ Migration order matters:
        self::backfill_signups_is_active($signups_table);
        self::maybe_drop_index($signups_table, 'uniq_person_slot');

        self::ensure_unique_index($signups_table, 'uniq_person_slot_active', ['person_id','slot_id','is_active']);

        // Use non-colliding index names (avoid KEY named same as column)
        self::maybe_add_index($signups_table, 'idx_is_active', 'is_active');

        self::ensure_unique_index($magic_links_table, 'token_hash', ['token_hash']);
        self::ensure_unique_index($magic_links_table, 'selector', ['selector']);
        self::ensure_unique_index($sessions_table, 'session_token', ['session_token']);
        self::ensure_unique_index($sessions_table, 'session_hash', ['session_hash']);

        self::maybe_add_index($sessions_table, 'idx_session_token_hash', 'session_token_hash');

        // ✅ make sure composite index exists for ordering signups/slots chronologically
        self::ensure_index($slots_table, 'idx_schedule_start_at', ['schedule_id','start_at']);

        // ✅ NEW: chapel backfills + slot sync
        self::backfill_schedules_chapel_id($schedules_table, $main_chapel_id);
        self::sync_slots_chapel_id_from_schedule($slots_table, $schedules_table);

        self::backfill_session_token_hash($sessions_table);
    }

    /**
     * ✅ NEW: Ensure chapels table has required columns + indexes for upgrades.
     */
    private static function ensure_chapels_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];

        if (!isset($have['name']))       $alters[] = "ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT ''";
        if (!isset($have['slug']))       $alters[] = "ADD COLUMN slug VARCHAR(191) NOT NULL DEFAULT ''";
        if (!isset($have['address']))    $alters[] = "ADD COLUMN address TEXT NULL";
        if (!isset($have['notes']))      $alters[] = "ADD COLUMN notes TEXT NULL";
        if (!isset($have['is_active']))  $alters[] = "ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1";
        if (!isset($have['created_at'])) $alters[] = "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
        if (!isset($have['updated_at'])) $alters[] = "ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::ensure_unique_index($table, 'slug', ['slug']);
        self::maybe_add_index($table, 'idx_is_active', 'is_active');
    }

    /**
     * ✅ NEW: Ensure "Main Chapel" exists and return its ID.
     * Safe to run repeatedly.
     */
    private static function ensure_main_chapel_exists(string $chapels_table): int {
        global $wpdb;

        if (!self::table_exists($chapels_table)) return 0;
        if (!self::column_exists($chapels_table, 'slug')) return 0;
        if (!self::column_exists($chapels_table, 'name')) return 0;

        $slug = 'main-chapel';
        $name = 'Main Chapel';

        // 1) Try by slug
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$chapels_table} WHERE slug = %s LIMIT 1",
            $slug
        ));
        if ($id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update($chapels_table, ['is_active' => 1], ['id' => $id], ['%d'], ['%d']);
            return $id;
        }

        // 2) Try by name
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$chapels_table} WHERE name = %s LIMIT 1",
            $name
        ));
        if ($id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $chapels_table,
                ['slug' => $slug, 'is_active' => 1],
                ['id' => $id],
                ['%s','%d'],
                ['%d']
            );
            return $id;
        }

        // 3) Insert
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert(
            $chapels_table,
            ['name' => $name, 'slug' => $slug, 'is_active' => 1],
            ['%s','%s','%d']
        );

        if (!$ok) {
            $fallback = 'main-chapel-' . wp_generate_password(6, false, false);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $chapels_table,
                ['name' => $name, 'slug' => $fallback, 'is_active' => 1],
                ['%s','%s','%d']
            );
        }

        return (int) $wpdb->insert_id;
    }

    private static function backfill_schedules_chapel_id(string $schedules_table, int $main_chapel_id): void {
        global $wpdb;

        if ($main_chapel_id <= 0) return;
        if (!self::table_exists($schedules_table)) return;
        if (!self::column_exists($schedules_table, 'chapel_id')) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$schedules_table}
                    SET chapel_id = %d
                  WHERE chapel_id IS NULL OR chapel_id = 0",
                $main_chapel_id
            )
        );
    }

    private static function sync_slots_chapel_id_from_schedule(string $slots_table, string $schedules_table): void {
        global $wpdb;

        if (!self::table_exists($slots_table)) return;
        if (!self::table_exists($schedules_table)) return;

        if (!self::column_exists($slots_table, 'chapel_id')) return;
        if (!self::column_exists($slots_table, 'schedule_id')) return;
        if (!self::column_exists($schedules_table, 'chapel_id')) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("
            UPDATE {$slots_table} s
            INNER JOIN {$schedules_table} sch ON sch.id = s.schedule_id
            SET s.chapel_id = sch.chapel_id
            WHERE s.chapel_id IS NULL
               OR s.chapel_id = 0
               OR s.chapel_id <> sch.chapel_id
        ");
    }

    private static function ensure_signup_audit_schema(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];

        if (!isset($have['signup_id']))     $alters[] = "ADD COLUMN signup_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0";
        if (!isset($have['event_type']))    $alters[] = "ADD COLUMN event_type VARCHAR(50) NOT NULL DEFAULT ''";
        if (!isset($have['actor_user_id'])) $alters[] = "ADD COLUMN actor_user_id BIGINT(20) UNSIGNED NULL";
        if (!isset($have['actor_label']))   $alters[] = "ADD COLUMN actor_label VARCHAR(191) NULL";
        if (!isset($have['meta']))          $alters[] = "ADD COLUMN meta LONGTEXT NULL";
        if (!isset($have['created_at']))    $alters[] = "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::maybe_add_index($table, 'idx_signup_id', 'signup_id');
        self::maybe_add_index($table, 'idx_event_type', 'event_type');
        self::maybe_add_index($table, 'idx_created_at', 'created_at');
    }

    private static function ensure_schedules_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];

        if (!isset($have['chapel_id'])) {
            $alters[] = "ADD COLUMN chapel_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0";
        }
        if (!isset($have['is_overnight'])) {
            $alters[] = "ADD COLUMN is_overnight TINYINT(1) NOT NULL DEFAULT 0";
        }
        // ✅ Perpetual adoration: how many days ahead the rolling slot generator keeps materialized.
        if (!isset($have['rolling_window_days'])) {
            $alters[] = "ADD COLUMN rolling_window_days INT NOT NULL DEFAULT 60";
        }

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::maybe_add_index($table, 'idx_chapel_id', 'chapel_id');
        self::maybe_add_index($table, 'idx_is_overnight', 'is_overnight');
    }

    /**
     * ✅ Perpetual adoration: date_patterns rows now serve double duty.
     * - Event schedules: `date` set, `day_of_week` NULL (unchanged behavior).
     * - Perpetual schedules: `day_of_week` (0=Sunday..6=Saturday) set, `date` NULL
     *   (a recurring weekday template; segments attach to it the same as before).
     *
     * dbDelta() is unreliable at loosening an existing NOT NULL constraint and at
     * adding brand-new columns to some existing installs, so we handle both explicitly.
     */
    private static function ensure_date_patterns_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = $c;
        }

        if (!isset($have['day_of_week'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN day_of_week TINYINT UNSIGNED NULL");
        }

        // ✅ Monthly recurrence (2026-07-16): 1-5 = nth occurrence, 6 = "last".
        // Only set alongside day_of_week (date NULL) — see the CREATE TABLE comment.
        if (!isset($have['week_of_month'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN week_of_month TINYINT UNSIGNED NULL");
        }

        // If `date` is still NOT NULL (pre-existing installs), loosen it so weekday
        // template rows (date IS NULL) can be inserted.
        if (isset($have['date']) && stripos((string)($have['date']['Null'] ?? 'YES'), 'NO') === 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN date DATE NULL");
        }

        self::maybe_add_index($table, 'idx_day_of_week', 'day_of_week');
        self::maybe_add_index($table, 'idx_week_of_month', 'week_of_month');
    }

    /**
     * ✅ Perpetual adoration: standing commitments schema hardening for upgrades.
     * (New installs get this correctly from dbDelta already; this is belt-and-suspenders
     * for the unique key, matching the pattern used for adoration_signups.)
     */
    private static function ensure_standing_commitments_schema(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        self::ensure_unique_index(
            $table,
            'uniq_commitment_active',
            ['schedule_id', 'day_of_week', 'start_time', 'person_id', 'is_active']
        );

        self::ensure_index($table, 'idx_schedule_day_time', ['schedule_id', 'day_of_week', 'start_time']);
        self::maybe_add_index($table, 'idx_schedule_id', 'schedule_id');
        self::maybe_add_index($table, 'idx_person_id', 'person_id');
        self::maybe_add_index($table, 'idx_is_active', 'is_active');
    }

    private static function backfill_slots_datetimes(string $slots_table): void {
        global $wpdb;

        if (!self::table_exists($slots_table)) return;
        if (!self::column_exists($slots_table, 'start_at')) return;
        if (!self::column_exists($slots_table, 'end_at')) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("
            UPDATE {$slots_table}
            SET
                start_at = IF(
                    start_at IS NULL OR start_at = '0000-00-00 00:00:00',
                    TIMESTAMP(`date`, start_time),
                    start_at
                ),
                end_at = IF(
                    end_at IS NULL OR end_at = '0000-00-00 00:00:00',
                    IF(
                        TIME(end_time) <= TIME(start_time),
                        TIMESTAMP(DATE_ADD(`date`, INTERVAL 1 DAY), end_time),
                        TIMESTAMP(`date`, end_time)
                    ),
                    end_at
                )
            WHERE `date` IS NOT NULL
        ");
    }

    private static function backfill_signups_is_active(string $signups_table): void {
        global $wpdb;

        if (!self::table_exists($signups_table)) return;
        if (!self::column_exists($signups_table, 'is_active')) return;
        if (!self::column_exists($signups_table, 'status')) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("UPDATE {$signups_table} SET is_active = 0 WHERE LOWER(status) = 'cancelled'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("UPDATE {$signups_table} SET is_active = 1 WHERE status IS NULL OR LOWER(status) <> 'cancelled'");
    }

    private static function maybe_drop_index(string $table, string $index_name): void {
        global $wpdb;

        $index_name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$index_name);
        if ($index_name === '') return;

        $schema = self::get_db_name();
        if ($schema === '') return;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND INDEX_NAME = %s
              LIMIT 1",
            $schema,
            $table,
            $index_name
        ));

        if (!$exists) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("ALTER TABLE {$table} DROP INDEX `{$index_name}`");
    }

    private static function ensure_email_log_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];
        if (!isset($have['created_at']))    $alters[] = "ADD COLUMN created_at DATETIME NOT NULL";
        if (!isset($have['to_email']))      $alters[] = "ADD COLUMN to_email VARCHAR(190) NOT NULL DEFAULT ''";
        if (!isset($have['type']))          $alters[] = "ADD COLUMN type VARCHAR(50) NOT NULL DEFAULT ''";
        if (!isset($have['context']))       $alters[] = "ADD COLUMN context VARCHAR(50) NOT NULL DEFAULT ''";
        if (!isset($have['schedule_id']))   $alters[] = "ADD COLUMN schedule_id BIGINT(20) UNSIGNED NULL";
        if (!isset($have['signup_id']))     $alters[] = "ADD COLUMN signup_id BIGINT(20) UNSIGNED NULL";
        if (!isset($have['subject']))       $alters[] = "ADD COLUMN subject TEXT NOT NULL";
        if (!isset($have['body']))          $alters[] = "ADD COLUMN body LONGTEXT NOT NULL";
        if (!isset($have['headers']))       $alters[] = "ADD COLUMN headers LONGTEXT NOT NULL";
        if (!isset($have['success']))       $alters[] = "ADD COLUMN success TINYINT(1) NOT NULL DEFAULT 0";
        if (!isset($have['error_message'])) $alters[] = "ADD COLUMN error_message TEXT NOT NULL";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::maybe_add_index($table, 'idx_created_at', 'created_at');
        self::maybe_add_index($table, 'idx_to_email', 'to_email');
        self::maybe_add_index($table, 'idx_type', 'type');
        self::maybe_add_index($table, 'idx_success', 'success');
        self::maybe_add_index($table, 'idx_schedule_id', 'schedule_id');
        self::maybe_add_index($table, 'idx_signup_id', 'signup_id');
    }

    private static function ensure_magic_links_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];
        if (!isset($have['ip']))         $alters[] = "ADD COLUMN ip VARCHAR(45) NULL";
        if (!isset($have['user_agent'])) $alters[] = "ADD COLUMN user_agent VARCHAR(255) NULL";

        // ✅ These were always written by MagicLinkService::handle_request()'s
        // insert (person_id, selector, token_hash, ..., request_ip, ip,
        // user_agent) but were never actually part of the CREATE TABLE
        // definition, so any site whose table was created before this fix
        // would fail every insert with "Unknown column 'selector'". Nullable
        // so existing rows (which have neither) don't break the ALTER.
        if (!isset($have['selector']))   $alters[] = "ADD COLUMN selector CHAR(24) NULL";
        if (!isset($have['request_ip'])) $alters[] = "ADD COLUMN request_ip VARCHAR(45) NULL";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }
    }

    private static function ensure_sessions_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];
        if (!isset($have['ip']))                 $alters[] = "ADD COLUMN ip VARCHAR(45) NULL";
        if (!isset($have['user_agent']))         $alters[] = "ADD COLUMN user_agent VARCHAR(255) NULL";
        if (!isset($have['session_token_hash'])) $alters[] = "ADD COLUMN session_token_hash CHAR(64) NULL";

        // ✅ Same drift as magic_links' selector/request_ip: handle_consume()
        // has always written session_hash, but it was never part of this
        // table's schema. Nullable so it retrofits safely onto existing rows.
        if (!isset($have['session_hash']))       $alters[] = "ADD COLUMN session_hash CHAR(64) NULL";

        // ✅ current_person() has always queried s.revoked_at (comment: "also
        // respect revoked_at if present"), but it was never an actual column
        // either — every login check fatal'd with "Unknown column
        // 's.revoked_at'" until this was added.
        if (!isset($have['revoked_at']))         $alters[] = "ADD COLUMN revoked_at DATETIME NULL";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::maybe_add_index($table, 'idx_session_token_hash', 'session_token_hash');
    }

    private static function backfill_session_token_hash(string $sessions_table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions_table));
        if ($exists !== $sessions_table) return;

        if (!self::column_exists($sessions_table, 'session_token')) return;
        if (!self::column_exists($sessions_table, 'session_token_hash')) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = (array) $wpdb->get_results("
            SELECT id, session_token
            FROM {$sessions_table}
            WHERE session_token_hash IS NULL
              AND session_token IS NOT NULL
              AND session_token <> ''
            LIMIT 500
        ", ARRAY_A);

        if (empty($rows)) return;

        foreach ($rows as $r) {
            $id  = (int)($r['id'] ?? 0);
            $tok = (string)($r['session_token'] ?? '');
            if ($id <= 0 || $tok === '') continue;

            $hash = hash_hmac('sha256', $tok, wp_salt('auth'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $sessions_table,
                ['session_token_hash' => $hash],
                ['id' => $id],
                ['%s'],
                ['%d']
            );
        }
    }

    private static function ensure_persons_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];

        if (!isset($have['first_name'])) $alters[] = "ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT ''";
        if (!isset($have['last_name']))  $alters[] = "ADD COLUMN last_name VARCHAR(100) NULL";
        if (!isset($have['email']))      $alters[] = "ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''";
        if (!isset($have['phone']))      $alters[] = "ADD COLUMN phone VARCHAR(50) NULL";
        if (!isset($have['wp_user_id'])) $alters[] = "ADD COLUMN wp_user_id BIGINT(20) UNSIGNED NULL";
        if (!isset($have['notes']))      $alters[] = "ADD COLUMN notes TEXT NULL";

        // ✅ Privacy/approval gate (2026-07-16): existing persons default to
        // 'approved' so sites that never turn the gate on see no behavior
        // change at all. Only the new Request Access flow explicitly inserts
        // 'pending'.
        if (!isset($have['approval_status'])) {
            $alters[] = "ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'approved'";
        }

        // ✅ Hybrid auth (Phase 2, 2026-07-16): optional password on top of
        // the permanent magic-link option. NULL by default — nobody has a
        // password until they explicitly set one from their dashboard.
        if (!isset($have['password_hash']))   $alters[] = "ADD COLUMN password_hash VARCHAR(255) NULL";
        if (!isset($have['password_set_at'])) $alters[] = "ADD COLUMN password_set_at DATETIME NULL";

        // ✅ Replacement requests (Phase 3, 2026-07-16): who's willing to be
        // emailed when someone needs coverage. Off by default — opt-in only.
        if (!isset($have['substitute_opt_in'])) {
            $alters[] = "ADD COLUMN substitute_opt_in TINYINT(1) NOT NULL DEFAULT 0";
        }

        // ✅ Clergy title + parish profile fields (2026-07-16): e.g. "Fr.",
        // "Deacon", "Bishop" + "Immaculate Heart of Mary Parish". Both
        // optional free text, NULL by default.
        if (!isset($have['title']))  $alters[] = "ADD COLUMN title VARCHAR(50) NULL";
        if (!isset($have['parish'])) $alters[] = "ADD COLUMN parish VARCHAR(190) NULL";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        // ✅ One-time migration (2026-07-16): the field was briefly called
        // `parish_role` (combined "Parish, Role" free text) before the user
        // decided against attaching a role to the parish name. Any site that
        // already ran that version gets its data carried over to the new
        // `parish` column, then the old column is dropped.
        if (isset($have['parish_role'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("UPDATE {$table} SET parish = parish_role WHERE (parish IS NULL OR parish = '') AND parish_role IS NOT NULL AND parish_role != ''");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN parish_role");
        }

        self::maybe_add_index($table, 'idx_wp_user_id', 'wp_user_id');
        self::maybe_add_index($table, 'idx_approval_status', 'approval_status');
        self::maybe_add_index($table, 'idx_substitute_opt_in', 'substitute_opt_in');
    }

    private static function ensure_signups_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];
        if (!isset($have['person_id']))   $alters[] = "ADD COLUMN person_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0";
        if (!isset($have['schedule_id'])) $alters[] = "ADD COLUMN schedule_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0";
        if (!isset($have['slot_id']))     $alters[] = "ADD COLUMN slot_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0";
        if (!isset($have['date']))        $alters[] = "ADD COLUMN date DATE NOT NULL DEFAULT '1970-01-01'";
        if (!isset($have['status']))      $alters[] = "ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'confirmed'";
        if (!isset($have['type']))        $alters[] = "ADD COLUMN type VARCHAR(30) NOT NULL DEFAULT 'one_time'";
        if (!isset($have['created_via'])) $alters[] = "ADD COLUMN created_via VARCHAR(30) NOT NULL DEFAULT 'public_form'";
        if (!isset($have['created_at']))  $alters[] = "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
        if (!isset($have['is_active']))   $alters[] = "ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1";

        // ✅ Replacement requests (Phase 3, 2026-07-16): a person can flag an
        // upcoming signup as needing coverage without cancelling it outright.
        // replacement_requested_by is preserved even after a claim reassigns
        // person_id, so history ("originally X, covered by Y") survives.
        if (!isset($have['needs_replacement']))         $alters[] = "ADD COLUMN needs_replacement TINYINT(1) NOT NULL DEFAULT 0";
        if (!isset($have['replacement_requested_at']))   $alters[] = "ADD COLUMN replacement_requested_at DATETIME NULL";
        if (!isset($have['replacement_requested_by']))   $alters[] = "ADD COLUMN replacement_requested_by BIGINT(20) UNSIGNED NULL";
        if (!isset($have['replacement_note']))           $alters[] = "ADD COLUMN replacement_note VARCHAR(500) NULL";
        if (!isset($have['replacement_claimed_by']))     $alters[] = "ADD COLUMN replacement_claimed_by BIGINT(20) UNSIGNED NULL";
        if (!isset($have['replacement_claimed_at']))     $alters[] = "ADD COLUMN replacement_claimed_at DATETIME NULL";

        // ✅ Direct-to-person swap requests (2026-07-16): when set, a
        // replacement request is exclusive to this one person until they
        // claim it or the requester reopens it to the whole community.
        if (!isset($have['replacement_target_person_id'])) $alters[] = "ADD COLUMN replacement_target_person_id BIGINT(20) UNSIGNED NULL";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::maybe_add_index($table, 'idx_schedule_id', 'schedule_id');
        self::maybe_add_index($table, 'idx_slot_id', 'slot_id');
        self::maybe_add_index($table, 'idx_date', 'date');
        self::maybe_add_index($table, 'idx_status', 'status');
        self::maybe_add_index($table, 'idx_is_active', 'is_active');
        self::maybe_add_index($table, 'idx_replacement_target', 'replacement_target_person_id');
        self::maybe_add_index($table, 'idx_needs_replacement', 'needs_replacement');
    }

    private static function ensure_slots_columns(string $table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower($c['Field'] ?? '')] = true;
        }

        $alters = [];

        if (!isset($have['chapel_id']))  $alters[] = "ADD COLUMN chapel_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0";
        if (!isset($have['public_note'])) $alters[] = "ADD COLUMN public_note VARCHAR(255) NULL";
        if (!isset($have['start_at']))   $alters[] = "ADD COLUMN start_at DATETIME NULL";
        if (!isset($have['end_at']))     $alters[] = "ADD COLUMN end_at DATETIME NULL";
        if (!isset($have['coverage_alert_sent_at'])) $alters[] = "ADD COLUMN coverage_alert_sent_at DATETIME NULL";

        if (!empty($alters)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $alters));
        }

        self::maybe_add_index($table, 'idx_chapel_id', 'chapel_id');
        self::ensure_index($table, 'idx_schedule_start_at', ['schedule_id','start_at']);
    }

    private static function ensure_persons_email_unique(string $persons_table, string $signups_table): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $persons_table));
        if ($exists !== $persons_table) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("UPDATE {$persons_table} SET email = LOWER(TRIM(email)) WHERE email IS NOT NULL");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $blank_ids = (array) $wpdb->get_col("SELECT id FROM {$persons_table} WHERE email IS NULL OR email = ''");
        foreach ($blank_ids as $pid) {
            $pid = (int) $pid;
            $placeholder = "missing+{$pid}@invalid.local";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update($persons_table, ['email' => $placeholder], ['id' => $pid], ['%s'], ['%d']);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $dupes = (array) $wpdb->get_results("
            SELECT email, COUNT(*) AS c
            FROM {$persons_table}
            GROUP BY email
            HAVING c > 1
        ", ARRAY_A);

        foreach ($dupes as $d) {
            $email = (string)($d['email'] ?? '');
            if ($email === '') continue;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $ids = (array) $wpdb->get_col(
                $wpdb->prepare("SELECT id FROM {$persons_table} WHERE email = %s ORDER BY id ASC", $email)
            );
            if (count($ids) <= 1) continue;

            $keep_id = (int) $ids[0];
            $others  = array_map('intval', array_slice($ids, 1));

            foreach ($others as $dup_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->update($signups_table, ['person_id' => $keep_id], ['person_id' => $dup_id], ['%d'], ['%d']);
            }

            foreach ($others as $dup_id) {
                $new_email = $email . '+dup' . $dup_id;
                if (strlen($new_email) > 190) {
                    $new_email = substr($email, 0, 180) . '+dup' . $dup_id;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->update($persons_table, ['email' => $new_email], ['id' => $dup_id], ['%s'], ['%d']);
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("ALTER TABLE {$persons_table} MODIFY email VARCHAR(190) NOT NULL");
        self::ensure_unique_index($persons_table, 'email', ['email']);
    }

    /**
     * ✅ Hardened: add single-column index if missing.
     * - backticks everything
     * - skips if empty
     * - ignores duplicate-key errors safely
     */
    private static function maybe_add_index(string $table, string $index_name, string $column): void {
        global $wpdb;

        $index_name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$index_name);
        $column     = preg_replace('/[^A-Za-z0-9_]/', '', (string)$column);
        if ($index_name === '' || $column === '') return;

        $schema = self::get_db_name();
        if ($schema === '') return;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND INDEX_NAME = %s
              LIMIT 1",
            $schema,
            $table,
            $index_name
        ));

        if ($exists) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r = $wpdb->query("ALTER TABLE {$table} ADD INDEX `{$index_name}` (`{$column}`)");
        if ($r === false && !empty($wpdb->last_error)) {
            if (stripos($wpdb->last_error, 'Duplicate key name') !== false) return;
            error_log('[AdorationScheduler] maybe_add_index failed: ' . $wpdb->last_error);
        }
    }

    /**
     * ✅ Ensure a NON-UNIQUE index exists (supports multi-column).
     * Hardened for empty names/columns + backticks.
     */
    private static function ensure_index(string $table, string $index_name, array $columns): void {
        global $wpdb;

        $index_name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$index_name);
        if ($index_name === '') return;

        $cols = [];
        foreach ($columns as $c) {
            $c = preg_replace('/[^A-Za-z0-9_]/', '', (string)$c);
            if ($c !== '') $cols[] = $c;
        }
        if (empty($cols)) return;

        $schema = self::get_db_name();
        if ($schema === '') return;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND INDEX_NAME = %s
              LIMIT 1",
            $schema,
            $table,
            $index_name
        ));

        if ($exists) return;

        $cols_sql = implode(',', array_map(fn($c) => "`{$c}`", $cols));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $r = $wpdb->query("ALTER TABLE {$table} ADD INDEX `{$index_name}` ({$cols_sql})");
        if ($r === false && !empty($wpdb->last_error)) {
            if (stripos($wpdb->last_error, 'Duplicate key name') !== false) return;
            error_log('[AdorationScheduler] ensure_index failed: ' . $wpdb->last_error);
        }
    }

    private static function ensure_unique_index(string $table, string $index_name, array $columns): void {
        global $wpdb;

        $index_name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$index_name);
        if ($index_name === '') return;

        $cols = [];
        foreach ($columns as $c) {
            $c = preg_replace('/[^A-Za-z0-9_]/', '', (string)$c);
            if ($c !== '') $cols[] = $c;
        }
        if (empty($cols)) return;

        $schema = self::get_db_name();
        if ($schema === '') return;

        $non_unique = $wpdb->get_var($wpdb->prepare(
            "SELECT NON_UNIQUE
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND INDEX_NAME = %s
              LIMIT 1",
            $schema,
            $table,
            $index_name
        ));

        $cols_sql = implode(',', array_map(fn($c) => "`{$c}`", $cols));

        if ($non_unique === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY `{$index_name}` ({$cols_sql})");
            return;
        }

        $non_unique = (int)$non_unique;
        if ($non_unique === 0) return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("ALTER TABLE {$table} DROP INDEX `{$index_name}`");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY `{$index_name}` ({$cols_sql})");
    }

    private static function get_db_name(): string {
        global $wpdb;

        if (defined('DB_NAME') && DB_NAME) {
            return (string) DB_NAME;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $name = $wpdb->get_var("SELECT DATABASE()");
        return $name ? (string)$name : '';
    }
}
