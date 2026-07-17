<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Services\ReplacementRequestService;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_my_replacement_requests redirect="/my-adoration/"]
 *
 * The signed-in person's OWN open replacement requests — distinct from
 * [adoration_needed_replacements], which shows the community-wide list of
 * everyone ELSE'S open requests to claim. This one is just "here's what
 * you're still waiting on coverage for," with an Undo action.
 */
class MyReplacementRequestsShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_my_replacement_requests', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_my_replacement_requests');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];

        $uid = self::new_uid('asmyreq');
        $redirect_url = self::current_url();
        $card         = self::wants_card($atts['card']);

        $rows = self::get_my_open_replacement_requests((int)($person['id'] ?? 0));

        ob_start();
        ?>
        <div class="adoration-widget adoration-my-replacement-requests uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <h3 class="uk-margin-remove-top">My Replacement Requests</h3>

                <?php if (empty($rows)): ?>
                    <p class="uk-margin-remove-top">You don't have any open replacement requests.</p>
                <?php else: ?>
                    <p class="uk-text-meta as-muted uk-margin-remove-top">
                        You're still on the hook for these until someone covers them.
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
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $signup_id = (int)($r['id'] ?? 0);
                                $date_lbl  = self::fmt_date((string)($r['date'] ?? ''));
                                $time_lbl  = self::fmt_time_range((string)($r['start_time'] ?? ''), (string)($r['end_time'] ?? ''));
                                $chapel    = (string)($r['chapel_name'] ?? '');
                                $sched     = (string)($r['schedule_name'] ?? '');
                                $note      = (string)($r['replacement_note'] ?? '');
                                $target_id = (int)($r['replacement_target_person_id'] ?? 0);
                                $target_name = trim((string)($r['target_first_name'] ?? '') . ' ' . (string)($r['target_last_name'] ?? ''));
                                $cancel_replacement_nonce = ($signup_id > 0) ? wp_create_nonce('adoration_cancel_replacement_' . $signup_id) : '';
                                $open_to_everyone_nonce   = ($signup_id > 0) ? wp_create_nonce('adoration_replacement_open_to_everyone_' . $signup_id) : '';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($date_lbl); ?></td>
                                    <td><?php echo esc_html($time_lbl); ?></td>
                                    <td><?php echo esc_html($chapel); ?></td>
                                    <td>
                                        <?php echo esc_html($sched); ?>
                                        <?php if ($target_id > 0): ?>
                                            <br><span class="uk-label uk-label-warning" style="font-size:10px;">Asked: <?php echo esc_html($target_name !== '' ? $target_name : '(unnamed)'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($note !== ''): ?>
                                            <br><span class="uk-text-meta as-muted"><?php echo esc_html($note); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="uk-text-right">
                                        <?php if ($signup_id > 0): ?>
                                            <?php if ($target_id > 0): ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin:0 0 0 4px;">
                                                    <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_OPEN_TO_EVERYONE); ?>" />
                                                    <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>" />
                                                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($open_to_everyone_nonce); ?>" />
                                                    <button
                                                        type="submit"
                                                        class="uk-button uk-button-default uk-button-small adoration-btn-secondary"
                                                        onclick="return confirm('Open this to everyone? Any opted-in substitute will then be able to claim it, not just <?php echo esc_js($target_name !== '' ? $target_name : 'the person you asked'); ?>.');"
                                                    >
                                                        Open to Everyone
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin:0 0 0 4px;">
                                                <input type="hidden" name="action" value="<?php echo esc_attr(ReplacementRequestService::ACTION_CANCEL); ?>" />
                                                <input type="hidden" name="signup_id" value="<?php echo esc_attr((string)$signup_id); ?>" />
                                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($cancel_replacement_nonce); ?>" />
                                                <button type="submit" class="uk-button uk-button-default uk-button-small adoration-btn-secondary">
                                                    Undo
                                                </button>
                                            </form>
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
