<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Services\ReplacementRequestService;
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
        $has_uikit_js  = self::has_uikit_js();
        $card          = self::wants_card($atts['card']);

        ob_start();
        ?>
        <div class="adoration-widget adoration-my-schedule uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
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
            </div>

            <!-- Request Replacement Modal -->
            <?php
            $target_search_nonce = wp_create_nonce(PersonTargetSearchAjax::ACTION);
            $target_search_url   = admin_url('admin-ajax.php');

            $replacement_form_inner = function() use ($uid, $redirect_url, $target_search_nonce, $target_search_url) {
                ?>
                <p class="as-muted uk-margin-small">
                    <strong id="<?php echo esc_attr($uid); ?>_replacement_slot_label">—</strong>
                </p>
                <p class="uk-text-meta as-muted uk-margin-small">
                    You'll stay on the schedule until someone covers this hour.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="<?php echo esc_attr($uid); ?>_replacement_form" style="margin:0;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_REQUEST); ?>" />
                    <input type="hidden" name="signup_id" value="" id="<?php echo esc_attr($uid); ?>_replacement_signup_id" />
                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                    <input type="hidden" name="_wpnonce" value="" id="<?php echo esc_attr($uid); ?>_replacement_nonce" />
                    <input type="hidden" name="target_person_id" value="" id="<?php echo esc_attr($uid); ?>_replacement_target_id" />

                    <div class="uk-margin-small">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_replacement_target_search">Ask a specific person (optional)</label>
                        <div class="uk-form-controls" style="position:relative;">
                            <input
                                type="text"
                                class="uk-input"
                                id="<?php echo esc_attr($uid); ?>_replacement_target_search"
                                placeholder="Start typing a name…"
                                autocomplete="off"
                                data-search-url="<?php echo esc_url($target_search_url); ?>"
                                data-search-nonce="<?php echo esc_attr($target_search_nonce); ?>"
                                data-search-action="<?php echo esc_attr(PersonTargetSearchAjax::ACTION); ?>"
                            />
                            <ul class="uk-nav uk-dropdown-nav" id="<?php echo esc_attr($uid); ?>_replacement_target_results" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:20; background:#fff; border:1px solid #ccd0d4; box-shadow:0 2px 6px rgba(0,0,0,.15); max-height:180px; overflow-y:auto; margin:2px 0 0; padding:4px 0;"></ul>
                        </div>
                        <p class="uk-text-meta as-muted uk-margin-remove-top" id="<?php echo esc_attr($uid); ?>_replacement_target_chosen" style="display:none;">
                            Asking: <strong></strong>
                            <button type="button" class="uk-button uk-button-link" data-as-clear-target="1">change</button>
                        </p>
                        <p class="uk-text-meta as-muted uk-margin-remove-top">
                            Leave blank to notify the admin and everyone who's opted in as a substitute instead.
                        </p>
                    </div>

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

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="<?php echo esc_attr($uid); ?>_cancel_form" style="margin:0;">
                            <input type="hidden" name="action" value="adoration_cancel_signup" />
                            <input type="hidden" name="signup_id" value="" id="<?php echo esc_attr($uid); ?>_cancel_signup_id" />
                            <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
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
                    Fallback (no UIkit JS detected): never shown as a visual
                    modal — the click handler below uses window.confirm()
                    instead and submits this form directly. Still needs to
                    exist in the DOM (hidden via .as-modal) so the JS has
                    real elements to populate and submit.
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

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="<?php echo esc_attr($uid); ?>_cancel_form" style="margin:0;">
                            <input type="hidden" name="action" value="adoration_cancel_signup" />
                            <input type="hidden" name="signup_id" value="" id="<?php echo esc_attr($uid); ?>_cancel_signup_id" />
                            <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
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
                }
                function closeReplacementModal() {
                    if (!replacementModal) return;
                    replacementModal.classList.remove('is-open');
                    replacementModal.setAttribute('aria-hidden', 'true');
                }

                root.querySelectorAll('[data-as-open-replacement="1"]').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        const signupId  = btn.getAttribute('data-signup-id') || '';
                        const nonce     = btn.getAttribute('data-nonce') || '';
                        const slotLabel = btn.getAttribute('data-slot-label') || '—';

                        if (replacementIdEl) replacementIdEl.value = signupId;
                        if (replacementNonceEl) replacementNonceEl.value = nonce;
                        if (replacementLabelEl) replacementLabelEl.textContent = slotLabel;

                        // Reset the target picker each time the modal opens
                        // for a (possibly different) signup, so a target
                        // chosen for a previous request doesn't leak in.
                        const tIdEl = document.getElementById(<?php echo json_encode($uid . '_replacement_target_id'); ?>);
                        const tSearchEl = document.getElementById(<?php echo json_encode($uid . '_replacement_target_search'); ?>);
                        const tChosenEl = document.getElementById(<?php echo json_encode($uid . '_replacement_target_chosen'); ?>);
                        if (tIdEl) tIdEl.value = '';
                        if (tSearchEl) { tSearchEl.value = ''; tSearchEl.style.display = ''; }
                        if (tChosenEl) tChosenEl.style.display = 'none';

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

                // --- "Ask a specific person" target picker ---
                const targetSearchEl  = document.getElementById(<?php echo json_encode($uid . '_replacement_target_search'); ?>);
                const targetResultsEl = document.getElementById(<?php echo json_encode($uid . '_replacement_target_results'); ?>);
                const targetIdEl      = replacementIdEl ? document.getElementById(<?php echo json_encode($uid . '_replacement_target_id'); ?>) : null;
                const targetChosenEl  = document.getElementById(<?php echo json_encode($uid . '_replacement_target_chosen'); ?>);

                if (targetSearchEl && targetResultsEl && targetIdEl && targetChosenEl) {
                    const chosenNameEl = targetChosenEl.querySelector('strong');
                    let searchTimer = null;
                    let currentFetch = null;

                    function hideResults() {
                        targetResultsEl.style.display = 'none';
                        targetResultsEl.innerHTML = '';
                    }

                    function chooseTarget(id, label) {
                        targetIdEl.value = id;
                        if (chosenNameEl) chosenNameEl.textContent = label;
                        targetChosenEl.style.display = '';
                        targetSearchEl.value = '';
                        targetSearchEl.style.display = 'none';
                        hideResults();
                    }

                    function clearTarget() {
                        targetIdEl.value = '';
                        targetChosenEl.style.display = 'none';
                        targetSearchEl.style.display = '';
                        targetSearchEl.value = '';
                        targetSearchEl.focus();
                    }

                    targetChosenEl.querySelectorAll('[data-as-clear-target="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            clearTarget();
                        });
                    });

                    targetSearchEl.addEventListener('input', function() {
                        const q = targetSearchEl.value.trim();
                        if (searchTimer) clearTimeout(searchTimer);

                        if (q.length < 2) {
                            hideResults();
                            return;
                        }

                        searchTimer = setTimeout(function() {
                            const base = targetSearchEl.getAttribute('data-search-url');
                            const nonce = targetSearchEl.getAttribute('data-search-nonce');
                            const action = targetSearchEl.getAttribute('data-search-action');
                            const url = base + '?action=' + encodeURIComponent(action) + '&_wpnonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q);

                            if (currentFetch && currentFetch.abort) currentFetch.abort();
                            const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                            currentFetch = controller;

                            fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined })
                                .then(r => r.json())
                                .then(function(json) {
                                    const results = (json && json.success && json.data && json.data.results) ? json.data.results : [];
                                    targetResultsEl.innerHTML = '';

                                    if (!results.length) {
                                        hideResults();
                                        return;
                                    }

                                    results.forEach(function(r) {
                                        const li = document.createElement('li');
                                        const a = document.createElement('a');
                                        a.href = '#';
                                        a.textContent = r.label;
                                        a.addEventListener('click', function(e) {
                                            e.preventDefault();
                                            chooseTarget(r.id, r.label);
                                        });
                                        li.appendChild(a);
                                        targetResultsEl.appendChild(li);
                                    });

                                    targetResultsEl.style.display = '';
                                })
                                .catch(function() { hideResults(); });
                        }, 250);
                    });

                    document.addEventListener('click', function(e) {
                        if (e.target !== targetSearchEl && !targetResultsEl.contains(e.target)) {
                            hideResults();
                        }
                    });
                }
            })();
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
