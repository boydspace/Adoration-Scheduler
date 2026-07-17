<?php
namespace AdorationScheduler\Domain\Services;

use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) exit;

class PersonCreationService {

    public static function register(): void {
        add_action('admin_post_adoration_create_person', [__CLASS__, 'handle_create_person']);
    }

    public static function handle_create_person(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        check_admin_referer('adoration_create_person');

        $first = sanitize_text_field( (string) wp_unslash($_POST['first_name'] ?? '') );
        $last  = sanitize_text_field( (string) wp_unslash($_POST['last_name'] ?? '') );
        $email = sanitize_email( (string) wp_unslash($_POST['email'] ?? '') );
        $phone = sanitize_text_field( (string) wp_unslash($_POST['phone'] ?? '') );
        $title  = sanitize_text_field( (string) wp_unslash($_POST['title'] ?? '') );
        $parish = sanitize_text_field( (string) wp_unslash($_POST['parish'] ?? '') );

        $preserve = self::preserved_args_from_post();

        if (trim($first) === '') {
            self::redirect_back_to_add($preserve, 'first_required');
        }

        // sanitize_email() may yield '' for invalid email; also handle user typed something.
        $email_raw = (string) wp_unslash($_POST['email'] ?? '');
        if (trim($email_raw) !== '' && $email === '') {
            self::redirect_back_to_add($preserve, 'bad_email');
        }

        $phone_norm = null;
        if (trim($phone) !== '') {
            $phone_norm = self::normalize_phone_us($phone);
            if ($phone_norm === null) {
                self::redirect_back_to_add($preserve, 'bad_phone');
            }
        }
        $phone = ($phone_norm !== null) ? $phone_norm : '';

        $repo = new PersonsRepository();

        // Preflight duplicate check (so we show the right message)
        if ($email !== '' && method_exists($repo, 'find_by_email')) {
            try {
                $existing = $repo->find_by_email($email);
                if (!empty($existing)) {
                    self::redirect_back_to_add($preserve, 'email_in_use');
                }
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] PersonCreationService find_by_email failed: ' . $e->getMessage());
                // Continue and let upsert/insert decide.
            }
        }

        $data = [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'phone'      => $phone,
            'title'      => $title,
            'parish'     => $parish,
        ];

        // 1) Preferred: repo upsert_by_email
        $created_id = self::try_repo_upsert_by_email($repo, $data);

        // 2) Fallback: direct DB insert (also handles duplicate error mapping)
        if (!$created_id) {
            $created_id = self::fallback_db_insert_with_reflection($repo, $data, $preserve);
        }

        if (!$created_id) {
            error_log('[AdorationScheduler] PersonCreationService could not create person. Repo public methods: ' . implode(', ', get_class_methods($repo)));
            self::redirect_back_to_add($preserve, 'repo_missing');
        }

        $name = trim($first . ' ' . $last);
        if ($name === '') $name = __('Person', 'adoration-scheduler');

        // ✅ Toast on success (no person_created=1, to avoid double notices)
        $msg = sanitize_text_field(wp_strip_all_tags(sprintf(__('%s created.', 'adoration-scheduler'), $name)));
        if (strlen($msg) > 300) $msg = substr($msg, 0, 300);

