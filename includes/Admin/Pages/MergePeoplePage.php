<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

class MergePeoplePage {

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $page_slug = sanitize_key($_GET['page'] ?? 'adoration_scheduler_people_merge');

        $prefill_from = isset($_GET['from_person_id']) ? (int)$_GET['from_person_id'] : 0;
        $prefill_to   = isset($_GET['to_person_id']) ? (int)$_GET['to_person_id'] : 0;

        $ajax_url       = admin_url('admin-ajax.php');
        $nonce          = wp_create_nonce('adoration_people_search');
        $preview_nonce  = wp_create_nonce('adoration_merge_preview');

        // ------------------------------------------------------------
        // ✅ Notices (success/error) after redirect
        // ------------------------------------------------------------
        $notice = '';

        if (isset($_GET['merged']) && (string)$_GET['merged'] === '1') {
            $moved   = (int)($_GET['merge_moved'] ?? 0);
            $skipped = (int)($_GET['merge_skipped'] ?? 0);
            $deleted = (string)($_GET['merge_deleted'] ?? '0') === '1';

            $msg = sprintf(
                __('People merged. %1$d signup(s) moved, %2$d duplicate signup(s) removed. Source %3$s deleted.', 'adoration-scheduler'),
                $moved,
                $skipped,
                $deleted ? __('was', 'adoration-scheduler') : __('was NOT', 'adoration-scheduler')
            );

            $notice .= '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        if (!empty($_GET['merge_error'])) {
            $err = sanitize_key((string)$_GET['merge_error']);

            $map = [
                'invalid'              => __('Invalid merge selection. Please choose two different people.', 'adoration-scheduler'),
                'not_found'            => __('One of the selected people could not be found.', 'adoration-scheduler'),
                'missing_signups_repo' => __('Merge failed: signups repository not found.', 'adoration-scheduler'),
                'missing_reassign'     => __('Merge failed: could not move signups (missing required repository methods).', 'adoration-scheduler'),
                'delete_failed'        => __('Merge completed but source person could not be deleted.', 'adoration-scheduler'),
            ];

            $msg = $map[$err] ?? __('Merge failed.', 'adoration-scheduler');
            $notice .= '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Merge People', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_people_tabs('adoration_scheduler_people_merge'); ?>

            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <p class="description" style="max-width: 900px;">
                <?php echo esc_html__('Click into a field to browse, or type to search. Choose “From” (source) and “To” (keep).', 'adoration-scheduler'); ?>
            </p>

            <div class="postbox" style="max-width: 900px;">
                <div class="postbox-header">
                    <h2 class="hndle"><?php echo esc_html__('Merge People', 'adoration-scheduler'); ?></h2>
                </div>
                <div class="inside">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
                        <?php wp_nonce_field('adoration_merge_people'); ?>
                        <input type="hidden" name="action" value="adoration_merge_people">
                        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">

                        <input type="hidden" name="from_person_id" id="from_person_id" value="<?php echo (int)$prefill_from; ?>">
                        <input type="hidden" name="to_person_id"   id="to_person_id"   value="<?php echo (int)$prefill_to; ?>">

                        <div style="min-width:420px; flex: 1 1 420px;">
                            <label for="from_person_combo"><strong><?php echo esc_html__('From (source)', 'adoration-scheduler'); ?></strong></label><br>
                            <div class="as-combo" data-target="from" style="position:relative; max-width: 520px;">
                                <input
                                    type="text"
                                    id="from_person_combo"
                                    class="regular-text"
                                    autocomplete="off"
                                    placeholder="<?php echo esc_attr__('Type name/email/ID…', 'adoration-scheduler'); ?>"
                                    style="width: 100%;"
                                />
                                <div
                                    class="as-combo-menu"
                                    id="from_menu"
                                    style="display:none; position:absolute; left:0; right:0; top:100%; margin-top:4px; z-index:9999;
                                           background:#fff; border:1px solid #dcdcde; border-radius:4px; box-shadow:0 2px 10px rgba(0,0,0,.08);
                                           max-height:260px; overflow:auto;"
                                ></div>
                            </div>
                            <p class="description" id="from_selected" style="margin-top:6px;">
                                <?php echo $prefill_from > 0 ? esc_html(sprintf(__('Selected ID: %d', 'adoration-scheduler'), $prefill_from)) : esc_html__('No person selected yet.', 'adoration-scheduler'); ?>
                            </p>
                        </div>

                        <div style="min-width:420px; flex: 1 1 420px;">
                            <label for="to_person_combo"><strong><?php echo esc_html__('To (keep)', 'adoration-scheduler'); ?></strong></label><br>
                            <div class="as-combo" data-target="to" style="position:relative; max-width: 520px;">
                                <input
                                    type="text"
                                    id="to_person_combo"
                                    class="regular-text"
                                    autocomplete="off"
                                    placeholder="<?php echo esc_attr__('Type name/email/ID…', 'adoration-scheduler'); ?>"
                                    style="width: 100%;"
                                />
                                <div
                                    class="as-combo-menu"
                                    id="to_menu"
                                    style="display:none; position:absolute; left:0; right:0; top:100%; margin-top:4px; z-index:9999;
                                           background:#fff; border:1px solid #dcdcde; border-radius:4px; box-shadow:0 2px 10px rgba(0,0,0,.08);
                                           max-height:260px; overflow:auto;"
                                ></div>
                            </div>
                            <p class="description" id="to_selected" style="margin-top:6px;">
                                <?php echo $prefill_to > 0 ? esc_html(sprintf(__('Selected ID: %d', 'adoration-scheduler'), $prefill_to)) : esc_html__('No person selected yet.', 'adoration-scheduler'); ?>
                            </p>
                        </div>

                        <div>
                            <?php submit_button(__('Merge', 'adoration-scheduler'), 'primary', 'submit', false, [
                                'onclick' => "return window.AdorationMergeConfirm && window.AdorationMergeConfirm();"
                            ]); ?>
                        </div>
                    </form>

                    <div id="merge_overlap_notice" class="notice notice-warning" style="display:none; margin-top:12px; max-width: 900px;">
                        <p style="margin:8px 12px;">
                            <?php echo esc_html__('Warning: These two people have overlapping signups. Duplicate signups will be removed during the merge.', 'adoration-scheduler'); ?>
                            <span id="merge_overlap_count" style="font-weight:600;"></span>
                        </p>
                    </div>

                </div>
            </div>
        </div>

        <script>
        (function(){
            const ajaxUrl       = <?php echo wp_json_encode($ajax_url); ?>;
            const nonce         = <?php echo wp_json_encode($nonce); ?>;
            const previewNonce  = <?php echo wp_json_encode($preview_nonce); ?>;

            const overlapBox   = document.getElementById('merge_overlap_notice');
            const overlapCount = document.getElementById('merge_overlap_count');

            const els = {
                from: {
                    input: document.getElementById('from_person_combo'),
                    menu:  document.getElementById('from_menu'),
                    hid:   document.getElementById('from_person_id'),
                    label: document.getElementById('from_selected'),
                },
                to: {
                    input: document.getElementById('to_person_combo'),
                    menu:  document.getElementById('to_menu'),
                    hid:   document.getElementById('to_person_id'),
                    label: document.getElementById('to_selected'),
                }
            };

            function otherTarget(t){ return t === 'from' ? 'to' : 'from'; }
            function showMenu(t){ els[t].menu.style.display = 'block'; }
            function hideMenu(t){ els[t].menu.style.display = 'none'; }
            function clearMenu(t){ els[t].menu.innerHTML = ''; }

            async function fetchPeople(q) {
                const url = new URL(ajaxUrl);
                url.searchParams.set('action', 'adoration_people_search');
                url.searchParams.set('_wpnonce', nonce);
                url.searchParams.set('q', q || '');

                const res = await fetch(url.toString(), { credentials: 'same-origin' });
                const data = await res.json();
                if (!data || !data.success) return [];
                return (data.data && data.data.results) ? data.data.results : [];
            }

            async function fetchOverlap(fromId, toId) {
                const url = new URL(ajaxUrl);
                url.searchParams.set('action', 'adoration_merge_preview');
                url.searchParams.set('_wpnonce', previewNonce);
                url.searchParams.set('from_person_id', fromId || '');
                url.searchParams.set('to_person_id', toId || '');

                try {
                    const res = await fetch(url.toString(), { credentials: 'same-origin' });
                    const data = await res.json();
                    if (!data || !data.success) return null;
                    return data.data || null;
                } catch (e) {
                    return null;
                }
            }

            let overlapTimer = null;
            function scheduleOverlapCheck() {
                if (!overlapBox || !overlapCount) return;

                if (overlapTimer) window.clearTimeout(overlapTimer);
                overlapTimer = window.setTimeout(async () => {
                    const fromId = String(els.from.hid.value || '').trim();
                    const toId   = String(els.to.hid.value || '').trim();

                    if (!fromId || !toId || fromId === toId) {
                        overlapBox.style.display = 'none';
                        overlapCount.textContent = '';
                        return;
                    }

                    const r = await fetchOverlap(fromId, toId);

                    // If endpoint not available yet, just don't show anything.
                    if (!r || r.unavailable) {
                        overlapBox.style.display = 'none';
                        overlapCount.textContent = '';
                        return;
                    }

                    const n = parseInt(r.overlap_count, 10);
                    const overlap = Number.isFinite(n) ? n : 0;

                    if (overlap > 0) {
                        overlapCount.textContent = ' (' + overlap + ')';
                        overlapBox.style.display = 'block';
                    } else {
                        overlapBox.style.display = 'none';
                        overlapCount.textContent = '';
                    }
                }, 200);
            }

            function setSelected(t, item) {
                const id = String(item.id || '').trim();
                if (!id) return;

                // Prevent same person on both sides
                const ot = otherTarget(t);
                if (els[ot].hid.value && String(els[ot].hid.value) === id) {
                    els[ot].hid.value = '';
                    els[ot].input.value = '';
                    els[ot].label.textContent = 'No person selected yet.';
                }

                els[t].hid.value = id;
                els[t].input.value = item.label || ('ID ' + id);
                els[t].label.textContent = 'Selected: ' + (item.label || ('ID ' + id));
                hideMenu(t);

                scheduleOverlapCheck();
            }

            function renderMenu(t, items) {
                clearMenu(t);

                const ot = otherTarget(t);
                const otherId = String(els[ot].hid.value || '');

                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.textContent = 'No matches.';
                    empty.style.padding = '10px';
                    empty.style.color = '#646970';
                    els[t].menu.appendChild(empty);
                    return;
                }

                items.forEach(it => {
                    if (!it || !it.id) return;
                    if (otherId && String(it.id) === otherId) return;

                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = it.label;
                    btn.style.display = 'block';
                    btn.style.width = '100%';
                    btn.style.textAlign = 'left';
                    btn.style.padding = '8px 10px';
                    btn.style.border = '0';
                    btn.style.background = 'transparent';
                    btn.style.cursor = 'pointer';
                    btn.addEventListener('mouseenter', () => btn.style.background = '#f6f7f7');
                    btn.addEventListener('mouseleave', () => btn.style.background = 'transparent');
                    btn.addEventListener('click', () => setSelected(t, it));
                    els[t].menu.appendChild(btn);
                });
            }

            function parseIdOnly(text) {
                const m = String(text || '').trim().match(/^(\d+)\b/);
                return m ? parseInt(m[1], 10) : 0;
            }

            function wire(t) {
                let timer = null;
                let flight = 0;

                els[t].input.addEventListener('input', function(){
                    const q = (els[t].input.value || '').trim();

                    if (/^\d+$/.test(q)) {
                        els[t].hid.value = q;
                        els[t].label.textContent = 'Selected ID: ' + q;
                        scheduleOverlapCheck();
                    } else if (q === '') {
                        els[t].hid.value = '';
                        els[t].label.textContent = 'No person selected yet.';
                        scheduleOverlapCheck();
                    } else {
                        // user is typing a name/email; don't force hidden id yet
                        els[t].hid.value = '';
                        els[t].label.textContent = 'No person selected yet.';
                        scheduleOverlapCheck();
                    }

                    showMenu(t);

                    if (timer) window.clearTimeout(timer);
                    timer = window.setTimeout(async () => {
                        const my = ++flight;
                        const items = await fetchPeople(q);
                        if (my !== flight) return;
                        renderMenu(t, items);
                    }, 180);
                });

                els[t].input.addEventListener('focus', async function(){
                    showMenu(t);
                    const q = (els[t].input.value || '').trim();
                    const my = ++flight;
                    const items = await fetchPeople(q);
                    if (my !== flight) return;
                    renderMenu(t, items);
                });

                els[t].input.addEventListener('blur', function(){
                    window.setTimeout(() => hideMenu(t), 150);
                });

                els[t].input.addEventListener('change', function(){
                    const val = (els[t].input.value || '').trim();
                    const id = parseIdOnly(val);
                    if (id > 0) {
                        els[t].hid.value = String(id);
                        els[t].label.textContent = 'Selected ID: ' + id;
                    } else {
                        els[t].hid.value = '';
                        els[t].label.textContent = 'No person selected yet.';
                    }
                    scheduleOverlapCheck();
                });
            }

            wire('from');
            wire('to');

            // If hidden IDs are prefilled (from row action), populate visible FROM field via AJAX.
            // (We intentionally do NOT auto-populate TO.)
            async function hydratePrefilledFromOnly() {
                const id = String(els.from.hid.value || '').trim();
                if (!id) return;

                if ((els.from.input.value || '').trim() !== '') return;

                const items = await fetchPeople(id);
                if (!items || !items.length) {
                    els.from.input.value = 'ID ' + id;
                    els.from.label.textContent = 'Selected ID: ' + id;
                    scheduleOverlapCheck();
                    return;
                }

                const exact = items.find(x => String(x.id) === id) || items[0];
                setSelected('from', exact);
            }

            hydratePrefilledFromOnly();

            // If coming from row action (From is prefilled), focus TO and open the menu
            (function focusToIfFromPrefilled(){
                const fromId = String(els.from.hid.value || '').trim();
                if (!fromId) return;

                els.to.input.focus();
                showMenu('to');
                fetchPeople((els.to.input.value || '').trim()).then(items => {
                    renderMenu('to', items);
                });
            })();

            window.AdorationMergeConfirm = function(){
                const fromId = (els.from.hid.value || '').trim();
                const toId   = (els.to.hid.value || '').trim();

                if (!fromId || !toId) {
                    alert('Please select both people before merging.');
                    return false;
                }
                if (fromId === toId) {
                    alert('Please choose two different people.');
                    return false;
                }
                return confirm('Merge these two people? This moves signups and may delete the source person. This cannot be undone.');
            };

            document.addEventListener('click', function(e){
                const fromWrap = els.from.input.closest('.as-combo');
                const toWrap   = els.to.input.closest('.as-combo');

                if (fromWrap && !fromWrap.contains(e.target)) hideMenu('from');
                if (toWrap && !toWrap.contains(e.target)) hideMenu('to');
            });

            // One initial check (usually hides box)
            scheduleOverlapCheck();
        })();
        </script>
        <?php
    }
}
