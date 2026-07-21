<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Frontend\DashboardActionsAssets;
use AdorationScheduler\Services\ReplacementRequestService;
use AdorationScheduler\Services\WaitlistService;
use AdorationScheduler\Services\CheckInService;
use AdorationScheduler\Domain\Repositories\WaitlistRepository;
use AdorationScheduler\Frontend\Ajax\PersonTargetSearchAjax;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_my_schedule redirect="/my-adoration/"]
 *
 * Standing hours + upcoming signups, with Cancel/Skip and Need a
 * Replacement/Undo actions. One piece of the modular family that replaced
 * the retired [adoration_my_adoration] shortcode.
 */
class MyScheduleShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_my_schedule', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_my_schedule');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];

        $uid = self::new_uid('asmy_sched');
        $redirect_url  = self::current_url();
        $card          = self::wants_card($atts['card']);

        // ✅ (2026-07-20) Needed per-row now that "Need a Replacement" is
        // an inline <details> panel instead of one shared modal — see the
        // Actions column below.
        $target_search_nonce = wp_create_nonce(PersonTargetSearchAjax::ACTION);
        $target_search_url   = admin_url('admin-ajax.php');

        // ✅ (2026-07-20) Registers the click-confirm + live-search
        // enhancement JS through WordPress's script API (wp_footer) —
        // NOT an inline <script> in this shortcode's returned HTML. See
        // DashboardActionsAssets docblock for why: YOOtheme was silently
        // stripping the old inline script, breaking every button in this
        // column with no console error. The buttons below work with zero
        // JS; this only adds a confirm dialog and person-search on top.
        DashboardActionsAssets::enqueue();

        ob_start();
        ?>
        <div class="adoration-widget adoration-my-schedule uk-width-1-1" id="<?php echo esc_attr($uid); ?>" <?php echo self::ajax_wrapper_attrs('adoration_my_schedule', $atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <?php
                $standing_hours = self::get_person_standing_hours((int)($person['id'] ?? 0));
                if (!empty($standing_hours)):
                ?>
                    <h3 class="uk-margin-remove-top">Your Standing Hours</h3>
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
                    <?php $now_ts = strtotime(current_time('mysql')); ?>
                    <div class="uk-overflow-auto">
                        <table class="uk-table uk-table-divider uk-table-small adoration-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Chapel</th>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                    <th>Check-in</th>
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
                                $status_raw  = (string)($r['status'] ?? '');
                                $status      = self::pretty_status($status_raw);
                                $needs_replacement = !empty($r['needs_replacement']);

                                $slot_label = trim($date_lbl . ' • ' . $time_lbl . ' • ' . $chapel);

                                $cancel_note = $is_standing
                                    ? 'This only skips ' . $date_lbl . ' — your regular standing hour continues the following week.'
                                    : 'This cancels your signup for this date.';

                                $replacement_nonce = ($signup_id > 0) ? wp_create_nonce('adoration_request_replacement_' . $signup_id) : '';
                                $cancel_replacement_nonce = ($signup_id > 0) ? wp_create_nonce('adoration_cancel_replacement_' . $signup_id) : '';

                                // ✅ Check-in (2026-07-18): "I'm here" only appears once the
                                // hour is actually close (30 min before start onward) —
                                // signups.checked_in_at, sl.start_at/end_at come from the
                                // widened get_person_signups_upcoming() query.
                                $checked_in_at  = trim((string)($r['checked_in_at'] ?? ''));
                                $checked_out_at = trim((string)($r['checked_out_at'] ?? ''));
                                $start_at       = trim((string)($r['start_at'] ?? ''));

                                $checkin_window_open = false;
                                if ($signup_id > 0 && $status_raw === 'confirmed' && $start_at !== '') {
                                    $start_ts = strtotime($start_at);
                                    if ($start_ts !== false && $now_ts >= ($start_ts - 30 * MINUTE_IN_SECONDS)) {
                                        $checkin_window_open = true;
                                    }
                                }
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
                                    <td>
                                        <?php if ($checked_in_at !== '' && $checked_out_at !== ''): ?>
                                            <span class="uk-text-meta as-muted">Checked in &amp; out</span>
                                        <?php elseif ($checked_in_at !== ''): ?>
                                            <span class="uk-label uk-label-success" style="font-size:10px; display:block; margin-bottom:4px;">Checked in</span>
                                            <a class="uk-button uk-button-default uk-button-small adoration-btn-secondary" href="<?php echo esc_url((string) CheckInService::build_checkin_url($signup_id, 'out')); ?>" target="_blank" rel="noopener">
                                                I'm leaving
                                            </a>
                                        <?php elseif ($checkin_window_open): ?>
                                            <a class="uk-button uk-button-primary uk-button-small adoration-btn" href="<?php echo esc_url((string) CheckInService::build_checkin_url($signup_id, 'in')); ?>" target="_blank" rel="noopener">
                                                I'm here
                                            </a>
                                        <?php else: ?>
                                            <span class="uk-text-meta as-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="uk-text-right">
                                        <?php if ($signup_id > 0): ?>
                                            <form
                                                method="post"
                                                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                                class="as-cancel-form as-ajax-form"
                                                style="display:inline;margin:0;"
                                                data-confirm="<?php echo esc_attr($cancel_note . ' ' . $slot_label); ?>"
                                            >
                                                <input type="hidden" name="action" value="adoration_cancel_signup" />
                                                <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>" />
                                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                                                <button type="submit" class="uk-button uk-button-danger uk-button-small adoration-btn adoration-btn-danger">
                                                    <?php echo $is_standing ? 'Skip this date' : 'Cancel'; ?>
                                                </button>
                                            </form>

                                            <?php if ($needs_replacement): ?>
                                                <span class="uk-label uk-label-warning" style="font-size:10px;">Replacement Requested</span>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="as-ajax-form" style="display:inline;margin:0 0 0 4px;">
                                                    <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_CANCEL); ?>" />
                                                    <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>" />
                                                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($cancel_replacement_nonce); ?>" />
                                                    <button type="submit" class="uk-button uk-button-default uk-button-small adoration-btn-secondary">
                                                        Undo
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <!--
                                                    (2026-07-20) Presents as a centered modal with a
                                                    backdrop, but the open/close mechanism is the
                                                    native details/summary toggle — pure CSS, zero
                                                    JavaScript required. The single summary element is
                                                    repositioned into a floating close button while
                                                    open (see SharedStyles). This survives page
                                                    builders that strip inline scripts, unlike the old
                                                    JS-toggled uk-modal this replaced. Escape-to-close
                                                    and backdrop-click-to-close are optional JS
                                                    enhancements from DashboardActionsAssets — the
                                                    modal already works fully without them.
                                                -->
                                                <details class="as-replacement-details">
                                                    <summary class="uk-button uk-button-default uk-button-small adoration-btn-secondary as-replacement-summary" role="button">
                                                        <span class="as-replacement-summary-open">Need a Replacement</span>
                                                        <span class="as-replacement-summary-close" aria-label="Close">&times;</span>
                                                    </summary>
                                                    <div class="as-replacement-overlay">
                                                        <div class="as-replacement-panel">
                                                            <h4 class="as-replacement-panel-title">Request a Replacement</h4>
                                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="uk-margin-small-top as-ajax-form">
                                                                <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_REQUEST); ?>" />
                                                                <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>" />
                                                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($replacement_nonce); ?>" />
                                                                <input type="hidden" name="target_person_id" value="" class="as-target-id-input" />

                                                                <div class="uk-margin-small uk-text-left" style="position:relative;">
                                                                    <label class="uk-form-label uk-text-small">Ask a specific person (optional)</label>
                                                                    <input
                                                                        type="text"
                                                                        class="uk-input uk-form-small as-target-search"
                                                                        placeholder="Start typing a name…"
                                                                        autocomplete="off"
                                                                        data-search-url="<?php echo esc_url($target_search_url); ?>"
                                                                        data-search-nonce="<?php echo esc_attr($target_search_nonce); ?>"
                                                                        data-search-action="<?php echo esc_attr(PersonTargetSearchAjax::ACTION); ?>"
                                                                    />
                                                                    <ul class="uk-nav uk-dropdown-nav as-target-results" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:20; background:#fff; border:1px solid #ccd0d4; box-shadow:0 2px 6px rgba(0,0,0,.15); max-height:180px; overflow-y:auto; margin:2px 0 0; padding:4px 0;"></ul>
                                                                    <p class="uk-text-meta as-muted uk-margin-remove-top as-target-chosen" style="display:none;">
                                                                        Asking: <strong></strong>
                                                                        <button type="button" class="uk-button uk-button-link as-target-clear">change</button>
                                                                    </p>
                                                                    <p class="uk-text-meta as-muted uk-margin-remove-top">
                                                                        Leave blank to notify the admin and everyone who's opted in as a substitute instead.
                                                                    </p>
                                                                </div>

                                                                <div class="uk-margin-small uk-text-left">
                                                                    <label class="uk-form-label uk-text-small">Note (optional)</label>
                                                                    <textarea class="uk-textarea" name="note" rows="2" maxlength="500" placeholder="e.g. Out of town that week"></textarea>
                                                                </div>

                                                                <p class="uk-text-right uk-margin-remove-bottom">
                                                                    <button type="submit" class="uk-button uk-button-primary uk-button-small adoration-btn">
                                                                        Request Replacement
                                                                    </button>
                                                                </p>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </details>
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

                <?php
                $waitlist_repo = new WaitlistRepository();
                $waitlist_rows = $waitlist_repo->list_for_person((int)($person['id'] ?? 0), true);
                if (!empty($waitlist_rows)):
                ?>
                    <h3 class="uk-margin-top">My Waitlist</h3>
                    <p class="uk-text-meta as-muted uk-margin-remove-top">
                        These hours were full when you signed up. We'll email you and confirm you automatically if a spot opens.
                    </p>
                    <div class="uk-overflow-auto">
                        <table class="uk-table uk-table-divider uk-table-small adoration-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Schedule</th>
                                    <th>Position</th>
                                    <th class="uk-text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($waitlist_rows as $w): ?>
                                <?php
                                $waitlist_id = (int)($w['id'] ?? 0);
                                $wl_nonce    = ($waitlist_id > 0) ? wp_create_nonce(WaitlistService::ACTION_LEAVE . '_' . $waitlist_id) : '';
                                $wl_date     = self::fmt_date((string)($w['date'] ?? ''));
                                $wl_time     = self::fmt_time_range((string)($w['slot_start_time'] ?? ''), (string)($w['slot_end_time'] ?? ''));
                                $wl_sched    = (string)($w['schedule_name'] ?? '');
                                $wl_position = $waitlist_id > 0 ? $waitlist_repo->position_in_line($waitlist_id) : 0;
                                ?>
                                <tr>
                                    <td><?php echo esc_html($wl_date); ?></td>
                                    <td><?php echo esc_html($wl_time); ?></td>
                                    <td><?php echo esc_html($wl_sched); ?></td>
                                    <td><?php echo $wl_position > 0 ? '#' . esc_html((string)$wl_position) : '—'; ?></td>
                                    <td class="uk-text-right">
                                        <?php if ($waitlist_id > 0): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="as-ajax-form" style="display:inline;margin:0;">
                                                <input type="hidden" name="action" value="<?php echo esc_attr(WaitlistService::ACTION_LEAVE); ?>" />
                                                <input type="hidden" name="waitlist_id" value="<?php echo esc_attr((string)$waitlist_id); ?>" />
                                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($wl_nonce); ?>" />
                                                <button type="submit" class="uk-button uk-button-danger uk-button-small adoration-btn adoration-btn-danger">
                                                    Leave Waitlist
                                                </button>
                                            </form>
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
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
