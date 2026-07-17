<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Admin\Tables\SchedulesListTable;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\DatePatternsRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class SchedulesPage {

    /**
     * Granular capability (future-friendly).
     * If not yet added to roles, we fall back to manage_options.
     */
    private const CAP_MANAGE_SCHEDULES = 'adoration_manage_schedules';

    /**
     * Basic rate limiting for create (prevents accidental double-submits).
     */
    private const RL_WINDOW_SECONDS = 60; // 1 minute
    private const RL_MAX_ATTEMPTS   = 20; // per user per window

    /**
     * Register early handlers (bulk actions) before admin output starts.
     *
     * Call this from Plugin.php (admin_init), e.g.:
     * add_action('admin_init', [\AdorationScheduler\Admin\Pages\SchedulesPage::class, 'register_actions']);
     */
    public static function register_actions(): void {
        add_action('admin_init', [__CLASS__, 'handle_bulk_actions_early'], 0);

        // ✅ Handle "Add New Schedule" form submit
        add_action('admin_post_adoration_create_schedule', [__CLASS__, 'handle_create_schedule']);
    }

    /**
     * Create Schedule handler for AddNewSchedulePage form.
     * POST target: admin-post.php?action=adoration_create_schedule
     */
    public static function handle_create_schedule(): void {

        self::require_admin_cap(self::CAP_MANAGE_SCHEDULES);

        // POST-only hard guard
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            wp_safe_redirect(admin_url('admin.php?page=adoration_scheduler_schedules'));
            exit;
        }

        if (!self::rate_limit_ok('create_schedule')) {
            wp_safe_redirect(add_query_arg([
                'page' => 'adoration_scheduler_add_new',
                'as_toast' => rawurlencode('Too many attempts. Please wait a moment and try again.'),
                'as_toast_type' => 'error',
            ], admin_url('admin.php')));
            exit;
        }

        check_admin_referer('adoration_create_schedule');

        $back_url = add_query_arg(['page' => 'adoration_scheduler_add_new'], admin_url('admin.php'));
        $list_url = add_query_arg(['page' => 'adoration_scheduler_schedules'], admin_url('admin.php'));

        // Required
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($name === '') {
            wp_safe_redirect(add_query_arg(['created' => '0'], $back_url));
            exit;
        }

        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'event';
        if ($type === '') $type = 'event';

        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft';
        if (!in_array($status, ['draft','active'], true)) $status = 'draft';

        $privacy_mode = isset($_POST['privacy_mode']) ? sanitize_key(wp_unslash($_POST['privacy_mode'])) : 'counts_only';
        $allowed_privacy = ['counts_only', 'first_name_only', 'first_last_initial', 'names'];
        if (!in_array($privacy_mode, $allowed_privacy, true)) $privacy_mode = 'counts_only';

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        $start_date = ($start_date !== '') ? $start_date : null;
        $end_date   = ($end_date !== '') ? $end_date : null;

        if ($start_date && $end_date && $end_date < $start_date) {
            // invalid range
            wp_safe_redirect(add_query_arg(['created' => '0'], $back_url));
            exit;
        }

        // ✅ NEW DEFAULTS (from AddNewSchedulePage)
        $default_slot_length = isset($_POST['default_slot_length']) ? (int) wp_unslash($_POST['default_slot_length']) : 60;
        if ($default_slot_length <= 0) $default_slot_length = 60;

        $default_min_adorers = isset($_POST['default_min_adorers']) ? (int) wp_unslash($_POST['default_min_adorers']) : 1;
        if ($default_min_adorers < 0) $default_min_adorers = 0;

        $max_raw = isset($_POST['default_max_adorers']) ? wp_unslash($_POST['default_max_adorers']) : '';
        $max_raw = is_string($max_raw) ? trim($max_raw) : '';
        $default_max_adorers = ($max_raw === '') ? null : (int) $max_raw;
        if ($default_max_adorers !== null && $default_max_adorers < 0) {
            $default_max_adorers = null;
        }

        // Optional event dates (one per line)
        $event_dates_raw = isset($_POST['event_dates']) ? (string) wp_unslash($_POST['event_dates']) : '';
        $event_dates_raw = trim($event_dates_raw);

        $event_dates = [];
        if ($event_dates_raw !== '') {
            $lines = preg_split('/\R+/', $event_dates_raw) ?: [];
            foreach ($lines as $ln) {
                $d = trim((string)$ln);
                if ($d === '') continue;

                // Very simple YYYY-MM-DD validation
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) continue;

                $event_dates[] = $d;
            }
            $event_dates = array_values(array_unique($event_dates));
            sort($event_dates);
        }

        // Create schedule
        $schedulesRepo = new SchedulesRepository();

        // Slug: allow handler to auto-generate if repo supports it, but we can provide one.
        $slug = sanitize_title($name);
        if ($slug === '') $slug = 'schedule-' . wp_generate_password(8, false, false);

        $schedule_id = 0;

        // ✅ Try common repo method names so this works across your iterations
        $payload = [
            'name'                => $name,
            'slug'                => $slug,
            'type'                => $type,
            'status'              => $status,
            'privacy_mode'        => $privacy_mode,
            'start_date'          => $start_date,
            'end_date'            => $end_date,
            'default_slot_length' => $default_slot_length,
            'default_min_adorers' => $default_min_adorers,
            'default_max_adorers' => $default_max_adorers,
        ];

        if (method_exists($schedulesRepo, 'create')) {
            $schedule_id = (int) $schedulesRepo->create($payload);
        } elseif (method_exists($schedulesRepo, 'insert')) {
            $schedule_id = (int) $schedulesRepo->insert($payload);
        } elseif (method_exists($schedulesRepo, 'create_schedule')) {
            $schedule_id = (int) $schedulesRepo->create_schedule($payload);
        } else {
            error_log('[AdorationScheduler] No create method found on SchedulesRepository (expected create/insert/create_schedule).');
            wp_safe_redirect(add_query_arg(['created' => '0'], $back_url));
            exit;
        }

        if ($schedule_id <= 0) {
            wp_safe_redirect(add_query_arg(['created' => '0'], $back_url));
            exit;
        }

        // Create date_patterns rows (event dates)
        if (!empty($event_dates)) {
            $dateRepo = new DatePatternsRepository();
            foreach ($event_dates as $d) {
                try {
                    $dateRepo->create($schedule_id, $d);
                } catch (\Throwable $e) {
                    // don't fail creation if one date fails
                    error_log('[AdorationScheduler] Failed adding event date ' . $d . ' for schedule ' . $schedule_id . ': ' . $e->getMessage());
                }
            }
        }

        // Redirect to edit screen on Basic tab (so they can continue configuring)
        $edit_url = add_query_arg([
            'page'        => 'adoration_scheduler_schedules',
            'action'      => 'edit',
            'schedule_id' => $schedule_id,
            'tab'         => 'basic',
            'as_toast'    => rawurlencode('Schedule created. Defaults saved.'),
            'as_toast_type' => 'success',
        ], admin_url('admin.php'));

        wp_safe_redirect($edit_url);
        exit;
    }

    /**
     * Bulk actions must run before admin page output, or redirects will throw "headers already sent".
     *
     * IMPORTANT:
     * - Only handle known bulk actions for THIS table.
     * - Only run on POST with schedule_ids[] present.
     * - Only run on our schedules admin page.
     */
    public static function handle_bulk_actions_early(): void {

        if ( ! is_admin() ) return;
        if ( ! self::current_user_can_with_fallback(self::CAP_MANAGE_SCHEDULES) ) return;

        // Only handle POST bulk submits
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

        // Only on our schedules page
        $page = sanitize_key($_REQUEST['page'] ?? '');
        if ($page !== 'adoration_scheduler_schedules') return;

        // Bulk actions always submit selected IDs
        if (empty($_POST['schedule_ids']) || !is_array($_POST['schedule_ids'])) return;

        // Determine the selected bulk action (top dropdown or bottom dropdown)
        $action  = sanitize_key($_POST['action'] ?? '');
        $action2 = sanitize_key($_POST['action2'] ?? '');

        $candidate = '';
        if ($action !== '' && $action !== '-1') {
            $candidate = $action;
        } elseif ($action2 !== '' && $action2 !== '-1') {
            $candidate = $action2;
        }

        if ($candidate === '') return;

        // Only allow our known bulk actions (prevents breaking other forms that use "action")
        $allowed_bulk = ['bulk-trash', 'bulk-restore', 'bulk-delete'];
        if ( ! in_array($candidate, $allowed_bulk, true) ) {
            return;
        }

        // Process now (early) so wp_safe_redirect works without "headers already sent"
        $table = new SchedulesListTable();
        $table->process_bulk_action(); // includes nonce check + redirect + exit
    }

    public function render(): void {

        if ( ! self::current_user_can_with_fallback(self::CAP_MANAGE_SCHEDULES) ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $action = sanitize_key($_GET['action'] ?? '');

        if ($action === 'edit') {
            if (!class_exists(EditSchedulePage::class)) {
                echo '<div class="wrap"><h1>Adoration Scheduler</h1><p>Missing required class: <code>\\AdorationScheduler\\Admin\\Pages\\EditSchedulePage</code></p></div>';
                return;
            }
            (new EditSchedulePage())->render();
            return;
        }

        $table = new SchedulesListTable();
        $table->prepare_items();

        // IMPORTANT: must match the submenu slug registered in Menu.php
        $add_new_url = menu_page_url('adoration_scheduler_add_new', false);
        if (empty($add_new_url)) {
            $add_new_url = admin_url('admin.php?page=adoration_scheduler_add_new');
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Adoration Schedules', 'adoration-scheduler'); ?></h1>
            <a href="<?php echo esc_url($add_new_url); ?>" class="page-title-action">
                <?php esc_html_e('Add New', 'adoration-scheduler'); ?>
            </a>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:4px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(\AdorationScheduler\Domain\Services\ScheduleExportService::ACTION_EXPORT_CSV); ?>" />
                <?php wp_nonce_field('adoration_schedules_export'); ?>
                <button type="submit" class="button"><?php esc_html_e('Export CSV', 'adoration-scheduler'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:4px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(\AdorationScheduler\Domain\Services\ScheduleExportService::ACTION_EXPORT_XLSX); ?>" />
                <?php wp_nonce_field('adoration_schedules_export'); ?>
                <button type="submit" class="button"><?php esc_html_e('Export XLSX', 'adoration-scheduler'); ?></button>
            </form>

            <hr class="wp-header-end">

            <?php
            /**
             * ------------------------------------------------------------
             * Single-item notices (from admin-post handlers)
             * ------------------------------------------------------------
             */
            if (isset($_GET['deleted']) && $_GET['deleted'] !== '') {
                $ok = ((string)$_GET['deleted'] === '1');
                echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' is-dismissible"><p>'
                    . esc_html(
                        $ok
                            ? __('Schedule moved to Trash.', 'adoration-scheduler')
                            : __('Schedule could not be moved to Trash. It may have signups.', 'adoration-scheduler')
                    )
                    . '</p></div>';
            }

            if (isset($_GET['restored']) && $_GET['restored'] !== '') {
                $ok = ((string)$_GET['restored'] === '1');
                echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' is-dismissible"><p>'
                    . esc_html(
                        $ok
                            ? __('Schedule restored.', 'adoration-scheduler')
                            : __('Schedule could not be restored.', 'adoration-scheduler')
                    )
                    . '</p></div>';
            }

            if (isset($_GET['permadeleted']) && $_GET['permadeleted'] !== '') {
                $ok = ((string)$_GET['permadeleted'] === '1');
                echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' is-dismissible"><p>'
                    . esc_html(
                        $ok
                            ? __('Schedule permanently deleted.', 'adoration-scheduler')
                            : __('Schedule could not be permanently deleted. It may have signups, or may not be in Trash.', 'adoration-scheduler')
                    )
                    . '</p></div>';
            }

            /**
             * ------------------------------------------------------------
             * Bulk notices (from SchedulesListTable redirect query args)
             * ------------------------------------------------------------
             */
            $bulk_trashed = isset($_GET['schedules_trashed']) ? (int)$_GET['schedules_trashed'] : 0;
            $bulk_blocked = isset($_GET['schedules_blocked']) ? (int)$_GET['schedules_blocked'] : 0;

            if ($bulk_trashed > 0 || $bulk_blocked > 0) {
                $parts = [];
                if ($bulk_trashed > 0) {
                    $parts[] = sprintf(
                        _n('%d schedule moved to Trash.', '%d schedules moved to Trash.', $bulk_trashed, 'adoration-scheduler'),
                        $bulk_trashed
                    );
                }
                if ($bulk_blocked > 0) {
                    $parts[] = sprintf(
                        _n('%d schedule could not be trashed (has signups).', '%d schedules could not be trashed (have signups).', $bulk_blocked, 'adoration-scheduler'),
                        $bulk_blocked
                    );
                }

                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(implode(' ', $parts)) . '</p></div>';
            }

            $bulk_restored = isset($_GET['schedules_restored']) ? (int)$_GET['schedules_restored'] : 0;
            if ($bulk_restored > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html(sprintf(
                        _n('%d schedule restored.', '%d schedules restored.', $bulk_restored, 'adoration-scheduler'),
                        $bulk_restored
                    ))
                    . '</p></div>';
            }

            $bulk_deleted_perm = isset($_GET['schedules_deleted_perm']) ? (int)$_GET['schedules_deleted_perm'] : 0;
            $bulk_blocked_perm = isset($_GET['schedules_blocked_perm']) ? (int)$_GET['schedules_blocked_perm'] : 0;

            if ($bulk_deleted_perm > 0 || $bulk_blocked_perm > 0) {
                $parts = [];
                if ($bulk_deleted_perm > 0) {
                    $parts[] = sprintf(
                        _n('%d schedule permanently deleted.', '%d schedules permanently deleted.', $bulk_deleted_perm, 'adoration-scheduler'),
                        $bulk_deleted_perm
                    );
                }
                if ($bulk_blocked_perm > 0) {
                    $parts[] = sprintf(
                        _n('%d schedule could not be permanently deleted (has signups or not in Trash).', '%d schedules could not be permanently deleted (have signups or not in Trash).', $bulk_blocked_perm, 'adoration-scheduler'),
                        $bulk_blocked_perm
                    );
                }

                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(implode(' ', $parts)) . '</p></div>';
            }
            ?>

            <?php $table->views(); ?>

            <!-- WP_List_Table expects POST for bulk actions -->
            <form method="post">
                <input type="hidden" name="page" value="adoration_scheduler_schedules" />

                <?php
                // Preserve view + filters + sorting when searching/paginating
                $preserve_keys = [
                    'status',
                    'start_from',
                    'start_to',
                    'end_from',
                    'end_to',
                    'orderby',
                    'order',
                    's',
                    'paged',
                ];

                foreach ($preserve_keys as $key) {
                    if (!isset($_REQUEST[$key]) || $_REQUEST[$key] === '') continue;

                    $val = wp_unslash($_REQUEST[$key]);

                    switch ($key) {
                        case 'status':
                        case 'orderby':
                        case 'order':
                            $val = sanitize_key($val);
                            break;
                        case 'paged':
                            $val = (string)max(1, (int)$val);
                            break;
                        default:
                            $val = sanitize_text_field($val);
                            break;
                    }

                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" />';
                }

                $table->search_box(__('Search Schedules', 'adoration-scheduler'), 'adoration-schedules');
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    // ----------------- helpers -----------------

    private static function require_admin_cap(string $capability): void
    {
        if (!is_admin()) {
            wp_die(esc_html__('Invalid context.', 'adoration-scheduler'), 400);
        }

        if (!self::current_user_can_with_fallback($capability)) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }
    }

    private static function current_user_can_with_fallback(string $capability): bool
    {
        $capability = sanitize_key($capability);

        if ($capability !== '' && current_user_can($capability)) {
            return true;
        }

        return current_user_can('manage_options');
    }

    private static function rate_limit_ok(string $action): bool
    {
        $action = sanitize_key($action);
        if ($action === '') $action = 'action';

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) return false;

        $key = 'as_rl_admin_' . md5($action . '|' . $user_id);

        $data = get_transient($key);
        if (!is_array($data)) {
            $data = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $now   = time();
        $start = (int)($data['start'] ?? $now);
        $count = (int)($data['count'] ?? 0);

        if (($now - $start) >= self::RL_WINDOW_SECONDS) {
            $start = $now;
            $count = 0;
        }

        $count++;
        $data['count'] = $count;
        $data['start'] = $start;

        $ttl = max(5, self::RL_WINDOW_SECONDS - ($now - $start)) + 5;
        set_transient($key, $data, $ttl);

        return ($count <= self::RL_MAX_ATTEMPTS);
    }
}
