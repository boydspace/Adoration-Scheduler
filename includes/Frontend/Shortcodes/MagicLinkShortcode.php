<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Services\MagicLinkService;
use AdorationScheduler\Services\PasswordAuthService;

if ( ! defined('ABSPATH') ) {
    exit;
}

class MagicLinkShortcode
{
    public static function register(): void
    {
        add_shortcode('adoration_magic_link', [__CLASS__, 'render']);
    }

    /**
     * Shortcode: [adoration_magic_link redirect="/my-adoration/"]
     */
    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
        ], (array)$atts, 'adoration_magic_link');

        $redirect = trim((string)($atts['redirect'] ?? ''));
        if ($redirect === '') $redirect = '/my-adoration/';

        $redirect_url = self::normalize_redirect_url($redirect);

        $action_url  = admin_url('admin-post.php');

        // Current URL used as "return" after requesting the magic link
        $return_url_raw = self::current_url();
        $return_url     = self::safe_return_url($return_url_raw);

        $person = MagicLinkService::current_person();

        // Unique id in case shortcode appears multiple times on a page
        $uid = 'asml_' . substr(wp_hash(uniqid('', true)), 0, 10);

        ob_start();
        ?>
        <div class="adoration-magic-link uk-width-1-1">

            <style>
                /* Fallback buttons */
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
                .adoration-btn[disabled],
                .adoration-btn.is-disabled {
                    opacity: .55;
                    cursor: not-allowed;
                }
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

                .adoration-magic-actions {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                    align-items: center;
                }

                /* Make input decent without UIkit */
                .adoration-magic-form input[type="email"] {
                    width: 100%;
                    max-width: 420px;
                    padding: 8px 10px;
                }

                .adoration-magic-help {
                    font-size: 0.95em;
                    opacity: 0.9;
                    margin-top: 8px;
                }
                .adoration-magic-help ul {
                    margin: 8px 0 0 18px;
                }
            </style>

            <?php if ($person): ?>

                <div class="uk-card uk-card-default uk-card-body uk-width-1-1">
                    <div class="uk-text-meta">
                        You are signed in as <strong><?php echo esc_html($person['email'] ?? ''); ?></strong>.
                    </div>

                    <div class="uk-margin-top adoration-magic-actions">
                        <a class="uk-button uk-button-primary adoration-btn" href="<?php echo esc_url($redirect_url); ?>">
                            Go to My Adoration
                        </a>

                        <form method="post" action="<?php echo esc_url($action_url); ?>" style="margin:0;">
                            <?php wp_nonce_field('adoration_magic_logout'); ?>
                            <input type="hidden" name="action" value="adoration_magic_logout" />
                            <button type="submit" class="uk-button uk-button-default adoration-btn-secondary">
                                Log out
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>

                <div class="uk-card uk-card-default uk-card-body uk-width-1-1">
                    <form method="post" action="<?php echo esc_url($action_url); ?>" class="adoration-magic-form uk-form-stacked">
                        <?php wp_nonce_field('adoration_magic_request'); ?>
                        <input type="hidden" name="action" value="adoration_magic_request" />
                        <input type="hidden" name="r" value="<?php echo esc_attr($redirect_url); ?>" />
                        <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>" />

                        <div class="uk-margin">
                            <label class="uk-form-label" for="<?php echo esc_attr('adoration_magic_email_' . $uid); ?>">
                                <strong>Email</strong>
                            </label>
                            <div class="uk-form-controls">
                                <input
                                    class="uk-input"
                                    id="<?php echo esc_attr('adoration_magic_email_' . $uid); ?>"
                                    name="email"
                                    type="email"
                                    inputmode="email"
                                    autocomplete="email"
                                    required
                                />
                            </div>
                        </div>

                        <div class="uk-margin adoration-magic-actions">
                            <button type="submit" class="uk-button uk-button-primary adoration-btn">
                                Email me a sign-in link
                            </button>
                        </div>

                        <div class="uk-text-small uk-text-muted adoration-magic-help">
                            The link expires quickly and can be used once.
                            <ul>
                                <li>Check your spam/junk folder.</li>
                                <li>Wait a minute and try again if it hasn’t arrived.</li>
                                <li>If you request too often, the system may temporarily block additional sends.</li>
                            </ul>
                        </div>
                    </form>

                    <details class="uk-margin-top adoration-password-toggle">
                        <summary class="uk-text-small">Have a password? Sign in with it instead</summary>

                        <form method="post" action="<?php echo esc_url($action_url); ?>" class="adoration-magic-form uk-form-stacked uk-margin-top">
                            <?php wp_nonce_field(PasswordAuthService::ACTION); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr(PasswordAuthService::ACTION); ?>" />
                            <input type="hidden" name="r" value="<?php echo esc_attr($redirect_url); ?>" />
                            <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>" />

                            <div class="uk-margin">
                                <label class="uk-form-label" for="<?php echo esc_attr('adoration_pwd_email_' . $uid); ?>">
                                    <strong>Email</strong>
                                </label>
                                <div class="uk-form-controls">
                                    <input
                                        class="uk-input"
                                        id="<?php echo esc_attr('adoration_pwd_email_' . $uid); ?>"
                                        name="email"
                                        type="email"
                                        inputmode="email"
                                        autocomplete="email"
                                        required
                                    />
                                </div>
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label" for="<?php echo esc_attr('adoration_pwd_password_' . $uid); ?>">
                                    <strong>Password</strong>
                                </label>
                                <div class="uk-form-controls">
                                    <input
                                        class="uk-input"
                                        id="<?php echo esc_attr('adoration_pwd_password_' . $uid); ?>"
                                        name="password"
                                        type="password"
                                        autocomplete="current-password"
                                        required
                                    />
                                </div>
                            </div>

                            <div class="uk-margin adoration-magic-actions">
                                <button type="submit" class="uk-button uk-button-primary adoration-btn">
                                    Sign in
                                </button>
                            </div>

                            <div class="uk-text-small uk-text-muted adoration-magic-help">
                                Not set up a password yet? Use “Email me a sign-in link” above instead.
                            </div>
                        </form>
                    </details>
                </div>

            <?php endif; ?>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Only allow:
     * - relative paths -> home_url()
     * - same-host absolute URLs that validate
     */
    private static function normalize_redirect_url(string $redirect): string
    {
        $redirect = trim($redirect);
        if ($redirect === '') return home_url('/my-adoration/');

        // Relative path (preferred)
        if (strpos($redirect, '/') === 0) {
            return home_url($redirect);
        }

        // Absolute URL: validate + same-host
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

    /**
     * Sanitize the return URL to avoid surprises.
     * Must be same-host; otherwise fallback to home_url('/').
     */
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
