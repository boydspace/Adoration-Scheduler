<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Services\AccessGateService;
use AdorationScheduler\Public\AccessRequestHandler;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode: [adoration_request_access]
 *
 * Shown by ScheduleShortcode/MyAdorationShortcode in place of their normal
 * content when AccessGateService::visitor_is_allowed() is false (i.e. the
 * site's optional approval gate is on and this visitor isn't yet an
 * approved person). Can also be dropped on its own page directly.
 *
 * Never creates a session — only submits a person into 'pending' status
 * via AccessRequestHandler for an admin to review.
 */
class AccessRequestShortcode
{
    /** Must match AntiSpamSettingsPage::OPTION_NAME */
    private const OPT_ANTISPAM_OPTIONS = 'adoration_scheduler_antispam_options';

    public static function register(): void
    {
        add_shortcode('adoration_request_access', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $status = AccessGateService::current_person_status(); // null | pending | approved | rejected

        // ✅ Accessibility (2026-07-18): unique instance id so the field
        // id/for pairs added below don't collide if this shortcode is ever
        // rendered twice on the same page (e.g. the gate auto-renders it in
        // place of another shortcode's content, and the page also has it
        // placed manually).
        $uid = 'ar_' . substr(wp_hash(uniqid('', true)), 0, 10);

        $action_url = admin_url('admin-post.php');
        $return_url = self::current_url();

        $antispam_opts = get_option(self::OPT_ANTISPAM_OPTIONS, []);
        $antispam_opts = is_array($antispam_opts) ? $antispam_opts : [];
        $turnstile_enabled  = !empty($antispam_opts['turnstile_enabled']);
        $turnstile_site_key = trim((string)($antispam_opts['turnstile_site_key'] ?? ''));

        ob_start();
        ?>
        <div class="adoration-request-access uk-card uk-card-default uk-card-body uk-width-1-1" style="max-width:520px;">

            <?php if ($status === PersonsRepository::STATUS_APPROVED): ?>
                <!-- Shouldn't normally be reached (the gate already lets approved visitors through), but handle it gracefully. -->
                <p class="uk-margin-remove">You already have access — <a href="<?php echo esc_url($return_url); ?>">reload this page</a>.</p>

            <?php elseif ($status === PersonsRepository::STATUS_PENDING): ?>
                <div class="uk-alert uk-alert-primary" role="status" uk-alert>
                    <p class="uk-margin-remove">
                        Your access request is awaiting review. You'll be able to sign in as soon as an admin approves it.
                    </p>
                </div>

            <?php else: ?>
                <?php if ($status === PersonsRepository::STATUS_REJECTED): ?>
                    <div class="uk-alert uk-alert-warning" role="status" uk-alert>
                        <p class="uk-margin-remove">
                            Your previous request wasn't approved. You can submit a new request below.
                        </p>
                    </div>
                <?php endif; ?>

                <h3 class="uk-margin-remove-top">Request Access</h3>
                <p class="uk-text-meta">
                    This schedule requires an approved account. Submit your info below and an admin will review your request.
                </p>

                <form method="post" action="<?php echo esc_url($action_url); ?>" class="uk-form-stacked">
                    <?php wp_nonce_field(AccessRequestHandler::ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(AccessRequestHandler::ACTION); ?>">
                    <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>">

                    <!-- Honeypot (matches SignupHandler::validate_honeypot() expectations) -->
                    <div style="position:absolute; left:-9999px; top:-9999px;" aria-hidden="true">
                        <label>Leave this field blank
                            <input type="text" name="as_website" value="" tabindex="-1" autocomplete="off">
                        </label>
                    </div>
                    <input type="hidden" name="as_form_ts" value="<?php echo esc_attr((string) time()); ?>">

                    <div class="uk-margin">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_title">Title</label>
                        <div class="uk-form-controls">
                            <input class="uk-input" type="text" name="title" id="<?php echo esc_attr($uid); ?>_title" autocomplete="honorific-prefix" placeholder="Father, Deacon, Bishop, Msgr., etc.">
                        </div>
                        <p class="uk-text-meta uk-margin-remove-top">Optional. For priests, deacons, bishops, etc.</p>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_first_name">First Name</label>
                        <div class="uk-form-controls">
                            <input class="uk-input" type="text" name="first_name" id="<?php echo esc_attr($uid); ?>_first_name" required autocomplete="given-name">
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_last_name">Last Name</label>
                        <div class="uk-form-controls">
                            <input class="uk-input" type="text" name="last_name" id="<?php echo esc_attr($uid); ?>_last_name" autocomplete="family-name">
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_email">Email</label>
                        <div class="uk-form-controls">
                            <input class="uk-input" type="email" name="email" id="<?php echo esc_attr($uid); ?>_email" required autocomplete="email">
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_phone">Cell Phone Number</label>
                        <div class="uk-form-controls">
                            <input class="uk-input" type="tel" name="phone" id="<?php echo esc_attr($uid); ?>_phone" required autocomplete="tel" placeholder="(555) 123-4567">
                        </div>
                        <p class="uk-text-meta uk-margin-remove-top">Required — please use a cell phone number, not a landline. We'll use this for text reminders in the future.</p>
                    </div>

                    <?php if ($turnstile_enabled && $turnstile_site_key !== ''): ?>
                        <?php
                        wp_enqueue_script(
                            'cf-turnstile',
                            'https://challenges.cloudflare.com/turnstile/v0/api.js',
                            [],
                            null,
                            true
                        );
                        ?>
                        <div class="uk-margin">
                            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                        </div>
                    <?php elseif ($turnstile_enabled && $turnstile_site_key === ''): ?>
                        <p class="uk-text-danger uk-text-small">Anti-spam is enabled but not fully configured. Please contact the site administrator.</p>
                    <?php endif; ?>

                    <div class="uk-margin">
                        <button type="submit" class="uk-button uk-button-primary">Request Access</button>
                    </div>
                </form>
            <?php endif; ?>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

        $url = $scheme . $host . $uri;
        $url = remove_query_arg(['as_toast', 'as_toast_type', 'as_toast_sticky'], $url);

        return (string) $url;
    }
}
