<?php
namespace AdorationScheduler\Frontend;

use AdorationScheduler\Frontend\Ajax\WidgetRerenderAjax;

if (!defined('ABSPATH')) exit;

/**
 * Shared front-end enhancement script for the "My Adoration" dashboard
 * shortcodes' plain-form row actions (Cancel/Skip confirm dialog, Need a
 * Replacement inline target-person search).
 *
 * ✅ Bug fix (2026-07-20): MyScheduleShortcode's Cancel/Skip/Need a
 * Replacement buttons stopped working — clicks did nothing, no console
 * errors. Root cause: those buttons were wired up by an inline <script>
 * tag embedded directly in the shortcode's returned HTML, and the site's
 * YOOtheme Builder element wrapping the shortcode strips <script> tags
 * (and possibly uk- and data- attributes) from embedded content before
 * display — confirmed by viewing page source and finding the wiring
 * script's comment text entirely absent. Any interactive shortcode using
 * the same "inline <script> IIFE keyed off a per-instance uid" pattern is
 * at risk of the same silent failure depending on how it's placed in a
 * page builder.
 *
 * Fix: two-part.
 *   1. The buttons themselves are now plain HTML that works with ZERO
 *      JavaScript — real <form> elements that submit directly (Cancel/
 *      Skip), and a native <details>/<summary> disclosure (Need a
 *      Replacement) instead of a JS-toggled modal. This matches the
 *      "Leave Waitlist" button already in the same file, which was never
 *      reported broken because it never depended on a script.
 *   2. This class ADDS BACK a confirm() dialog on cancel and a live
 *      person-search convenience on top of that working baseline — but
 *      registers its JS through WordPress's own script API
 *      (wp_register_script + wp_add_inline_script, printed in wp_footer)
 *      instead of embedding it in the shortcode's returned HTML string.
 *      wp_footer output is completely separate from post/element content
 *      and is never touched by a page builder's content sanitizer, so
 *      this survives no matter how the shortcode is placed.
 *
 * Every behavior here is delegated on `document` and scoped via
 * closest()/data-* attributes rather than per-instance ids, so it works
 * correctly no matter how many widget instances are on one page.
 *
 * ✅ AJAX conversion (2026-07-20): also carries the generic `.as-ajax-form`
 * submit interceptor - every row action across the My Adoration dashboard
 * family already works as a plain POST to admin-post.php with zero JS (see
 * each shortcode's markup); this intercepts that same submit and POSTs the
 * identical fields to admin-ajax.php instead, using
 * window.AdorationScheduler.toast (ToastService) for feedback and
 * WidgetRerenderAjax to swap in fresh widget HTML afterward - all
 * layered on top of the working no-JS baseline, never a replacement for it.
 */
class DashboardActionsAssets
{
    private static bool $enqueued = false;

