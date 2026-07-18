<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Frontend\Handlers\UpdateContactInfoHandler;
use AdorationScheduler\Frontend\Handlers\PasswordSetHandler;
use AdorationScheduler\Services\ReplacementRequestService;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\StandingCommitmentsRepository;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Services\AccessGateService;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * ⚠️ RETIRED (2026-07-16): [adoration_my_adoration] is no longer registered
 * (see Plugin.php::register_public_features()) and this class's register()
 * is intentionally never called. It's kept in place only as a reference —
 * do not re-enable without updating it to match the current schema/services.
 *
 * It was replaced by a modular family of smaller shortcodes so a page can be
 * composed from independent pieces instead of one all-in-one block:
 * [adoration_account_status], [adoration_profile_card],
 * [adoration_next_adoration_hour], [adoration_my_schedule],
 * [adoration_my_replacement_requests], [adoration_needed_replacements],
 * [adoration_announcements] — see includes/Frontend/Shortcodes/ and the
 * shared includes/Frontend/Shortcodes/Concerns/PersonDashboardTrait.php.
 *
 * Original docblock, for history:
 * Shortcode: [adoration_my_adoration]
 *
 * Account page for plugin-only users:
 * - If not signed in: shows magic link request form
 * - If signed in: shows upcoming signups + cancellation buttons + logout
 *
 * NOTE (Toast system):
 * - We do NOT render notices from query params here anymore.
 * - ToastService converts ?as_toast=... into a one-shot cookie + clean redirect.
 * - This shortcode stays focused on the account UI.
 */
