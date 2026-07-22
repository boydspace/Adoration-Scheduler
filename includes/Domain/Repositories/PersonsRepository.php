<?php
namespace AdorationScheduler\Domain\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class PersonsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'adoration_persons';
    }

    public function find(int $person_id): ?array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $person_id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }

    public function find_by_email(string $email): ?array {
        global $wpdb;

        $email = strtolower(trim($email));
        if ($email === '') return null;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE email = %s LIMIT 1",
            $email
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ? (array)$row : null;
    }

    /**
     * Create or update a person and return person_id.
     *
     * IMPORTANT SAFETY:
     * - Email is treated as the unique identity key.
     * - We will NEVER overwrite an existing person's name/phone from admin/public input,
     *   unless the incoming record matches existing (same first+last, case-insensitive),
     *   OR the existing field is empty (fill blanks).
     *
     * Behavior:
     * - If email exists:
     *    - If first+last match existing -> allow filling blanks and updating phone (if provided)
     *    - If first/last do NOT match -> return 0 (caller should show "email already belongs to X")
     * - If email does not exist:
     *    - Insert new person (requires first,last,email)
     *
     * Returns person_id on success, 0 on failure / mismatch.
     */
    public function upsert_by_email(array $data): int {
        global $wpdb;

        $first = sanitize_text_field($data['first_name'] ?? '');
        $last  = sanitize_text_field($data['last_name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');
        $title  = sanitize_text_field($data['title'] ?? '');
        $parish = sanitize_text_field($data['parish'] ?? '');

        if ($first === '' || $last === '' || $email === '') {
            return 0;
        }

        $email_norm = strtolower(trim($email));

        // Look up existing person by email
        $existing = $this->find_by_email($email_norm);

        if ($existing) {
            $person_id = (int)($existing['id'] ?? 0);
            if ($person_id <= 0) return 0;

            $ex_first = trim((string)($existing['first_name'] ?? ''));
            $ex_last  = trim((string)($existing['last_name'] ?? ''));

            // Compare names case-insensitively
            $match_first = (strcasecmp($ex_first, $first) === 0);
            $match_last  = (strcasecmp($ex_last,  $last)  === 0);

            // If email exists but name doesn't match, DO NOT overwrite.
            if (!$match_first || !$match_last) {
                return 0;
            }

            // Safe updates: only fill blanks; update phone if provided (and different)
            $update = [];

            if ($ex_first === '' && $first !== '') $update['first_name'] = $first;
            if ($ex_last  === '' && $last  !== '') $update['last_name']  = $last;

            $ex_phone = (string)($existing['phone'] ?? '');
            if ($phone !== '' && $phone !== $ex_phone) {
                $update['phone'] = $phone;
            }

            $ex_title = (string)($existing['title'] ?? '');
            if ($title !== '' && $title !== $ex_title) {
                $update['title'] = $title;
            }

            $ex_parish = (string)($existing['parish'] ?? '');
            if ($parish !== '' && $parish !== $ex_parish) {
                $update['parish'] = $parish;
            }

            if (!empty($update)) {
                $formats = [];
                foreach ($update as $k => $v) {
                    $formats[] = '%s';
                }

                $res = $wpdb->update(
                    $this->table,
                    $update,
                    ['id' => $person_id],
                    $formats,
                    ['%d']
                );

                // If update fails, still return person_id (record exists)
                if ($res === false) {
                    return $person_id;
                }
            }

            return $person_id;
        }

        // Insert new person.
        // Email should be UNIQUE in schema; if a race happens, we re-select.
        $ok = $wpdb->insert(
            $this->table,
            [
                'first_name' => $first,
                'last_name'  => ($last !== '' ? $last : null),
                'email'      => $email_norm,
                'phone'      => ($phone !== '' ? $phone : null),
                'title'      => ($title !== '' ? $title : null),
                'parish'     => ($parish !== '' ? $parish : null),
            ],
            ['%s','%s','%s','%s','%s','%s']
        );

        if ($ok) {
            return (int)$wpdb->insert_id;
        }

        // If insert failed due to duplicate email (race), try to load it.
        $maybe = $this->find_by_email($email_norm);
        return $maybe ? (int)$maybe['id'] : 0;
    }

    /**
     * LEGACY: Count distinct people that have at least one signup.
     */
    public function count_with_signups(): int {
        global $wpdb;
        $signups = $wpdb->prefix . 'adoration_signups';

        $sql = "SELECT COUNT(DISTINCT p.id)
                FROM {$this->table} p
                INNER JOIN {$signups} s ON s.person_id = p.id";

        return (int) $wpdb->get_var($sql);
    }

    /**
     * LEGACY: List people that have at least one signup, with signup_count and last_signup_at.
     */
    public function list_with_signups(int $limit = 50, int $offset = 0, string $search = ''): array {
        global $wpdb;
        $signups = $wpdb->prefix . 'adoration_signups';

        $where = "1=1";
        $params = [];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (
                p.first_name LIKE %s OR
                p.last_name LIKE %s OR
                p.email LIKE %s OR
                p.phone LIKE %s
            )";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT
                    p.*,
                    COUNT(s.id) AS signup_count,
                    MAX(s.created_at) AS last_signup_at
                FROM {$this->table} p
                INNER JOIN {$signups} s ON s.person_id = p.id
                WHERE {$where}
                GROUP BY p.id
                ORDER BY last_signup_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, ...$params);
        return (array) $wpdb->get_results($prepared, ARRAY_A);
    }

    /**
     * NEW: Count ALL people (search-aware). Use this for the People page.
     */
    public function count_all_people(string $search = '', string $approval_status = ''): int {
        global $wpdb;

        $where = "1=1";
        $params = [];

        $approval_status = sanitize_key($approval_status);
        if ($approval_status !== '') {
            $where .= " AND approval_status = %s";
            $params[] = $approval_status;
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (
                first_name LIKE %s OR
                last_name LIKE %s OR
                email LIKE %s OR
                phone LIKE %s
            )";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
        $prepared = !empty($params) ? $wpdb->prepare($sql, ...$params) : $sql;

        return (int) $wpdb->get_var($prepared);
    }

    /**
     * NEW: List ALL people (even zero signups) with stats.
     * ✅ Optional $approval_status filter (used by the People list's
     * Pending/Approved/Rejected view tabs).
     */
    public function list_all_people_with_stats(int $limit = 50, int $offset = 0, string $search = '', string $approval_status = ''): array {
        global $wpdb;

        $signups = $wpdb->prefix . 'adoration_signups';

        $where = "1=1";
        $params = [];

        $approval_status = sanitize_key($approval_status);
        if ($approval_status !== '') {
            $where .= " AND p.approval_status = %s";
            $params[] = $approval_status;
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (
                p.first_name LIKE %s OR
                p.last_name LIKE %s OR
                p.email LIKE %s OR
                p.phone LIKE %s
            )";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT
                    p.*,
                    COUNT(s.id) AS signup_count,
                    MAX(s.created_at) AS last_signup_at
                FROM {$this->table} p
                LEFT JOIN {$signups} s ON s.person_id = p.id
                WHERE {$where}
                GROUP BY p.id
                ORDER BY p.last_name ASC, p.first_name ASC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, ...$params);
        return (array) $wpdb->get_results($prepared, ARRAY_A);
    }

    /**
     * Privacy-scoped person search for the public "ask a specific person"
     * swap-request picker (Direct-to-person swap requests). Unlike
     * list_all_people_with_stats() (admin-facing, returns email/phone/signup
     * counts), this ONLY returns id + display name fields — no contact
     * details — since it's callable by any signed-in parishioner, not just
     * admins. Only matches approved persons, excludes the requester
     * themselves, and requires a minimum query length (enforced by caller)
     * to avoid cheap directory enumeration.
     */
    public function search_by_name_for_target(string $query, int $exclude_person_id, int $limit = 8): array {
        global $wpdb;

        $query = trim($query);
        if ($query === '') return [];

        $exclude_person_id = (int)$exclude_person_id;
        $limit = max(1, min(25, (int)$limit));

        $like = '%' . $wpdb->esc_like($query) . '%';

        $where = "p.approval_status = 'approved' AND (p.first_name LIKE %s OR p.last_name LIKE %s)";
        $params = [$like, $like];

        if ($exclude_person_id > 0) {
            $where .= " AND p.id != %d";
            $params[] = $exclude_person_id;
        }

        $params[] = $limit;

        $sql = "SELECT p.id, p.first_name, p.last_name, p.title, p.parish
                FROM {$this->table} p
                WHERE {$where}
                ORDER BY p.last_name ASC, p.first_name ASC
                LIMIT %d";

        $prepared = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Check if email exists on a DIFFERENT person record.
     * Used when admin edits a person.
     */
    public function exists_email_except_id(string $email, int $except_id): bool {
        global $wpdb;

        $email = strtolower(trim($email));
        if ($email === '') return false;

        $sql = $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE email = %s AND id != %d LIMIT 1",
            $email,
            $except_id
        );

        $row = $wpdb->get_var($sql);
        return !empty($row);
    }

    /**
     * Admin edit: update person fields (explicit overwrite allowed here).
     * - first_name required
     * - last/email/phone may be blank (stored as NULL)
     */
    public function update_person(int $person_id, array $data): bool {
        global $wpdb;

        if ($person_id <= 0) return false;

        $first = sanitize_text_field($data['first_name'] ?? '');
        $last  = sanitize_text_field($data['last_name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');
        $title  = sanitize_text_field($data['title'] ?? '');
        $parish = sanitize_text_field($data['parish'] ?? '');

        if ($first === '') return false;

        // Normalize email if provided
        $email_norm = $email !== '' ? strtolower(trim($email)) : '';

        $update = [
            'first_name' => $first,
            'last_name'  => ($last !== '' ? $last : null),
            'email'      => ($email_norm !== '' ? $email_norm : null),
            'phone'      => ($phone !== '' ? $phone : null),
            'title'      => ($title !== '' ? $title : null),
            'parish'     => ($parish !== '' ? $parish : null),
        ];

        $res = $wpdb->update(
            $this->table,
            $update,
            ['id' => $person_id],
            ['%s','%s','%s','%s','%s','%s'],
            ['%d']
        );

        return ($res !== false);
    }

    // -------------------------------------------------------------------------
    // APPROVAL / PRIVACY GATE
    // -------------------------------------------------------------------------

    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Normalize whatever's in the approval_status column (existing rows
     * before this column existed will read as NULL/'' via COALESCE-less
     * SELECT * — treat that as approved, matching the column's own default).
     */
    public function approval_status_of(array $person): string {
        $status = trim((string)($person['approval_status'] ?? ''));
        return $status !== '' ? $status : self::STATUS_APPROVED;
    }

    public function set_approval_status(int $person_id, string $status): bool {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return false;

        $status = sanitize_key($status);
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_PENDING, self::STATUS_REJECTED], true)) {
            return false;
        }

        $res = $wpdb->update(
            $this->table,
            ['approval_status' => $status],
            ['id' => $person_id],
            ['%s'],
            ['%d']
        );

        return ($res !== false);
    }

    public function count_by_approval_status(string $status, string $search = ''): int {
        global $wpdb;

        $status = sanitize_key($status);
        $where  = "approval_status = %s";
        $params = [$status];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$where}", ...$params);
        return (int) $wpdb->get_var($sql);
    }

    public function list_by_approval_status(string $status, int $limit = 50, int $offset = 0, string $search = ''): array {
        global $wpdb;

        $status = sanitize_key($status);
        $where  = "approval_status = %s";
        $params = [$status];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$params
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Create a brand-new person explicitly in 'pending' status (Request
     * Access flow). Unlike upsert_by_email(), this NEVER updates an existing
     * record — the caller (AccessRequestHandler) is expected to have already
     * checked find_by_email() first and handled the "already exists" cases
     * (already approved -> tell them to sign in; already pending -> tell
     * them it's still pending; rejected -> allow resubmission via
     * set_approval_status() back to pending instead of calling this).
     *
     * Returns the new person_id, or 0 on failure.
     */
    public function create_pending_person(array $data): int {
        global $wpdb;

        $first = sanitize_text_field($data['first_name'] ?? '');
        $last  = sanitize_text_field($data['last_name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');
        $title = sanitize_text_field($data['title'] ?? '');

        if ($first === '' || $email === '') {
            return 0;
        }

        $email_norm = strtolower(trim($email));

        $ok = $wpdb->insert(
            $this->table,
            [
                'first_name'      => $first,
                'last_name'       => ($last !== '' ? $last : null),
                'email'           => $email_norm,
                'phone'           => ($phone !== '' ? $phone : null),
                'title'           => ($title !== '' ? $title : null),
                'approval_status' => self::STATUS_PENDING,
            ],
            ['%s','%s','%s','%s','%s','%s']
        );

        if ($ok) {
            return (int)$wpdb->insert_id;
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // HYBRID AUTH (Phase 2): optional password on top of the permanent
    // magic-link option. A person has no password until they explicitly set
    // one from their dashboard; forgetting it just means falling back to the
    // magic link, which is already the recovery mechanism.
    // -------------------------------------------------------------------------

    public function has_password(array $person): bool {
        return trim((string)($person['password_hash'] ?? '')) !== '';
    }

    /**
     * Hash + store a new password for a person, using WordPress's own
     * password hashing (same primitives wp_check_password() understands),
     * so we don't roll our own crypto.
     */
    public function set_password(int $person_id, string $plain_password): bool {
        global $wpdb;

        if ($person_id <= 0 || trim($plain_password) === '') {
            return false;
        }

        $hash = wp_hash_password($plain_password);
        $now  = gmdate('Y-m-d H:i:s');

        $res = $wpdb->update(
            $this->table,
            [
                'password_hash'   => $hash,
                'password_set_at' => $now,
            ],
            ['id' => $person_id],
            ['%s', '%s'],
            ['%d']
        );

        return $res !== false;
    }

    /**
     * Remove a person's password, reverting them to magic-link-only.
     */
    public function clear_password(int $person_id): bool {
        global $wpdb;

        if ($person_id <= 0) return false;

        $res = $wpdb->update(
            $this->table,
            [
                'password_hash'   => null,
                'password_set_at' => null,
            ],
            ['id' => $person_id],
            ['%s', '%s'],
            ['%d']
        );

        return $res !== false;
    }

    /**
     * Verify a plaintext password against a person row's stored hash.
     * Returns false (never throws) if the person has no password set.
     */
    public function verify_password(array $person, string $plain_password): bool {
        $hash = trim((string)($person['password_hash'] ?? ''));
        if ($hash === '' || trim($plain_password) === '') {
            return false;
        }

        return (bool) wp_check_password($plain_password, $hash);
    }

    // -------------------------------------------------------------------------
    // SELF-SERVICE ACCOUNT DELETION
    // -------------------------------------------------------------------------

    /**
     * Anonymize a person's own record on self-service "Delete My Account".
     *
     * Deliberately NOT a hard delete: this row's id is still referenced by
     * adoration_signups (historical coverage counts) and possibly
     * adoration_standing_commitments, so removing the row outright would
     * either orphan that history or require a much larger cascade the
     * caller is responsible for running FIRST (cancelling future signups,
     * ending standing commitments, clearing any replacement_target
     * pointers aimed at this person — see AccountDeletionService, which
     * orchestrates all of that before calling this method).
     *
     * The replacement email is unique (satisfies the `email` UNIQUE key)
     * so the person's real email address becomes free for them (or anyone
     * else) to sign up with again as a brand-new person in the future.
     */
    public function anonymize_person(int $person_id): bool {
        global $wpdb;

        if ($person_id <= 0) return false;

        $placeholder_email = 'removed-' . $person_id . '-' . substr(md5(uniqid((string)$person_id, true)), 0, 12) . '@removed.invalid';

        $res = $wpdb->update(
            $this->table,
            [
                'first_name'        => 'Removed',
                'last_name'         => 'Adorer',
                'title'             => null,
                'parish'            => null,
                'email'             => $placeholder_email,
                'phone'             => null,
                'notes'             => null,
                'password_hash'     => null,
                'password_set_at'   => null,
                'substitute_opt_in' => 0,
                'email_reminder_opt_in' => 1,
                'sms_reminder_opt_in'   => 0,
                'reminder_lead_hours'   => 24,
                'calendar_token'    => null,
                'anonymized_at'     => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $person_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s'],
            ['%d']
        );

        return $res !== false;
    }

    /**
     * True if this person has already gone through self-service deletion.
     */
    public function is_anonymized(array $person): bool {
        return !empty($person['anonymized_at']);
    }

    // -------------------------------------------------------------------------
    // PERSONAL ICAL FEED TOKEN
    // -------------------------------------------------------------------------

    /**
     * Return this person's calendar subscribe token, generating and
     * persisting one on first use. Unlike password/session credentials,
     * this is deliberately stored retrievable (not hashed) so it can be
     * re-displayed on the dashboard any time — see the schema comment in
     * Installer.php::ensure_persons_columns() for why.
     */
    public function get_or_create_calendar_token(int $person_id): ?string {
        if ($person_id <= 0) return null;

        $person = $this->find($person_id);
        if (!$person) return null;

        $existing = trim((string)($person['calendar_token'] ?? ''));
        if ($existing !== '') return $existing;

        return $this->regenerate_calendar_token($person_id);
    }

    /**
     * Issue a brand-new token, replacing any previous one (e.g. if a
     * person suspects their subscribe URL leaked). Old calendar-app
     * subscriptions using the previous token will stop working.
     */
    public function regenerate_calendar_token(int $person_id): ?string {
        global $wpdb;

        if ($person_id <= 0) return null;

        // 32 raw bytes -> 64 hex chars, matches the CHAR(64) column.
        $token = bin2hex(random_bytes(32));

        $res = $wpdb->update(
            $this->table,
            ['calendar_token' => $token],
            ['id' => $person_id],
            ['%s'],
            ['%d']
        );

        return ($res !== false) ? $token : null;
    }

    /**
     * Look up a person by their raw calendar token (as it appears in the
     * subscribe URL). Returns null on no match — callers should treat
     * that identically to "feed not found" without leaking why.
     */
    public function find_by_calendar_token(string $token): ?array {
        global $wpdb;

        $token = trim($token);
        if ($token === '') return null;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE calendar_token = %s LIMIT 1",
                $token
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // REPLACEMENT REQUESTS (Phase 3): substitute opt-in
    // -------------------------------------------------------------------------

    public function is_substitute_opt_in(array $person): bool {
        return !empty($person['substitute_opt_in']);
    }

    public function set_substitute_opt_in(int $person_id, bool $opt_in): bool {
        global $wpdb;

        if ($person_id <= 0) return false;

        $res = $wpdb->update(
            $this->table,
            ['substitute_opt_in' => $opt_in ? 1 : 0],
            ['id' => $person_id],
            ['%d'],
            ['%d']
        );

        return $res !== false;
    }

    // -------------------------------------------------------------------------
    // REMINDER CHANNEL PREFERENCES (2026-07-21): per-person opt-in for the
    // 24h reminder, separate from the admin-level "is SMS configured at
    // all" switch on SmsSettingsPage. See ReminderPreferencesShortcode.
    // -------------------------------------------------------------------------

    /**
     * Defaults true (not false-if-missing like is_sms_reminder_opt_in())
     * because the column default is 1 — every existing person already
     * always got the email reminder, and this must never silently become
     * an opt-out just because the key happened to be absent.
     */
    public function is_email_reminder_opt_in(array $person): bool {
        return !isset($person['email_reminder_opt_in']) || (bool)$person['email_reminder_opt_in'];
    }

    public function is_sms_reminder_opt_in(array $person): bool {
        return !empty($person['sms_reminder_opt_in']);
    }

    /**
     * How many hours before their slot this person wants to be reminded.
     * A fixed "always 24h before" reproduces the same clock time every
     * day — bad for very early/late slots — so this is configurable per
     * person; see ReminderScheduler::compute_remind_timestamp(). Clamped
     * to [1, 168] (1 hour to 1 week) and defaults to 24 for missing/zero/
     * invalid values, same defensive posture as is_email_reminder_opt_in().
     */
    public function get_reminder_lead_hours(array $person): int {
        $hours = isset($person['reminder_lead_hours']) ? (int)$person['reminder_lead_hours'] : 24;
        if ($hours < 1)   return 24;
        if ($hours > 168) return 168;
        return $hours;
    }

    public function set_reminder_preferences(int $person_id, bool $email_opt_in, bool $sms_opt_in, int $lead_hours): bool {
        global $wpdb;

        if ($person_id <= 0) return false;

        $lead_hours = max(1, min(168, $lead_hours));

        $res = $wpdb->update(
            $this->table,
            [
                'email_reminder_opt_in' => $email_opt_in ? 1 : 0,
                'sms_reminder_opt_in'   => $sms_opt_in ? 1 : 0,
                'reminder_lead_hours'   => $lead_hours,
            ],
            ['id' => $person_id],
            ['%d', '%d', '%d'],
            ['%d']
        );

        return $res !== false;
    }

    /**
     * Emails of everyone who's opted in to be contacted about open
     * replacement requests. Used by ReplacementRequestService's notification.
     */
    public function list_opted_in_substitute_emails(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $emails = (array) $wpdb->get_col(
            "SELECT email FROM {$this->table} WHERE substitute_opt_in = 1 AND email != ''"
        );

        return array_values(array_filter(array_map('sanitize_email', $emails)));
    }

    public function display_name_for_person(array $p): string {
        $first = trim((string)($p['first_name'] ?? ''));
        $last  = trim((string)($p['last_name'] ?? ''));

        if ($first === '' && $last === '') return '—';
        if ($first === '') return $last;
        if ($last === '') return $first;

        return $first . ' ' . strtoupper(substr($last, 0, 1)) . '.';
    }

    /**
     * Full display name (no last-name abbreviation) with an optional
     * clergy/religious title prefixed — e.g. "Fr. Andrew Boyd". Falls back
     * to plain first + last if no title is set. Used on the profile card
     * where the person is looking at their own record.
     */
    public function full_name_with_title(array $p): string {
        $title = trim((string)($p['title'] ?? ''));
        $first = trim((string)($p['first_name'] ?? ''));
        $last  = trim((string)($p['last_name'] ?? ''));

        $name = trim($first . ' ' . $last);
        if ($name === '') $name = '—';

        return $title !== '' ? ($title . ' ' . $name) : $name;
    }

    /**
     * Count how many signups exist for a person.
     */
    public function count_signups_for_person(int $person_id): int {
        global $wpdb;
        $signups = $wpdb->prefix . 'adoration_signups';

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$signups} WHERE person_id = %d",
            $person_id
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Delete a person row ONLY.
     * SAFETY: refuses to delete if person has any signups.
     */
    public function delete_person(int $person_id): bool {
        global $wpdb;

        if ($person_id <= 0) return false;

        // Defense-in-depth: never allow deletion if signups exist.
        if ($this->count_signups_for_person($person_id) > 0) {
            return false;
        }

        $res = $wpdb->delete($this->table, ['id' => $person_id], ['%d']);
        return ($res !== false && (int)$res > 0);
    }

    // -------------------------------------------------------------------------
    // MERGE TOOL (Option 2)
    // -------------------------------------------------------------------------

    /**
     * Merge one person INTO another.
     *
     * Moves signups from $from_id (source) to $to_id (target), deduping conflicts.
     * Then fills blanks on target from source (never overwrites existing target values).
     * Optionally deletes source.
     *
     * Options:
     *  - delete_source: bool (default true)
     *
     * Returns:
     *  [
     *    'moved' => int,
     *    'skipped' => int,
     *    'deleted_source' => bool,
     *    'error' => string|null
     *  ]
     */
    public function merge_people(int $from_id, int $to_id, array $opts = []): array {
        $from_id = (int)$from_id;
        $to_id   = (int)$to_id;

        $delete_source = array_key_exists('delete_source', $opts) ? (bool)$opts['delete_source'] : true;

        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            return ['moved' => 0, 'skipped' => 0, 'deleted_source' => false, 'error' => 'invalid'];
        }

        $from = $this->find($from_id);
        $to   = $this->find($to_id);

        if (!$from || !$to) {
            return ['moved' => 0, 'skipped' => 0, 'deleted_source' => false, 'error' => 'not_found'];
        }

        // Require SignupsRepository and the method that moves + dedupes signups.
        $signupsRepoClass = '\AdorationScheduler\Domain\Repositories\SignupsRepository';
        if (!class_exists($signupsRepoClass)) {
            return ['moved' => 0, 'skipped' => 0, 'deleted_source' => false, 'error' => 'missing_signups_repo'];
        }

        $signupsRepo = new $signupsRepoClass();

        if (!method_exists($signupsRepo, 'reassign_person_and_dedupe')) {
            return ['moved' => 0, 'skipped' => 0, 'deleted_source' => false, 'error' => 'missing_reassign'];
        }

        // 1) Move signups + dedupe
        $move = (array)$signupsRepo->reassign_person_and_dedupe($from_id, $to_id);
        $moved   = (int)($move['moved'] ?? 0);
        $skipped = (int)($move['skipped'] ?? 0);

        // 2) Fill blanks on target from source (no overwrites)
        $this->fill_blanks_from_other($to_id, $from_id);

        // 3) Delete source (now safe because signups were moved/deduped)
        $deleted = false;
        $error = null;

        if ($delete_source) {
            $deleted = $this->delete_person_force($from_id);
            if (!$deleted) {
                // Not fatal to the merge; the merge worked, but cleanup failed.
                $error = 'delete_failed';
            }
        }

        return [
            'moved' => $moved,
            'skipped' => $skipped,
            'deleted_source' => $deleted,
            'error' => $error,
        ];
    }

    /**
     * Fill blanks on the KEEP record from the FROM record.
     * Never overwrites any existing values on keep.
     */
    public function fill_blanks_from_other(int $keep_id, int $from_id): bool {
        global $wpdb;

        $keep_id = (int)$keep_id;
        $from_id = (int)$from_id;

        if ($keep_id <= 0 || $from_id <= 0 || $keep_id === $from_id) return false;

        $keep = $this->find($keep_id);
        $from = $this->find($from_id);

        if (!$keep || !$from) return false;

        $update = [];

        foreach (['first_name','last_name','email','phone'] as $field) {
            $keep_val = trim((string)($keep[$field] ?? ''));
            $from_val = trim((string)($from[$field] ?? ''));

            if ($keep_val === '' && $from_val !== '') {
                if ($field === 'email') {
                    $from_val = strtolower(trim($from_val));
                }
                $update[$field] = $from_val;
            }
        }

        if (empty($update)) return true;

        $formats = array_fill(0, count($update), '%s');

        $res = $wpdb->update(
            $this->table,
            $update,
            ['id' => $keep_id],
            $formats,
            ['%d']
        );

        return ($res !== false);
    }

    /**
     * Force-delete person WITHOUT checking signups.
     * Only safe to call AFTER signups were moved away.
     */
    public function delete_person_force(int $person_id): bool {
        global $wpdb;

        $person_id = (int)$person_id;
        if ($person_id <= 0) return false;

        $res = $wpdb->delete($this->table, ['id' => $person_id], ['%d']);
        return ($res !== false && (int)$res > 0);
    }
}
