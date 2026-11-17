<?php
namespace AdorationScheduler\Services;

use AdorationScheduler\Admin\Pages\SmsSettingsPage;

if (!defined('ABSPATH')) exit;

/** Central policy and presentation for A2P-compliant SMS consent. */
class SmsConsentService
{
    public const DISCLOSURE_VERSION = '2026-11-17-v1';
    public const SOURCE_PUBLIC_SIGNUP = 'public_signup';
    public const SOURCE_REMINDER_PREFERENCES = 'reminder_preferences';
    public const SOURCE_TWILIO_STOP = 'twilio_stop';
    private static bool $styles_printed = false;

    public static function has_valid_consent(array $person): bool
    {
        return !empty($person['sms_reminder_opt_in'])
            && trim((string)($person['sms_consent_at'] ?? '')) !== ''
            && trim((string)($person['sms_consent_version'] ?? '')) !== '';
    }

    public static function render_checkbox(string $id, bool $checked = false): void
    {
        if (!SmsSettingsPage::is_configured()) return;

        $o = SmsSettingsPage::get_options();
        $organization = trim((string)$o['consent_organization_name']);
        $privacy_url = trim((string)$o['privacy_policy_url']);
        $terms_url = trim((string)$o['sms_terms_url']);
        if (!self::$styles_printed) {
            self::$styles_printed = true;
            ?>
            <style>
                .adoration-sms-consent {
                    width: 100%;
                    margin: 12px 0 !important;
                    text-align: left !important;
                    box-sizing: border-box;
                }
                .adoration-sms-consent .adoration-sms-consent-label {
                    display: grid !important;
                    grid-template-columns: 20px minmax(0, 1fr) !important;
                    align-items: start !important;
                    gap: 10px !important;
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    color: inherit !important;
                    font: inherit !important;
                    line-height: 1.4 !important;
                    letter-spacing: normal !important;
                    text-align: left !important;
                    text-transform: none !important;
                    box-sizing: border-box;
                }
                .adoration-sms-consent .adoration-sms-consent-checkbox {
                    margin: 2px 0 0 !important;
                }
                .adoration-sms-consent .adoration-sms-consent-copy {
                    display: block !important;
                    min-width: 0 !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    font: inherit !important;
                    letter-spacing: normal !important;
                    text-transform: none !important;
                }
                .adoration-sms-consent .adoration-sms-consent-title {
                    display: block !important;
                    margin: 0 0 4px !important;
                    color: inherit !important;
                    font-family: inherit !important;
                    font-size: 15px !important;
                    font-style: normal !important;
                    font-weight: 600 !important;
                    line-height: 1.4 !important;
                    letter-spacing: normal !important;
                    text-transform: none !important;
                }
                .adoration-sms-consent .adoration-sms-consent-details {
                    display: block !important;
                    margin: 0 !important;
                    color: inherit !important;
                    font-family: inherit !important;
                    font-size: 13px !important;
                    font-style: normal !important;
                    font-weight: 400 !important;
                    line-height: 1.5 !important;
                    letter-spacing: normal !important;
                    opacity: .78 !important;
                    text-transform: none !important;
                    white-space: normal !important;
                    overflow-wrap: anywhere;
                }
                .adoration-sms-consent .adoration-sms-consent-details a {
                    font: inherit !important;
                    letter-spacing: normal !important;
                    text-transform: none !important;
                }
            </style>
            <?php
        }
        ?>
        <div class="adoration-sms-consent uk-margin-small-top">
            <label for="<?php echo esc_attr($id); ?>" class="adoration-sms-consent-label">
                <input type="checkbox" class="uk-checkbox adoration-sms-consent-checkbox" name="sms_consent" id="<?php echo esc_attr($id); ?>" value="1" <?php checked($checked); ?> />
                <span class="adoration-sms-consent-copy">
                    <strong class="adoration-sms-consent-title"><?php echo esc_html(sprintf(__('I agree to receive SMS messages from %s.', 'adoration-scheduler'), $organization)); ?></strong>
                    <span class="adoration-sms-consent-details">
                        <?php esc_html_e('These messages may include Eucharistic Adoration scheduling confirmations, reminders, schedule changes, and volunteer coverage requests. Message frequency varies. Message and data rates may apply. Reply STOP to unsubscribe or HELP for assistance. Consent is not required to register for Adoration.', 'adoration-scheduler'); ?>
                        <?php if ($privacy_url !== ''): ?>
                            <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy Policy', 'adoration-scheduler'); ?></a>
                        <?php endif; ?>
                        <?php if ($terms_url !== ''): ?>
                            <?php echo $privacy_url !== '' ? ' | ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed separator. ?>
                            <a href="<?php echo esc_url($terms_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('SMS Terms and Conditions', 'adoration-scheduler'); ?></a>
                        <?php endif; ?>
                    </span>
                </span>
            </label>
        </div>
        <?php
    }
}
