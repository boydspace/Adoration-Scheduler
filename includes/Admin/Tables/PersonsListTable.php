<?php
namespace AdorationScheduler\Admin\Tables;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Public\AccessRequestHandler;

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class PersonsListTable extends \WP_List_Table {

    /** @var PersonsRepository */
    private PersonsRepository $repo;

    /** @var string */
    private string $page_slug;

    /** @var string */
    private string $search;

    /** @var int */
    private int $per_page = 50;

    public function __construct(string $page_slug, string $search = '') {
        parent::__construct([
            'singular' => 'person',
            'plural'   => 'people',
            'ajax'     => false,
        ]);

        $this->repo      = new PersonsRepository();
        $this->page_slug = sanitize_key($page_slug);
        $this->search    = sanitize_text_field($search);
    }

    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox" />',
            'id'            => __('ID', 'adoration-scheduler'),
            'name'          => __('Name', 'adoration-scheduler'),
            'email'         => __('Email', 'adoration-scheduler'),
            'phone'         => __('Phone', 'adoration-scheduler'),
            'approval'      => __('Access', 'adoration-scheduler'),
            'signup_count'  => __('Signups', 'adoration-scheduler'),
            'last_signup'   => __('Last Signup', 'adoration-scheduler'),
        ];
    }

    /**
     * ✅ Approval-status filter tabs (All / Pending / Approved / Rejected),
     * same convention WP core list tables use for e.g. Posts' status links.
     */
    public function get_views(): array {
        $current = sanitize_key((string) ($_GET['approval_status'] ?? ''));

        $base = admin_url('admin.php?page=' . $this->page_slug);

        $counts = [
            ''                                    => $this->repo->count_all_people($this->search),
            PersonsRepository::STATUS_PENDING  => $this->repo->count_by_approval_status(PersonsRepository::STATUS_PENDING, $this->search),
            PersonsRepository::STATUS_APPROVED => $this->repo->count_by_approval_status(PersonsRepository::STATUS_APPROVED, $this->search),
            PersonsRepository::STATUS_REJECTED => $this->repo->count_by_approval_status(PersonsRepository::STATUS_REJECTED, $this->search),
        ];

        $labels = [
            ''                                    => __('All', 'adoration-scheduler'),
            PersonsRepository::STATUS_PENDING  => __('Pending', 'adoration-scheduler'),
            PersonsRepository::STATUS_APPROVED => __('Approved', 'adoration-scheduler'),
            PersonsRepository::STATUS_REJECTED => __('Rejected', 'adoration-scheduler'),
        ];

        $views = [];
        foreach ($labels as $key => $label) {
            $url = ($key === '') ? $base : add_query_arg(['approval_status' => $key], $base);
            $class = ($current === $key) ? ' class="current"' : '';
            $views[$key === '' ? 'all' : $key] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $class,
                esc_html($label),
                (int)$counts[$key]
            );
        }

        return $views;
    }

    public function get_sortable_columns(): array {
        return [
            'id'           => ['id', true],
            'name'         => ['name', false],
            'email'        => ['email', false],
            'signup_count' => ['signup_count', true],
            'last_signup'  => ['last_signup', true],
        ];
    }

    protected function get_bulk_actions(): array {
        // Matches WP Posts behavior (bulk action label "Delete" but we enforce safe delete)
        // NOTE: the array KEY ('approve') is the internal action value posted
        // to process_bulk_action() and PeopleAdminActionsService — only the
        // label changed to "Accept" for clarity, the key stays as-is.
        return [
            'delete'  => __('Delete', 'adoration-scheduler'),
            'approve' => __('Accept', 'adoration-scheduler'),
            'reject'  => __('Reject', 'adoration-scheduler'),
        ];
    }

    /**
     * ✅ Access/approval status column.
     */
    public function column_approval($item): string {
        $status = $this->repo->approval_status_of($item);

        $badges = [
            PersonsRepository::STATUS_APPROVED => ['#00a32a', __('Approved', 'adoration-scheduler')],
            PersonsRepository::STATUS_PENDING  => ['#dba617', __('Pending', 'adoration-scheduler')],
            PersonsRepository::STATUS_REJECTED => ['#d63638', __('Rejected', 'adoration-scheduler')],
        ];

        [$color, $label] = $badges[$status] ?? ['#646970', ucfirst($status)];

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:%s;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Disable checkbox when person has signups (cannot be deleted).
     */
    public function column_cb($item): string {
        $id = (int)($item['id'] ?? 0);
        $signup_count = (int)($item['signup_count'] ?? 0);

        if ($id <= 0) return '';

        if ($signup_count > 0) {
            // No checkbox if they have signups
            return sprintf(
                '<input type="checkbox" disabled="disabled" title="%s" />',
                esc_attr__('Cannot delete: person has signups.', 'adoration-scheduler')
            );
        }

        return sprintf('<input type="checkbox" name="person_ids[]" value="%d" />', $id);
    }

    /**
     * Click-to-copy ID column
     */
    public function column_id($item): string {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) return '—';

        // Use a <button> so it's accessible and doesn't navigate.
        return sprintf(
            '<button type="button" class="button button-small as-copy-id" data-copy="%1$d" style="font-family:monospace;">%1$d</button>',
            $id
        );
    }

    /**
     * Preserve current list state in links so you don't lose search/sort/page
     */
    private function preserved_args_from_request(): array {
        $out = [];
        $keys = ['s','paged','orderby','order'];

        foreach ($keys as $k) {
            if (!isset($_REQUEST[$k])) continue;

            $v = $_REQUEST[$k];
            if (is_array($v)) continue;

            $v = wp_unslash($v);

            if ($k === 'paged') {
                $v = (string) max(1, (int) $v);
            } elseif ($k === 'orderby' || $k === 'order') {
                $v = sanitize_key($v);
            } else {
                $v = sanitize_text_field($v);
            }

            if ($v !== '') $out[$k] = $v;
        }

        return $out;
    }

    public function column_name($item): string {
        $id = (int)($item['id'] ?? 0);

        $first = trim((string)($item['first_name'] ?? ''));
        $last  = trim((string)($item['last_name'] ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name === '') $name = '—';

        $signup_count = (int)($item['signup_count'] ?? 0);

        $preserve = $this->preserved_args_from_request();

        // ✅ primary click goes to "view"
        $view_url = add_query_arg(array_merge($preserve, [
            'page'      => $this->page_slug,
            'action'    => 'view',
            'person_id' => $id,
        ]), admin_url('admin.php'));

        // Keep edit as a row action
        $edit_url = add_query_arg(array_merge($preserve, [
            'page'      => $this->page_slug,
            'action'    => 'edit',
            'person_id' => $id,
        ]), admin_url('admin.php'));

        $actions = [
            // ✅ NEW: add a View row action (does not replace anything)
            'view' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($view_url),
                esc_html__('View', 'adoration-scheduler')
            ),

            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_url),
                esc_html__('Edit', 'adoration-scheduler')
            ),
        ];

        // ✅ Merge action (prefills From person on the merge screen)
        $merge_url = add_query_arg(array_merge($preserve, [
            'page'           => 'adoration_scheduler_people_merge',
            'from_person_id' => $id,
        ]), admin_url('admin.php'));

        $actions['merge'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($merge_url),
            esc_html__('Merge…', 'adoration-scheduler')
        );

        // ✅ Accept/Reject row actions (privacy/approval gate). Rendered as
        // visible small buttons (not plain row-action text links) so
        // they're obvious at a glance, especially on Pending/Rejected rows —
        // a rejected person still gets an "Accept" button here since that's
        // the same underlying transition (target status = approved).
        $status = $this->repo->approval_status_of($item);

        $approval_form = function (string $target_status, string $label, string $confirm, string $button_class) use ($id) {
            return sprintf(
                '<form method="post" action="%s" style="display:inline;">
                    %s
                    <input type="hidden" name="action" value="adoration_set_person_approval" />
                    <input type="hidden" name="page" value="%s" />
                    <input type="hidden" name="person_id" value="%d" />
                    <input type="hidden" name="approval_status" value="%s" />
                    <button type="submit" class="button button-small %s"%s>%s</button>
                </form>',
                esc_url(admin_url('admin-post.php')),
                wp_nonce_field('adoration_set_person_approval_' . $id, '_wpnonce', true, false),
                esc_attr($this->page_slug),
                $id,
                esc_attr($target_status),
                esc_attr($button_class),
                $confirm !== '' ? ' onclick="return confirm(' . wp_json_encode($confirm) . ');"' : '',
                esc_html($label)
            );
        };

        if ($status !== PersonsRepository::STATUS_APPROVED) {
            $actions['approve'] = $approval_form(PersonsRepository::STATUS_APPROVED, __('Accept', 'adoration-scheduler'), '', 'button-primary');
        }
        if ($status !== PersonsRepository::STATUS_REJECTED) {
            $actions['reject'] = $approval_form(PersonsRepository::STATUS_REJECTED, __('Reject', 'adoration-scheduler'), __('Reject this person\'s access request?', 'adoration-scheduler'), '');
        }

        /**
         * Single-row delete uses admin-post.php so redirects happen BEFORE any output.
         */
        if ($signup_count > 0) {
            $actions['delete'] = sprintf(
                '<span style="color:#a7aaad;" title="%s">%s</span>',
                esc_attr__('This person has signups and cannot be deleted until those signups are removed.', 'adoration-scheduler'),
                esc_html__('Can’t delete (has signups)', 'adoration-scheduler')
            );
        } else {
            $delete_form = sprintf(
                '<form method="post" action="%s" style="display:inline;">
                    %s
                    <input type="hidden" name="action" value="adoration_delete_person" />
                    <input type="hidden" name="page" value="%s" />
                    <input type="hidden" name="person_id" value="%d" />
                    <button type="submit" class="submitdelete" style="background:none;border:none;padding:0;margin:0;color:#b32d2e;cursor:pointer;"
                        onclick="return confirm(%s);">%s</button>
                </form>',
                esc_url(admin_url('admin-post.php')),
                wp_nonce_field('adoration_delete_person_' . $id, '_wpnonce', true, false),
                esc_attr($this->page_slug),
                $id,
                wp_json_encode(__('Delete this person? This cannot be undone.', 'adoration-scheduler')),
                esc_html__('Delete', 'adoration-scheduler')
            );

            $actions['delete'] = $delete_form;
        }

        // Link title goes to view page
        $name_link = sprintf(
            '<a class="row-title" href="%s">%s</a>',
            esc_url($view_url),
            esc_html($name)
        );

        return $name_link . $this->row_actions($actions);
    }

    public function column_email($item): string {
        $email = trim((string)($item['email'] ?? ''));
        return esc_html($email !== '' ? $email : '—');
    }

    public function column_phone($item): string {
        $phone = trim((string)($item['phone'] ?? ''));
        return esc_html($phone !== '' ? $phone : '—');
    }

    public function column_signup_count($item): string {
        $count = (int)($item['signup_count'] ?? 0);

        if ($count > 0) {
            return sprintf('<strong>%d</strong>', $count);
        }
        return '0';
    }

    public function column_last_signup($item): string {
        $raw = trim((string)($item['last_signup_at'] ?? ''));
        if ($raw === '') return '—';

        $ts = strtotime($raw);
        if (!$ts) return esc_html($raw);

        return esc_html(date_i18n('M j, Y', $ts));
    }

    public function column_default($item, $column_name) {
        return '';
    }

    /**
     * Bulk delete (Posts-style):
     * - Must be POST
     * - Nonce check: bulk-{$plural}
     * - Safe delete: only people with 0 signups
     * - Redirect after processing with counts in query args
     *
     * NOTE:
     * This is still fine because your Plugin.php runs this early via admin_init.
     */
    public function process_bulk_action(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action  = sanitize_key($_POST['action'] ?? '');
        $action2 = sanitize_key($_POST['action2'] ?? '');

        $candidate = '';
        if ($action !== '' && $action !== '-1') {
            $candidate = $action;
        } elseif ($action2 !== '' && $action2 !== '-1') {
            $candidate = $action2;
        }

        if (!in_array($candidate, ['delete', 'approve', 'reject'], true)) {
            return;
        }

        if ( ! current_user_can('manage_options') ) {
            return;
        }

        check_admin_referer('bulk-' . $this->_args['plural']);

        $ids = isset($_POST['person_ids']) ? (array) $_POST['person_ids'] : [];
        $ids = array_map('intval', $ids);
        $ids = array_values(array_filter($ids, fn($v) => $v > 0));

        if (empty($ids)) {
            return;
        }

        $base = admin_url('admin.php?page=' . $this->page_slug);

        $preserve = ['s','paged','orderby','order','approval_status'];
        $args = [];

        foreach ($preserve as $k) {
            if (!isset($_REQUEST[$k]) || $_REQUEST[$k] === '') continue;
            $v = wp_unslash($_REQUEST[$k]);

            if (in_array($k, ['orderby','order','approval_status'], true)) {
                $v = sanitize_key($v);
            } elseif ($k === 'paged') {
                $v = (string)max(1, (int)$v);
            } else {
                $v = sanitize_text_field($v);
            }

            $args[$k] = $v;
        }

        if ($candidate === 'approve' || $candidate === 'reject') {
            $target_status = ($candidate === 'approve')
                ? PersonsRepository::STATUS_APPROVED
                : PersonsRepository::STATUS_REJECTED;

            $updated = 0;
            foreach ($ids as $pid) {
                // ✅ Same "only email on an actual transition into approved"
                // guard as the single-row action (PeopleAdminActionsService::
                // handle_set_approval()) — capture prior status first so a
                // bulk-approve that includes an already-approved row doesn't
                // re-send the notice.
                $person_before = $this->repo->find($pid);
                $prior_status  = $person_before ? $this->repo->approval_status_of($person_before) : null;

                if ($this->repo->set_approval_status($pid, $target_status)) {
                    $updated++;

                    if ($target_status === PersonsRepository::STATUS_APPROVED && $prior_status !== PersonsRepository::STATUS_APPROVED) {
                        AccessRequestHandler::notify_person_approved($person_before);
                    }
                }
            }

            $args['bulk_approval_updated'] = (string)$updated;
            $args['bulk_approval_action']  = $candidate;

            wp_safe_redirect(add_query_arg($args, $base));
            exit;
        }

        $deleted = 0;
        $blocked = 0;

        foreach ($ids as $pid) {
            $count = (int)$this->repo->count_signups_for_person($pid);
            if ($count > 0) {
                $blocked++;
                continue;
            }

            if ($this->repo->delete_person($pid)) {
                $deleted++;
            }
        }

        $args['bulk_deleted'] = (string)$deleted;
        $args['bulk_blocked'] = (string)$blocked;

        $url = add_query_arg($args, $base);

        wp_safe_redirect($url);
        exit;
    }

    public function prepare_items(): void {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $paged  = max(1, (int)$this->get_pagenum());
        $offset = ($paged - 1) * $this->per_page;

        $orderby = sanitize_key($_GET['orderby'] ?? 'name');
        $order   = strtolower(sanitize_key($_GET['order'] ?? 'asc'));
        if (!in_array($order, ['asc','desc'], true)) $order = 'asc';

        $approval_status = sanitize_key((string) ($_GET['approval_status'] ?? ''));

        $total = $this->repo->count_all_people($this->search, $approval_status);
        $rows  = $this->repo->list_all_people_with_stats($this->per_page, $offset, $this->search, $approval_status);

        // in-PHP sort for current page
        if ($orderby === 'id') {
            usort($rows, function($a, $b) use ($order) {
                $va = (int)($a['id'] ?? 0);
                $vb = (int)($b['id'] ?? 0);
                return $order === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
            });
        } elseif ($orderby === 'email') {
            usort($rows, function($a, $b) use ($order) {
                $va = strtolower((string)($a['email'] ?? ''));
                $vb = strtolower((string)($b['email'] ?? ''));
                return $order === 'asc' ? strcmp($va, $vb) : strcmp($vb, $va);
            });
        } elseif ($orderby === 'signup_count') {
            usort($rows, function($a, $b) use ($order) {
                $va = (int)($a['signup_count'] ?? 0);
                $vb = (int)($b['signup_count'] ?? 0);
                return $order === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
            });
        } elseif ($orderby === 'last_signup') {
            usort($rows, function($a, $b) use ($order) {
                $va = (string)($a['last_signup_at'] ?? '');
                $vb = (string)($b['last_signup_at'] ?? '');
                $ta = $va ? strtotime($va) : 0;
                $tb = $vb ? strtotime($vb) : 0;
                return $order === 'asc' ? ($ta <=> $tb) : ($tb <=> $ta);
            });
        } else {
            usort($rows, function($a, $b) use ($order) {
                $la = strtolower((string)($a['last_name'] ?? ''));
                $lb = strtolower((string)($b['last_name'] ?? ''));
                $fa = strtolower((string)($a['first_name'] ?? ''));
                $fb = strtolower((string)($b['first_name'] ?? ''));
                $cmp = $la <=> $lb;
                if ($cmp === 0) $cmp = $fa <=> $fb;
                return $order === 'asc' ? $cmp : -$cmp;
            });
        }

        $this->items = $rows;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $this->per_page,
            'total_pages' => (int) ceil($total / $this->per_page),
        ]);
    }

    public function search_box($text, $input_id) {
        parent::search_box($text, $input_id);
    }

    public function display(): void {
        parent::display();

        // One-time click-to-copy script for the ID buttons.
        ?>
        <script>
        (function(){
            function copyText(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text);
                }
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch(e) {}
                document.body.removeChild(ta);
                return Promise.resolve();
            }

            document.addEventListener('click', function(e){
                const btn = e.target && e.target.closest ? e.target.closest('.as-copy-id') : null;
                if (!btn) return;

                e.preventDefault();
                const val = btn.getAttribute('data-copy') || '';
                if (!val) return;

                const old = btn.textContent;
                copyText(val).then(() => {
                    btn.textContent = 'Copied';
                    window.setTimeout(() => { btn.textContent = old; }, 900);
                });
            });
        })();
        </script>
        <?php
    }
}