class MyAdorationShortcode
{
    public static function register(): void
    {
        add_shortcode('adoration_my_adoration', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        // ✅ Optional site-wide approval gate (off by default). When on, a
        // visitor who isn't an approved person sees the Request Access form
        // instead of anything else on this page — no sign-in wall, no
        // dashboard. WP staff always bypass this (see AccessGateService).
        if (!AccessGateService::visitor_is_allowed()) {
            return do_shortcode('[adoration_request_access]');
        }

        $person = MagicLinkService::current_person();

        // ✅ WordPress admins are always let in, even without a parishioner
        // magic-link session. If their WP account email matches a person
        // record, show that person's real data. Otherwise fall through to
        // a neutral notice instead of the parishioner sign-in wall.
        $viewing_as_admin_match = false;
        $admin_email_for_notice = '';

        if (!$person && is_user_logged_in() && current_user_can('manage_options')) {
            $wp_user = wp_get_current_user();
            $admin_email_for_notice = (string)($wp_user->user_email ?? '');

            if ($admin_email_for_notice !== '' && class_exists(PersonsRepository::class)) {
                try {
                    $repo = new PersonsRepository();
                    $matched = $repo->find_by_email($admin_email_for_notice);
                    if ($matched) {
                        $person = $matched;
                        $viewing_as_admin_match = true;
                    }
                } catch (\Throwable $e) {
                    error_log('[AdorationScheduler] Admin->person email match failed: ' . $e->getMessage());
                }
            }
        }

        // Unique id in case shortcode appears multiple times on a page
        $uid = 'asma_' . substr(wp_hash(uniqid('', true)), 0, 10);

        // IMPORTANT:
        // Don't hardcode /my-adoration/. This shortcode may be placed on any page.
        // Redirect back to the current URL (toast params stripped).
        $redirect_url  = self::current_url();
        $redirect_attr = esc_attr($redirect_url);

        ob_start();
        ?>
        <div class="adoration-my-adoration uk-width-1-1" id="<?php echo esc_attr($uid); ?>">

            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <style>
            .adoration-my-adoration { width: 100% !important; max-width: none !important; }
            .adoration-my-adoration .adoration-my-adoration-inner { width: 100% !important; max-width: none !important; }

            /* Fallback buttons (if UIkit isn't present) */
            .adoration-btn {
                display: inline-block;
                padding: 6px 10px;
                border-radius: 4px;
                border: 1px solid #2271b1;
                background: #2271b1;
                color: #fff;
                cursor: pointer;
                font-size: 13px;
                line-height: 1.4;
                text-decoration: none;
            }
            .adoration-btn[disabled], .adoration-btn.is-disabled { opacity: .55; cursor: not-allowed; }
            .adoration-btn-secondary {
                display: inline-block;
                padding: 6px 10px;
                border-radius: 4px;
                border: 1px solid #dcdcde;
                background: #f6f7f7;
                color: #1d2327;
                cursor: pointer;
                font-size: 13px;
                line-height: 1.4;
                text-decoration: none;
            }
            .adoration-btn-secondary:hover { background: #f0f0f1; }
            .adoration-btn-danger { border-color: #d63638; background: #d63638; }

            /* Make the table behave nicely even without UIkit */
            table.adoration-table {
                width: 100% !important;
                max-width: none !important;
                border-collapse: collapse;
                table-layout: auto;
                margin: 0 0 10px 0;
            }
            table.adoration-table th,
            table.adoration-table td {
                border: 1px solid #dcdcde;
                padding: 10px 12px;
                vertical-align: top;
            }
            table.adoration-table th {
                background: #f6f7f7;
                text-align: left;
                font-weight: 600;
            }

            /* Small helper text */
            .as-muted { opacity: .85; }

            /* Fallback (non-UIkit JS) modal container styles */
            .as-modal { display: none; }
            .as-modal.is-open { display: block; }
            .as-modal__backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,.45);
                z-index: 9998;
            }
            .as-modal__panel {
                position: fixed;
                left: 50%;
                top: 10%;
                transform: translateX(-50%);
                width: min(680px, calc(100% - 32px));
                background: #fff;
                border-radius: 10px;
                z-index: 9999;
                box-shadow: 0 10px 30px rgba(0,0,0,.25);
                padding: 0;
            }
            .as-modal__header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 14px 16px;
                border-bottom: 1px solid #eee;
            }
            .as-modal__title { margin: 0; font-size: 18px; }
            .as-modal__close {
                background: none;
                border: 0;
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
            }
            .as-modal__body { padding: 16px; }
            .as-modal__actions {
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                margin-top: 14px;
            }
            </style>

            <?php if (!$person): ?>
                <?php if (is_user_logged_in() && current_user_can('manage_options')): ?>
                    <div class="uk-alert uk-alert-warning" role="status" uk-alert>
                        <p class="uk-margin-remove">
                            No Adoration profile is linked to this WordPress account<?php echo $admin_email_for_notice !== '' ? ' (' . esc_html($admin_email_for_notice) . ')' : ''; ?>.
                            Add a person with this email under
                            <a href="<?php echo esc_url(admin_url('admin.php?page=adoration_scheduler_people_add')); ?>">People &rarr; Add Person</a>
                            to preview it here, or manage everyone's signups from the
                            <a href="<?php echo esc_url(admin_url('admin.php?page=adoration_scheduler_signups')); ?>">Signups</a> admin page.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="uk-alert uk-alert-primary" role="status" uk-alert>
                        <p class="uk-margin-remove">Please sign in to view your Adoration commitments.</p>
                    </div>

                    <?php
                    echo do_shortcode('[adoration_magic_link redirect="' . $redirect_attr . '"]');
                    ?>
                <?php endif; ?>

            <?php else: ?>
                <?php
                $person_id      = (int)($person['id'] ?? 0);
                $contact_nonce  = ($person_id > 0) ? wp_create_nonce('adoration_update_contact_' . $person_id) : '';
                $password_nonce = ($person_id > 0) ? wp_create_nonce('adoration_set_password_' . $person_id) : '';
                $persons_repo   = new PersonsRepository();
                $has_password   = $persons_repo->has_password($person);
                $has_uikit_js   = self::has_uikit_js();

                // IMPORTANT:
                // We DO NOT use wp_style_is() to decide UIkit theming,
                // because many themes (e.g. Yootheme) load UIkit outside WP handles.
                // So: always output UIkit classes; fallback CSS still makes it usable.
                $btn_edit_class   = 'uk-button uk-button-default uk-button-small adoration-btn-secondary';
                $btn_save_class   = 'uk-button uk-button-primary adoration-btn';
                $btn_cancel_class = 'uk-button uk-button-default adoration-btn-secondary';
                $input_class      = 'uk-input';
                $form_class       = 'uk-form-stacked';
                ?>

                <div class="uk-card uk-card-default uk-card-body uk-width-1-1">
                    <?php if ($viewing_as_admin_match): ?>
                        <div class="uk-alert uk-alert-primary uk-margin-small-bottom" role="status" uk-alert>
                            <p class="uk-margin-remove">
                                Viewing as an admin, matched to this Adoration profile by email. This isn't a parishioner sign-in session — there's no "log out" needed.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
                        <div class="uk-text-meta">
                            Signed in as <strong><?php echo esc_html($person['email'] ?? ''); ?></strong>
                        </div>

                        <div class="uk-margin-small-top">
                            <?php if ($person_id > 0): ?>
                                <button
                                    type="button"
                                    class="<?php echo esc_attr($btn_edit_class); ?> as-open-contact"
                                    data-as-open-contact="1"
                                    <?php if ($has_uikit_js): ?>
                                        uk-toggle="target: #<?php echo esc_attr($uid); ?>_contact_modal"
                                    <?php endif; ?>
                                >
                                    Edit Contact Info
                                </button>
                                <button
                                    type="button"
                                    class="<?php echo esc_attr($btn_edit_class); ?> as-open-password"
                                    data-as-open-password="1"
                                    <?php if ($has_uikit_js): ?>
                                        uk-toggle="target: #<?php echo esc_attr($uid); ?>_password_modal"
                                    <?php endif; ?>
                                >
                                    <?php echo $has_password ? 'Change Password' : 'Set a Password'; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php
                    $standing_hours = self::get_person_standing_hours((int)($person['id'] ?? 0));
                    if (!empty($standing_hours)):
                    ?>
                        <h3 class="uk-margin-top">Your Standing Hours</h3>
                        <p class="uk-text-meta as-muted uk-margin-remove-top">
                            These repeat every week. You don’t need to sign up again — we’ll automatically confirm you each week.
                        </p>
                        <div class="uk-overflow-auto">
                            <table class="uk-table uk-table-divider uk-table-small adoration-table">
                                <thead>
                                    <tr>
                                        <th>Day &amp; Time</th>
                                        <th>Schedule</th>
                                        <th>Since</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($standing_hours as $sh): ?>
                                    <tr>
                                        <td><?php echo esc_html(self::fmt_day_of_week((int)($sh['day_of_week'] ?? 0)) . ' ' . self::fmt_time_range((string)($sh['start_time'] ?? ''), (string)($sh['end_time'] ?? ''))); ?></td>
                                        <td><?php echo esc_html((string)($sh['schedule_name'] ?? '')); ?></td>
                                        <td><?php echo esc_html(self::fmt_date((string)($sh['started_on'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <h3 class="uk-margin-top">My Upcoming Signups</h3>

                    <?php
                    $rows = self::get_person_signups_upcoming((int)($person['id'] ?? 0));
                    if (empty($rows)):
                    ?>
                        <p class="uk-margin-remove-top">You don’t currently have any upcoming signups.</p>
                    <?php else: ?>
                        <div class="uk-overflow-auto">
                            <table class="uk-table uk-table-divider uk-table-small adoration-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Chapel</th>
                                        <th>Schedule</th>
                                        <th>Status</th>
                                        <th class="uk-text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $signup_id   = (int)($r['id'] ?? 0);
                                    $is_standing = ((string)($r['type'] ?? '') === 'standing');
                                    $nonce       = ($signup_id > 0) ? wp_create_nonce('adoration_cancel_signup_' . $signup_id) : '';
                                    $date_lbl    = self::fmt_date((string)($r['date'] ?? ''));
                                    $time_lbl    = self::fmt_time_range((string)($r['start_time'] ?? ''), (string)($r['end_time'] ?? ''));
                                    $chapel      = (string)($r['chapel_name'] ?? '');
                                    $sched       = (string)($r['schedule_name'] ?? '');
                                    $status      = self::pretty_status((string)($r['status'] ?? ''));
                                    $needs_replacement = !empty($r['needs_replacement']);

                                    $slot_label = trim($date_lbl . ' • ' . $time_lbl . ' • ' . $chapel);

                                    $cancel_note = $is_standing
                                        ? 'This only skips ' . $date_lbl . ' — your regular standing hour continues the following week.'
                                        : 'This cancels your signup for this date.';

                                    $replacement_nonce = ($signup_id > 0) ? wp_create_nonce('adoration_request_replacement_' . $signup_id) : '';
                                    $cancel_replacement_nonce = ($signup_id > 0) ? wp_create_nonce('adoration_cancel_replacement_' . $signup_id) : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo esc_html($date_lbl); ?>
                                            <?php if ($is_standing): ?>
                                                <span class="uk-label uk-label-success" style="font-size:10px;">Recurring</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($time_lbl); ?></td>
                                        <td><?php echo esc_html($chapel); ?></td>
                                        <td><?php echo esc_html($sched); ?></td>
                                        <td><?php echo esc_html($status); ?></td>
                                        <td class="uk-text-right">
                                            <?php if ($signup_id > 0): ?>
                                                <button
                                                    type="button"
                                                    class="uk-button uk-button-danger uk-button-small adoration-btn adoration-btn-danger as-cancel-btn"
                                                    data-signup-id="<?php echo esc_attr((string)$signup_id); ?>"
                                                    data-nonce="<?php echo esc_attr($nonce); ?>"
                                                    data-slot-label="<?php echo esc_attr($slot_label); ?>"
                                                    data-cancel-note="<?php echo esc_attr($cancel_note); ?>"
                                                    uk-toggle="target: #<?php echo esc_attr($uid); ?>_cancel_modal"
                                                >
                                                    <?php echo $is_standing ? 'Skip this date' : 'Cancel'; ?>
                                                </button>

                                                <?php if ($needs_replacement): ?>
                                                    <span class="uk-label uk-label-warning" style="font-size:10px;">Replacement Requested</span>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin:0 0 0 4px;">
                                                        <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_CANCEL); ?>" />
                                                        <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>" />
                                                        <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($cancel_replacement_nonce); ?>" />
                                                        <button type="submit" class="uk-button uk-button-default uk-button-small adoration-btn-secondary">
                                                            Undo
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="uk-button uk-button-default uk-button-small adoration-btn-secondary as-replacement-btn"
                                                        data-signup-id="<?php echo esc_attr((string)$signup_id); ?>"
                                                        data-nonce="<?php echo esc_attr($replacement_nonce); ?>"
                                                        data-slot-label="<?php echo esc_attr($slot_label); ?>"
                                                        data-as-open-replacement="1"
                                                        <?php if ($has_uikit_js): ?>
                                                            uk-toggle="target: #<?php echo esc_attr($uid); ?>_replacement_modal"
                                                        <?php endif; ?>
                                                    >
                                                        Need a Replacement
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!$viewing_as_admin_match): ?>
                        <div class="uk-margin-top">
                            <?php
                            echo do_shortcode('[adoration_magic_link redirect="' . $redirect_attr . '"]');
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php
                $open_requests = self::get_open_replacement_requests((int)($person['id'] ?? 0));
                if (!empty($open_requests)):
                ?>
                    <div class="uk-card uk-card-default uk-card-body uk-width-1-1 uk-margin-top">
                        <h3 class="uk-margin-remove-top">Coverage Needed</h3>
                        <p class="uk-text-meta as-muted uk-margin-remove-top">
                            Someone else needs a substitute for one of these hours. Can you cover it?
                        </p>
                        <div class="uk-overflow-auto">
                            <table class="uk-table uk-table-divider uk-table-small adoration-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Chapel</th>
                                        <th>Schedule</th>
                                        <th class="uk-text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($open_requests as $req): ?>
                                    <?php
                                    $req_id      = (int)($req['id'] ?? 0);
                                    $req_date    = self::fmt_date((string)($req['date'] ?? ''));
                                    $req_time    = self::fmt_time_range((string)($req['start_time'] ?? ''), (string)($req['end_time'] ?? ''));
                                    $req_chapel  = (string)($req['chapel_name'] ?? '');
                                    $req_sched   = (string)($req['schedule_name'] ?? '');
                                    $claim_nonce = ($req_id > 0) ? wp_create_nonce('adoration_claim_replacement_' . $req_id) : '';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($req_date); ?></td>
                                        <td><?php echo esc_html($req_time); ?></td>
                                        <td><?php echo esc_html($req_chapel); ?></td>
                                        <td><?php echo esc_html($req_sched); ?></td>
                                        <td class="uk-text-right">
                                            <?php if ($req_id > 0): ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin:0;">
                                                    <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_CLAIM); ?>" />
                                                    <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$req_id); ?>" />
                                                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($claim_nonce); ?>" />
                                                    <button type="submit" class="uk-button uk-button-primary uk-button-small adoration-btn">
                                                        I Can Cover This
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                $fulfilled_requests = self::get_fulfilled_replacement_requests();
                if (!empty($fulfilled_requests)):
                ?>
                    <div class="uk-card uk-card-default uk-card-body uk-width-1-1 uk-margin-top">
                        <h3 class="uk-margin-remove-top">Recently Fulfilled</h3>
                        <div class="uk-overflow-auto">
                            <table class="uk-table uk-table-divider uk-table-small adoration-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Chapel</th>
                                        <th>Covered By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($fulfilled_requests as $f): ?>
                                    <?php
                                    $f_date   = self::fmt_date((string)($f['date'] ?? ''));
                                    $f_time   = self::fmt_time_range((string)($f['start_time'] ?? ''), (string)($f['end_time'] ?? ''));
                                    $f_chapel = (string)($f['chapel_name'] ?? '');
                                    $sub_name = trim((string)($f['substitute_first_name'] ?? '') . ' ' . (string)($f['substitute_last_name'] ?? ''));
                                    if ($sub_name === '') $sub_name = '—';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($f_date); ?></td>
                                        <td><?php echo esc_html($f_time); ?></td>
                                        <td><?php echo esc_html($f_chapel); ?></td>
                                        <td><?php echo esc_html($sub_name); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Contact Info Modal -->
                <?php if ($has_uikit_js): ?>
                    <!-- UIkit JS present: use real uk-modal -->
                    <div id="<?php echo esc_attr($uid); ?>_contact_modal" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body">
                            <h3 class="uk-modal-title">Update Contact Info</h3>

                            <p class="uk-text-meta as-muted uk-margin-small">
                                You can update your name and phone number here. Email changes must be handled by the parish office.
                            </p>

                            <form method="post"
                                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  class="<?php echo esc_attr($form_class); ?> uk-margin"
                                  style="margin:0;">
                                <input type="hidden" name="action" value="<?php echo esc_attr(UpdateContactInfoHandler::ACTION); ?>" />
                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($contact_nonce); ?>" />

                                <div class="uk-grid-small" uk-grid>
                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_first">First Name</label>
                                        <div class="uk-form-controls">
                                            <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_first" type="text" name="first_name"
                                                value="<?php echo esc_attr((string)($person['first_name'] ?? '')); ?>" />
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_last">Last Name</label>
                                        <div class="uk-form-controls">
                                            <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_last" type="text" name="last_name"
                                                value="<?php echo esc_attr((string)($person['last_name'] ?? '')); ?>" />
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_phone">Phone Number</label>
                                        <div class="uk-form-controls">
                                            <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_phone" type="text" name="phone"
                                                placeholder="(555) 555-5555"
                                                value="<?php echo esc_attr((string)($person['phone'] ?? '')); ?>" />
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_email">Email</label>
                                        <div class="uk-form-controls">
                                            <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_email" type="email"
                                                value="<?php echo esc_attr((string)($person['email'] ?? '')); ?>" readonly disabled />
                                        </div>
                                    </div>
                                </div>

                                <p class="uk-text-meta uk-margin-small-top as-muted">
                                    Email changes must be handled by the parish office.
                                </p>

                                <label class="uk-margin-small-top" style="display:flex;align-items:flex-start;gap:6px;">
                                    <input type="checkbox" class="uk-checkbox" name="substitute_opt_in" value="1"
                                        <?php checked($persons_repo->is_substitute_opt_in($person)); ?> />
                                    <span class="uk-text-small">
                                        I'm willing to be contacted as a substitute for open Adoration hours.
                                    </span>
                                </label>

                                <p class="uk-text-right uk-margin-top">
                                    <button type="button" class="<?php echo esc_attr($btn_cancel_class); ?> uk-modal-close">
                                        Cancel
                                    </button>
                                    <button type="submit" class="<?php echo esc_attr($btn_save_class); ?>">
                                        Save
                                    </button>
                                </p>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- No UIkit JS: use fallback modal container, but ALWAYS output UIkit classes so UIkit CSS can theme it -->
                    <div id="<?php echo esc_attr($uid); ?>_contact_modal" class="as-modal" aria-hidden="true">
                        <div class="as-modal__backdrop" data-as-close-contact="1"></div>
                        <div class="as-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>_contact_title">
                            <div class="as-modal__header">
                                <h3 class="as-modal__title" id="<?php echo esc_attr($uid); ?>_contact_title">Update Contact Info</h3>
                                <button type="button" class="as-modal__close" aria-label="Close" data-as-close-contact="1">×</button>
                            </div>
                            <div class="as-modal__body">
                                <p class="as-muted">
                                    You can update your name and phone number here. Email changes must be handled by the parish office.
                                </p>

                                <form method="post"
                                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                      class="<?php echo esc_attr($form_class); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(UpdateContactInfoHandler::ACTION); ?>" />
                                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($contact_nonce); ?>" />

                                    <div class="uk-grid-small" uk-grid>
                                        <div class="uk-width-1-2@s">
                                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_first_fallback">First Name</label>
                                            <div class="uk-form-controls">
                                                <input id="<?php echo esc_attr($uid); ?>_first_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="first_name"
                                                       value="<?php echo esc_attr((string)($person['first_name'] ?? '')); ?>">
                                            </div>
                                        </div>

                                        <div class="uk-width-1-2@s">
                                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_last_fallback">Last Name</label>
                                            <div class="uk-form-controls">
                                                <input id="<?php echo esc_attr($uid); ?>_last_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="last_name"
                                                       value="<?php echo esc_attr((string)($person['last_name'] ?? '')); ?>">
                                            </div>
                                        </div>

                                        <div class="uk-width-1-2@s">
                                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_phone_fallback">Phone Number</label>
                                            <div class="uk-form-controls">
                                                <input id="<?php echo esc_attr($uid); ?>_phone_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="phone"
                                                       placeholder="(555) 555-5555"
                                                       value="<?php echo esc_attr((string)($person['phone'] ?? '')); ?>">
                                            </div>
                                        </div>

                                        <div class="uk-width-1-2@s">
                                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_email_fallback">Email</label>
                                            <div class="uk-form-controls">
                                                <input id="<?php echo esc_attr($uid); ?>_email_fallback" class="<?php echo esc_attr($input_class); ?>" type="email"
                                                       value="<?php echo esc_attr((string)($person['email'] ?? '')); ?>" readonly disabled>
                                            </div>
                                        </div>
                                    </div>

                                    <label class="uk-margin-small-top" style="display:flex;align-items:flex-start;gap:6px;">
                                        <input type="checkbox" class="uk-checkbox" name="substitute_opt_in" value="1"
                                            <?php checked($persons_repo->is_substitute_opt_in($person)); ?> />
                                        <span class="uk-text-small">
                                            I'm willing to be contacted as a substitute for open Adoration hours.
                                        </span>
                                    </label>

                                    <div class="uk-flex uk-flex-right uk-margin-top uk-grid-small" uk-grid>
                                        <div>
                                            <button type="button" class="<?php echo esc_attr($btn_cancel_class); ?>" data-as-close-contact="1">Cancel</button>
                                        </div>
                                        <div>
                                            <button type="submit" class="<?php echo esc_attr($btn_save_class); ?>">Save</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Password Modal -->
                <?php
                $password_form_inner = function() use ($uid, $password_nonce, $redirect_url, $input_class, $has_password) {
                    ?>
                    <p class="uk-text-meta as-muted uk-margin-small">
                        <?php if ($has_password): ?>
                            You have a password set. Enter a new one below to change it, or use the emailed sign-in link if you've forgotten it.
                        <?php else: ?>
                            Optional: set a password so you can sign in without waiting for an email each time. You can always fall back to the emailed sign-in link.
                        <?php endif; ?>
                    </p>

                    <form method="post"
                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          class="uk-form-stacked uk-margin"
                          style="margin:0;">
                        <input type="hidden" name="action" value="<?php echo esc_attr(PasswordSetHandler::ACTION); ?>" />
                        <input type="hidden" name="mode" value="set" />
                        <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($password_nonce); ?>" />

                        <div class="uk-margin-small">
                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_new_password">New Password</label>
                            <div class="uk-form-controls">
                                <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_new_password"
                                    type="password" name="new_password" autocomplete="new-password" minlength="8" required />
                            </div>
                        </div>

                        <div class="uk-margin-small">
                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_confirm_password">Confirm New Password</label>
                            <div class="uk-form-controls">
                                <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_confirm_password"
                                    type="password" name="confirm_password" autocomplete="new-password" minlength="8" required />
                            </div>
                        </div>

                        <p class="uk-text-right uk-margin-top">
                            <button type="submit" class="uk-button uk-button-primary adoration-btn">Save Password</button>
                        </p>
                    </form>

                    <?php if ($has_password): ?>
                        <form method="post"
                              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              style="margin:0;"
                              onsubmit="return window.confirm('Remove your password? You will need to use the emailed sign-in link to access your account.');">
                            <input type="hidden" name="action" value="<?php echo esc_attr(PasswordSetHandler::ACTION); ?>" />
                            <input type="hidden" name="mode" value="remove" />
                            <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($password_nonce); ?>" />

                            <p class="uk-text-right uk-margin-remove-top">
                                <button type="submit" class="uk-button uk-button-danger uk-button-small adoration-btn adoration-btn-danger">
                                    Remove Password
                                </button>
                            </p>
                        </form>
                    <?php endif; ?>
                    <?php
                };
                ?>

                <?php if ($has_uikit_js): ?>
                    <div id="<?php echo esc_attr($uid); ?>_password_modal" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body">
                            <h3 class="uk-modal-title"><?php echo $has_password ? 'Change Password' : 'Set a Password'; ?></h3>
                            <?php $password_form_inner(); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="<?php echo esc_attr($uid); ?>_password_modal" class="as-modal" aria-hidden="true">
                        <div class="as-modal__backdrop" data-as-close-password="1"></div>
                        <div class="as-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>_password_title">
                            <div class="as-modal__header">
                                <h3 class="as-modal__title" id="<?php echo esc_attr($uid); ?>_password_title"><?php echo $has_password ? 'Change Password' : 'Set a Password'; ?></h3>
                                <button type="button" class="as-modal__close" aria-label="Close" data-as-close-password="1">×</button>
                            </div>
                            <div class="as-modal__body">
                                <?php $password_form_inner(); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Request Replacement Modal -->
                <?php
                $replacement_form_inner = function() use ($uid, $redirect_url) {
                    ?>
                    <p class="as-muted uk-margin-small">
                        <strong id="<?php echo esc_attr($uid); ?>_replacement_slot_label">—</strong>
                    </p>
                    <p class="uk-text-meta as-muted uk-margin-small">
                        You'll stay on the schedule until someone covers this hour. Admins and opted-in substitutes will be notified.
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="<?php echo esc_attr($uid); ?>_replacement_form" style="margin:0;">
                        <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_REQUEST); ?>" />
                        <input type="hidden" name="signup_id" value="" id="<?php echo esc_attr($uid); ?>_replacement_signup_id" />
                        <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                        <input type="hidden" name="_wpnonce" value="" id="<?php echo esc_attr($uid); ?>_replacement_nonce" />

                        <div class="uk-margin-small">
                            <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_replacement_note">Note (optional)</label>
                            <div class="uk-form-controls">
                                <textarea class="uk-textarea" id="<?php echo esc_attr($uid); ?>_replacement_note" name="note" rows="2" maxlength="500" placeholder="e.g. Out of town that week"></textarea>
                            </div>
                        </div>

                        <p class="uk-text-right uk-margin-top">
                            <button type="button" class="uk-button uk-button-default adoration-btn-secondary uk-modal-close" data-as-close-replacement="1">
                                Cancel
                            </button>
                            <button type="submit" class="uk-button uk-button-primary adoration-btn">
                                Request Replacement
                            </button>
                        </p>
                    </form>
                    <?php
                };
                ?>

