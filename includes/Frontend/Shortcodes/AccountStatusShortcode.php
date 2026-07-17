<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Services\MagicLinkService;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_account_status sign_in_url="/my-adoration/"]
 *
 * A compact "Signed in as X | Log out" strip (or a slim sign-in link when
 * not signed in) — meant for a page header or menu area, not a full card.
 * Unlike the other widgets in this family it does NOT gate on the approval
 * gate or require sign-in to render something: an anonymous visitor just
 * sees a small sign-in link.
 */
class AccountStatusShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_account_status', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'sign_in_url' => '/my-adoration/',
        ], (array)$atts, 'adoration_account_status');

        $sign_in_url = self::normalize_url((string)$atts['sign_in_url']);

        $person = MagicLinkService::current_person();

        $viewing_as_admin_match = false;
        if (!$person && is_user_logged_in() && current_user_can('manage_options')) {
            try {
                $wp_user = wp_get_current_user();
                $email = (string)($wp_user->user_email ?? '');
                if ($email !== '' && class_exists(\AdorationScheduler\Domain\Repositories\PersonsRepository::class)) {
                    $repo = new \AdorationScheduler\Domain\Repositories\PersonsRepository();
                    $matched = $repo->find_by_email($email);
                    if ($matched) {
                        $person = $matched;
                        $viewing_as_admin_match = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[AdorationScheduler] AccountStatusShortcode admin match failed: ' . $e->getMessage());
            }
        }

        $uid = self::new_uid('asstatus');
        $logout_nonce = wp_create_nonce('adoration_magic_logout');

        ob_start();
        ?>
        <span class="adoration-account-status" id="<?php echo esc_attr($uid); ?>" style="display:inline-flex;align-items:center;gap:8px;font-size:13px;">
            <?php if ($person): ?>
                <span>Signed in as <strong><?php echo esc_html((string)($person['email'] ?? '')); ?></strong></span>
                <?php if (!$viewing_as_admin_match): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin:0;">
                        <input type="hidden" name="action" value="adoration_magic_logout" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($logout_nonce); ?>" />
                        <button type="submit" style="background:none;border:0;padding:0;color:#2271b1;text-decoration:underline;cursor:pointer;font-size:13px;">
                            Log out
                        </button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <a href="<?php echo esc_url($sign_in_url); ?>" style="color:#2271b1;text-decoration:underline;">
                    Sign in
                </a>
            <?php endif; ?>
        </span>
        <?php
        return (string) ob_get_clean();
    }

    private static function normalize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return home_url('/my-adoration/');

        if (strpos($url, '/') === 0) {
            return home_url($url);
        }

        $safe = wp_validate_redirect($url, '');
        return $safe ? $safe : home_url('/my-adoration/');
    }
}
