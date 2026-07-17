<?php
namespace AdorationScheduler\Domain\Services;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Utils\XlsxWriter;
use AdorationScheduler\Utils\XlsxReader;

/**
 * Bulk CSV/XLSX export and import for the People roster.
 *
 * Export streams every person as either a .csv (mirrors EmailLogPage's
 * export pattern) or .xlsx (via the hand-rolled XlsxWriter — no bundled
 * library). Import is a two-step upload -> preview -> confirm flow (this
 * plugin has no prior "upload then preview" precedent to mirror, so the
 * shape here is new): the uploaded file is parsed and validated
 * immediately, the validated rows are stashed in a short-lived transient
 * keyed by a random token, and PeopleImportExportPage renders a preview
 * table from that token before a second POST actually commits anything.
 * Nothing is written to the persons table until the commit step, and
 * every row goes through PersonsRepository::upsert_by_email() — the same
 * method the public signup forms already use — so import behaves exactly
 * like "this person filled out the signup form themselves" (new email =>
 * new person, approved by the column's own DB default; matching email
 * with matching name => fills in blanks; matching email with a DIFFERENT
 * name => flagged as a conflict and skipped, never silently overwritten).
 */
class PeopleImportExportService
{
    public const ACTION_EXPORT_CSV   = 'adoration_people_export_csv';
    public const ACTION_EXPORT_XLSX  = 'adoration_people_export_xlsx';
    public const ACTION_IMPORT_START = 'adoration_people_import_start';
    public const ACTION_IMPORT_COMMIT = 'adoration_people_import_commit';
    public const ACTION_IMPORT_CANCEL = 'adoration_people_import_cancel';

    private const TRANSIENT_PREFIX = 'as_people_import_';
    private const TRANSIENT_TTL    = 900; // 15 minutes