                <?php if ($has_uikit_js): ?>
                    <div id="<?php echo esc_attr($uid); ?>_replacement_modal" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body">
                            <h3 class="uk-modal-title">Request a Replacement</h3>
                            <?php $replacement_form_inner(); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="<?php echo esc_attr($uid); ?>_replacement_modal" class="as-modal" aria-hidden="true">
                        <div class="as-modal__backdrop" data-as-close-replacement="1"></div>
                        <div class="as-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>_replacement_title">
                            <div class="as-modal__header">
                                <h3 class="as-modal__title" id="<?php echo esc_attr($uid); ?>_replacement_title">Request a Replacement</h3>
                                <button type="button" class="as-modal__close" aria-label="Close" data-as-close-replacement="1">×</button>
                            </div>
                            <div class="as-modal__body">
                                <?php $replacement_form_inner(); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($has_uikit_js): ?>
                    <!-- UIkit Cancel Confirmation Modal -->
                    <div id="<?php echo esc_attr($uid); ?>_cancel_modal" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body">
                            <h3 class="uk-modal-title">Cancel this signup?</h3>

                            <p class="as-muted uk-margin-small">
                                <strong id="<?php echo esc_attr($uid); ?>_cancel_slot_label">—</strong>
                            </p>

                            <p class="uk-margin-small" id="<?php echo esc_attr($uid); ?>_cancel_note">
                                This will cancel your commitment for the selected time.
                            </p>

