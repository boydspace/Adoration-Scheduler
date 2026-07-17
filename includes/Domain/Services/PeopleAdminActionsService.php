<?php
namespace AdorationScheduler\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Public\AccessRequestHandler;

class PeopleAdminActionsService {

    public static function register(): void {
        add_action('admin_post_adoration_delete_person', [__CLASS__, 'handle_delete_person']);
        add_action('admin_post_adoration_save_person',   [__CLASS__, 'handle_save_person']);
        add_action('admin_post_adoration_set_person_approval', [__CLASS__, 'handle_set_approval']);
    }

    private static function normalize_phone_us(?string $raw): ?string {
        $raw = (string)($raw ?? '');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) return null;

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10) return null;

        return sprintf('(%s) %s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 4)
        );
    }

    /**
     * Preserve list-table view state on redirects (search/sort/paging).
     * Works for both GET and POST submissions.
     */
    private static function get_preserved_args(array $src): array {
        $out = [];

        $keys = ['s','paged','orderby','order'];

        foreach ($keys as $k) {
            if (!isset($src[$k])) continue;

            $v = $src[$k];
            if (is_array($v)) continue;

            $v = wp_unslash($v);

            if ($k === 'paged') {
                $v = (string) max(1, (int) $v);
            } elseif ($k === 'orderby' || $k === 'order') {
                $v = sanitize_key($v);
            } else {
                $v = sanitize_text_field($v);
            }

            if ($v !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private static function base_people_url(string $page_slug, array $preserve_from): string {
        $page_slug = sanitize_key($page_slug);
        if ($page_slug === '') {
            $page_slug = 'adoration_scheduler_people';
        }

        $base = admin_url('admin.php?page=' . $page_slug);

        $keep = self::get_preserved_args($preserve_from);
        if (!empty($keep)) {
            $base = add_query_arg($keep, $base);
        }

        return $base;
    }

    /**
     * Add toast params to a redirect URL.
     */
    private static function with_toast(string $url, string $message, string $type = 'success', bool $sticky = false): string {
        $type = sanitize_key($type);
        $allowed = ['success','error','warning','info'];
        if (!in_array($type, $allowed, true)) $type = 'success';

        // ✅ add_query_arg() does NOT urlencode new values — must encode
        // ourselves or punctuation (e.g. apostrophes) can ride raw into
        // the URL and get mangled along the way.
        $message = sanitize_text_field(wp_strip_all_tags($message));
        if (strlen($message) > 300) {
            $message = substr($message, 0, 300);
        }

        $args = [
            'as_toast'      => rawurlencode($message),
            'as_toast_type' => $type,
        ];
        if ($sticky) {
            $args['as_toast_sticky'] = '1';
        }

        return add_query_arg($args, $url);
    }

    private static function toast_msg_delete_error(string $code, int $count = 0): string {
        $code = sanitize_key($code);

        switch ($code) {
            case 'invalid':
                return __('Invalid person.', 'adoration-scheduler');
            case 'repo_missing':
                return __('Could not delete person (repository error).', 'adoration-scheduler');
            case 'has_signups':
                if ($count > 0) {
                    return sprintf(
                        /* translators: %d is number of signups */
                        _n('Cannot delete: %d signup exists for this person.', 'Cannot delete: %d signups exist for this person.', $count, 'adoration-scheduler'),
                        $count
                    );
                }
                return __('Cannot delete: this person has signups.', 'adoration-scheduler');
            case 'failed':
            default:
                return __('Could not delete person.', 'adoration-scheduler');
        }
    }

    private static function toast_msg_save_error(string $code): string {
        $code = sanitize_key($code);

        switch ($code) {
            case 'invalid':
                return __('Invalid person.', 'adoration-scheduler');
            case 'first_required':
                return __('First name is required.', 'adoration-scheduler');
            case 'bad_email':
                return __('Please enter a valid email address.', 'adoration-scheduler');
            case 'email_in_use':
                return __('That email address is already in use.', 'adoration-scheduler');
            case 'bad_phone':
                return __('Please enter a valid US phone number.', 'adoration-scheduler');
            case 'repo_missing':
                return __('Could not save person (repository error).', 'adoration-scheduler');
            case 'failed':
            default:
                return __('Could not save changes.', 'adoration-scheduler');
        }
    }

    public static function handle_delete_person(): void {

        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }

        $page_slug = sanitize_key((string) wp_unslash($_POST['page'] ?? 'adoration_scheduler_people'));

        // Preserve list state (search/paged/sort) from POST (if present)
        $redirect = self::base_people_url($page_slug, $_POST);

        $pid = (int) wp_unslash($_POST['person_id'] ?? 0);
        if ($pid <= 0) {
            $u = add_query_arg(['person_delete_error' => 'invalid'], $redirect);
            $u = self::with_toast($u, self::toast_msg_delete_error('invalid'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        check_admin_referer('adoration_delete_person_' . $pid);

        $repo = new PersonsRepository();

        if (!method_exists($repo, 'count_signups_for_person') || !method_exists($repo, 'delete_person')) {
            $u = add_query_arg(['person_delete_error' => 'repo_missing'], $redirect);
            $u = self::with_toast($u, self::toast_msg_delete_error('repo_missing'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        $count = (int) $repo->count_signups_for_person($pid);
        if ($count > 0) {
            $u = add_query_arg([
                'person_delete_error' => 'has_signups',
                'signup_count'        => $count,
            ], $redirect);
            $u = self::with_toast($u, self::toast_msg_delete_error('has_signups', $count), 'warning', true);
            wp_safe_redirect($u);
            exit;
        }

        $ok = (bool) $repo->delete_person($pid);

        if ($ok) {
            // ✅ success toast; DO NOT also set person_deleted=1 (avoids double notices)
            $u = self::with_toast($redirect, __('Person deleted.', 'adoration-scheduler'), 'success', false);
            wp_safe_redirect($u);
            exit;
        }

        $u = add_query_arg(['person_delete_error' => 'failed'], $redirect);
        $u = self::with_toast($u, self::toast_msg_delete_error('failed'), 'error', true);
        wp_safe_redirect($u);
        exit;
    }

    public static function handle_save_person(): void {

        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }

        check_admin_referer('adoration_save_person');

        $page_slug = sanitize_key((string) wp_unslash($_POST['page'] ?? 'adoration_scheduler_people'));

        $person_id = (int) wp_unslash($_POST['person_id'] ?? 0);

        $first     = sanitize_text_field((string) wp_unslash($_POST['first_name'] ?? ''));
        $last      = sanitize_text_field((string) wp_unslash($_POST['last_name'] ?? ''));
        $email_raw = (string) wp_unslash($_POST['email'] ?? '');
        $email     = sanitize_email($email_raw);

        $phone_raw = sanitize_text_field((string) wp_unslash($_POST['phone'] ?? ''));
        $title  = sanitize_text_field((string) wp_unslash($_POST['title'] ?? ''));
        $parish = sanitize_text_field((string) wp_unslash($_POST['parish'] ?? ''));

        $edit_redirect = admin_url('admin.php?page=' . $page_slug . '&action=edit&person_id=' . (int)$person_id);
        $list_redirect = self::base_people_url($page_slug, $_POST);

        if ($person_id <= 0) {
            $u = add_query_arg(['person_save_error' => 'invalid'], $list_redirect);
            $u = self::with_toast($u, self::toast_msg_save_error('invalid'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        if ($first === '') {
            $u = add_query_arg(['person_save_error' => 'first_required'], $edit_redirect);
            $u = self::with_toast($u, self::toast_msg_save_error('first_required'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        // If they typed an email but it got sanitized into empty, it was invalid.
        if (trim($email_raw) !== '' && $email === '') {
            $u = add_query_arg(['person_save_error' => 'bad_email'], $edit_redirect);
            $u = self::with_toast($u, self::toast_msg_save_error('bad_email'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        $repo = new PersonsRepository();

        if ($email !== '' && method_exists($repo, 'exists_email_except_id') && $repo->exists_email_except_id($email, $person_id)) {
            $u = add_query_arg(['person_save_error' => 'email_in_use'], $edit_redirect);
            $u = self::with_toast($u, self::toast_msg_save_error('email_in_use'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        $phone = '';
        if (trim($phone_raw) !== '') {
            $p = self::normalize_phone_us($phone_raw);
            if ($p === null) {
                $u = add_query_arg(['person_save_error' => 'bad_phone'], $edit_redirect);
                $u = self::with_toast($u, self::toast_msg_save_error('bad_phone'), 'error', true);
                wp_safe_redirect($u);
                exit;
            }
            $phone = $p;
        }

        if (!method_exists($repo, 'update_person')) {
            $u = add_query_arg(['person_save_error' => 'repo_missing'], $edit_redirect);
            $u = self::with_toast($u, self::toast_msg_save_error('repo_missing'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        $ok = (bool) $repo->update_person($person_id, [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'phone'      => $phone,
            'title'      => $title,
            'parish'     => $parish,
        ]);

        if ($ok) {
            // ✅ success toast; DO NOT also set person_updated=1 (avoids double notices)
            $u = self::with_toast($list_redirect, __('Person updated.', 'adoration-scheduler'), 'success', false);
            wp_safe_redirect($u);
            exit;
        }

        $u = add_query_arg(['person_save_error' => 'failed'], $edit_redirect);
        $u = self::with_toast($u, self::toast_msg_save_error('failed'), 'error', true);
        wp_safe_redirect($u);
        exit;
    }

    /**
     * ✅ Privacy/approval gate: Approve/Reject a person (row action or bulk
     * action from the People list, filtered to the Pending view).
     */
    public static function handle_set_approval(): void {

        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to do that.'), 403 );
        }

        $page_slug = sanitize_key((string) wp_unslash($_POST['page'] ?? 'adoration_scheduler_people'));
        $redirect  = self::base_people_url($page_slug, $_POST);

        $pid    = (int) wp_unslash($_POST['person_id'] ?? 0);
        $status = sanitize_key((string) wp_unslash($_POST['approval_status'] ?? ''));

        if ($pid <= 0 || ! in_array($status, [
            PersonsRepository::STATUS_APPROVED,
            PersonsRepository::STATUS_REJECTED,
            PersonsRepository::STATUS_PENDING,
        ], true)) {
            $u = self::with_toast($redirect, __('Invalid request.', 'adoration-scheduler'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        check_admin_referer('adoration_set_person_approval_' . $pid);

        $repo = new PersonsRepository();

        // ✅ Capture prior status so we only email on an actual transition
        // into 'approved' — never on a no-op re-save of someone already
        // approved (e.g. clicking Approve twice, or a stale page reload).
        $person_before = $repo->find($pid);
        $prior_status  = $person_before ? $repo->approval_status_of($person_before) : null;

        $ok = $repo->set_approval_status($pid, $status);

        if (!$ok) {
            $u = self::with_toast($redirect, __('Could not update this person.', 'adoration-scheduler'), 'error', true);
            wp_safe_redirect($u);
            exit;
        }

        if ($status === PersonsRepository::STATUS_APPROVED && $prior_status !== PersonsRepository::STATUS_APPROVED) {
            AccessRequestHandler::notify_person_approved($person_before);
        }

        $labels = [
            PersonsRepository::STATUS_APPROVED => __('Person approved.', 'adoration-scheduler'),
            PersonsRepository::STATUS_REJECTED => __('Person rejected.', 'adoration-scheduler'),
            PersonsRepository::STATUS_PENDING  => __('Person moved back to pending.', 'adoration-scheduler'),
        ];

        $u = self::with_toast($redirect, $labels[$status], 'success', false);
        wp_safe_redirect($u);
        exit;
    }
}
