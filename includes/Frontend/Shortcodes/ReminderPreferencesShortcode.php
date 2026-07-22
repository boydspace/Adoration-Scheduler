<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Frontend\DashboardActionsAssets;
use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Frontend\Handlers\ReminderPreferencesHandler;
use AdorationScheduler\Admin\Pages\SmsSettingsPage;
use AdorationScheduler\Utils\PhoneNumberFormatter;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_reminder_preferences redirect="/my-adoration/"]
 *
 * Lets a person choose which channels their own reminder goes out on
 * (email, SMS, both, or neither) AND how many hours before their slot it
 * fires — a fixed "always 24h before" reproduces the same clock time
 * every day, which is wrong for very early/late hours (a 3am slot's
 * reminder would also land at 3am the day before). A deliberately
 * separate widget from ProfileCardShortcode, not folded into its
 * contact-info section, per product direction. One piece of the modular
 * "My Adoration" family; see PersonDashboardTrait for the shared sign-in
 * gate/AJAX-rerender mechanism every sibling shortcode in this family
 * uses.
 *
 * The SMS checkbox only appears when SMS is actually usable — the parish
 * has Twilio configured (SmsSettingsPage::is_configured()) AND this
 * person has a phone number that converts to a valid number
 * (PhoneNumberFormatter::to_e164()). A checkbox for a channel that
 * literally can't work yet is just confusing, so it's hidden rather than
 * shown disabled — except when only the phone number is missing, where a
 * short note pointing at Contact Info is more useful than silence.
 */
class ReminderPreferencesShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_reminder_preferences', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_reminder_preferences');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];

        $uid = self::new_uid('asremind');
        $redirect_url = self::current_url();
        $card_class   = self::card_class(self::wants_card($atts['card']));

        DashboardActionsAssets::enqueue();

        $person_id = (int)($person['id'] ?? 0);
        $persons_repo = new PersonsRepository();

        $email_opt_in = $persons_repo->is_email_reminder_opt_in($person);
        $sms_opt_in   = $persons_repo->is_sms_reminder_opt_in($person);
        $lead_hours   = $persons_repo->get_reminder_lead_hours($person);

        $sms_configured = SmsSettingsPage::is_configured();
        $has_valid_phone = PhoneNumberFormatter::to_e164((string)($person['phone'] ?? '')) !== null;
        $sms_available  = $sms_configured && $has_valid_phone;

        $nonce = wp_create_nonce('adoration_update_reminder_prefs_' . $person_id);

        ob_start();
        ?>
        <div class="adoration-widget adoration-reminder-preferences uk-width-1-1" id="<?php echo esc_attr($uid); ?>" <?php echo self::ajax_wrapper_attrs('adoration_reminder_preferences', $atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr($card_class); ?>">
                <h3 class="uk-margin-remove-top">Reminder Preferences</h3>
                <p class="uk-text-meta as-muted uk-margin-remove-top">
                    Choose how you'd like to be reminded about your upcoming Adoration hours.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="as-ajax-form uk-margin-small-top">
                    <input type="hidden" name="action" value="<?php echo esc_attr(ReminderPreferencesHandler::ACTION); ?>" />
                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />

                    <p class="uk-margin-small">
                        <label>
                            <input type="checkbox" class="uk-checkbox" name="email_reminders" value="1" <?php checked($email_opt_in); ?> />
                            Email reminder
                        </label>
                    </p>

                    <?php if ($sms_available): ?>
                        <p class="uk-margin-small">
                            <label>
                                <input type="checkbox" class="uk-checkbox" name="sms_reminders" value="1" <?php checked($sms_opt_in); ?> />
                                Text message reminder
                            </label>
                        </p>
                    <?php elseif ($sms_configured && !$has_valid_phone): ?>
                        <p class="uk-text-meta as-muted uk-margin-small">
                            Add a phone number in your Contact Info to enable text message reminders.
                        </p>
                    <?php endif; ?>

                    <p class="uk-margin-small">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_lead_hours">Remind me</label>
                        <select class="uk-select uk-form-width-small" id="<?php echo esc_attr($uid); ?>_lead_hours" name="reminder_lead_hours">
                            <?php foreach (ReminderPreferencesHandler::LEAD_HOURS_OPTIONS as $opt): ?>
                                <option value="<?php echo esc_attr((string)$opt); ?>" <?php selected($lead_hours, $opt); ?>>
                                    <?php echo esc_html($opt . ($opt === 1 ? ' hour before' : ' hours before')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <br />
                        <span class="uk-text-meta as-muted">Applies to whichever reminder(s) above are checked — useful if your hour is very early or very late.</span>
                    </p>

                    <p class="uk-margin-small uk-margin-remove-bottom">
                        <button type="submit" class="uk-button uk-button-primary uk-button-small adoration-btn">
                            Save Preferences
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