                            <?php
                            $action_url = admin_url('admin-post.php');
                            $return_url = self::current_url();
                            ?>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" id="<?php echo esc_attr($uid); ?>_cancel_form" style="margin:0;">
                                <input type="hidden" name="action" value="adoration_cancel_signup" />
                                <input type="hidden" name="signup_id" value="" id="<?php echo esc_attr($uid); ?>_cancel_signup_id" />
                                <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>" />
                                <input type="hidden" name="_wpnonce" value="" id="<?php echo esc_attr($uid); ?>_cancel_nonce" />

                                <p class="uk-text-right uk-margin-top">
                                    <button type="button" class="uk-button uk-button-default uk-modal-close adoration-btn-secondary">
                                        Keep
                                    </button>
                                    <button type="submit" class="uk-button uk-button-danger adoration-btn adoration-btn-danger">
                                        Yes, cancel it
                                    </button>
                                </p>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!--
                        Fallback (no UIkit JS detected): this is never shown as a
                        visual modal — the click handler below uses a plain
                        window.confirm() dialog instead and submits this form
                        directly. It still needs to exist in the DOM (hidden via
                        the same .as-modal default-hidden class the Contact modal
                        fallback uses) purely so the JS has real elements/a real
                        form to populate and submit. Previously this whole block
                        was unconditionally rendered as bare `uk-modal` with no
                        hiding CSS at all when UIkit wasn't present, which is why
                        it showed up as plain visible page content.
                    -->
                    <div id="<?php echo esc_attr($uid); ?>_cancel_modal" class="as-modal" aria-hidden="true">
                        <div class="as-modal__body">
                            <h3 class="as-modal__title">Cancel this signup?</h3>