        $redirect = add_query_arg(
            array_merge(
                [
                    'page'          => 'adoration_scheduler_people',
                    'as_toast'      => $msg,          // DO NOT rawurlencode; add_query_arg() encodes.
                    'as_toast_type' => 'success',
                ],
                $preserve
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private static function try_repo_upsert_by_email(object $repo, array $data): int {
        if (!method_exists($repo, 'upsert_by_email')) return 0;

        $email = (string)($data['email'] ?? '');
        if ($email === '') return 0;

        try {
            $rm = new \ReflectionMethod($repo, 'upsert_by_email');
            $n  = $rm->getNumberOfParameters();

            if ($n === 1) {
                $res = $repo->upsert_by_email($data);
                return self::coerce_idish_result($res);
            }

            if ($n >= 4) {
                $res = $repo->upsert_by_email(
                    (string)$data['email'],
                    (string)$data['first_name'],
                    (string)$data['last_name'],
                    (string)$data['phone']
                );
                return self::coerce_idish_result($res);
            }

            if ($n === 3) {
                $res = $repo->upsert_by_email(
                    (string)$data['email'],
                    (string)$data['first_name'],
                    (string)$data['last_name']
                );
                return self::coerce_idish_result($res);
            }

            if ($n === 2) {
                $res = $repo->upsert_by_email(
                    (string)$data['email'],
                    (string)$data['first_name']
                );
                return self::coerce_idish_result($res);
            }

            $res = $repo->upsert_by_email($data);
            return self::coerce_idish_result($res);

        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] PersonCreationService upsert_by_email failed: ' . $e->getMessage());
            return 0;
        }
    }

    private static function coerce_idish_result($res): int {
        if (is_int($res) && $res > 0) return $res;
        if (is_string($res) && ctype_digit($res) && (int)$res > 0) return (int)$res;
        if (is_array($res) && isset($res['id']) && (int)$res['id'] > 0) return (int)$res['id'];
        if ($res) return 1;
        return 0;
    }

    private static function fallback_db_insert_with_reflection(object $repo, array $data, array $preserve): int {
        global $wpdb;

        $table = self::discover_table_name($repo);
        if ($table === '') {
            error_log('[AdorationScheduler] PersonCreationService: could not discover persons table name from repository.');
            return 0;
        }

        if (strpos($table, '.') === false && strpos($table, $wpdb->prefix) !== 0 && strpos($table, 'wp_') !== 0) {
            $table = $wpdb->prefix . ltrim($table, '_');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert(
            $table,
            [
                'first_name' => (string)($data['first_name'] ?? ''),
                'last_name'  => (string)($data['last_name'] ?? ''),
                'email'      => (string)($data['email'] ?? ''),
                'phone'      => (string)($data['phone'] ?? ''),
                'title'      => (string)($data['title'] ?? ''),
                'parish'     => (string)($data['parish'] ?? ''),
            ],
            ['%s','%s','%s','%s','%s','%s']
        );

        if ($ok === false) {
            $err = (string)$wpdb->last_error;
            error_log('[AdorationScheduler] PersonCreationService DB insert failed into ' . $table . ': ' . $err);

            // Map duplicate email constraint to email_in_use
            if ($err !== '' && stripos($err, 'Duplicate entry') !== false && stripos($err, '.email') !== false) {
                self::redirect_back_to_add($preserve, 'email_in_use');
            }

            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    private static function discover_table_name(object $repo): string {
        $method_candidates = [
            'table_name','get_table_name','getTableName',
            'table','get_table','getTable',
        ];

        foreach ($method_candidates as $m) {
            if (!method_exists($repo, $m)) continue;
            try {
                $t = $repo->{$m}();
                if (is_string($t) && $t !== '') return $t;
            } catch (\Throwable $e) {}
        }

        $prop_candidates = ['table', 'table_name', 'persons_table', 'people_table'];

        try {
            $rc = new \ReflectionClass($repo);
            foreach ($prop_candidates as $p) {
                if (!$rc->hasProperty($p)) continue;
                $rp = $rc->getProperty($p);
                $rp->setAccessible(true);
                $val = $rp->getValue($repo);
                if (is_string($val) && $val !== '') return $val;
            }
        } catch (\Throwable $e) {}

        return '';
    }

    private static function redirect_back_to_add(array $preserve, string $code): void {
        // Keep the error code for the form UI, but also show a sticky toast.
        $msg = self::toast_message_for_error_code($code);
        if ($msg === '') {
            $msg = __('Could not create person.', 'adoration-scheduler');
        }

        $type = 'error';
        $msg  = sanitize_text_field(wp_strip_all_tags($msg));
        if (strlen($msg) > 300) $msg = substr($msg, 0, 300);

        $redirect = add_query_arg(
            array_merge(
                [
                    'page'               => 'adoration_scheduler_people_add',
                    'person_create_error'=> sanitize_key($code),

                    'as_toast'           => $msg,     // DO NOT rawurlencode; add_query_arg() encodes.
                    'as_toast_type'      => $type,
                    'as_toast_sticky'    => '1',
                ],
                $preserve
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private static function toast_message_for_error_code(string $code): string {
        $code = sanitize_key($code);

        switch ($code) {
            case 'first_required':
                return __('First name is required.', 'adoration-scheduler');
            case 'bad_email':
                return __('Please enter a valid email address.', 'adoration-scheduler');
            case 'bad_phone':
                return __('Please enter a valid US phone number.', 'adoration-scheduler');
            case 'email_in_use':
                return __('That email address is already in use.', 'adoration-scheduler');
            case 'repo_missing':
                return __('Could not create person (repository error).', 'adoration-scheduler');
            default:
                return '';
        }
    }

    private static function preserved_args_from_post(): array {
        $out = [];
        $keys = ['s','paged','orderby','order'];

        foreach ($keys as $k) {
            if (!isset($_POST[$k])) continue;
            if (is_array($_POST[$k])) continue;

            $v = wp_unslash($_POST[$k]);

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

    private static function normalize_phone_us(?string $raw): ?string {
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
}
