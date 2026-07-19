<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Services\PasswordAuthService;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Compact sign-in widget for [adoration_magic_link] — same underlying
 * magic-link + optional-password auth (MagicLinkService / PasswordAuthService),
 * but with the explanatory copy and card chrome stripped out. Meant for a
 * front page or sidebar: an email field, a submit button, and a small toggle
 * to sign in with a password instead. No "check your spam folder" help text,
 * no rate-limit explanation — those still apply, they're just not narrated
 * here.
 */
class MiniLoginShortcode
{
    public static function register(): void
    {
        add_shortcode('adoration_mini_login', [__CLASS__, 'render']);
    }

    /**
     * Shortcode: [adoration_mini_login redirect="/my-adoration/"]
     */
    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
        ], (array)$atts, 'adoration_mini_login');

        $redirect = trim((string)($atts['redirect'] ?? ''));
        if ($redirect === '') $redirect = '/my-adoration/';

        $redirect_url = self::normalize_redirect_url($redirect);
        $action_url   = admin_url('admin-post.php');

        $return_url = self::safe_return_url(self::current_url());

        $person = MagicLinkService::current_person();

        // Unique id in case the shortcode appears more than once on a page
        $uid = 'asmini_' . substr(wp_hash(uniqid('', true)), 0, 10);

        // ✅ Theme UIkit detection (2026-07-18): when the active theme already
        // loads UIkit, use its own component classes (uk-button, uk-input,
        // uk-text-*) so this widget picks up the theme's brand color, font,
        // radius, and hover states instead of the plugin's own generic blue
        // fallback. Only fall back to the hand-rolled classes/CSS below when
        // UIkit genuinely isn't present, otherwise the fallback <style>
        // block (printed inline, after the theme's stylesheet in source
        // order) would win the cascade on equal specificity and mask the
        // theme's look even though the uk-* class was also present.
        $has_uikit = false;
        if (class_exists('\\AdorationScheduler\\Core\\Plugin') && method_exists('\\AdorationScheduler\\Core\\Plugin', 'theme_has_uikit')) {
            $has_uikit = (bool) \AdorationScheduler\Core\Plugin::theme_has_uikit();
        }

        $btn_primary_class   = $has_uikit ? 'uk-button uk-button-primary uk-button-small' : 'adoration-mini-btn';
        $btn_secondary_class = $has_uikit ? 'uk-button uk-button-default uk-button-small' : 'adoration-mini-btn adoration-mini-btn-secondary';
        $input_class         = $has_uikit ? 'uk-input' : '';
        $toggle_summary_class = $has_uikit ? 'uk-text-small' : 'adoration-mini-toggle-summary';

        ob_start();
        ?>
        <div class="adoration-mini-login">

            <style>
                /* Layout-only — safe under any theme, UIkit or not */
                .adoration-mini-login .adoration-mini-row {
                    display: flex;
                    gap: 8px;
                    flex-wrap: wrap;
                    align-items: center;
                }
                .adoration-mini-login input[type="email"],
                .adoration-mini-login input[type="password"] {
                    min-width: 200px;
                    flex: 1 1 200px;
                }
                .adoration-mini-login .adoration-mini-toggle {
                    margin-top: 8px;
                }
                .adoration-mini-login .adoration-mini-toggle form {
                    margin-top: 8px;
                }
                .adoration-mini-login form {
                    margin: 0;
                }
                .adoration-mini-login .adoration-mini-signed-in {
                    font-size: 13px;
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    flex-wrap: wrap;
                }

                <?php if (!$has_uikit): ?>
                /* Fallback appearance — only needed when the theme has no UIkit */
                .adoration-mini-login input[type="email"],
                .adoration-mini-login input[type="password"] {
                    padding: 7px 9px;
                    border: 1px solid #dcdcde;
                    border-radius: 4px;
                    font-size: 13px;
                }
                .adoration-mini-login .adoration-mini-btn {
                    display: inline-block;
                    padding: 7px 14px;
                    border-radius: 4px;
                    border: 1px solid #2271b1;
                    background: #2271b1;
                    color: #fff;
                    cursor: pointer;
                    font-size: 13px;
                    line-height: 1.4;
                    text-decoration: none;
                    white-space: nowrap;
                }
                .adoration-mini-login .adoration-mini-btn-secondary {
                    background: #f6f7f7;
                    border-color: #dcdcde;
                    color: #1d2327;
                }
                .adoration-mini-login .adoration-mini-toggle-summary {
                    font-size: 12px;
                    cursor: pointer;
                    color: #2271b1;
                }
                <?php else: ?>
                /* UIkit theme present: still make <summary> read as tappable */
                .adoration-mini-login .adoration-mini-toggle summary {
                    cursor: pointer;
                }
                <?php endif; ?>
            </style>

            <?php if ($person): ?>

                <div class="adoration-mini-signed-in">
                    <span><?php echo esc_html($person['email'] ?? ''); ?></span>
                    <a class="<?php echo esc_attr($btn_primary_class); ?>" href="<?php echo esc_url($redirect_url); ?>">
                        <?php esc_html_e('Go to My Adoration', 'adoration-scheduler'); ?>
                    </a>
                    <form method="post" action="<?php echo esc_url($action_url); ?>">
                        <?php wp_nonce_field('adoration_magic_logout'); ?>
                        <input type="hidden" name="action" value="adoration_magic_logout" />
                        <button type="submit" class="<?php echo esc_attr($btn_secondary_class); ?>">
                            <?php esc_html_e('Log out', 'adoration-scheduler'); ?>
                        </button>
                    </form>
                </div>

            <?php else: ?>

                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('adoration_magic_request'); ?>
                    <input type="hidden" name="action" value="adoration_magic_request" />
                    <input type="hidden" name="r" value="<?php echo esc_attr($redirect_url); ?>" />
                    <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>" />

                    <div class="adoration-mini-row">
                        <label for="<?php echo esc_attr('adoration_mini_email_' . $uid); ?>" class="screen-reader-text">
                            <?php esc_html_e('Email', 'adoration-scheduler'); ?>
                        </label>
                        <input
                            class="<?php echo esc_attr($input_class); ?>"
                            id="<?php echo esc_attr('adoration_mini_email_' . $uid); ?>"
                            name="email"
                            type="email"
                            inputmode="email"
                            autocomplete="email"
                            placeholder="<?php esc_attr_e('Email address', 'adoration-scheduler'); ?>"
                            required
                        />
                        <button type="submit" class="<?php echo esc_attr($btn_primary_class); ?>">
                            <?php esc_html_e('Sign in', 'adoration-scheduler'); ?>
                        </button>
                    </div>
                </form>

                <details class="adoration-mini-toggle">
                    <summary class="<?php echo esc_attr($toggle_summary_class); ?>"><?php esc_html_e('Have a password?', 'adoration-scheduler'); ?></summary>

                    <form method="post" action="<?php echo esc_url($action_url); ?>">
                        <?php wp_nonce_field(PasswordAuthService::ACTION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(PasswordAuthService::ACTION); ?>" />
                        <input type="hidden" name="r" value="<?php echo esc_attr($redirect_url); ?>" />
                        <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>" />

                        <div class="adoration-mini-row">
                            <label for="<?php echo esc_attr('adoration_mini_pwd_email_' . $uid); ?>" class="screen-reader-text">
                                <?php esc_html_e('Email', 'adoration-scheduler'); ?>
                            </label>
                            <input
                                class="<?php echo esc_attr($input_class); ?>"
                                id="<?php echo esc_attr('adoration_mini_pwd_email_' . $uid); ?>"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                placeholder="<?php esc_attr_e('Email address', 'adoration-scheduler'); ?>"
                                required
                            />
                            <label for="<?php echo esc_attr('adoration_mini_pwd_password_' . $uid); ?>" class="screen-reader-text">
                                <?php esc_html_e('Password', 'adoration-scheduler'); ?>
                            </label>
                            <input
                                class="<?php echo esc_attr($input_class); ?>"
                                id="<?php echo esc_attr('adoration_mini_pwd_password_' . $uid); ?>"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="<?php esc_attr_e('Password', 'adoration-scheduler'); ?>"
                                required
                            />
                            <button type="submit" class="<?php echo esc_attr($btn_primary_class); ?>">
                                <?php esc_html_e('Sign in', 'adoration-scheduler'); ?>
                            </button>
                        </div>
                    </form>
                </details>

            <?php endif; ?>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Same allow-list logic as MagicLinkShortcode::normalize_redirect_url()
     * — kept as its own copy rather than a shared dependency since each
     * shortcode class in this plugin is self-contained.
     */
    private static function normalize_redirect_url(string $redirect): string
    {
        $redirect = trim($redirect);
        if ($redirect === '') return home_url('/my-adoration/');

        if (strpos($redirect, '/') === 0) {
            return home_url($redirect);
        }

        $safe = wp_validate_redirect($redirect, '');
        if (!$safe) {
            return home_url('/my-adoration/');
        }

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $safe_host = wp_parse_url($safe, PHP_URL_HOST);

        if ($home_host && $safe_host && strtolower($home_host) !== strtolower($safe_host)) {
            return home_url('/my-adoration/');
        }

        return $safe;
    }

    private static function safe_return_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return home_url('/');

        $safe = wp_validate_redirect($url, '');
        if (!$safe) return home_url('/');

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $safe_host = wp_parse_url($safe, PHP_URL_HOST);

        if ($home_host && $safe_host && strtolower($home_host) !== strtolower($safe_host)) {
            return home_url('/');
        }

        return $safe;
    }

    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
        $uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');

        if ($host === '') {
            return home_url('/');
        }

        return $scheme . '://' . $host . $uri;
    }
}