                            <p class="as-muted uk-margin-small">
                                <strong id="<?php echo esc_attr($uid); ?>_cancel_slot_label">—</strong>
                            </p>

                            <p class="uk-margin-small" id="<?php echo esc_attr($uid); ?>_cancel_note">
                                This will cancel your commitment for the selected time.
                            </p>

                            <?php
                            $action_url = admin_url('admin-post.php');
                            $return_url = self::current_url();
                            ?>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" id="<?php echo esc_attr($uid); ?>_cancel_form" style="margin:0;">
                                <input type="hidden" name="action" value="adoration_cancel_signup" />
                                <input type="hidden" name="signup_id" value="" id="<?php echo esc_attr($uid); ?>_cancel_signup_id" />
                                <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>" />
                                <input type="hidden" name="_wpnonce" value="" id="<?php echo esc_attr($uid); ?>_cancel_nonce" />
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <script>
                (function() {
                    const root = document.getElementById(<?php echo json_encode($uid); ?>);
                    if (!root) return;

                    // --- Cancel modal wiring ---
                    const labelElId = <?php echo json_encode($uid . '_cancel_slot_label'); ?>;
                    const idElId    = <?php echo json_encode($uid . '_cancel_signup_id'); ?>;
                    const nonceElId = <?php echo json_encode($uid . '_cancel_nonce'); ?>;
                    const noteElId  = <?php echo json_encode($uid . '_cancel_note'); ?>;
                    const formId    = <?php echo json_encode($uid . '_cancel_form'); ?>;

                    const labelEl = document.getElementById(labelElId);
                    const idEl    = document.getElementById(idElId);
                    const nonceEl = document.getElementById(nonceElId);
                    const noteEl  = document.getElementById(noteElId);
                    const form    = document.getElementById(formId);

                    root.querySelectorAll('.as-cancel-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const signupId  = btn.getAttribute('data-signup-id') || '';
                            const nonce     = btn.getAttribute('data-nonce') || '';
                            const slotLabel = btn.getAttribute('data-slot-label') || '—';
                            const cancelNote = btn.getAttribute('data-cancel-note') || 'This will cancel your commitment for the selected time.';

                            const hasUIkit = (window.UIkit && typeof window.UIkit.modal === 'function');

                            if (!hasUIkit) {
                                const ok = window.confirm(cancelNote + '\n\n' + slotLabel);
                                if (!ok) return;

                                if (idEl) idEl.value = signupId;
                                if (nonceEl) nonceEl.value = nonce;
                                if (labelEl) labelEl.textContent = slotLabel;
                                if (noteEl) noteEl.textContent = cancelNote;
                                if (form) form.submit();
                                return;
                            }

                            if (idEl) idEl.value = signupId;
                            if (nonceEl) nonceEl.value = nonce;
                            if (labelEl) labelEl.textContent = slotLabel;
                            if (noteEl) noteEl.textContent = cancelNote;
                        });
                    });

                    // --- Contact modal fallback wiring (only for .as-modal) ---
                    const contactModalId = <?php echo json_encode($uid . '_contact_modal'); ?>;
                    const contactModal   = document.getElementById(contactModalId);

                    function openContactModal() {
                        if (!contactModal) return;
                        contactModal.classList.add('is-open');
                        contactModal.setAttribute('aria-hidden', 'false');
                        if (window.AdorationA11y) window.AdorationA11y.trap(contactModal);
                    }
                    function closeContactModal() {
                        if (!contactModal) return;
                        contactModal.classList.remove('is-open');
                        contactModal.setAttribute('aria-hidden', 'true');
                        if (window.AdorationA11y) window.AdorationA11y.release(contactModal);
                    }

                    if (contactModal && contactModal.classList.contains('as-modal')) {
                        root.querySelectorAll('[data-as-open-contact="1"]').forEach(btn => {
                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                openContactModal();
                            });
                        });

                        contactModal.querySelectorAll('[data-as-close-contact="1"]').forEach(btn => {
                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                closeContactModal();
                            });
                        });

                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') closeContactModal();
                        });
                    }

                    // --- Password modal fallback wiring (only for .as-modal) ---
                    const passwordModalId = <?php echo json_encode($uid . '_password_modal'); ?>;
                    const passwordModal   = document.getElementById(passwordModalId);

                    function openPasswordModal() {
                        if (!passwordModal) return;
                        passwordModal.classList.add('is-open');
                        passwordModal.setAttribute('aria-hidden', 'false');
                        if (window.AdorationA11y) window.AdorationA11y.trap(passwordModal);
                    }
                    function closePasswordModal() {
                        if (!passwordModal) return;
                        passwordModal.classList.remove('is-open');
                        passwordModal.setAttribute('aria-hidden', 'true');
                        if (window.AdorationA11y) window.AdorationA11y.release(passwordModal);
                    }

                    if (passwordModal && passwordModal.classList.contains('as-modal')) {
                        root.querySelectorAll('[data-as-open-password="1"]').forEach(btn => {
                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                openPasswordModal();
                            });
                        });

                        passwordModal.querySelectorAll('[data-as-close-password="1"]').forEach(btn => {
                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                closePasswordModal();
                            });
                        });

                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') closePasswordModal();
                        });
                    }

                    // --- Replacement request modal wiring ---
                    const replacementModalId  = <?php echo json_encode($uid . '_replacement_modal'); ?>;
                    const replacementModal    = document.getElementById(replacementModalId);
                    const replacementLabelEl  = document.getElementById(<?php echo json_encode($uid . '_replacement_slot_label'); ?>);
                    const replacementIdEl     = document.getElementById(<?php echo json_encode($uid . '_replacement_signup_id'); ?>);
                    const replacementNonceEl  = document.getElementById(<?php echo json_encode($uid . '_replacement_nonce'); ?>);

                    function openReplacementModal() {
                        if (!replacementModal) return;
                        replacementModal.classList.add('is-open');
                        replacementModal.setAttribute('aria-hidden', 'false');
                        if (window.AdorationA11y) window.AdorationA11y.trap(replacementModal);
                    }
                    function closeReplacementModal() {
                        if (!replacementModal) return;
                        replacementModal.classList.remove('is-open');
                        replacementModal.setAttribute('aria-hidden', 'true');
                        if (window.AdorationA11y) window.AdorationA11y.release(replacementModal);
                    }

                    root.querySelectorAll('[data-as-open-replacement="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            const signupId  = btn.getAttribute('data-signup-id') || '';
                            const nonce     = btn.getAttribute('data-nonce') || '';
                            const slotLabel = btn.getAttribute('data-slot-label') || '—';

                            if (replacementIdEl) replacementIdEl.value = signupId;
                            if (replacementNonceEl) replacementNonceEl.value = nonce;
                            if (replacementLabelEl) replacementLabelEl.textContent = slotLabel;

                            const hasUIkit = (window.UIkit && typeof window.UIkit.modal === 'function');
                            if (!hasUIkit) {
                                e.preventDefault();
                                openReplacementModal();
                            }
                        });
                    });

                    if (replacementModal && replacementModal.classList.contains('as-modal')) {
                        replacementModal.querySelectorAll('[data-as-close-replacement="1"]').forEach(btn => {
                            btn.addEventListener('click', function(e) {
                                e.preventDefault();
                                closeReplacementModal();
                            });
                        });

                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') closeReplacementModal();
                        });
                    }
                })();
                </script>

            <?php endif; ?>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * UIkit JS detection (controls uk-modal behavior)
     */
    private static function has_uikit_js(): bool
    {
        if (!function_exists('wp_script_is')) return false;

        $handles = [
            'uikit',
            'uikit-js',
            'uikit-min',
            'yootheme-uikit',
            'yootheme-uikit-js',
        ];

        foreach ($handles as $h) {
            if (wp_script_is($h, 'enqueued') || wp_script_is($h, 'registered')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active standing (recurring weekly) commitments for this person.
     */
    private static function get_person_standing_hours(int $person_id): array
    {
        if ($person_id <= 0) return [];
        if (!class_exists(StandingCommitmentsRepository::class)) return [];

        try {
            $repo = new StandingCommitmentsRepository();
            return $repo->list_for_person($person_id, true);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_person_standing_hours failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function fmt_day_of_week(int $dow): string
    {
        $labels = [
            0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
        ];
        return $labels[$dow] ?? '';
    }

    /**
     * Upcoming only, excluding cancelled.
     * Uses date >= today (site local).
     */
    private static function get_person_signups_upcoming(int $person_id): array
    {
        if ($person_id <= 0) return [];

        global $wpdb;

        $signups  = $wpdb->prefix . 'adoration_signups';
        $slots    = $wpdb->prefix . 'adoration_slots';
        $sched    = $wpdb->prefix . 'adoration_schedules';
        $chapels  = $wpdb->prefix . 'adoration_chapels';

        $today = wp_date('Y-m-d'); // site timezone

        $sql = "
            SELECT
                su.id,
                su.date,
                su.status,
                su.type,
                su.needs_replacement,
                sl.start_time,
                sl.end_time,
                sc.name AS schedule_name,
                ch.name AS chapel_name
            FROM {$signups} su
            INNER JOIN {$slots} sl ON sl.id = su.slot_id
            INNER JOIN {$sched} sc ON sc.id = su.schedule_id
            INNER JOIN {$chapels} ch ON ch.id = sc.chapel_id
            WHERE su.person_id = %d
              AND su.status <> 'cancelled'
              AND su.date >= %s
            ORDER BY su.date ASC, sl.start_time ASC
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $person_id, $today), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * "Coverage Needed": open replacement requests from other people.
     */
    private static function get_open_replacement_requests(int $exclude_person_id): array
    {
        try {
            $repo = new SignupsRepository();
            return $repo->list_open_replacement_requests($exclude_person_id, 25);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_open_replacement_requests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * "Recently Fulfilled": claimed replacement requests, for transparency.
     */
    private static function get_fulfilled_replacement_requests(): array
    {
        try {
            $repo = new SignupsRepository();
            return $repo->list_fulfilled_replacement_requests(10);
        } catch (\Throwable $e) {
            error_log('[AdorationScheduler] get_fulfilled_replacement_requests failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

        $url = $scheme . $host . $uri;

        // Ensure we don't preserve toast args in return URLs
        $url = remove_query_arg(['as_toast', 'as_toast_type', 'as_toast_sticky'], $url);

        return (string) $url;
    }

    private static function fmt_date(string $ymd): string
    {
        $ts = strtotime($ymd . ' 00:00:00');
        if (!$ts) return $ymd;
        return date_i18n(get_option('date_format'), $ts);
    }

    private static function fmt_time_range(string $start, string $end): string
    {
        $s = self::fmt_time($start);
        $e = self::fmt_time($end);
        if ($s && $e) return "{$s} – {$e}";
        return trim($start . ' - ' . $end);
    }

    private static function fmt_time(string $t): string
    {
        $ts = strtotime('1970-01-01 ' . $t);
        if (!$ts) return '';
        return date_i18n(get_option('time_format'), $ts);
    }

    private static function pretty_status(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') return '—';
        if ($status === 'confirmed') return 'Confirmed';
        if ($status === 'pending') return 'Pending';
        if ($status === 'cancelled') return 'Cancelled';
        return ucfirst($status);
    }
}
