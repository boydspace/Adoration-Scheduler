<?php
namespace AdorationScheduler\Admin;

if ( ! defined('ABSPATH') ) exit;

class Menu {

    /**
     * Prevent duplicate menu registration in the same request.
     */
    private static bool $did_register = false;

    /**
     * Capability constants (match Installer caps).
     */
    private const CAP_MANAGE_SCHEDULES = 'adoration_manage_schedules';
    private const CAP_MANAGE_SIGNUPS   = 'adoration_manage_signups';
    private const CAP_MANAGE_SETTINGS  = 'adoration_manage_settings';
    private const CAP_MANAGE_PEOPLE    = 'adoration_manage_people';

    /**
     * This should be called by Plugin::register_admin_menu() ONLY.
     * Do NOT hook this directly from Plugin::init.
     */
    public static function register_admin_menu(): void {

        // Guard: if something hooks us twice, ignore the second call.
        if (self::$did_register) {
            return;
        }
        self::$did_register = true;

        // ✅ Custom icon (monstrance) as data-uri SVG (reliable, no CSS hacks).
        $icon = self::menu_icon_monstrance_data_uri();

        // Top-level menu (Dashboard) — the landing page when an admin
        // clicks "Adoration Scheduler". Schedules is now its own explicit
        // sibling submenu below rather than doubling as the top-level page.
        add_menu_page(
            __('Adoration Scheduler', 'adoration-scheduler'),
            __('Adoration Scheduler', 'adoration-scheduler'),
            self::CAP_MANAGE_SCHEDULES,
            'adoration_scheduler_dashboard',
            [__CLASS__, 'render_dashboard_page'],
            $icon
        );

        // Dashboard (same-slug trick as Chapels/Settings below: relabels
        // the auto-created first submenu item from "Adoration Scheduler"
        // to "Dashboard").
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Dashboard', 'adoration-scheduler'),
            __('Dashboard', 'adoration-scheduler'),
            self::CAP_MANAGE_SCHEDULES,
            'adoration_scheduler_dashboard',
            [__CLASS__, 'render_dashboard_page']
        );