    public static function enqueue(): void
    {
        if (self::$enqueued) return;
        self::$enqueued = true;

        $handle = 'adoration-scheduler-dashboard-actions';
        wp_register_script($handle, '', [], null, true);
        wp_enqueue_script($handle);
        wp_localize_script($handle, 'AdorationSchedulerAjax', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'rerenderAction' => WidgetRerenderAjax::ACTION,
            'rerenderNonce'  => wp_create_nonce(WidgetRerenderAjax::ACTION),
        ]);
        wp_add_inline_script($handle, self::js());
    }

    private static function js(): string
    {
        return <<<'JS'
(function(){
    // --- Cancel / Skip confirm (progressive enhancement — the form
    // submits fine on its own if this script never runs) ---
    document.addEventListener('submit', function(e){
        var form = e.target.closest ? e.target.closest('.as-cancel-form') : null;
        if (!form) return;
        var msg = form.getAttribute('data-confirm') || 'Cancel this signup?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });

    // --- "Ask a specific person" live search (Need a Replacement panel) ---
    var searchTimer = null;
    var currentController = null;

    function hideResults(panel) {
        var results = panel.querySelector('.as-target-results');
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    }

    function chooseTarget(panel, id, label) {
        var idInput = panel.querySelector('.as-target-id-input');
        var searchInput = panel.querySelector('.as-target-search');
        var chosenEl = panel.querySelector('.as-target-chosen');
        if (idInput) idInput.value = id;
        if (chosenEl) {
            var strong = chosenEl.querySelector('strong');
            if (strong) strong.textContent = label;
            chosenEl.style.display = '';
        }
        if (searchInput) { searchInput.value = ''; searchInput.style.display = 'none'; }
        hideResults(panel);
    }

    function clearTarget(panel) {
        var idInput = panel.querySelector('.as-target-id-input');
        var searchInput = panel.querySelector('.as-target-search');
        var chosenEl = panel.querySelector('.as-target-chosen');
        if (idInput) idInput.value = '';
        if (chosenEl) chosenEl.style.display = 'none';
        if (searchInput) { searchInput.style.display = ''; searchInput.value = ''; searchInput.focus(); }
        hideResults(panel);
    }

    document.addEventListener('input', function(e){
        var input = e.target;
        if (!input.classList || !input.classList.contains('as-target-search')) return;

        var panel = input.closest('.as-replacement-panel');
        if (!panel) return;

        var results = panel.querySelector('.as-target-results');
        if (!results) return;

        var q = input.value.trim();
        if (searchTimer) clearTimeout(searchTimer);

        if (q.length < 2) {
            hideResults(panel);
            return;
        }

        searchTimer = setTimeout(function(){
            var base   = input.getAttribute('data-search-url');
            var nonce  = input.getAttribute('data-search-nonce');
            var action = input.getAttribute('data-search-action');
            if (!base || !nonce || !action) return;

            var url = base + '?action=' + encodeURIComponent(action) + '&_wpnonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q);

            if (currentController && currentController.abort) currentController.abort();
            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            currentController = controller;

            fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    var list = (json && json.success && json.data && json.data.results) ? json.data.results : [];
                    results.innerHTML = '';
                    if (!list.length) { hideResults(panel); return; }

                    list.forEach(function(r){
                        var li = document.createElement('li');
                        var a = document.createElement('a');
                        a.href = '#';
                        a.textContent = r.label;
                        a.addEventListener('click', function(ev){
                            ev.preventDefault();
                            chooseTarget(panel, r.id, r.label);
                        });
                        li.appendChild(a);
                        results.appendChild(li);
                    });
                    results.style.display = '';
                })
                .catch(function(){ hideResults(panel); });
        }, 250);
    });

    document.addEventListener('click', function(e){
        var clearBtn = e.target.closest ? e.target.closest('.as-target-clear') : null;
        if (clearBtn) {
            e.preventDefault();
            var panel = clearBtn.closest('.as-replacement-panel');
            if (panel) clearTarget(panel);
            return;
        }

        // Close any open result dropdown when clicking elsewhere.
        document.querySelectorAll('.as-replacement-panel .as-target-results').forEach(function(el){
            var panel = el.closest('.as-replacement-panel');
            var input = panel ? panel.querySelector('.as-target-search') : null;
            if (e.target !== input && !el.contains(e.target)) {
                el.style.display = 'none';
                el.innerHTML = '';
            }
        });
    });

    // --- Need a Replacement modal: backdrop-click-to-close (progressive
    // enhancement — the modal already opens/closes fine via the native
    // details/summary toggle with zero JS; this just adds the extra
    // click-outside convenience people expect from a modal). ---
    document.addEventListener('click', function(e){
        var openDetails = document.querySelector('.as-replacement-details[open]');
        if (!openDetails) return;
        if (e.target.closest('.as-replacement-panel')) return;
        if (e.target.closest('.as-replacement-summary')) return;
        if (openDetails.contains(e.target)) {
            openDetails.open = false;
        }
    });

    // --- Need a Replacement modal: Escape-to-close (same rationale) ---
    document.addEventListener('keydown', function(e){
        if (e.key !== 'Escape') return;
        var openDetails = document.querySelector('.as-replacement-details[open]');
        if (openDetails) openDetails.open = false;
    });

    // --- Generic AJAX form interceptor for `.as-ajax-form` --------------
    // Progressive enhancement: every form this targets already works as a
    // plain POST to admin-post.php with zero JS. This intercepts submit
    // and POSTs the identical fields to admin-ajax.php instead (the
    // plugin registers the same action string under both
    // admin_post_{action} and wp_ajax_{action}, so no field changes are
    // needed), then shows a toast and swaps in fresh widget HTML instead
    // of a full page reload. If this script never runs, or fetch fails,
    // the form still submits/works the normal way.
    function toast(opts) {
        if (window.AdorationScheduler && typeof window.AdorationScheduler.toast === 'function') {
            window.AdorationScheduler.toast(opts);
        }
    }

    function rerenderWidget(wrapper) {
        if (!wrapper || !window.AdorationSchedulerAjax) return;
        var tag = wrapper.getAttribute('data-as-shortcode');
        if (!tag) return;
        var attsRaw = wrapper.getAttribute('data-as-atts') || '{}';

        var fd = new FormData();
        fd.append('action', window.AdorationSchedulerAjax.rerenderAction);
        fd.append('_wpnonce', window.AdorationSchedulerAjax.rerenderNonce);
        fd.append('shortcode', tag);
        fd.append('atts', attsRaw);

        fetch(window.AdorationSchedulerAjax.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (!json || !json.success || !json.data || typeof json.data.html !== 'string') return;
                wrapper.outerHTML = json.data.html;
            })
            .catch(function(){ /* leave the pre-action HTML in place */ });
    }

    document.addEventListener('submit', function(e){
        var form = e.target.closest ? e.target.closest('.as-ajax-form') : null;
        if (!form) return;

        // A confirm()-style handler (the .as-cancel-form listener above,
        // or an inline onsubmit/onclick="return confirm(...)") already ran
        // first and called preventDefault() if the person cancelled -
        // respect that instead of submitting anyway.
        if (e.defaultPrevented) return;

        e.preventDefault();

        var fd = new FormData(form);
        var ajaxUrl = window.AdorationSchedulerAjax
            ? window.AdorationSchedulerAjax.ajaxUrl
            : form.getAttribute('action');

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function(r){ return r.json(); })
            .then(function(json){
                var data = (json && json.data) ? json.data : {};
                var message = data.message || (json && json.success ? 'Done.' : 'Something went wrong. Please try again.');
                var type = data.type || (json && json.success ? 'success' : 'error');

                toast({ message: message, type: type });

                if (!json || !json.success) return;

                if (form.classList.contains('as-magic-link-form')) {
                    var sent = document.querySelector('.as-magic-link-sent');
                    if (sent) {
                        form.style.display = 'none';
                        sent.style.display = '';
                    }
                    return;
                }

                // Close an open "Need a Replacement" panel before the
                // widget underneath it gets swapped out from under it.
                var openDetails = form.closest('.as-replacement-details[open]');
                if (openDetails) openDetails.open = false;

                var wrapper = form.closest('[data-as-shortcode]');
                if (wrapper) rerenderWidget(wrapper);
            })
            .catch(function(){
                toast({ message: 'Network error. Please try again.', type: 'error' });
            });
    });
})();
JS;
    }
}
