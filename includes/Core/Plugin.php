<?php
namespace AdorationScheduler\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Plugin {

    /** Prevent init() work from running twice in the same request */
    private static bool $did_init = false;

    /** Prevent admin menu registration from running twice in the same request */
    private static bool $did_admin_menu = false;

    /** Prevent adding the admin_menu hook twice */
    private static bool $did_hook_admin_menu = false;

    /**
     * Granular caps (with manage_options fallback).
     */
    private const CAP_MANAGE_SCHEDULES = 'adoration_manage_schedules';
    private const CAP_MANAGE_SIGNUPS   = 'adoration_manage_signups';
    private const CAP_MANAGE_SETTINGS  = 'adoration_manage_settings';
    private const CAP_VIEW_REPORTS     = 'adoration_view_reports';

    public static function activate(): void {
        Installer::install();

        $includes_dir = dirname(__DIR__); // /includes

        self::require_first_existing($includes_dir, [
            'Services/AuthCleanupService.php',
            'services/AuthCleanupService.php',
        ]);

        $cleanupClass = 'AdorationScheduler\\Services\\AuthCleanupService';
        if (class_exists($cleanupClass) && method_exists($cleanupClass, 'activate')) {
            $cleanupClass::activate();
        }

        // ✅ Email log retention: optional “kick” on activation
        self::require_first_existing($includes_dir, [
            'Services/EmailLogRetentionService.php',
            'services/EmailLogRetentionService.php',
        ]);
        $retClass = 'AdorationScheduler\\Services\\EmailLogRetentionService';
        if (class_exists($retClass) && method_exists($retClass, 'ensure_scheduled')) {
            $retClass::ensure_scheduled();
        }

        // ✅ Perpetual adoration: rolling-window sync job
        self::require_first_existing($includes_dir, [
            'Services/PerpetualScheduleGeneratorService.php',
            'services/PerpetualScheduleGeneratorService.php',
        ]);
        $perpClass = 'AdorationScheduler\\Services\\PerpetualScheduleGeneratorService';
        if (class_exists($perpClass) && method_exists($perpClass, 'activate')) {
            $perpClass::activate();
        }

        // ✅ Coverage alerts: daily open-hour digest job
        self::require_first_existing($includes_dir, [
            'Services/CoverageAlertService.php',
            'services/CoverageAlertService.php',
        ]);
        $coverageAlertClass = 'AdorationScheduler\\Services\\CoverageAlertService';
        if (class_exists($coverageAlertClass) && method_exists($coverageAlertClass, 'activate')) {
            $coverageAlertClass::activate();
        }

        // ✅ Monthly recurrence: rolling-window sync job
        self::require_first_existing($includes_dir, [
            'Services/MonthlyScheduleGeneratorService.php',
            'services/MonthlyScheduleGeneratorService.php',
        ]);
        $monthlyClass = 'AdorationScheduler\\Services\\MonthlyScheduleGeneratorService';
        if (class_exists($monthlyClass) && method_exists($monthlyClass, 'activate')) {
            $monthlyClass::activate();
        }
    }

    public static function deactivate(): void {
        $includes_dir = dirname(__DIR__); // /includes

        self::require_first_existing($includes_dir, [
            'Services/AuthCleanupService.php',
            'services/AuthCleanupService.php',
        ]);

        $cleanupClass = 'AdorationScheduler\\Services\\AuthCleanupService';
        if (class_exists($cleanupClass) && method_exists($cleanupClass, 'deactivate')) {
            $cleanupClass::deactivate();
        }

        // ✅ Email log retention: unschedule on deactivate
        self::require_first_existing($includes_dir, [
            'Services/EmailLogRetentionService.php',
            'services/EmailLogRetentionService.php',
        ]);
        $retClass = 'AdorationScheduler\\Services\\EmailLogRetentionService';
        if (class_exists($retClass) && method_exists($retClass, 'unschedule_all')) {
            $retClass::unschedule_all();
        }

        // ✅ Perpetual adoration: unschedule rolling-window sync job
        self::require_first_existing($includes_dir, [
            'Services/PerpetualScheduleGeneratorService.php',
            'services/PerpetualScheduleGeneratorService.php',
        ]);
        $perpClass = 'AdorationScheduler\\Services\\PerpetualScheduleGeneratorService';
        if (class_exists($perpClass) && method_exists($perpClass, 'deactivate')) {
            $perpClass::deactivate();
        }

        // ✅ Coverage alerts: unschedule daily digest job
        self::require_first_existing($includes_dir, [
            'Services/CoverageAlertService.php',
            'services/CoverageAlertService.php',
        ]);
        $coverageAlertClass = 'AdorationScheduler\\Services\\CoverageAlertService';
        if (class_exists($coverageAlertClass) && method_exists($coverageAlertClass, 'deactivate')) {
            $coverageAlertClass::deactivate();
        }

        // ✅ Monthly recurrence: unschedule rolling-window sync job
        self::require_first_existing($includes_dir, [
            'Services/MonthlyScheduleGeneratorService.php',
            'services/MonthlyScheduleGeneratorService.php',
        ]);
        $monthlyClass = 'AdorationScheduler\\Services\\MonthlyScheduleGeneratorService';
        if (class_exists($monthlyClass) && method_exists($monthlyClass, 'deactivate')) {
            $monthlyClass::deactivate();
        }
    }

