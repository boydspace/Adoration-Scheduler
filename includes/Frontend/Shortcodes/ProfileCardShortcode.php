<?php
namespace AdorationScheduler\Frontend\Shortcodes;

use AdorationScheduler\Frontend\Shortcodes\Concerns\PersonDashboardTrait;
use AdorationScheduler\Frontend\UikitLoader;
use AdorationScheduler\Frontend\SharedStyles;
use AdorationScheduler\Frontend\Handlers\UpdateContactInfoHandler;
use AdorationScheduler\Frontend\Handlers\PasswordSetHandler;
use AdorationScheduler\Frontend\Handlers\DataExportHandler;
use AdorationScheduler\Services\AccountDeletionService;
use AdorationScheduler\Domain\Repositories\PersonsRepository;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Shortcode: [adoration_profile_card redirect="/my-adoration/"]
 *
 * "Signed in as X" + editable contact info (name/phone/substitute opt-in)
 * + optional password management + log out. One piece of the modular
 * family that replaced the retired [adoration_my_adoration] shortcode.
 */
class ProfileCardShortcode
{
    use PersonDashboardTrait;

    public static function register(): void
    {
        add_shortcode('adoration_profile_card', [__CLASS__, 'render']);
    }

    public static function render($atts = []): string
    {
        $atts = shortcode_atts([
            'redirect' => '/my-adoration/',
            'card'     => '0',
        ], (array)$atts, 'adoration_profile_card');

        $guard = self::guard_and_get_person((string)$atts['redirect']);
        if ($guard['html'] !== null) return $guard['html'];
        $person = $guard['person'];
        $viewing_as_admin_match = $guard['viewing_as_admin_match'];

        $uid = self::new_uid('asprofile');
        $redirect_url = self::current_url();
        $has_uikit_js = self::has_uikit_js();
        $card         = self::wants_card($atts['card']);

        $person_id      = (int)($person['id'] ?? 0);
        $contact_nonce  = ($person_id > 0) ? wp_create_nonce('adoration_update_contact_' . $person_id) : '';
        $password_nonce = ($person_id > 0) ? wp_create_nonce('adoration_set_password_' . $person_id) : '';
        $logout_nonce   = wp_create_nonce('adoration_magic_logout');
        $export_nonce   = ($person_id > 0) ? wp_create_nonce(DataExportHandler::ACTION . '_' . $person_id) : '';
        $delete_nonce   = ($person_id > 0) ? wp_create_nonce(AccountDeletionService::ACTION . '_' . $person_id) : '';
        $persons_repo   = new PersonsRepository();
        $has_password   = $persons_repo->has_password($person);
        $display_name   = $persons_repo->full_name_with_title($person);
        $parish_val = trim((string)($person['parish'] ?? ''));

        $btn_edit_class   = 'uk-button uk-button-default uk-button-small adoration-btn-secondary';
        $btn_save_class   = 'uk-button uk-button-primary adoration-btn';
        $btn_cancel_class = 'uk-button uk-button-default adoration-btn-secondary';
        $input_class      = 'uk-input';
        $form_class       = 'uk-form-stacked';

        ob_start();
        ?>
        <div class="adoration-widget adoration-profile-card uk-width-1-1" id="<?php echo esc_attr($uid); ?>">
            <?php echo UikitLoader::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo SharedStyles::print_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="<?php echo esc_attr(self::card_class($card)); ?>">
                <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
                    <div>
                        <div class="uk-text-large" style="font-weight:600;">
                            <?php echo esc_html($display_name); ?>
                        </div>
                        <?php if ($parish_val !== ''): ?>
                            <div class="uk-text-meta as-muted uk-margin-remove-top">
                                <?php echo esc_html($parish_val); ?>
                            </div>
                        <?php endif; ?>
                        <div class="uk-text-meta as-muted uk-margin-remove-top">
                            Signed in as <?php echo esc_html($person['email'] ?? ''); ?>
                        </div>
                    </div>

                    <div class="uk-margin-small-top">
                        <?php if ($person_id > 0): ?>
                            <button
                                type="button"
                                class="<?php echo esc_attr($btn_edit_class); ?> as-open-contact"
                                data-as-open-contact="1"
                                <?php if ($has_uikit_js): ?>
                                    uk-toggle="target: #<?php echo esc_attr($uid); ?>_contact_modal"
                                <?php endif; ?>
                            >
                                Edit Contact Info
                            </button>
                            <button
                                type="button"
                                class="<?php echo esc_attr($btn_edit_class); ?> as-open-password"
                                data-as-open-password="1"
                                <?php if ($has_uikit_js): ?>
                                    uk-toggle="target: #<?php echo esc_attr($uid); ?>_password_modal"
                                <?php endif; ?>
                            >
                                <?php echo $has_password ? 'Change Password' : 'Set a Password'; ?>
                            </button>
                            <?php if (!$viewing_as_admin_match): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(DataExportHandler::ACTION); ?>" />
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($export_nonce); ?>" />
                                    <button type="submit" class="<?php echo esc_attr($btn_edit_class); ?>">
                                        Download My Data
                                    </button>
                                </form>
                                <button
                                    type="button"
                                    class="<?php echo esc_attr($btn_edit_class); ?> as-open-delete"
                                    data-as-open-delete="1"
                                    <?php if ($has_uikit_js): ?>
                                        uk-toggle="target: #<?php echo esc_attr($uid); ?>_delete_modal"
                                    <?php endif; ?>
                                >
                                    Delete My Account
                                </button>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
                                    <input type="hidden" name="action" value="adoration_magic_logout" />
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($logout_nonce); ?>" />
                                    <button type="submit" class="<?php echo esc_attr($btn_cancel_class); ?>">
                                        Log out
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Contact Info Modal -->
            <?php if ($has_uikit_js): ?>
                <div id="<?php echo esc_attr($uid); ?>_contact_modal" uk-modal>
                    <div class="uk-modal-dialog uk-modal-body">
                        <h3 class="uk-modal-title">Update Contact Info</h3>

                        <p class="uk-text-meta as-muted uk-margin-small">
                            You can update your name and phone number here. Email changes must be handled by the parish office.
                        </p>

                        <form method="post"
                              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              class="<?php echo esc_attr($form_class); ?> uk-margin"
                              style="margin:0;">
                            <input type="hidden" name="action" value="<?php echo esc_attr(UpdateContactInfoHandler::ACTION); ?>" />
                            <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($contact_nonce); ?>" />

                            <div class="uk-grid-small" uk-grid>
                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_title">Title</label>
                                    <div class="uk-form-controls">
                                        <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_title" type="text" name="title"
                                            placeholder="Father, Deacon, Bishop, Msgr., etc."
                                            value="<?php echo esc_attr((string)($person['title'] ?? '')); ?>" />
                                    </div>
                                </div>

                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_first">First Name</label>
                                    <div class="uk-form-controls">
                                        <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_first" type="text" name="first_name"
                                            value="<?php echo esc_attr((string)($person['first_name'] ?? '')); ?>" />
                                    </div>
                                </div>

                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_last">Last Name</label>
                                    <div class="uk-form-controls">
                                        <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_last" type="text" name="last_name"
                                            value="<?php echo esc_attr((string)($person['last_name'] ?? '')); ?>" />
                                    </div>
                                </div>

                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_parish">Parish</label>
                                    <div class="uk-form-controls">
                                        <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_parish" type="text" name="parish"
                                            placeholder="Immaculate Heart of Mary Parish"
                                            value="<?php echo esc_attr((string)($person['parish'] ?? '')); ?>" />
                                    </div>
                                </div>

                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_phone">Phone Number</label>
                                    <div class="uk-form-controls">
                                        <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_phone" type="text" name="phone"
                                            placeholder="(555) 555-5555"
                                            value="<?php echo esc_attr((string)($person['phone'] ?? '')); ?>" />
                                    </div>
                                </div>

                                <div class="uk-width-1-2@s">
                                    <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_email">Email</label>
                                    <div class="uk-form-controls">
                                        <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_email" type="email"
                                            value="<?php echo esc_attr((string)($person['email'] ?? '')); ?>" readonly disabled />
                                    </div>
                                </div>
                            </div>

                            <p class="uk-text-meta uk-margin-small-top as-muted">
                                Email changes must be handled by the parish office.
                            </p>

                            <label class="uk-margin-small-top" style="display:flex;align-items:flex-start;gap:6px;">
                                <input type="checkbox" class="uk-checkbox" name="substitute_opt_in" value="1"
                                    <?php checked($persons_repo->is_substitute_opt_in($person)); ?> />
                                <span class="uk-text-small">
                                    I'm willing to be contacted as a substitute for open Adoration hours.
                                </span>
                            </label>

                            <p class="uk-text-right uk-margin-top">
                                <button type="button" class="<?php echo esc_attr($btn_cancel_class); ?> uk-modal-close">
                                    Cancel
                                </button>
                                <button type="submit" class="<?php echo esc_attr($btn_save_class); ?>">
                                    Save
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div id="<?php echo esc_attr($uid); ?>_contact_modal" class="as-modal" aria-hidden="true">
                    <div class="as-modal__backdrop" data-as-close-contact="1"></div>
                    <div class="as-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>_contact_title">
                        <div class="as-modal__header">
                            <h3 class="as-modal__title" id="<?php echo esc_attr($uid); ?>_contact_title">Update Contact Info</h3>
                            <button type="button" class="as-modal__close" aria-label="Close" data-as-close-contact="1">×</button>
                        </div>
                        <div class="as-modal__body">
                            <p class="as-muted">
                                You can update your name and phone number here. Email changes must be handled by the parish office.
                            </p>

                            <form method="post"
                                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  class="<?php echo esc_attr($form_class); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(UpdateContactInfoHandler::ACTION); ?>" />
                                <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($contact_nonce); ?>" />

                                <div class="uk-grid-small" uk-grid>
                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_title_fallback">Title</label>
                                        <div class="uk-form-controls">
                                            <input id="<?php echo esc_attr($uid); ?>_title_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="title"
                                                   placeholder="Father, Deacon, Bishop, Msgr., etc."
                                                   value="<?php echo esc_attr((string)($person['title'] ?? '')); ?>">
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_first_fallback">First Name</label>
                                        <div class="uk-form-controls">
                                            <input id="<?php echo esc_attr($uid); ?>_first_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="first_name"
                                                   value="<?php echo esc_attr((string)($person['first_name'] ?? '')); ?>">
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_last_fallback">Last Name</label>
                                        <div class="uk-form-controls">
                                            <input id="<?php echo esc_attr($uid); ?>_last_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="last_name"
                                                   value="<?php echo esc_attr((string)($person['last_name'] ?? '')); ?>">
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_parish_fallback">Parish</label>
                                        <div class="uk-form-controls">
                                            <input id="<?php echo esc_attr($uid); ?>_parish_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="parish"
                                                   placeholder="Immaculate Heart of Mary Parish"
                                                   value="<?php echo esc_attr((string)($person['parish'] ?? '')); ?>">
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_phone_fallback">Phone Number</label>
                                        <div class="uk-form-controls">
                                            <input id="<?php echo esc_attr($uid); ?>_phone_fallback" class="<?php echo esc_attr($input_class); ?>" type="text" name="phone"
                                                   placeholder="(555) 555-5555"
                                                   value="<?php echo esc_attr((string)($person['phone'] ?? '')); ?>">
                                        </div>
                                    </div>

                                    <div class="uk-width-1-2@s">
                                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_email_fallback">Email</label>
                                        <div class="uk-form-controls">
                                            <input id="<?php echo esc_attr($uid); ?>_email_fallback" class="<?php echo esc_attr($input_class); ?>" type="email"
                                                   value="<?php echo esc_attr((string)($person['email'] ?? '')); ?>" readonly disabled>
                                        </div>
                                    </div>
                                </div>

                                <label class="uk-margin-small-top" style="display:flex;align-items:flex-start;gap:6px;">
                                    <input type="checkbox" class="uk-checkbox" name="substitute_opt_in" value="1"
                                        <?php checked($persons_repo->is_substitute_opt_in($person)); ?> />
                                    <span class="uk-text-small">
                                        I'm willing to be contacted as a substitute for open Adoration hours.
                                    </span>
                                </label>

                                <div class="uk-flex uk-flex-right uk-margin-top uk-grid-small" uk-grid>
                                    <div>
                                        <button type="button" class="<?php echo esc_attr($btn_cancel_class); ?>" data-as-close-contact="1">Cancel</button>
                                    </div>
                                    <div>
                                        <button type="submit" class="<?php echo esc_attr($btn_save_class); ?>">Save</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Password Modal -->
            <?php
            $password_form_inner = function() use ($uid, $password_nonce, $redirect_url, $input_class, $has_password) {
                ?>
                <p class="uk-text-meta as-muted uk-margin-small">
                    <?php if ($has_password): ?>
                        You have a password set. Enter a new one below to change it, or use the emailed sign-in link if you've forgotten it.
                    <?php else: ?>
                        Optional: set a password so you can sign in without waiting for an email each time. You can always fall back to the emailed sign-in link.
                    <?php endif; ?>
                </p>

                <form method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      class="uk-form-stacked uk-margin"
                      style="margin:0;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(PasswordSetHandler::ACTION); ?>" />
                    <input type="hidden" name="mode" value="set" />
                    <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($password_nonce); ?>" />

                    <div class="uk-margin-small">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_new_password">New Password</label>
                        <div class="uk-form-controls">
                            <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_new_password"
                                type="password" name="new_password" autocomplete="new-password" minlength="8" required />
                        </div>
                    </div>

                    <div class="uk-margin-small">
                        <label class="uk-form-label" for="<?php echo esc_attr($uid); ?>_confirm_password">Confirm New Password</label>
                        <div class="uk-form-controls">
                            <input class="<?php echo esc_attr($input_class); ?>" id="<?php echo esc_attr($uid); ?>_confirm_password"
                                type="password" name="confirm_password" autocomplete="new-password" minlength="8" required />
                        </div>
                    </div>

                    <p class="uk-text-right uk-margin-top">
                        <button type="submit" class="uk-button uk-button-primary adoration-btn">Save Password</button>
                    </p>
                </form>

                <?php if ($has_password): ?>
                    <form method="post"
                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          style="margin:0;"
                          onsubmit="return window.confirm('Remove your password? You will need to use the emailed sign-in link to access your account.');">
                        <input type="hidden" name="action" value="<?php echo esc_attr(PasswordSetHandler::ACTION); ?>" />
                        <input type="hidden" name="mode" value="remove" />
                        <input type="hidden" name="return" value="<?php echo esc_attr($redirect_url); ?>" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($password_nonce); ?>" />

                        <p class="uk-text-right uk-margin-remove-top">
                            <button type="submit" class="uk-button uk-button-danger uk-button-small adoration-btn adoration-btn-danger">
                                Remove Password
                            </button>
                        </p>
                    </form>
                <?php endif; ?>
                <?php
            };
            ?>

            <?php if ($has_uikit_js): ?>
                <div id="<?php echo esc_attr($uid); ?>_password_modal" uk-modal>
                    <div class="uk-modal-dialog uk-modal-body">
                        <h3 class="uk-modal-title"><?php echo $has_password ? 'Change Password' : 'Set a Password'; ?></h3>
                        <?php $password_form_inner(); ?>
                    </div>
                </div>
            <?php else: ?>
                <div id="<?php echo esc_attr($uid); ?>_password_modal" class="as-modal" aria-hidden="true">
                    <div class="as-modal__backdrop" data-as-close-password="1"></div>
                    <div class="as-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>_password_title">
                        <div class="as-modal__header">
                            <h3 class="as-modal__title" id="<?php echo esc_attr($uid); ?>_password_title"><?php echo $has_password ? 'Change Password' : 'Set a Password'; ?></h3>
                            <button type="button" class="as-modal__close" aria-label="Close" data-as-close-password="1">×</button>
                        </div>
                        <div class="as-modal__body">
                            <?php $password_form_inner(); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Delete Account Modal -->
            <?php if (!$viewing_as_admin_match): ?>
                <?php
                $delete_form_inner = function() use ($uid, $delete_nonce) {
                    ?>
                    <p class="uk-text-meta as-muted uk-margin-small">
                        This permanently removes your name, email, and phone number from our records and cancels
                        any upcoming Adoration hours or standing commitments you have. Past hours you've already
                        served stay on the schedule so coverage history stays accurate, but they'll no longer be
                        linked to your name. This cannot be undone.
                    </p>

                    <form method="post"
                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          class="uk-form-stacked uk-margin"
                          style="margin:0;">
                        <input type="hidden" name="action" value="<?php echo esc_attr(AccountDeletionService::ACTION); ?>" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($delete_nonce); ?>" />

                        <label style="display:flex;align-items:flex-start;gap:6px;">
                            <input type="checkbox" class="uk-checkbox" name="confirm_delete" value="1" required />
                            <span class="uk-text-small">
                                I understand this permanently deletes my account and cannot be undone.
                            </span>
                        </label>

                        <p class="uk-text-right uk-margin-top">
                            <button type="submit" class="uk-button uk-button-danger adoration-btn adoration-btn-danger"
                                onclick="return window.confirm('Delete your account? This cannot be undone.');">
                                Delete My Account
                            </button>
                        </p>
                    </form>
                    <?php
                };
                ?>

                <?php if ($has_uikit_js): ?>
                    <div id="<?php echo esc_attr($uid); ?>_delete_modal" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body">
                            <h3 class="uk-modal-title">Delete My Account</h3>
                            <?php $delete_form_inner(); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="<?php echo esc_attr($uid); ?>_delete_modal" class="as-modal" aria-hidden="true">
                        <div class="as-modal__backdrop" data-as-close-delete="1"></div>
                        <div class="as-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>_delete_title">
                            <div class="as-modal__header">
                                <h3 class="as-modal__title" id="<?php echo esc_attr($uid); ?>_delete_title">Delete My Account</h3>
                                <button type="button" class="as-modal__close" aria-label="Close" data-as-close-delete="1">×</button>
                            </div>
                            <div class="as-modal__body">
                                <?php $delete_form_inner(); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <script>
            (function() {
                const root = document.getElementById(<?php echo json_encode($uid); ?>);
                if (!root) return;

                // --- Contact modal fallback wiring (only for .as-modal) ---
                const contactModalId = <?php echo json_encode($uid . '_contact_modal'); ?>;
                const contactModal   = document.getElementById(contactModalId);

                function openContactModal() {
                    if (!contactModal) return;
                    contactModal.classList.add('is-open');
                    contactModal.setAttribute('aria-hidden', 'false');
                    if (window.AdorationA11y) window.AdorationA11y.trap(contactModal);
                }
                function closeContactModal() {
                    if (!contactModal) return;
                    contactModal.classList.remove('is-open');
                    contactModal.setAttribute('aria-hidden', 'true');
                    if (window.AdorationA11y) window.AdorationA11y.release(contactModal);
                }

                if (contactModal && contactModal.classList.contains('as-modal')) {
                    root.querySelectorAll('[data-as-open-contact="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            openContactModal();
                        });
                    });

                    contactModal.querySelectorAll('[data-as-close-contact="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeContactModal();
                        });
                    });

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') closeContactModal();
                    });
                }

                // --- Password modal fallback wiring (only for .as-modal) ---
                const passwordModalId = <?php echo json_encode($uid . '_password_modal'); ?>;
                const passwordModal   = document.getElementById(passwordModalId);

                function openPasswordModal() {
                    if (!passwordModal) return;
                    passwordModal.classList.add('is-open');
                    passwordModal.setAttribute('aria-hidden', 'false');
                    if (window.AdorationA11y) window.AdorationA11y.trap(passwordModal);
                }
                function closePasswordModal() {
                    if (!passwordModal) return;
                    passwordModal.classList.remove('is-open');
                    passwordModal.setAttribute('aria-hidden', 'true');
                    if (window.AdorationA11y) window.AdorationA11y.release(passwordModal);
                }

                if (passwordModal && passwordModal.classList.contains('as-modal')) {
                    root.querySelectorAll('[data-as-open-password="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            openPasswordModal();
                        });
                    });

                    passwordModal.querySelectorAll('[data-as-close-password="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closePasswordModal();
                        });
                    });

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') closePasswordModal();
                    });
                }

                // --- Delete-account modal fallback wiring (only for .as-modal) ---
                const deleteModalId = <?php echo json_encode($uid . '_delete_modal'); ?>;
                const deleteModal   = document.getElementById(deleteModalId);

                function openDeleteModal() {
                    if (!deleteModal) return;
                    deleteModal.classList.add('is-open');
                    deleteModal.setAttribute('aria-hidden', 'false');
                    if (window.AdorationA11y) window.AdorationA11y.trap(deleteModal);
                }
                function closeDeleteModal() {
                    if (!deleteModal) return;
                    deleteModal.classList.remove('is-open');
                    deleteModal.setAttribute('aria-hidden', 'true');
                    if (window.AdorationA11y) window.AdorationA11y.release(deleteModal);
                }

                if (deleteModal && deleteModal.classList.contains('as-modal')) {
                    root.querySelectorAll('[data-as-open-delete="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            openDeleteModal();
                        });
                    });

                    deleteModal.querySelectorAll('[data-as-close-delete="1"]').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeDeleteModal();
                        });
                    });

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') closeDeleteModal();
                    });
                }
            })();
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