    /** Header names an import file is expected to have (case-insensitive). */
    private const IMPORT_HEADERS = ['title', 'first name', 'last name', 'email', 'phone', 'parish'];

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION_EXPORT_CSV,   [__CLASS__, 'handle_export_csv']);
        add_action('admin_post_' . self::ACTION_EXPORT_XLSX,  [__CLASS__, 'handle_export_xlsx']);
        add_action('admin_post_' . self::ACTION_IMPORT_START, [__CLASS__, 'handle_import_start']);
        add_action('admin_post_' . self::ACTION_IMPORT_COMMIT, [__CLASS__, 'handle_import_commit']);
        add_action('admin_post_' . self::ACTION_IMPORT_CANCEL, [__CLASS__, 'handle_import_cancel']);
    }

    private static function require_capability(): void
    {
        if (!current_user_can('adoration_manage_people') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'adoration-scheduler'), 403);
        }
    }

    private static function redirect_with_toast(string $msg, string $type = 'success', array $extra_args = []): void
    {
        $url = admin_url('admin.php?page=adoration_scheduler_people_import_export');
        $url = remove_query_arg(['as_toast', 'as_toast_type', 'preview_token'], $url);
        $url = add_query_arg(array_merge([
            'as_toast'      => rawurlencode($msg),
            'as_toast_type' => $type,
        ], $extra_args), $url);

        wp_safe_redirect($url);
        exit;
    }

    // ---------------------------------------------------------------
    // EXPORT
    // ---------------------------------------------------------------

    private static function export_rows(): array
    {
        $repo = new PersonsRepository();
        $people = $repo->list_all_people_with_stats(1000000, 0, '', '');

        $header = ['Title', 'First Name', 'Last Name', 'Email', 'Phone', 'Parish', 'Approval Status', 'Substitute Opt-In', 'Created At'];
        $rows = [$header];

        foreach ($people as $p) {
            $rows[] = [
                (string)($p['title'] ?? ''),
                (string)($p['first_name'] ?? ''),
                (string)($p['last_name'] ?? ''),
                (string)($p['email'] ?? ''),
                (string)($p['phone'] ?? ''),
                (string)($p['parish'] ?? ''),
                (string)($p['approval_status'] ?? ''),
                !empty($p['substitute_opt_in']) ? 'Yes' : 'No',
                (string)($p['created_at'] ?? ''),
            ];
        }

        return $rows;
    }

    public static function handle_export_csv(): void
    {
        self::require_capability();
        check_admin_referer('adoration_people_export');

        $rows = self::export_rows();
        $filename = 'adoration-people-' . date('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public static function handle_export_xlsx(): void
    {
        self::require_capability();
        check_admin_referer('adoration_people_export');

        if (!XlsxWriter::is_available()) {
            self::redirect_with_toast('XLSX export needs the PHP zip extension, which isn\'t available on this server. Try CSV export instead.', 'error');
        }

        $rows = self::export_rows();
        $writer = new XlsxWriter();
        foreach ($rows as $row) {
            $writer->add_row($row);
        }

        $filename = 'adoration-people-' . date('Y-m-d-His') . '.xlsx';
        $writer->output($filename);
    }

    // ---------------------------------------------------------------
    // IMPORT: step 1 — upload, parse, validate, stash for preview
    // ---------------------------------------------------------------

    public static function handle_import_start(): void
    {
        self::require_capability();
        check_admin_referer('adoration_people_import_start');

        if (empty($_FILES['import_file']) || !is_array($_FILES['import_file']) || (int)($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            self::redirect_with_toast('Please choose a .csv or .xlsx file to import.', 'error');
        }

        $orig_name = (string)($_FILES['import_file']['name'] ?? '');
        $ext = strtolower((string) pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            self::redirect_with_toast('That file isn\'t a .csv or .xlsx file.', 'error');
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $overrides = [
            'test_form' => false,
            'mimes' => [
                'csv'  => 'text/csv',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ];

        $moved = wp_handle_upload($_FILES['import_file'], $overrides);

        if (!is_array($moved) || !empty($moved['error'])) {
            self::redirect_with_toast('Could not read that file: ' . (string)($moved['error'] ?? 'unknown error'), 'error');
        }

        $tmp_path = (string) $moved['file'];

        try {
            $sheet_rows = ($ext === 'xlsx')
                ? XlsxReader::read_first_sheet($tmp_path)
                : self::read_csv_rows($tmp_path);
        } catch (\Throwable $e) {
            @unlink($tmp_path);
            self::redirect_with_toast('Could not read that file: ' . $e->getMessage(), 'error');
            return;
        }

        // Never leave an uploaded roster file (names/emails/phones) sitting
        // in the public uploads directory longer than it takes to parse it.
        @unlink($tmp_path);

        if (empty($sheet_rows)) {
            self::redirect_with_toast('That file appears to be empty.', 'error');
        }

        $result = self::validate_rows($sheet_rows);
        if ($result === null) {
            $expected = 'Title, First Name, Last Name, Email, Phone, Parish';
            self::redirect_with_toast('That file is missing required columns. It needs at least: First Name, Last Name, Email (headers: ' . $expected . ').', 'error');
            return;
        }

        if (empty($result)) {
            self::redirect_with_toast('That file has a header row but no data rows.', 'error');
        }

        $token = wp_generate_password(24, false, false);
        set_transient(self::TRANSIENT_PREFIX . $token, [
            'user_id' => get_current_user_id(),
            'rows'    => $result,
        ], self::TRANSIENT_TTL);

        self::redirect_with_toast('File parsed — review the rows below before confirming.', 'info', ['preview_token' => $token]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function read_csv_rows(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('the file could not be opened.');
        }

        // Skip a UTF-8 BOM if present so "Title" doesn't become "\xEF\xBB\xBFTitle".
        $bom = fread($fh, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($fh);
        }

        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = array_map(static fn($v) => (string)$v, $row);
        }
        fclose($fh);

        return $rows;
    }

    /**
     * Maps headers, validates every data row, and classifies it against
     * the current persons table. Returns null if required headers are
     * missing, [] if there are zero data rows, otherwise the row list.
     *
     * @param array<int, array<int, string>> $sheet_rows
     * @return array<int, array<string, mixed>>|null
     */
    private static function validate_rows(array $sheet_rows): ?array
    {
        $header_row = array_shift($sheet_rows);
        if ($header_row === null) return [];

        $col_index = [];
        foreach ($header_row as $i => $label) {
            $col_index[strtolower(trim((string)$label))] = $i;
        }

        foreach (['first name', 'last name', 'email'] as $required) {
            if (!isset($col_index[$required])) {
                return null;
            }
        }

        $get = static function (array $row, string $key) use ($col_index): string {
            $i = $col_index[$key] ?? null;
            if ($i === null) return '';
            return trim((string)($row[$i] ?? ''));
        };

        $persons_repo = new PersonsRepository();
        $out = [];
        $line = 1; // header was line 1

        foreach ($sheet_rows as $row) {
            $line++;

            $title  = $get($row, 'title');
            $first  = $get($row, 'first name');
            $last   = $get($row, 'last name');
            $email  = $get($row, 'email');
            $phone  = $get($row, 'phone');
            $parish = $get($row, 'parish');

            if ($title === '' && $first === '' && $last === '' && $email === '' && $phone === '' && $parish === '') {
                continue; // skip fully blank rows
            }

            $entry = [
                'line'    => $line,
                'title'   => $title,
                'first'   => $first,
                'last'    => $last,
                'email'   => $email,
                'phone'   => '',
                'parish'  => $parish,
                'status'  => 'new',
                'message' => '',
            ];

            if ($first === '') {
                $entry['status'] = 'error';
                $entry['message'] = 'First name is required.';
                $out[] = $entry;
                continue;
            }
            if ($last === '') {
                $entry['status'] = 'error';
                $entry['message'] = 'Last name is required.';
                $out[] = $entry;
                continue;
            }
            if ($email === '' || !is_email($email)) {
                $entry['status'] = 'error';
                $entry['message'] = 'A valid email address is required.';
                $out[] = $entry;
                continue;
            }

            if ($phone !== '') {
                $normalized_phone = self::normalize_phone_us($phone);
                if ($normalized_phone === null) {
                    $entry['status'] = 'error';
                    $entry['message'] = 'Phone number isn\'t a valid 10-digit US number.';
                    $out[] = $entry;
                    continue;
                }
                $entry['phone'] = $normalized_phone;
            }

            $email_norm = strtolower($email);
            $existing = $persons_repo->find_by_email($email_norm);

            if ($existing) {
                $ex_first = trim((string)($existing['first_name'] ?? ''));
                $ex_last  = trim((string)($existing['last_name'] ?? ''));

                $first_conflict = ($ex_first !== '' && strcasecmp($ex_first, $first) !== 0);
                $last_conflict  = ($ex_last !== '' && strcasecmp($ex_last, $last) !== 0);

                if ($first_conflict || $last_conflict) {
                    $display = $persons_repo->display_name_for_person($existing);
                    $entry['status'] = 'conflict';
                    $entry['message'] = 'That email already belongs to ' . ($display !== '' ? $display : 'someone else') . ' — skipped.';
                } else {
                    $entry['status'] = 'update';
                    $entry['message'] = 'Matches an existing person — will fill in any blank fields.';
                }
            } else {
                $entry['status'] = 'new';
                $entry['message'] = 'Will be added as a new person.';
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Same normalization rules as AddPersonPage's signup/contact-info
     * forms (10-digit US number, optional leading country code 1).
     */
    private static function normalize_phone_us(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) return null;

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) return null;

        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }

    // ---------------------------------------------------------------
    // IMPORT: step 2 — commit or cancel a previously-parsed preview
    // ---------------------------------------------------------------

    public static function handle_import_commit(): void
    {
        self::require_capability();

        $token = isset($_POST['preview_token']) ? sanitize_text_field(wp_unslash((string)$_POST['preview_token'])) : '';
        if ($token === '') {
            self::redirect_with_toast('That import session has expired. Please upload the file again.', 'error');
        }

        check_admin_referer('adoration_people_import_commit_' . $token);

        $data = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($data) || empty($data['rows'])) {
            self::redirect_with_toast('That import session has expired. Please upload the file again.', 'error');
        }

        delete_transient(self::TRANSIENT_PREFIX . $token);

        $repo = new PersonsRepository();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ((array)$data['rows'] as $row) {
            $status = (string)($row['status'] ?? '');
            if ($status !== 'new' && $status !== 'update') {
                $skipped++;
                continue;
            }

            $person_id = $repo->upsert_by_email([
                'title'      => (string)($row['title'] ?? ''),
                'first_name' => (string)($row['first'] ?? ''),
                'last_name'  => (string)($row['last'] ?? ''),
                'email'      => (string)($row['email'] ?? ''),
                'phone'      => (string)($row['phone'] ?? ''),
                'parish'     => (string)($row['parish'] ?? ''),
            ]);

            if ($person_id <= 0) {
                $skipped++;
                continue;
            }

            if ($status === 'new') $created++;
            else $updated++;
        }

        $msg = sprintf(
            'Import complete: %d added, %d updated, %d skipped.',
            $created,
            $updated,
            $skipped
        );

        self::redirect_with_toast($msg, 'success');
    }

    public static function handle_import_cancel(): void
    {
        self::require_capability();

        $token = isset($_POST['preview_token']) ? sanitize_text_field(wp_unslash((string)$_POST['preview_token'])) : '';
        if ($token !== '') {
            check_admin_referer('adoration_people_import_commit_' . $token);
            delete_transient(self::TRANSIENT_PREFIX . $token);
        }

        self::redirect_with_toast('Import cancelled — nothing was changed.', 'info');
    }

    /**
     * Reads back a previously-stashed preview (used by the admin page to
     * render the review table). Returns null if the token is missing,
     * expired, or belongs to a different user than the one viewing it.
     */
    public static function get_preview(string $token): ?array
    {
        if ($token === '') return null;

        $data = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($data) || empty($data['rows'])) return null;

        if ((int)($data['user_id'] ?? 0) !== get_current_user_id()) return null;

        return (array) $data['rows'];
    }
}