        // Schedules list
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Schedules', 'adoration-scheduler'),
            __('Schedules', 'adoration-scheduler'),
            self::CAP_MANAGE_SCHEDULES,
            'adoration_scheduler_schedules',
            [__CLASS__, 'render_schedules_page']
        );

        // Add New Schedule
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Add New Schedule', 'adoration-scheduler'),
            __('Add New Schedule', 'adoration-scheduler'),
            self::CAP_MANAGE_SCHEDULES,
            'adoration_scheduler_add_new',
            [__CLASS__, 'render_add_new_page']
        );

        // ✅ Signups
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Signups', 'adoration-scheduler'),
            __('Signups', 'adoration-scheduler'),
            self::CAP_MANAGE_SIGNUPS,
            'adoration_scheduler_signups',
            [__CLASS__, 'render_signups_page']
        );

        // People
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('People', 'adoration-scheduler'),
            __('People', 'adoration-scheduler'),
            self::CAP_MANAGE_PEOPLE,
            'adoration_scheduler_people',
            [__CLASS__, 'render_people_page']
        );

        // ✅ Access Requests (pending-registration review queue, split out
        // of All People so Accept/Reject are unmissable)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Access Requests', 'adoration-scheduler'),
            __('Access Requests', 'adoration-scheduler'),
            self::CAP_MANAGE_PEOPLE,
            'adoration_scheduler_people_access_requests',
            [__CLASS__, 'render_access_requests_page']
        );

        // Add Person
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Add Person', 'adoration-scheduler'),
            __('Add Person', 'adoration-scheduler'),
            self::CAP_MANAGE_PEOPLE,
            'adoration_scheduler_people_add',
            [__CLASS__, 'render_add_person_page']
        );

        // Merge People
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Merge People', 'adoration-scheduler'),
            __('Merge People', 'adoration-scheduler'),
            self::CAP_MANAGE_PEOPLE,
            'adoration_scheduler_people_merge',
            [__CLASS__, 'render_merge_people_page']
        );

        // ✅ Import / Export (bulk CSV/XLSX roster import + export)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Import / Export', 'adoration-scheduler'),
            __('Import / Export', 'adoration-scheduler'),
            self::CAP_MANAGE_PEOPLE,
            'adoration_scheduler_people_import_export',
            [__CLASS__, 'render_people_import_export_page']
        );

        // ✅ Chapels (treat as settings-level) — this is also the visible
        // "Settings" entry point; its sidebar label is "Settings" but the
        // page itself still renders as "Chapels" with a tab bar (see
        // render_settings_tabs()) linking to the other settings-family
        // pages below, all of which stay registered but are hidden from
        // the sidebar via remove_submenu_page() at the end of this method.
        $chapels_hook = add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Chapels', 'adoration-scheduler'),
            __('Settings', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_chapels',
            [__CLASS__, 'render_chapels_page']
        );

        // ✅ CRITICAL: handle POST/GET actions BEFORE any output begins (prevents headers-sent)
        if ($chapels_hook) {
            add_action('load-' . $chapels_hook, [__CLASS__, 'load_chapels_page']);
        }

        // Email Templates (settings-level)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Email Templates', 'adoration-scheduler'),
            __('Email Templates', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_email_templates',
            [__CLASS__, 'render_email_templates_page']
        );

        // ✅ Email Log (settings-level / admin)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Email Log', 'adoration-scheduler'),
            __('Email Log', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_email_log',
            [__CLASS__, 'render_email_log_page']
        );

        // Anti-Spam (settings-level)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Anti-Spam', 'adoration-scheduler'),
            __('Anti-Spam', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_antispam',
            [__CLASS__, 'render_antispam_page']
        );

        // ✅ Pages & Shortcodes (settings-level diagnostic page)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Pages & Shortcodes', 'adoration-scheduler'),
            __('Pages & Shortcodes', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_pages_shortcodes',
            [__CLASS__, 'render_pages_shortcodes_page']
        );

        // ✅ Access & Privacy (optional approval gate settings)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Access & Privacy', 'adoration-scheduler'),
            __('Access & Privacy', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_access',
            [__CLASS__, 'render_access_settings_page']
        );

        // ✅ Coverage Alerts (admin digest: open hours coming up soon)
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Coverage Alerts', 'adoration-scheduler'),
            __('Coverage Alerts', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_coverage_alerts',
            [__CLASS__, 'render_coverage_alerts_page']
        );

        // ✅ Coverage Report (2026-07-17): hours-served + fill-rate history,
        // for stewardship recognition / year-end reports. Grouped into the
        // Settings tab family like Coverage Alerts and Email Log (both
        // also signups-adjacent data views rather than actual settings),
        // so uses CAP_MANAGE_SETTINGS to match that family's capability.
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Coverage Report', 'adoration-scheduler'),
            __('Coverage Report', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_coverage_report',
            [__CLASS__, 'render_coverage_report_page']
        );

        // ✅ Attendance (2026-07-18): review who checked in for confirmed
        // hours + manually mark present/absent. Same Settings-family
        // grouping and capability as Coverage Report (signups-adjacent
        // reporting, not a toggle-style setting).
        $attendance_hook = add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Attendance', 'adoration-scheduler'),
            __('Attendance', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_attendance',
            [__CLASS__, 'render_attendance_page']
        );

        if ($attendance_hook) {
            add_action('load-' . $attendance_hook, [__CLASS__, 'load_attendance_page']);
        }

        // ✅ No-Show Alerts (2026-07-18): admin digest for confirmed hours
        // nobody checked into. Same Settings-API page pattern as Coverage
        // Alerts (register_settings() + options.php), no load-hook needed.
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('No-Show Alerts', 'adoration-scheduler'),
            __('No-Show Alerts', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_no_show_alerts',
            [__CLASS__, 'render_no_show_alerts_page']
        );

        // ✅ Setup Wizard (2026-07-18): first-run onboarding, reached only via
        // the one-time activation redirect (see Plugin.php) or a direct link
        // from the Dashboard checklist card — never shown in the sidebar and
        // not part of the Settings tab family (it's not a settings page).
        // Still needs a real add_submenu_page() registration so admin.php
        // routing/capability checks resolve it (see the note below about why
        // remove_submenu_page() isn't used for hiding).
        add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Setup Wizard', 'adoration-scheduler'),
            __('Setup Wizard', 'adoration-scheduler'),
            self::CAP_MANAGE_SCHEDULES,
            'adoration_scheduler_setup_wizard',
            [__CLASS__, 'render_setup_wizard_page']
        );

        // ✅ Announcements (front-end "news" feed via [adoration_announcements])
        $announcements_hook = add_submenu_page(
            'adoration_scheduler_dashboard',
            __('Announcements', 'adoration-scheduler'),
            __('Announcements', 'adoration-scheduler'),
            self::CAP_MANAGE_SETTINGS,
            'adoration_scheduler_announcements',
            [__CLASS__, 'render_announcements_page']
        );

        if ($announcements_hook) {
            add_action('load-' . $announcements_hook, [__CLASS__, 'load_announcements_page']);
        }

        // ✅ Consolidation (2026-07-16, revised for Dashboard): all items
        // above stay fully registered (URLs, load-hooks, and internal
        // self-referencing redirects all keep working exactly as before),
        // but only 5 are shown in the sidebar: Dashboard, Schedules,
        // Signups, People, and Settings (Chapels, relabeled above). The
        // rest are reachable via the tab bars added to each page's
        // render() (render_settings_tabs() / render_people_tabs()) or, for
        // Add New Schedule, the existing "Add New" button already on the
        // Schedules list page.
        //
        // ⚠️ Do NOT use remove_submenu_page() for this: it unsets the entry
        // from the $submenu global, and WordPress's own admin.php routing
        // (get_plugin_page_hook() -> get_admin_page_parent()) re-scans that
        // SAME $submenu global at request time to resolve which parent a
        // directly-navigated page belongs to. With the entry removed, that
        // lookup fails and admin.php falls through to its own core
        // "Sorry, you are not allowed to access this page." wp_die() —
        // even for a full administrator. (Tried this first; it broke every
        // tab.) Hiding the <li> visually via CSS instead leaves $submenu
        // fully intact, so routing/capability checks never change.
        add_action('admin_head', [__CLASS__, 'print_hidden_submenu_css']);
    }

    /**
     * Visually hides the 9 consolidated-away submenu items from the
     * sidebar without touching the $submenu global (see the comment above
     * register_admin_menu()'s call to this). Each item's <li> has no other
     * visible content once its <a> is hidden (WP core's own submenu CSS
     * puts all padding/height on the <a>), so hiding the anchor is enough
     * to collapse it — no JS or :has() dependency needed.
     */
    public static function print_hidden_submenu_css(): void {
        $hidden_slugs = [
            'adoration_scheduler_add_new',
            'adoration_scheduler_people_access_requests',
            'adoration_scheduler_people_add',
            'adoration_scheduler_people_merge',
            'adoration_scheduler_people_import_export',
            'adoration_scheduler_email_templates',
            'adoration_scheduler_email_log',
            'adoration_scheduler_antispam',
            'adoration_scheduler_pages_shortcodes',
            'adoration_scheduler_access',
            'adoration_scheduler_coverage_alerts',
            'adoration_scheduler_coverage_report',
            'adoration_scheduler_attendance',
            'adoration_scheduler_no_show_alerts',
            'adoration_scheduler_announcements',
            'adoration_scheduler_setup_wizard',
        ];

        echo '<style>';
        foreach ($hidden_slugs as $slug) {
            echo '#adminmenu a[href$="page=' . esc_attr($slug) . '"]{display:none !important;}';
        }
        echo '</style>';
    }

    /**
     * Shared tab bar for the Settings-family pages (Chapels, Email
     * Templates, Email Log, Anti-Spam, Access & Privacy, Announcements,
     * Pages & Shortcodes) — these are all still independently registered
     * pages (see register_admin_menu()), just hidden from the sidebar in
     * favor of one visible "Settings" entry point. Call at the top of each
     * page's render(), passing that page's own slug as $active.
     */
    public static function render_settings_tabs(string $active): void {
        $tabs = [
            'adoration_scheduler_chapels'          => __('Chapels', 'adoration-scheduler'),
            'adoration_scheduler_email_templates'  => __('Email Templates', 'adoration-scheduler'),
            'adoration_scheduler_email_log'        => __('Email Log', 'adoration-scheduler'),
            'adoration_scheduler_antispam'          => __('Anti-Spam', 'adoration-scheduler'),
            'adoration_scheduler_access'            => __('Access & Privacy', 'adoration-scheduler'),
            'adoration_scheduler_coverage_alerts'   => __('Coverage Alerts', 'adoration-scheduler'),
            'adoration_scheduler_coverage_report'   => __('Coverage Report', 'adoration-scheduler'),
            'adoration_scheduler_attendance'        => __('Attendance', 'adoration-scheduler'),
            'adoration_scheduler_no_show_alerts'    => __('No-Show Alerts', 'adoration-scheduler'),
            'adoration_scheduler_announcements'     => __('Announcements', 'adoration-scheduler'),
            'adoration_scheduler_pages_shortcodes'  => __('Pages & Shortcodes', 'adoration-scheduler'),
        ];

        self::render_tab_bar($tabs, $active);
    }

    /**
     * Shared tab bar for the People-family pages (All People, Access
     * Requests, Add Person, Merge People) — same hidden-but-registered
     * pattern as the Settings tabs above.
     */
    public static function render_people_tabs(string $active): void {
        $access_requests_label = __('Access Requests', 'adoration-scheduler');

        // ✅ Pending-count badge on the tab itself (WP-core "Pending (3)"
        // convention) so the queue is visible from anywhere in People
        // without having to click in first.
        if (class_exists('\\AdorationScheduler\\Domain\\Repositories\\PersonsRepository')) {
            try {
                $repo = new \AdorationScheduler\Domain\Repositories\PersonsRepository();
                $pending_count = (int) $repo->count_by_approval_status(\AdorationScheduler\Domain\Repositories\PersonsRepository::STATUS_PENDING);
                if ($pending_count > 0) {
                    $access_requests_label .= ' (' . $pending_count . ')';
                }
            } catch (\Throwable $e) {
                // Silently omit the badge if anything goes wrong — never
                // let a count query break the tab bar itself.
            }
        }

        $tabs = [
            'adoration_scheduler_people'                 => __('All People', 'adoration-scheduler'),
            'adoration_scheduler_people_access_requests'  => $access_requests_label,
            'adoration_scheduler_people_add'              => __('Add Person', 'adoration-scheduler'),
            'adoration_scheduler_people_merge'            => __('Merge People', 'adoration-scheduler'),
            'adoration_scheduler_people_import_export'    => __('Import / Export', 'adoration-scheduler'),
        ];

        self::render_tab_bar($tabs, $active);
    }

    /**
     * @param array<string,string> $tabs slug => label
     */
    private static function render_tab_bar(array $tabs, string $active): void {
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ($tabs as $slug => $label) {
            $url = admin_url('admin.php?page=' . $slug);
            $class = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    /**
     * Runs early (load-hook) to process announcement actions safely before headers/output.
     */
    public static function load_announcements_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AnnouncementsPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AnnouncementsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        $page = new $class();

        if (method_exists($page, 'handle_request')) {
            $page->handle_request();
        }
    }

    /**
     * Runs early (load-hook) to process chapels actions safely before headers/output.
     */
    public static function load_chapels_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = [
            'Pages/ChapelsPage.php',
            'Pages/ChapelPage.php',
            'Pages/Chapels.php',
        ];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\ChapelsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        $page = new $class();

        // ✅ If the page exposes handle_request(), run it here (pre-output)
        if (method_exists($page, 'handle_request')) {
            $page->handle_request();
        }
    }

    /**
     * Runs early (load-hook) to process the Mark Present/Absent POST safely
     * before headers/output — same pattern as load_chapels_page().
     */
    public static function load_attendance_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AttendancePage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AttendancePage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        $page = new $class();

        if (method_exists($page, 'handle_request')) {
            $page->handle_request();
        }
    }

    /**
     * Menu icon: improved monstrance silhouette that reads well at 20px.
     * Uses currentColor so WP applies the correct menu icon color automatically.
     */
    private static function menu_icon_monstrance_data_uri(): string {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <g fill="currentColor">
    <rect x="31" y="2" width="2" height="10"/>
    <rect x="31" y="52" width="2" height="10"/>
    <rect x="2" y="31" width="10" height="2"/>
    <rect x="52" y="31" width="10" height="2"/>
    <rect x="10" y="10" width="2" height="10" transform="rotate(-45 11 15)"/>
    <rect x="52" y="10" width="2" height="10" transform="rotate(45 53 15)"/>
    <rect x="10" y="44" width="2" height="10" transform="rotate(45 11 49)"/>
    <rect x="52" y="44" width="2" height="10" transform="rotate(-45 53 49)"/>
    <circle cx="32" cy="32" r="14"/>
    <circle cx="32" cy="32" r="6" fill="#fff"/>
  </g>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Helper: safely require a page class file from includes/Admin/Pages/
     */
    private static function require_admin_page_file(array $relative_candidates): void {
        $base_dir = plugin_dir_path(__FILE__); // .../includes/Admin/

        foreach ($relative_candidates as $rel) {
            $path = $base_dir . ltrim($rel, '/');
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
    }

    private static function die_missing(string $class, array $tried_files): void {
        $msg  = '<h1>Adoration Scheduler – Missing Admin Page</h1>';
        $msg .= '<p>Could not load required class:</p>';
        $msg .= '<p><code>' . esc_html($class) . '</code></p>';
        $msg .= '<p>Files checked:</p><ul>';
        foreach ($tried_files as $f) {
            $msg .= '<li><code>' . esc_html($f) . '</code></li>';
        }
        $msg .= '</ul>';
        $msg .= '<p>This usually means the filename does not match the class name your autoloader expects, or the file was moved/renamed.</p>';
        wp_die($msg, 500);
    }

    public static function render_schedules_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SCHEDULES) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = [
            'Pages/SchedulesPage.php',
            'Pages/SchedulePage.php',
            'Pages/Schedules.php',
        ];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\SchedulesPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_add_new_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SCHEDULES) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = [
            'Pages/AddNewSchedulePage.php',
            'Pages/AddSchedulePage.php',
            'Pages/AddNewPage.php',
        ];
        self::require_admin_page_file($candidates);

        $classes_to_try = [
            '\\AdorationScheduler\\Admin\\Pages\\AddNewSchedulePage',
            '\\AdorationScheduler\\Admin\\Pages\\AddSchedulePage',
            '\\AdorationScheduler\\Admin\\Pages\\AddNewPage',
        ];

        foreach ($classes_to_try as $class) {
            if (class_exists($class)) {
                (new $class())->render();
                return;
            }
        }

        self::die_missing($classes_to_try[0], $candidates);
    }

    public static function render_signups_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SIGNUPS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = [
            'Pages/SignupsPage.php',
            'Pages/Signups.php',
            'Pages/SignupPage.php',
        ];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\SignupsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        if (method_exists($class, 'register_actions')) {
            $class::register_actions();
        }

        (new $class())->render();
    }

    public static function render_people_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_PEOPLE) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        self::require_admin_page_file(['Pages/PersonsPage.php']);

        $class = '\\AdorationScheduler\\Admin\\Pages\\PersonsPage';
        if (!class_exists($class)) {
            self::die_missing($class, ['Pages/PersonsPage.php']);
        }

        (new $class())->render();
    }

    public static function render_dashboard_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SCHEDULES) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/DashboardPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\DashboardPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_access_requests_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_PEOPLE) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AccessRequestsPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AccessRequestsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_add_person_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_PEOPLE) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AddPersonPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AddPersonPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_merge_people_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_PEOPLE) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/MergePeoplePage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\MergePeoplePage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_people_import_export_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_PEOPLE) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/PeopleImportExportPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\PeopleImportExportPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_chapels_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = [
            'Pages/ChapelsPage.php',
            'Pages/ChapelPage.php',
            'Pages/Chapels.php',
        ];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\ChapelsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_email_templates_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $path = plugin_dir_path(__FILE__) . 'Pages/EmailTemplatesPage.php';
        if (file_exists($path)) {
            require_once $path;
        }

        $class = '\\AdorationScheduler\\Admin\\Pages\\EmailTemplatesPage';
        if (!class_exists($class)) {
            self::die_missing($class, ['Pages/EmailTemplatesPage.php']);
        }

        (new $class())->render();
    }

    public static function render_email_log_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = [
            'Pages/EmailLogPage.php',
            'Pages/MailLogPage.php',
        ];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\EmailLogPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        if (method_exists($class, 'register_actions')) {
            $class::register_actions();
        }

        (new $class())->render();
    }

    public static function render_antispam_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $path = plugin_dir_path(__FILE__) . 'Pages/AntiSpamSettingsPage.php';
        if (file_exists($path)) {
            require_once $path;
        }

        $class = '\\AdorationScheduler\\Admin\\Pages\\AntiSpamSettingsPage';
        if (!class_exists($class)) {
            wp_die(
                esc_html__('Anti-Spam settings page class not found. Please check the file name/path.', 'adoration-scheduler'),
                500
            );
        }

        if (method_exists($class, 'render')) {
            $class::render();
            return;
        }

        if (method_exists($class, 'render_page')) {
            $class::render_page();
            return;
        }

        $obj = new $class();
        if (method_exists($obj, 'render')) {
            $obj->render();
            return;
        }

        wp_die(
            esc_html__('Anti-Spam page is missing a render method.', 'adoration-scheduler'),
            500
        );
    }

    public static function render_pages_shortcodes_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/PagesShortcodesPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\PagesShortcodesPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_access_settings_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AccessSettingsPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AccessSettingsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        if (method_exists($class, 'render')) {
            $class::render();
            return;
        }

        (new $class())->render();
    }

    public static function render_coverage_alerts_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/CoverageAlertsSettingsPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\CoverageAlertsSettingsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        if (method_exists($class, 'render')) {
            $class::render();
            return;
        }

        (new $class())->render();
    }

    public static function render_coverage_report_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/CoverageReportPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\CoverageReportPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_attendance_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AttendancePage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AttendancePage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_no_show_alerts_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/NoShowAlertsSettingsPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\NoShowAlertsSettingsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        if (method_exists($class, 'render')) {
            $class::render();
            return;
        }

        (new $class())->render();
    }

    public static function render_setup_wizard_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SCHEDULES) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/SetupWizardPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\SetupWizardPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }

    public static function render_announcements_page(): void {
        if ( ! current_user_can(self::CAP_MANAGE_SETTINGS) && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $candidates = ['Pages/AnnouncementsPage.php'];
        self::require_admin_page_file($candidates);

        $class = '\\AdorationScheduler\\Admin\\Pages\\AnnouncementsPage';
        if (!class_exists($class)) {
            self::die_missing($class, $candidates);
        }

        (new $class())->render();
    }
}
