<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

class EmailLogPage
{
    private const ACTION_EXPORT      = 'adoration_email_log_export';
    private const ACTION_PURGE       = 'adoration_email_log_purge';
    private const ACTION_BULK_DELETE = 'adoration_email_log_bulk_delete';
    private const ACTION_DELETE_ONE  = 'adoration_email_log_delete_one';

    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_EXPORT,      [__CLASS__, 'handle_export']);
        add_action('admin_post_' . self::ACTION_PURGE,       [__CLASS__, 'handle_purge']);
        add_action('admin_post_' . self::ACTION_BULK_DELETE, [__CLASS__, 'handle_bulk_delete']);
        add_action('admin_post_' . self::ACTION_DELETE_ONE,  [__CLASS__, 'handle_delete_one']);
    }

    public function render(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $repo_class = '\\AdorationScheduler\\Domain\\Repositories\\EmailLogRepository';
        if ( ! class_exists($repo_class) ) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Email Log', 'adoration-scheduler') . '</h1>';
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('EmailLogRepository not found.', 'adoration-scheduler');
            echo '</p></div>';
            echo '</div>';
            return;
        }

        $action = sanitize_key($_GET['action'] ?? '');
        if ($action === 'view') {
            $this->render_view((int)($_GET['log_id'] ?? 0), $repo_class);
            return;
        }

        $this->render_list($repo_class);
    }

    // ---------------------------------------------------------------------
    // LIST
    // ---------------------------------------------------------------------

    private function render_list(string $repo_class): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Email Log', 'adoration-scheduler') . '</h1>';
        \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_email_log');

        // notices
        $msg = sanitize_text_field($_GET['msg'] ?? '');
        if ($msg === 'purged') {
            $n = (int)($_GET['n'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf('Purged %d log entries.', $n));
            echo '</p></div>';
        } elseif ($msg === 'export_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('Export failed.', 'adoration-scheduler');
            echo '</p></div>';
        } elseif ($msg === 'purge_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('Purge failed.', 'adoration-scheduler');
            echo '</p></div>';
        } elseif ($msg === 'deleted') {
            $n = (int)($_GET['n'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf('Deleted %d log entries.', $n));
            echo '</p></div>';
        } elseif ($msg === 'delete_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('Delete failed.', 'adoration-scheduler');
            echo '</p></div>';
        } elseif ($msg === 'delete_none') {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html__('No log entries were selected.', 'adoration-scheduler');
            echo '</p></div>';
        }

        // Read filters from query string
        $s       = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string)$_GET['s'])) : '';
        $type    = isset($_GET['type']) ? sanitize_key(wp_unslash((string)$_GET['type'])) : '';
        $success = isset($_GET['success']) ? (string)wp_unslash((string)$_GET['success']) : ''; // '1'|'0'|''

        $paged    = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
        $per_page = max(1, min(100, $per_page));

        $orderby = isset($_GET['orderby']) ? sanitize_key((string)$_GET['orderby']) : 'created_at';
        $order   = isset($_GET['order']) ? strtoupper(sanitize_key((string)$_GET['order'])) : 'DESC';

        $allowed_orderby = ['id','created_at','to_email','type','context','success'];
        if (!in_array($orderby, $allowed_orderby, true)) $orderby = 'created_at';
        $order = ($order === 'ASC') ? 'ASC' : 'DESC';

        $page_slug = 'adoration_scheduler_email_log';

        // Base args to preserve view state
        $base_args = [
            'page'     => $page_slug,
            's'        => $s,
            'type'     => $type,
            'success'  => ($success === '1' || $success === '0') ? $success : '',
            'per_page' => $per_page,
            'orderby'  => $orderby,
            'order'    => $order,
        ];

        $admin_url_with = function(array $more = []) use ($base_args): string {
            $args = array_merge($base_args, $more);

            // Drop empties (clean URLs)
            foreach ($args as $k => $v) {
                if ($v === '' || $v === null) unset($args[$k]);
            }

            return add_query_arg($args, admin_url('admin.php'));
        };

        $sort_link = function(string $col) use ($orderby, $order, $paged, $admin_url_with): string {
            $next = 'ASC';
            if ($orderby === $col && $order === 'ASC') $next = 'DESC';

            return $admin_url_with([
                'orderby' => $col,
                'order'   => $next,
                'paged'   => $paged,
            ]);
        };

        $sort_indicator = function(string $col) use ($orderby, $order): string {
            if ($orderby !== $col) return '';
            return $order === 'ASC' ? ' ▲' : ' ▼';
        };

        try {
            /** @var object $repo */
            $repo = new $repo_class();

            $res = (array) $repo->query([
                's'        => $s,
                'type'     => $type,
                'success'  => ($success === '1' || $success === '0') ? $success : '',
                'paged'    => $paged,
                'per_page' => $per_page,
                'orderby'  => $orderby,
                'order'    => $order,
            ]);

            $rows  = (array)($res['rows'] ?? []);
            $total = (int)($res['total'] ?? 0);

            // Build type options (keep your defaults + add any observed)
            $type_options = [
                '' => __('All types', 'adoration-scheduler'),
                'signup_confirmation' => 'signup_confirmation',
                'reminder_24h'        => 'reminder_24h',
                'magic_link'          => 'magic_link',
            ];
            foreach ($rows as $r) {
                $t = sanitize_key((string)($r['type'] ?? ''));
                if ($t !== '' && !isset($type_options[$t])) $type_options[$t] = $t;
            }

            // ---------- Toolbar: Export + Purge ----------
            $export_url = admin_url('admin-post.php');
            $purge_url  = admin_url('admin-post.php');

            echo '<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin: 10px 0 14px;">';

            // Export form (respects filters)
            echo '<form method="post" action="' . esc_url($export_url) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_EXPORT) . '"/>';
            wp_nonce_field('adoration_email_log_export');
            echo '<input type="hidden" name="s" value="' . esc_attr($s) . '"/>';
            echo '<input type="hidden" name="type" value="' . esc_attr($type) . '"/>';
            echo '<input type="hidden" name="success" value="' . esc_attr(($success === '1' || $success === '0') ? $success : '') . '"/>';
            echo '<button class="button" type="submit">' . esc_html__('Export CSV', 'adoration-scheduler') . '</button>';
            echo '</form>';

            // Purge form
            echo '<form method="post" action="' . esc_url($purge_url) . '" onsubmit="return confirm(\'Purge old email logs? This cannot be undone.\');">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_PURGE) . '"/>';
            wp_nonce_field('adoration_email_log_purge');

            echo '<label for="adoration-email-log-purge-days" style="font-weight:600; display:block;">' . esc_html__('Purge logs older than', 'adoration-scheduler') . '</label>';
            echo '<div style="display:flex; gap:8px; align-items:center;">';
            echo '<select id="adoration-email-log-purge-days" name="days">';
            echo '<option value="30">30 days</option>';
            echo '<option value="90">90 days</option>';
            echo '<option value="180">180 days</option>';
            echo '<option value="365">365 days</option>';
            echo '</select>';
            echo '<button class="button button-secondary" type="submit">' . esc_html__('Purge', 'adoration-scheduler') . '</button>';
            echo '</div>';

            echo '</form>';
            echo '</div>';

            // ---------- Filter form ----------
            echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin: 10px 0 12px;">';
            echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '" />';

            echo '<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">';

            // Search
            echo '<div>';
            echo '<label for="adoration-email-log-s" style="display:block; font-weight:600;">' . esc_html__('Search', 'adoration-scheduler') . '</label>';
            echo '<input id="adoration-email-log-s" class="regular-text" type="search" name="s" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('email, subject, type…', 'adoration-scheduler') . '" />';
            echo '</div>';

            // Type
            echo '<div>';
            echo '<label for="adoration-email-log-type" style="display:block; font-weight:600;">' . esc_html__('Type', 'adoration-scheduler') . '</label>';
            echo '<select id="adoration-email-log-type" name="type">';
            foreach ($type_options as $val => $label) {
                echo '<option value="' . esc_attr($val) . '" ' . selected($type, $val, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</div>';

            // Success
            echo '<div>';
            echo '<label for="adoration-email-log-success" style="display:block; font-weight:600;">' . esc_html__('Success', 'adoration-scheduler') . '</label>';
            echo '<select id="adoration-email-log-success" name="success">';
            echo '<option value="" ' . selected($success, '', false) . '>' . esc_html__('All', 'adoration-scheduler') . '</option>';
            echo '<option value="1" ' . selected($success, '1', false) . '>' . esc_html__('Success', 'adoration-scheduler') . '</option>';
            echo '<option value="0" ' . selected($success, '0', false) . '>' . esc_html__('Fail', 'adoration-scheduler') . '</option>';
            echo '</select>';
            echo '</div>';

            // Per page
            echo '<div>';
            echo '<label for="adoration-email-log-per-page" style="display:block; font-weight:600;">' . esc_html__('Rows', 'adoration-scheduler') . '</label>';
            echo '<select id="adoration-email-log-per-page" name="per_page">';
            foreach ([10,20,50,100] as $n) {
                echo '<option value="' . (int)$n . '" ' . selected((string)$per_page, (string)$n, false) . '>' . esc_html($n . ' / page') . '</option>';
            }
            echo '</select>';
            echo '</div>';

            // Keep current sort when filtering
            echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '" />';
            echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';

            // Buttons
            echo '<div>';
            echo '<button class="button button-primary" type="submit">' . esc_html__('Filter', 'adoration-scheduler') . '</button> ';
            $reset_url = add_query_arg(['page' => $page_slug], admin_url('admin.php'));
            echo '<a class="button" href="' . esc_url($reset_url) . '">' . esc_html__('Reset', 'adoration-scheduler') . '</a>';
            echo '</div>';

            echo '</div>';
            echo '</form>';

            // Summary
            $showing_from = ($total > 0) ? (($paged - 1) * $per_page + 1) : 0;
            $showing_to   = min($total, $paged * $per_page);
            echo '<p class="description">' . esc_html(sprintf('Showing %d–%d of %d log entries.', $showing_from, $showing_to, $total)) . '</p>';

            if (empty($rows)) {
                echo '<div class="notice notice-info"><p>' . esc_html__('No email log entries found for these filters.', 'adoration-scheduler') . '</p></div>';
                echo '</div>';
                return;
            }

            // ----------------------------
            // Bulk delete form + table
            // ----------------------------
            $bulk_url = admin_url('admin-post.php');

            echo '<form method="post" action="' . esc_url($bulk_url) . '" onsubmit="return confirm(\'Delete selected log entries? This cannot be undone.\');">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_BULK_DELETE) . '"/>';
            wp_nonce_field('adoration_email_log_bulk_delete');

            // Preserve view state in POST so we can redirect back
            foreach ($base_args as $k => $v) {
                if ($v === '' || $v === null) continue;
                echo '<input type="hidden" name="return_' . esc_attr($k) . '" value="' . esc_attr((string)$v) . '" />';
            }
            echo '<input type="hidden" name="return_paged" value="' . esc_attr((string)$paged) . '" />';

            echo '<div class="tablenav top" style="display:flex; align-items:center; gap:8px; margin: 10px 0;">';
            echo '<select name="bulk_action">';
            echo '<option value="">' . esc_html__('Bulk actions', 'adoration-scheduler') . '</option>';
            echo '<option value="delete">' . esc_html__('Delete', 'adoration-scheduler') . '</option>';
            echo '</select>';
            echo '<button class="button action" type="submit">' . esc_html__('Apply', 'adoration-scheduler') . '</button>';
            echo '</div>';

            // Table (sortable headers)
            echo '<table class="widefat striped">';
            echo '<thead><tr>';

            echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1" /></td>';
            echo '<th style="width:170px;"><a href="' . esc_url($sort_link('created_at')) . '">' . esc_html__('When', 'adoration-scheduler') . esc_html($sort_indicator('created_at')) . '</a></th>';
            echo '<th style="width:240px;"><a href="' . esc_url($sort_link('to_email')) . '">' . esc_html__('To', 'adoration-scheduler') . esc_html($sort_indicator('to_email')) . '</a></th>';
            echo '<th style="width:170px;"><a href="' . esc_url($sort_link('type')) . '">' . esc_html__('Type', 'adoration-scheduler') . esc_html($sort_indicator('type')) . '</a></th>';
            echo '<th style="width:160px;"><a href="' . esc_url($sort_link('context')) . '">' . esc_html__('Context', 'adoration-scheduler') . esc_html($sort_indicator('context')) . '</a></th>';
            echo '<th style="width:90px;"><a href="' . esc_url($sort_link('success')) . '">' . esc_html__('Success', 'adoration-scheduler') . esc_html($sort_indicator('success')) . '</a></th>';
            echo '<th>' . esc_html__('Subject', 'adoration-scheduler') . '</th>';

            echo '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $id      = (int)($r['id'] ?? 0);
                $created = (string)($r['created_at'] ?? '');
                $to      = (string)($r['to_email'] ?? '');
                $rtype   = (string)($r['type'] ?? '');
                $context = (string)($r['context'] ?? '');
                $ok      = isset($r['success']) ? (int)$r['success'] : 0;
                $subject = (string)($r['subject'] ?? '');

                $view_url = $admin_url_with([
                    'action' => 'view',
                    'log_id' => $id,
                    'paged'  => $paged,
                ]);

                $delete_one_url = wp_nonce_url(
                    add_query_arg(array_merge($base_args, [
                        'page'    => $page_slug,
                        'action'  => 'delete_one',
                        'log_id'  => $id,
                        'paged'   => $paged,
                    ]), admin_url('admin-post.php')),
                    'adoration_email_log_delete_one',
                    '_wpnonce'
                );

                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr((string)$id) . '" /></th>';
                echo '<td>' . esc_html($created) . '</td>';
                echo '<td><code>' . esc_html($to) . '</code></td>';
                echo '<td><code>' . esc_html($rtype) . '</code></td>';
                echo '<td><code>' . esc_html($context) . '</code></td>';
                echo '<td>' . ($ok ? '✅' : '❌') . '</td>';
                echo '<td>' . esc_html($subject);

                if ($id > 0) {
                    echo '<div class="row-actions">';
                    echo '<span class="view"><a href="' . esc_url($view_url) . '">' . esc_html__('View', 'adoration-scheduler') . '</a></span> | ';
                    echo '<span class="trash"><a href="' . esc_url($delete_one_url) . '" onclick="return confirm(\'Delete this log entry? This cannot be undone.\');">' . esc_html__('Delete', 'adoration-scheduler') . '</a></span>';
                    echo '</div>';
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '</form>';

            // JS: select all checkboxes
            echo '<script>
                (function(){
                    var cb = document.getElementById("cb-select-all-1");
                    if (!cb) return;
                    cb.addEventListener("change", function(){
                        var checks = document.querySelectorAll("input[name=\'ids[]\']");
                        for (var i=0;i<checks.length;i++) { checks[i].checked = cb.checked; }
                    });
                })();
            </script>';

            // Pagination (preserves filters + sorting + per_page)
            $total_pages = (int) ceil(max(0, $total) / max(1, $per_page));
            if ($total_pages > 1) {
                $page_links = paginate_links([
                    'base'      => $admin_url_with(['paged' => '%#%']),
                    'format'    => '',
                    'prev_text' => __('&laquo; Previous', 'adoration-scheduler'),
                    'next_text' => __('Next &raquo;', 'adoration-scheduler'),
                    'total'     => $total_pages,
                    'current'   => $paged,
                    'type'      => 'array',
                ]);

                if (!empty($page_links)) {
                    echo '<div class="tablenav"><div class="tablenav-pages" style="margin: 10px 0;">';
                    echo '<span class="pagination-links">';
                    foreach ($page_links as $link) echo wp_kses_post($link) . " \n";
                    echo '</span>';
                    echo '</div></div>';
                }
            }

        } catch (\Throwable $e) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Email Log page error: ', 'adoration-scheduler') . esc_html($e->getMessage());
            echo '</p></div>';
        }

        echo '</div>';
    }

    // ---------------------------------------------------------------------
    // VIEW
    // ---------------------------------------------------------------------

    private function render_view(int $log_id, string $repo_class): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Email Log Entry', 'adoration-scheduler') . '</h1>';

        $back_args = ['page' => 'adoration_scheduler_email_log'];
        foreach (['s','type','success','paged','per_page','orderby','order'] as $k) {
            if (isset($_GET[$k]) && (string)$_GET[$k] !== '') {
                $back_args[$k] = sanitize_text_field(wp_unslash((string)$_GET[$k]));
            }
        }
        $back_url = add_query_arg($back_args, admin_url('admin.php'));

        echo '<p><a class="button" href="' . esc_url($back_url) . '">&larr; ' . esc_html__('Back to Email Log', 'adoration-scheduler') . '</a></p>';

        if ($log_id <= 0) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Missing or invalid log_id.', 'adoration-scheduler') . '</p></div>';
            echo '</div>';
            return;
        }

        try {
            $repo = new $repo_class();
            $row = $repo->find($log_id);

            if (!$row || !is_array($row)) {
                echo '<div class="notice notice-warning"><p>' . esc_html__('Log entry not found.', 'adoration-scheduler') . '</p></div>';
                echo '</div>';
                return;
            }

            $created = (string)($row['created_at'] ?? '');
            $to      = (string)($row['to_email'] ?? '');
            $type    = (string)($row['type'] ?? '');
            $context = (string)($row['context'] ?? '');
            $sid     = isset($row['schedule_id']) ? (int)$row['schedule_id'] : 0;
            $signup  = isset($row['signup_id']) ? (int)$row['signup_id'] : 0;
            $subject = (string)($row['subject'] ?? '');
            $headers = (string)($row['headers'] ?? '');
            $body    = (string)($row['body'] ?? '');
            $ok      = isset($row['success']) ? (int)$row['success'] : 0;
            $err     = (string)($row['error_message'] ?? '');

            echo '<table class="widefat striped" style="max-width: 1100px;"><tbody>';
            $this->kv_row(__('When', 'adoration-scheduler'), $created);
            $this->kv_row(__('To', 'adoration-scheduler'), '<code>' . esc_html($to) . '</code>', true);
            $this->kv_row(__('Type', 'adoration-scheduler'), '<code>' . esc_html($type) . '</code>', true);
            $this->kv_row(__('Context', 'adoration-scheduler'), '<code>' . esc_html($context) . '</code>', true);
            $this->kv_row(__('Schedule ID', 'adoration-scheduler'), $sid > 0 ? (string)$sid : '');
            $this->kv_row(__('Signup ID', 'adoration-scheduler'), $signup > 0 ? (string)$signup : '');
            $this->kv_row(__('Success', 'adoration-scheduler'), $ok ? '✅' : '❌');
            if (!$ok && $err !== '') $this->kv_row(__('Error', 'adoration-scheduler'), '<code>' . esc_html($err) . '</code>', true);
            $this->kv_row(__('Subject', 'adoration-scheduler'), esc_html($subject));
            echo '</tbody></table>';

            echo '<h2 style="margin-top: 18px;">' . esc_html__('Headers', 'adoration-scheduler') . '</h2>';
            echo '<textarea readonly="readonly" class="large-text code" rows="6">' . esc_textarea($headers) . '</textarea>';

            echo '<h2 style="margin-top: 18px;">' . esc_html__('Body', 'adoration-scheduler') . '</h2>';
            echo '<textarea readonly="readonly" class="large-text code" rows="16">' . esc_textarea($body) . '</textarea>';

        } catch (\Throwable $e) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Email Log view error: ', 'adoration-scheduler') . esc_html($e->getMessage());
            echo '</p></div>';
        }

        echo '</div>';
    }

    private function kv_row(string $label, string $value, bool $value_is_html = false): void
    {
        echo '<tr><th style="width: 180px;">' . esc_html($label) . '</th><td>';
        if ($value_is_html) echo $value;
        else echo esc_html($value);
        echo '</td></tr>';
    }

    // ---------------------------------------------------------------------
    // HANDLERS
    // ---------------------------------------------------------------------

    public static function handle_purge(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }
        check_admin_referer('adoration_email_log_purge');

        $days = isset($_POST['days']) ? (int)$_POST['days'] : 90;
        $days = max(1, min(3650, $days)); // cap at 10 years

        $repo_class = '\\AdorationScheduler\\Domain\\Repositories\\EmailLogRepository';
        if (!class_exists($repo_class)) {
            wp_safe_redirect(add_query_arg(['page'=>'adoration_scheduler_email_log','msg'=>'purge_failed'], admin_url('admin.php')));
            exit;
        }

        $repo = new $repo_class();
        $n = method_exists($repo, 'delete_older_than_days') ? (int)$repo->delete_older_than_days($days) : 0;

        wp_safe_redirect(add_query_arg([
            'page' => 'adoration_scheduler_email_log',
            'msg'  => 'purged',
            'n'    => $n,
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_export(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }
        check_admin_referer('adoration_email_log_export');

        $repo_class = '\\AdorationScheduler\\Domain\\Repositories\\EmailLogRepository';
        if (!class_exists($repo_class)) {
            wp_safe_redirect(add_query_arg(['page'=>'adoration_scheduler_email_log','msg'=>'export_failed'], admin_url('admin.php')));
            exit;
        }

        $s       = isset($_POST['s']) ? sanitize_text_field(wp_unslash((string)$_POST['s'])) : '';
        $type    = isset($_POST['type']) ? sanitize_key((string)$_POST['type']) : '';
        $success = isset($_POST['success']) ? (string)$_POST['success'] : '';
        if ($success !== '1' && $success !== '0') $success = '';

        $repo = new $repo_class();

        $rows = method_exists($repo, 'export_rows')
            ? (array) $repo->export_rows([
                's'       => $s,
                'type'    => $type,
                'success' => $success,
                'orderby' => 'created_at',
                'order'   => 'DESC',
            ], 5000)
            : [];

        // Send CSV
        $filename = 'adoration-email-log-' . date('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');

        // BOM for Excel friendliness
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, [
            'id','created_at','to_email','type','context','schedule_id','signup_id','success','subject','error_message'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                (string)($r['id'] ?? ''),
                (string)($r['created_at'] ?? ''),
                (string)($r['to_email'] ?? ''),
                (string)($r['type'] ?? ''),
                (string)($r['context'] ?? ''),
                (string)($r['schedule_id'] ?? ''),
                (string)($r['signup_id'] ?? ''),
                (string)($r['success'] ?? ''),
                (string)($r['subject'] ?? ''),
                (string)($r['error_message'] ?? ''),
            ]);
        }

        fclose($out);
        exit;
    }

    public static function handle_bulk_delete(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }
        check_admin_referer('adoration_email_log_bulk_delete');

        $action = sanitize_key($_POST['bulk_action'] ?? '');
        $ids    = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];

        // Build redirect args (preserve view state)
        $return_args = ['page' => 'adoration_scheduler_email_log'];
        foreach (['page','s','type','success','paged','per_page','orderby','order'] as $k) {
            $rk = 'return_' . $k;
            if (!isset($_POST[$rk])) continue;
            $val = wp_unslash((string)$_POST[$rk]);
            if ($val === '') continue;
            $return_args[$k] = sanitize_text_field($val);
        }
        // Ensure correct page slug
        $return_args['page'] = 'adoration_scheduler_email_log';

        if ($action !== 'delete') {
            wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
            exit;
        }

        $ids = array_values(array_filter($ids, fn($v) => $v > 0));
        if (empty($ids)) {
            $return_args['msg'] = 'delete_none';
            wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
            exit;
        }

        $repo_class = '\\AdorationScheduler\\Domain\\Repositories\\EmailLogRepository';
        if (!class_exists($repo_class)) {
            $return_args['msg'] = 'delete_failed';
            wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
            exit;
        }

        $repo = new $repo_class();
        $n = method_exists($repo, 'delete_ids') ? (int)$repo->delete_ids($ids) : 0;

        $return_args['msg'] = 'deleted';
        $return_args['n']   = $n;

        wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
        exit;
    }

    public static function handle_delete_one(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }
        // This one is a GET admin-post link with wp_nonce_url()
        check_admin_referer('adoration_email_log_delete_one');

        $id = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;

        // Preserve state from query string if present
        $return_args = ['page' => 'adoration_scheduler_email_log'];
        foreach (['s','type','success','paged','per_page','orderby','order'] as $k) {
            if (isset($_GET[$k]) && (string)$_GET[$k] !== '') {
                $return_args[$k] = sanitize_text_field(wp_unslash((string)$_GET[$k]));
            }
        }

        if ($id <= 0) {
            $return_args['msg'] = 'delete_failed';
            wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
            exit;
        }

        $repo_class = '\\AdorationScheduler\\Domain\\Repositories\\EmailLogRepository';
        if (!class_exists($repo_class)) {
            $return_args['msg'] = 'delete_failed';
            wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
            exit;
        }

        $repo = new $repo_class();
        $n = method_exists($repo, 'delete_ids') ? (int)$repo->delete_ids([$id]) : 0;

        $return_args['msg'] = 'deleted';
        $return_args['n']   = $n;

        wp_safe_redirect(add_query_arg($return_args, admin_url('admin.php')));
        exit;
    }
}
