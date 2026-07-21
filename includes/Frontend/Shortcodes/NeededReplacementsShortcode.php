<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Services\ReplacementRequestService;
use AdorationScheduler\Utils\ClergyTitles;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_needed_replacements redirect="/my-adoration/"]
 *
 * Community-wide "Coverage Needed" list (claim button) + "Recently
 * Fulfilled" list, for transparency. One piece of the modular family that
 * replaced the retired [adoration_my_adoration] shortcode.
 */
class NeededReplacementsShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_needed_replacements', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_needed_replacements');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];

        $uid = self::new_uid('asneeded');
        $redirect_url = self::current_url();
        $card_class   = self::card_class(self::wants_card($atts['card']));

        $person_id = (int)($person['id'] ?? 0);
        $targeted_requests = self::get_my_targeted_replacement_requests($person_id);
        $open_requests = self::get_open_replacement_requests($person_id);
        $fulfilled_requests = self::get_fulfilled_replacement_requests();

        ob_start();
        ?>
        <div class="adoration-widget adoration-needed-replacements uk-width-1-1" id="<?php echo esc_attr($uid); ?>" <?php echo self::ajax_wrapper_attrs('adoration_needed_replacements', $atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php if (empty($targeted_requests) && empty($open_requests) && empty($fulfilled_requests)): ?>
                <div class="<?php echo esc_attr($card_class); ?>">
                    <p class="uk-margin-remove">No coverage requests right now.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($targeted_requests)): ?>
                <div class="<?php echo esc_attr($card_class); ?>">
                    <h3 class="uk-margin-remove-top">Asked of You</h3>
                    <p class="uk-text-meta as-muted uk-margin-remove-top">
                        Someone asked YOU specifically to cover one of their hours.
                    </p>
                    <div class="uk-overflow-auto">
                        <table class="uk-table uk-table-divider uk-table-small adoration-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Chapel</th>
                                    <th>Asked By</th>
                                    <th class="uk-text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($targeted_requests as $req): ?>
                                <?php
                                $req_id      = (int)($req['id'] ?? 0);
                                $req_date    = self::fmt_date((string)($req['date'] ?? ''));
                                $req_time    = self::fmt_time_range((string)($req['start_time'] ?? ''), (string)($req['end_time'] ?? ''));
                                $req_chapel  = (string)($req['chapel_name'] ?? '');
                                $requester_title = ClergyTitles::abbreviate((string)($req['requester_title'] ?? ''));
                                $requester   = trim($requester_title . ' ' . (string)($req['requester_first_name'] ?? '') . ' ' . (string)($req['requester_last_name'] ?? ''));
                                if ($requester === '') $requester = '(unnamed)';
                                $claim_nonce = ($req_id > 0) ? wp_create_nonce('adoration_claim_replacement_' . $req_id) : '';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($req_date); ?></td>
                                    <td><?php echo esc_html($req_time); ?></td>
                                    <td><?php echo esc_html($req_chapel); ?></td>
                                    <td><?php echo esc_html($requester); ?></td>
                                    <td class="uk-text-right">
                                        <?php if ($req_id > 0): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="as-ajax-form" style="display:inline;margin:0;">
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

            <?php if (!empty($open_requests)): ?>
                <div class="<?php echo esc_attr($card_class); ?>">
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
                                    <th>Requested By</th>
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
                                $requester_title = ClergyTitles::abbreviate((string)($req['requester_title'] ?? ''));
                                $requester   = trim($requester_title . ' ' . (string)($req['requester_first_name'] ?? '') . ' ' . (string)($req['requester_last_name'] ?? ''));
                                if ($requester === '') $requester = '(unnamed)';
                                $claim_nonce = ($req_id > 0) ? wp_create_nonce('adoration_claim_replacement_' . $req_id) : '';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($req_date); ?></td>
                                    <td><?php echo esc_html($req_time); ?></td>
                                    <td><?php echo esc_html($req_chapel); ?></td>
                                    <td><?php echo esc_html($req_sched); ?></td>
                                    <td><?php echo esc_html($requester); ?></td>
                                    <td class="uk-text-right">
                                        <?php if ($req_id > 0): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="as-ajax-form" style="display:inline;margin:0;">
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

            <?php if (!empty($fulfilled_requests)): ?>
                <div class="<?php echo esc_attr($card_class . ' uk-margin-top'); ?>">
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
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
