<?php
namespace AdorationScheduler\Admin\Support;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shared out-of-band <form> + click handler for WP_List_Table row actions
 * that need to POST to admin-post.php (cancel/delete/approve/etc.).
 *
 * WHY THIS EXISTS:
 * Per-row actions used to render their own <form method="post">...</form>
 * per row (e.g. Cancel/Delete on Signups, Accept/Reject/Delete on People).
 * Those pages also wrap the whole list table in their own outer
 * <form method="post"> for WP_List_Table's bulk actions. That means every
 * row action form was NESTED inside the page's outer form.
 *
 * Nested <form> elements are invalid HTML5. When the browser parses a
 * <form> start tag while a form is already open, it ignores the new start
 * tag (no inner form element is actually created) and merges the "inner"
 * fields into the still-open outer form. The inner </form> end tag then
 * closes whatever form is actually open — the outer one. In practice this
 * made per-row Cancel/Delete/Accept/Reject/Delete buttons unreliable:
 * clicking one could submit the outer bulk-action form's fields instead of
 * (or in addition to) the row's own action/id/nonce.
 *
 * Fix: render ONE hidden <form> per page, placed outside any other <form>,
 * and have row-action buttons trigger it via a small shared click handler
 * instead of each carrying their own <form> tag.
 */
class RowActionForm {

    private static bool $printed = false;

    /**
     * Build a row-action trigger button. Renders as a plain <button
     * type="button">, not a <form> — safe to place inside another <form>
     * (e.g. inside a WP_List_Table row that's itself inside the page's
     * outer bulk-action form).
     *
     * @param array<string,string|int> $fields Hidden field name => value
     *                                          pairs to submit. Must include
     *                                          'action' and '_wpnonce'.
     */
    public static function button(
        string $label,
        array $fields,
        string $style = '',
        string $confirm = '',
        string $class = 'button-link'
    ): string {
        $style_attr   = $style !== '' ? ' style="' . esc_attr($style) . '"' : '';
        $confirm_attr = $confirm !== '' ? ' data-confirm="' . esc_attr($confirm) . '"' : '';

        return sprintf(
            '<button type="button" class="%s as-row-action-btn"%s data-fields="%s"%s>%s</button>',
            esc_attr($class),
            $style_attr,
            esc_attr((string) wp_json_encode($fields)),
            $confirm_attr,
            esc_html($label)
        );
    }

    /**
     * Print the shared hidden <form> + click-handler JS. Safe to call more
     * than once per page — only prints once (static flag). Call this
     * OUTSIDE any other <form> on the page (e.g. right after the page's
     * own outer <form>...</form> closes).
     */
    public static function render_once(): void {
        if (self::$printed) return;
        self::$printed = true;
        ?>
        <form id="adoration-row-action-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;"></form>
        <script>
        (function () {
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.as-row-action-btn');
                if (!btn) return;
                e.preventDefault();

                var confirmMsg = btn.getAttribute('data-confirm');
                if (confirmMsg && !window.confirm(confirmMsg)) return;

                var form = document.getElementById('adoration-row-action-form');
                if (!form) return;

                // Clear fields from any previous click before adding this row's.
                var old = form.querySelectorAll('[data-adoration-dynamic]');
                for (var i = 0; i < old.length; i++) old[i].remove();

                var fields = {};
                try { fields = JSON.parse(btn.getAttribute('data-fields') || '{}'); } catch (err) { fields = {}; }

                Object.keys(fields).forEach(function (key) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = fields[key];
                    input.setAttribute('data-adoration-dynamic', '1');
                    form.appendChild(input);
                });

                form.submit();
            });
        })();
        </script>
        <?php
    }
}