    public static function init(): void {

        if (self::$did_init) {
            error_log('[AdorationScheduler] Plugin::init called again (ignored)');
            return;
        }
        self::$did_init = true;

        Installer::maybe_upgrade();

        $includes_dir = dirname(__DIR__); // /includes

        /**
         * ✅ TOAST SYSTEM (admin + frontend) — moved OUT of Plugin.php
         */
        self::require_first_existing($includes_dir, [
            'Services/ToastService.php',
            'services/ToastService.php',
        ]);
        $toastClass = 'AdorationScheduler\\Services\\ToastService';
        if (class_exists($toastClass) && method_exists($toastClass, 'register')) {
            $toastClass::register();
        } else {
            // Don't hard-fail; just log.
            error_log('[AdorationScheduler] ToastService missing or no register() method: ' . $toastClass);
        }

        /**
         * FRONTEND + SHARED
         */
        if ( ! has_action('init', [ __CLASS__, 'register_public_features' ]) ) {
            add_action('init', [ __CLASS__, 'register_public_features' ], 5);
        }

        /**
         * ✅ Assets: detect UIkit + expose flag (no forced UIkit load)
         * (ToastService handles actual toast JS/CSS enqueues when needed)
         */
        add_action('wp_enqueue_scripts',    [__CLASS__, 'enqueue_frontend_assets'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets'], 20);

        /**
         * ✅ Email log retention (every 7 days)
         */
        self::require_first_existing($includes_dir, [
            'Services/EmailLogRetentionService.php',
            'services/EmailLogRetentionService.php',
        ]);
        $retClass = 'AdorationScheduler\\Services\\EmailLogRetentionService';
        if (class_exists($retClass) && method_exists($retClass, 'register')) {
            $retClass::register();
        } else {
            error_log('[AdorationScheduler] EmailLogRetentionService missing or no register() method: ' . $retClass);
        }

        /**
         * CRON / REMINDERS
         */
        self::require_first_existing($includes_dir, [
            'Services/ReminderScheduler.php',
            'services/ReminderScheduler.php',
        ]);

        $reminderClass = 'AdorationScheduler\\Services\\ReminderScheduler';
        if (class_exists($reminderClass) && method_exists($reminderClass, 'register')) {
            $reminderClass::register();
        } else {
            error_log('[AdorationScheduler] ReminderScheduler missing or no register() method: ' . $reminderClass);
        }

        /**
         * AUTH CLEANUP
         */
        self::require_first_existing($includes_dir, [
            'Services/AuthCleanupService.php',
            'services/AuthCleanupService.php',
        ]);

        $cleanupClass = 'AdorationScheduler\\Services\\AuthCleanupService';
        if (class_exists($cleanupClass) && method_exists($cleanupClass, 'register')) {
            $cleanupClass::register();
        } else {
            error_log('[AdorationScheduler] AuthCleanupService missing or no register() method: ' . $cleanupClass);
        }

        /**
         * PERPETUAL ADORATION: rolling-window slot generator
         */
        self::require_first_existing($includes_dir, [
            'Services/PerpetualScheduleGeneratorService.php',
            'services/PerpetualScheduleGeneratorService.php',
        ]);

        $perpClass = 'AdorationScheduler\\Services\\PerpetualScheduleGeneratorService';
        if (class_exists($perpClass) && method_exists($perpClass, 'register')) {
            $perpClass::register();
        } else {
            error_log('[AdorationScheduler] PerpetualScheduleGeneratorService missing or no register() method: ' . $perpClass);
        }

        /**
         * COVERAGE ALERTS: daily admin digest of soon-unfilled hours
         */
        self::require_first_existing($includes_dir, [
            'Services/CoverageAlertService.php',
            'services/CoverageAlertService.php',
        ]);

        $coverageAlertClass = 'AdorationScheduler\\Services\\CoverageAlertService';
        if (class_exists($coverageAlertClass) && method_exists($coverageAlertClass, 'register')) {
            $coverageAlertClass::register();
        } else {
            error_log('[AdorationScheduler] CoverageAlertService missing or no register() method: ' . $coverageAlertClass);
        }

        /**
         * MONTHLY RECURRENCE: rolling-window slot generator
         */
        self::require_first_existing($includes_dir, [
            'Services/MonthlyScheduleGeneratorService.php',
            'services/MonthlyScheduleGeneratorService.php',
        ]);

        $monthlyClass = 'AdorationScheduler\\Services\\MonthlyScheduleGeneratorService';
        if (class_exists($monthlyClass) && method_exists($monthlyClass, 'register')) {
            $monthlyClass::register();
        } else {
            error_log('[AdorationScheduler] MonthlyScheduleGeneratorService missing or no register() method: ' . $monthlyClass);
        }

        /**
         * ADMIN ONLY
         */
        if (is_admin()) {

            // Ensure Menu class file is available
            self::require_first_existing($includes_dir, [
                'Admin/Menu.php',
                'admin/Menu.php',
            ]);

            // ✅ PEOPLE SERVICES
            self::require_first_existing($includes_dir, [
                'Domain/Services/PeopleAdminActionsService.php',
                'domain/Services/PeopleAdminActionsService.php',
                'Domain/services/PeopleAdminActionsService.php',
                'domain/services/PeopleAdminActionsService.php',
            ]);

            self::try_register_service(
                [
                    'AdorationScheduler\\Domain\\Services\\PeopleAdminActionsService',
                    'AdorationScheduler\\Services\\PeopleAdminActionsService',
                ],
                ['register', 'init', 'bootstrap', 'boot'],
                'PeopleAdminActionsService'
            );

            // Merge service (admin-post handler)
            self::require_first_existing($includes_dir, [
                'Domain/Services/PeopleMergeService.php',
                'domain/Services/PeopleMergeService.php',
                'Domain/services/PeopleMergeService.php',
                'domain/services/PeopleMergeService.php',
            ]);

            self::try_register_service(
                [
                    'AdorationScheduler\\Domain\\Services\\PeopleMergeService',
                    'AdorationScheduler\\Services\\PeopleMergeService',
                ],
                ['register', 'init', 'bootstrap', 'boot'],
                'PeopleMergeService'
            );

            // HARD GUARANTEE merge POST action
            if (!has_action('admin_post_adoration_merge_people')) {
                $mergeCandidates = [
                    'AdorationScheduler\\Domain\\Services\\PeopleMergeService',
                    'AdorationScheduler\\Services\\PeopleMergeService',
                ];

                foreach ($mergeCandidates as $cls) {
                    if (class_exists($cls) && method_exists($cls, 'handle')) {
                        add_action('admin_post_adoration_merge_people', [ $cls, 'handle' ]);
                        error_log('[AdorationScheduler] Fallback merge hook added -> ' . $cls . '::handle');
                        break;
                    }
                }
            }

            // Person creation handler
            self::require_first_existing($includes_dir, [
                'Domain/Services/PersonCreationService.php',
                'domain/Services/PersonCreationService.php',
                'Domain/services/PersonCreationService.php',
                'domain/services/PersonCreationService.php',
            ]);

            self::try_register_service(
                [
                    'AdorationScheduler\\Domain\\Services\\PersonCreationService',
                ],
                ['register', 'init', 'bootstrap', 'boot'],
                'PersonCreationService'
            );

            // People bulk import/export (CSV + XLSX)
            self::require_first_existing($includes_dir, [
                'Domain/Services/PeopleImportExportService.php',
                'domain/Services/PeopleImportExportService.php',
            ]);

            self::try_register_service(
                [
                    'AdorationScheduler\\Domain\\Services\\PeopleImportExportService',
                ],
                ['register', 'init', 'bootstrap', 'boot'],
                'PeopleImportExportService'
            );

            // Schedules export (CSV + XLSX)
            self::require_first_existing($includes_dir, [
                'Domain/Services/ScheduleExportService.php',
                'domain/Services/ScheduleExportService.php',
            ]);

            self::try_register_service(
                [
                    'AdorationScheduler\\Domain\\Services\\ScheduleExportService',
                ],
                ['register', 'init', 'bootstrap', 'boot'],
                'ScheduleExportService'
            );

            // Printable rosters (2026-07-17): staff-only chapel-binder view
            self::require_first_existing($includes_dir, [
                'Domain/Services/RosterPrintService.php',
                'domain/Services/RosterPrintService.php',
            ]);

            self::try_register_service(
                [
                    'AdorationScheduler\\Domain\\Services\\RosterPrintService',
                ],
                ['register', 'init', 'bootstrap', 'boot'],
                'RosterPrintService'
            );

            // ADMIN AJAX: People search
            self::require_first_existing($includes_dir, [
                'Admin/Ajax/PeopleSearchAjax.php',
                'admin/Ajax/PeopleSearchAjax.php',
                'Admin/ajax/PeopleSearchAjax.php',
                'admin/ajax/PeopleSearchAjax.php',
            ]);

            $peopleSearchAjaxClass = 'AdorationScheduler\\Admin\\Ajax\\PeopleSearchAjax';
            if (class_exists($peopleSearchAjaxClass) && method_exists($peopleSearchAjaxClass, 'register')) {
                $peopleSearchAjaxClass::register();
            } else {
                error_log('[AdorationScheduler] PeopleSearchAjax missing or no register() method: ' . $peopleSearchAjaxClass);
            }

            // ADMIN AJAX: merge preview
            self::require_first_existing($includes_dir, [
                'Admin/Ajax/PeopleMergePreviewAjax.php',
                'admin/Ajax/PeopleMergePreviewAjax.php',
                'Admin/ajax/PeopleMergePreviewAjax.php',
                'admin/ajax/PeopleMergePreviewAjax.php',
            ]);

            $mergePreviewAjaxClass = '\\AdorationScheduler\\Admin\\Ajax\\PeopleMergePreviewAjax';
            if (class_exists($mergePreviewAjaxClass) && method_exists($mergePreviewAjaxClass, 'register')) {
                $mergePreviewAjaxClass::register();
            }

            /**
             * EARLY BULK ACTIONS HANDLER: SCHEDULES
             * (Uses granular cap + fallback instead of manage_options)
             */
            add_action('admin_init', function () use ($includes_dir) {

                if ( ! Plugin::current_user_can_with_fallback(Plugin::CAP_MANAGE_SCHEDULES) ) return;

                $page = sanitize_key($_REQUEST['page'] ?? '');
                if ($page !== 'adoration_scheduler_schedules') return;

                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

                $action  = sanitize_key($_POST['action'] ?? '');
                $action2 = sanitize_key($_POST['action2'] ?? '');

                $bulk_action = '';
                if ($action !== '' && $action !== '-1') {
                    $bulk_action = $action;
                } elseif ($action2 !== '' && $action2 !== '-1') {
                    $bulk_action = $action2;
                }

                $allowed_bulk = ['bulk-trash', 'bulk-restore', 'bulk-delete'];
                if ($bulk_action === '' || !in_array($bulk_action, $allowed_bulk, true)) return;

                if (empty($_POST['schedule_ids']) || !is_array($_POST['schedule_ids'])) return;

                $table_path = $includes_dir . '/Admin/Tables/SchedulesListTable.php';
                if (is_file($table_path)) require_once $table_path;

                if (class_exists('\\AdorationScheduler\\Admin\\Tables\\SchedulesListTable')) {
                    $table = new \AdorationScheduler\Admin\Tables\SchedulesListTable();
                    $table->process_bulk_action();
                }

            }, 0);

            /**
             * EARLY BULK ACTIONS HANDLER: PEOPLE
             * Note: You don’t yet have a dedicated "manage_people" cap; using manage_signups is a safe operational stand-in.
             * (Still falls back to manage_options.)
             */
            add_action('admin_init', function () use ($includes_dir) {

                if ( ! Plugin::current_user_can_with_fallback(Plugin::CAP_MANAGE_SIGNUPS) ) return;

                $page = sanitize_key($_REQUEST['page'] ?? '');
                if ($page !== 'adoration_scheduler_people') return;

                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

                if (empty($_POST['person_ids']) || !is_array($_POST['person_ids'])) return;

                $action  = sanitize_key($_POST['action'] ?? '');
                $action2 = sanitize_key($_POST['action2'] ?? '');

                $bulk_action = '';
                if ($action !== '' && $action !== '-1') {
                    $bulk_action = $action;
                } elseif ($action2 !== '' && $action2 !== '-1') {
                    $bulk_action = $action2;
                }

                if ($bulk_action !== 'delete') return;

                $table_path = $includes_dir . '/Admin/Tables/PersonsListTable.php';
                if (is_file($table_path)) require_once $table_path;

                if (class_exists('\\AdorationScheduler\\Admin\\Tables\\PersonsListTable')) {
                    $search = sanitize_text_field($_REQUEST['s'] ?? '');
                    $table  = new \AdorationScheduler\Admin\Tables\PersonsListTable('adoration_scheduler_people', $search);
                    $table->process_bulk_action();
                }

            }, 0);

            // Admin signup row actions (cancel/delete)
            self::require_first_existing($includes_dir, [
                'Services/AdminSignupActionsService.php',
                'services/AdminSignupActionsService.php',
            ]);

            $adminSignupActions = 'AdorationScheduler\\Services\\AdminSignupActionsService';
            if (class_exists($adminSignupActions) && method_exists($adminSignupActions, 'register')) {
                $adminSignupActions::register();
            } else {
                error_log('[AdorationScheduler] AdminSignupActionsService missing or no register() method: ' . $adminSignupActions);
            }

            // ✅ FIX: Admin AJAX resend emails (Signups modal)
            self::require_first_existing($includes_dir, [
                'Services/AdminResendEmailAjaxService.php',
                'services/AdminResendEmailAjaxService.php',
            ]);

            $adminResendAjax = 'AdorationScheduler\\Services\\AdminResendEmailAjaxService';
            if (class_exists($adminResendAjax) && method_exists($adminResendAjax, 'register')) {
                $adminResendAjax::register();
            } else {
                error_log('[AdorationScheduler] AdminResendEmailAjaxService missing or no register() method: ' . $adminResendAjax);
            }

            /**
             * ✅ Admin Signups Page actions
             * Loads SignupsPage.php and registers its AJAX actions early.
             */
            add_action('admin_init', function () use ($includes_dir) {
                $page_path = $includes_dir . '/Admin/Pages/SignupsPage.php';
                if (is_file($page_path)) {
                    require_once $page_path;

                    if (class_exists('\\AdorationScheduler\\Admin\\Pages\\SignupsPage')
                        && method_exists('\\AdorationScheduler\\Admin\\Pages\\SignupsPage', 'register_actions')) {
                        \AdorationScheduler\Admin\Pages\SignupsPage::register_actions();
                    }
                }
            });

            // ADMIN MENU (guard adding hook + guard execution)
            if (!self::$did_hook_admin_menu) {
                self::$did_hook_admin_menu = true;
                add_action('admin_menu', [ __CLASS__, 'register_admin_menu' ]);
            }

            // Anti-Spam settings page
            add_action('admin_init', function () use ($includes_dir) {
                $path = $includes_dir . '/Admin/Pages/AntiSpamSettingsPage.php';
                if (is_file($path)) {
                    require_once $path;

                    if (class_exists('\\AdorationScheduler\\Admin\\Pages\\AntiSpamSettingsPage')
                        && method_exists('\\AdorationScheduler\\Admin\\Pages\\AntiSpamSettingsPage', 'register')) {
                        \AdorationScheduler\Admin\Pages\AntiSpamSettingsPage::register();
                    }
                }
            });

            // ✅ Access & Privacy settings page (optional approval gate)
            add_action('admin_init', function () use ($includes_dir) {
                $path = $includes_dir . '/Admin/Pages/AccessSettingsPage.php';
                if (is_file($path)) {
                    require_once $path;

                    if (class_exists('\\AdorationScheduler\\Admin\\Pages\\AccessSettingsPage')
                        && method_exists('\\AdorationScheduler\\Admin\\Pages\\AccessSettingsPage', 'register')) {
                        \AdorationScheduler\Admin\Pages\AccessSettingsPage::register();
                    }
                }
            });

            // ✅ Coverage Alerts settings page (open-hour admin digest)
            add_action('admin_init', function () use ($includes_dir) {
                $path = $includes_dir . '/Admin/Pages/CoverageAlertsSettingsPage.php';
                if (is_file($path)) {
                    require_once $path;

                    if (class_exists('\\AdorationScheduler\\Admin\\Pages\\CoverageAlertsSettingsPage')
                        && method_exists('\\AdorationScheduler\\Admin\\Pages\\CoverageAlertsSettingsPage', 'register')) {
                        \AdorationScheduler\Admin\Pages\CoverageAlertsSettingsPage::register();
                    }
                }
            });

            // EmailTemplatesPage actions
            add_action('admin_init', function () use ($includes_dir) {

                $page_path = $includes_dir . '/Admin/Pages/EmailTemplatesPage.php';
                if (is_file($page_path)) {
                    require_once $page_path;
                }

                $tabs = [
                    $includes_dir . '/Admin/Pages/EmailTemplates/Tabs/AbstractEmailTemplatesTab.php',
                    $includes_dir . '/Admin/Pages/EmailTemplates/Tabs/SenderTab.php',
                    $includes_dir . '/Admin/Pages/EmailTemplates/Tabs/SignupConfirmationTab.php',
                    $includes_dir . '/Admin/Pages/EmailTemplates/Tabs/Reminder24hTab.php',
                ];
                foreach ($tabs as $p) {
                    if (is_file($p)) require_once $p;
                }

                if (class_exists('\\AdorationScheduler\\Admin\\Pages\\EmailTemplatesPage')
                    && method_exists('\\AdorationScheduler\\Admin\\Pages\\EmailTemplatesPage', 'register_actions')) {
                    \AdorationScheduler\Admin\Pages\EmailTemplatesPage::register_actions();
                }
            });

            // EmailLogPage actions
            add_action('admin_init', function () use ($includes_dir) {

                $page_path = $includes_dir . '/Admin/Pages/EmailLogPage.php';
                if (is_file($page_path)) {
                    require_once $page_path;
                }

                if (class_exists('\\AdorationScheduler\\Admin\\Pages\\EmailLogPage')
                    && method_exists('\\AdorationScheduler\\Admin\\Pages\\EmailLogPage', 'register_actions')) {
                    \AdorationScheduler\Admin\Pages\EmailLogPage::register_actions();
                }
            });

            // Schedule creation handler
            self::require_first_existing($includes_dir, [
                'Services/ScheduleCreationService.php',
                'services/ScheduleCreationService.php',
            ]);

            $svcClass = 'AdorationScheduler\\Services\\ScheduleCreationService';
            if (class_exists($svcClass) && method_exists($svcClass, 'register')) {
                $svcClass::register();
            } else {
                error_log('[AdorationScheduler] ScheduleCreationService missing or no register() method: ' . $svcClass);
            }

            // Schedule deletion/restore handler
            self::require_first_existing($includes_dir, [
                'Services/ScheduleDeletionService.php',
                'services/ScheduleDeletionService.php',
            ]);

            $delClass = 'AdorationScheduler\\Services\\ScheduleDeletionService';
            if (class_exists($delClass) && method_exists($delClass, 'register')) {
                $delClass::register();
            } else {
                error_log('[AdorationScheduler] ScheduleDeletionService missing or no register() method: ' . $delClass);
            }

            // ✅ Schedule duplication handler
            self::require_first_existing($includes_dir, [
                'Services/ScheduleDuplicationService.php',
                'services/ScheduleDuplicationService.php',
            ]);

            $dupClass = 'AdorationScheduler\\Services\\ScheduleDuplicationService';
            if (class_exists($dupClass) && method_exists($dupClass, 'register')) {
                $dupClass::register();
            } else {
                error_log('[AdorationScheduler] ScheduleDuplicationService missing or no register() method: ' . $dupClass);
            }
        }

        error_log('[AdorationScheduler] Plugin::init complete');
    }

    /**
     * Detect whether the current theme appears to be loading UIkit already.
     * We keep it conservative: only checks for registered/enqueued handles we commonly see.
     */
    public static function theme_has_uikit(): bool
    {
        // Front-end context
        if (!function_exists('wp_script_is')) return false;

        $script_handles = [
            'uikit',
            'uikit-js',
            'uikit-min',
            'yootheme-uikit',
            'yootheme-uikit-js',
        ];

        foreach ($script_handles as $h) {
            if (wp_script_is($h, 'enqueued') || wp_script_is($h, 'registered')) {
                return true;
            }
        }

        // Sometimes UIkit is only a style handle
        if (function_exists('wp_style_is')) {
            $style_handles = [
                'uikit',
                'uikit-css',
                'uikit-min',
                'yootheme-uikit',
                'yootheme-uikit-css',
            ];
            foreach ($style_handles as $h) {
                if (wp_style_is($h, 'enqueued') || wp_style_is($h, 'registered')) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function enqueue_frontend_assets(): void
    {
        // Only set flags for now (no forced UIkit load).
        $has_uikit = self::theme_has_uikit();

        wp_register_script('adoration-scheduler-frontend-flags', '', [], null, true);
        wp_enqueue_script('adoration-scheduler-frontend-flags');
        wp_add_inline_script(
            'adoration-scheduler-frontend-flags',
            'window.AdorationScheduler = window.AdorationScheduler || {}; window.AdorationScheduler.hasUIkit = ' . ($has_uikit ? 'true' : 'false') . ';'
        );
    }

    public static function enqueue_admin_assets($hook_suffix): void
    {
        // Admin does NOT generally use theme UIkit; still expose a flag for consistency.
        wp_register_script('adoration-scheduler-admin-flags', '', [], null, true);
        wp_enqueue_script('adoration-scheduler-admin-flags');
        wp_add_inline_script(
            'adoration-scheduler-admin-flags',
            'window.AdorationScheduler = window.AdorationScheduler || {}; window.AdorationScheduler.hasUIkit = false;'
        );

        // ✅ Toast rendering + enqueue is handled by Services\ToastService now.
    }

    public static function register_public_features(): void {

        $includes_dir = dirname(__DIR__);

        self::require_first_existing($includes_dir, [
            'Shortcodes/ScheduleShortcode.php',
            'shortcodes/ScheduleShortcode.php',
            'Frontend/Shortcodes/ScheduleShortcode.php',
            'Frontend/shortcodes/ScheduleShortcode.php',
        ]);

        $shortcodeClass = 'AdorationScheduler\\Shortcodes\\ScheduleShortcode';
        if (class_exists($shortcodeClass)) {
            if (method_exists($shortcodeClass, 'register')) {
                $shortcodeClass::register();
            } elseif (method_exists($shortcodeClass, 'render')) {
                add_shortcode('adoration_schedule', [ $shortcodeClass, 'render' ]);
            }
        }

        self::require_first_existing($includes_dir, [
            'Public/SignupHandler.php',
            'public/SignupHandler.php',
            'Handlers/SignupHandler.php',
            'handlers/SignupHandler.php',
            'Frontend/Handlers/SignupHandler.php',
            'Frontend/handlers/SignupHandler.php',
        ]);

        $handlerClass = 'AdorationScheduler\\Public\\SignupHandler';
        if (class_exists($handlerClass) && method_exists($handlerClass, 'register')) {
            $handlerClass::register();
        }

        // ✅ Public self-service standing (weekly recurring) commitment claims
        self::require_first_existing($includes_dir, [
            'Public/StandingSignupHandler.php',
            'public/StandingSignupHandler.php',
        ]);

        $standingHandlerClass = 'AdorationScheduler\\Public\\StandingSignupHandler';
        if (class_exists($standingHandlerClass) && method_exists($standingHandlerClass, 'register')) {
            $standingHandlerClass::register();
        }

        // ✅ Public "Request Access" submissions (privacy/approval gate)
        self::require_first_existing($includes_dir, [
            'Public/AccessRequestHandler.php',
            'public/AccessRequestHandler.php',
        ]);

        $accessRequestHandlerClass = 'AdorationScheduler\\Public\\AccessRequestHandler';
        if (class_exists($accessRequestHandlerClass) && method_exists($accessRequestHandlerClass, 'register')) {
            $accessRequestHandlerClass::register();
        }

        // ✅ Frontend: update contact info (phone/name; email stays locked)
        self::require_first_existing($includes_dir, [
            'Frontend/Handlers/UpdateContactInfoHandler.php',
            'Frontend/handlers/UpdateContactInfoHandler.php',
            'Handlers/UpdateContactInfoHandler.php',
            'handlers/UpdateContactInfoHandler.php',
            'Public/UpdateContactInfoHandler.php',
            'public/UpdateContactInfoHandler.php',
        ]);

        $updateContactHandler = 'AdorationScheduler\\Frontend\\Handlers\\UpdateContactInfoHandler';
        if (class_exists($updateContactHandler) && method_exists($updateContactHandler, 'register')) {
            $updateContactHandler::register();
        } else {
            error_log('[AdorationScheduler] UpdateContactInfoHandler missing or no register() method: ' . $updateContactHandler);
        }

        // Notifications
        self::require_first_existing($includes_dir, [
            'Services/NotificationService.php',
            'services/NotificationService.php',
        ]);

        $notifSvc = 'AdorationScheduler\\Services\\NotificationService';
        if (class_exists($notifSvc) && method_exists($notifSvc, 'register')) {
            $notifSvc::register();
        } else {
            error_log('[AdorationScheduler] NotificationService missing or no register() method: ' . $notifSvc);
        }

        self::require_first_existing($includes_dir, [
            'Services/MagicLinkService.php',
            'services/MagicLinkService.php',
        ]);

        $magicSvc = 'AdorationScheduler\\Services\\MagicLinkService';
        if (class_exists($magicSvc) && method_exists($magicSvc, 'register')) {
            $magicSvc::register();
        } else {
            error_log('[AdorationScheduler] MagicLinkService missing or no register() method: ' . $magicSvc);
        }

        // ✅ Personal + public iCal subscribe feeds (2026-07-17)
        self::require_first_existing($includes_dir, [
            'Services/CalendarFeedService.php',
            'services/CalendarFeedService.php',
        ]);

        $calendarFeedSvc = 'AdorationScheduler\\Services\\CalendarFeedService';
        if (class_exists($calendarFeedSvc) && method_exists($calendarFeedSvc, 'register')) {
            $calendarFeedSvc::register();
        } else {
            error_log('[AdorationScheduler] CalendarFeedService missing or no register() method: ' . $calendarFeedSvc);
        }

        // ✅ Hybrid auth (Phase 2): optional "sign in with password"
        self::require_first_existing($includes_dir, [
            'Services/PasswordAuthService.php',
            'services/PasswordAuthService.php',
        ]);

        $passwordAuthSvc = 'AdorationScheduler\\Services\\PasswordAuthService';
        if (class_exists($passwordAuthSvc) && method_exists($passwordAuthSvc, 'register')) {
            $passwordAuthSvc::register();
        } else {
            error_log('[AdorationScheduler] PasswordAuthService missing or no register() method: ' . $passwordAuthSvc);
        }

        // ✅ Hybrid auth (Phase 2): set/change/remove password from dashboard
        self::require_first_existing($includes_dir, [
            'Frontend/Handlers/PasswordSetHandler.php',
            'Frontend/handlers/PasswordSetHandler.php',
        ]);

        $passwordSetHandler = 'AdorationScheduler\\Frontend\\Handlers\\PasswordSetHandler';
        if (class_exists($passwordSetHandler) && method_exists($passwordSetHandler, 'register')) {
            $passwordSetHandler::register();
        } else {
            error_log('[AdorationScheduler] PasswordSetHandler missing or no register() method: ' . $passwordSetHandler);
        }

        // ✅ Replacement requests (Phase 3): request/claim/cancel handlers + notifications
        self::require_first_existing($includes_dir, [
            'Services/ReplacementRequestService.php',
            'services/ReplacementRequestService.php',
        ]);

        $replacementSvc = 'AdorationScheduler\\Services\\ReplacementRequestService';
        if (class_exists($replacementSvc) && method_exists($replacementSvc, 'register')) {
            $replacementSvc::register();
        } else {
            error_log('[AdorationScheduler] ReplacementRequestService missing or no register() method: ' . $replacementSvc);
        }

        // ✅ Waitlists (2026-07-17): leave-waitlist + admin-remove handlers,
        // plus promote_next_for_slot() used by cancellation call sites above.
        self::require_first_existing($includes_dir, [
            'Services/WaitlistService.php',
            'services/WaitlistService.php',
        ]);

        $waitlistSvc = 'AdorationScheduler\\Services\\WaitlistService';
        if (class_exists($waitlistSvc) && method_exists($waitlistSvc, 'register')) {
            $waitlistSvc::register();
        } else {
            error_log('[AdorationScheduler] WaitlistService missing or no register() method: ' . $waitlistSvc);
        }

        // ✅ Self-service data export ("Download My Data")
        self::require_first_existing($includes_dir, [
            'Frontend/Handlers/DataExportHandler.php',
            'Frontend/handlers/DataExportHandler.php',
        ]);

        $dataExportHandler = 'AdorationScheduler\\Frontend\\Handlers\\DataExportHandler';
        if (class_exists($dataExportHandler) && method_exists($dataExportHandler, 'register')) {
            $dataExportHandler::register();
        } else {
            error_log('[AdorationScheduler] DataExportHandler missing or no register() method: ' . $dataExportHandler);
        }

        // ✅ Self-service account deletion ("Delete My Account") — anonymizes
        // the person row rather than hard-deleting; see AccountDeletionService
        // docblock for why.
        self::require_first_existing($includes_dir, [
            'Services/AccountDeletionService.php',
            'services/AccountDeletionService.php',
        ]);

        $accountDeletionSvc = 'AdorationScheduler\\Services\\AccountDeletionService';
        if (class_exists($accountDeletionSvc) && method_exists($accountDeletionSvc, 'register')) {
            $accountDeletionSvc::register();
        } else {
            error_log('[AdorationScheduler] AccountDeletionService missing or no register() method: ' . $accountDeletionSvc);
        }

        // ✅ Direct-to-person swap requests: public, signed-in-only AJAX
        // search backing the "ask a specific person" picker.
        self::require_first_existing($includes_dir, [
            'Frontend/Ajax/PersonTargetSearchAjax.php',
            'Frontend/ajax/PersonTargetSearchAjax.php',
        ]);

        $personTargetSearchAjax = 'AdorationScheduler\\Frontend\\Ajax\\PersonTargetSearchAjax';
        if (class_exists($personTargetSearchAjax) && method_exists($personTargetSearchAjax, 'register')) {
            $personTargetSearchAjax::register();
        } else {
            error_log('[AdorationScheduler] PersonTargetSearchAjax missing or no register() method: ' . $personTargetSearchAjax);
        }

        self::require_first_existing($includes_dir, [
            'Frontend/Shortcodes/MagicLinkShortcode.php',
            'Frontend/shortcodes/MagicLinkShortcode.php',
        ]);

        $magicShortcode = 'AdorationScheduler\\Frontend\\Shortcodes\\MagicLinkShortcode';
        if (class_exists($magicShortcode) && method_exists($magicShortcode, 'register')) {
            $magicShortcode::register();
        } else {
            error_log('[AdorationScheduler] MagicLinkShortcode missing or no register() method: ' . $magicShortcode);
        }

        // ✅ Modular "My Adoration" dashboard shortcodes (2026-07-16). Replaced
        // the old monolithic [adoration_my_adoration] shortcode (now retired,
        // class file left in place but no longer registered) so a page can be
        // composed from independent pieces instead of one all-in-one block.
        foreach ([
            'MyScheduleShortcode',
            'NeededReplacementsShortcode',
            'ProfileCardShortcode',
            'AccountStatusShortcode',
            'MyReplacementRequestsShortcode',
            'NextAdorationHourShortcode',
            'AnnouncementsShortcode',
            'OpenHoursShortcode',
            'CalendarSubscribeShortcode',
        ] as $shortcode_class_name) {
            self::require_first_existing($includes_dir, [
                "Frontend/Shortcodes/{$shortcode_class_name}.php",
                "Frontend/shortcodes/{$shortcode_class_name}.php",
            ]);

            $fqcn = 'AdorationScheduler\\Frontend\\Shortcodes\\' . $shortcode_class_name;
            if (class_exists($fqcn) && method_exists($fqcn, 'register')) {
                $fqcn::register();
            } else {
                error_log('[AdorationScheduler] ' . $shortcode_class_name . ' missing or no register() method: ' . $fqcn);
            }
        }

        // ✅ [adoration_request_access] (privacy/approval gate)
        self::require_first_existing($includes_dir, [
            'Frontend/Shortcodes/AccessRequestShortcode.php',
            'Frontend/shortcodes/AccessRequestShortcode.php',
        ]);

        $accessRequestShortcode = 'AdorationScheduler\\Frontend\\Shortcodes\\AccessRequestShortcode';
        if (class_exists($accessRequestShortcode) && method_exists($accessRequestShortcode, 'register')) {
            $accessRequestShortcode::register();
        }

        /**
         * ✅ Step 2: My Adoration page service
         * Ensures the My Adoration page renders even if the shortcode was removed from the page content.
         */
        self::require_first_existing($includes_dir, [
            'Frontend/MyAdorationPageService.php',
            'Frontend/myAdorationPageService.php',
            'frontend/MyAdorationPageService.php',
            'frontend/myAdorationPageService.php',
        ]);

        $myAdorationPageSvc = 'AdorationScheduler\\Frontend\\MyAdorationPageService';
        if (class_exists($myAdorationPageSvc) && method_exists($myAdorationPageSvc, 'register')) {
            $myAdorationPageSvc::register();
        } else {
            error_log('[AdorationScheduler] MyAdorationPageService missing or no register() method: ' . $myAdorationPageSvc);
        }

        self::require_first_existing($includes_dir, [
            'Services/SignupCancellationService.php',
            'services/SignupCancellationService.php',
        ]);

        $cancelSvc = 'AdorationScheduler\\Services\\SignupCancellationService';
        if (class_exists($cancelSvc) && method_exists($cancelSvc, 'register')) {
            $cancelSvc::register();
        } else {
            error_log('[AdorationScheduler] SignupCancellationService missing or no register() method: ' . $cancelSvc);
        }
    }

    private static function require_first_existing(string $baseDir, array $relativePaths): ?string {
        foreach ($relativePaths as $rel) {
            $path = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (is_file($path)) {
                require_once $path;
                return $path;
            }
        }
        return null;
    }

    /**
     * Try multiple class names + multiple method names, log what we did.
     */
    private static function try_register_service(array $classCandidates, array $methodCandidates, string $label): void {
        foreach ($classCandidates as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ($methodCandidates as $m) {
                if (method_exists($class, $m)) {
                    error_log('[AdorationScheduler] ' . $label . ' using ' . $class . '::' . $m . '()');
                    try {
                        $class::$m();
                    } catch (\Throwable $e) {
                        error_log('[AdorationScheduler] ' . $label . ' threw: ' . $e->getMessage());
                    }
                    return;
                }
            }

            error_log('[AdorationScheduler] ' . $label . ' class found but no usable method on ' . $class
                . ' (tried: ' . implode(',', $methodCandidates) . ')');
            return;
        }

        error_log('[AdorationScheduler] ' . $label . ' missing class. Tried: ' . implode(' | ', $classCandidates));
    }

    public static function register_admin_menu(): void {

        if (self::$did_admin_menu) {
            error_log('[AdorationScheduler] Plugin::register_admin_menu fired again (ignored)');
            return;
        }
        self::$did_admin_menu = true;

        error_log('[AdorationScheduler] Plugin::register_admin_menu fired');

        $menuClass = \AdorationScheduler\Admin\Menu::class;

        if (!class_exists($menuClass)) {
            error_log('[AdorationScheduler] Menu class missing: AdorationScheduler\\Admin\\Menu');
            return;
        }

        if (method_exists($menuClass, 'register_admin_menu')) {
            $menuClass::register_admin_menu();
            return;
        }

        $menu = new $menuClass();

        if (method_exists($menu, 'register')) {
            $menu->register();
            return;
        }

        if (method_exists($menu, 'register_admin_menu')) {
            $menu->register_admin_menu();
            return;
        }

        error_log('[AdorationScheduler] Menu has no register method (expected register_admin_menu or register).');
    }

    /**
     * Granular cap check with safe fallback.
     */
    public static function current_user_can_with_fallback(string $capability): bool
    {
        $capability = sanitize_key($capability);

        if ($capability !== '' && current_user_can($capability)) {
            return true;
        }

        return current_user_can('manage_options');
    }
}
