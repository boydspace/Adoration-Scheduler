<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Services\OnboardingChecklist;

if ( ! defined('ABSPATH') ) exit;

/**
 * SetupWizardPage
 *
 * Full-page first-run wizard, shown automatically once via a redirect right
 * after activation (see Plugin::activate() + the admin_init redirect check
 * in Plugin.php) — never shown again automatically after that, whether the
 * admin finishes it or skips it. Anyone who skips it, or wants to revisit
 * unfinished steps later, sees the same checklist as a persistent card on
 * the Dashboard (DashboardPage::render() — both use
 * OnboardingChecklist::steps()/render_list() so the two never drift apart).
 *
 * Hidden from the sidebar like every other page in the consolidated admin
 * menu (see Menu.php) — reachable only via the activation redirect or a
 * direct link from the Dashboard card.
 */
class SetupWizardPage {

    public function render(): void {
        if ( ! current_user_can('manage_options') && ! current_user_can('adoration_manage_schedules') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        // "Skip for now" — dismiss and go straight to the Dashboard. Handled
        // here (before any output) so we can redirect.
        if ( isset($_GET['adoration_skip']) && $_GET['adoration_skip'] === '1' ) {
            check_admin_referer('adoration_setup_wizard_skip');
            OnboardingChecklist::dismiss();
            wp_safe_redirect( admin_url('admin.php?page=adoration_scheduler_dashboard') );
            exit;
        }

        $steps = OnboardingChecklist::steps();
        $all_done = true;
        foreach ($steps as $step) {
            if (empty($step['done'])) { $all_done = false; break; }
        }

        $skip_url = wp_nonce_url(
            admin_url('admin.php?page=adoration_scheduler_setup_wizard&adoration_skip=1'),
            'adoration_setup_wizard_skip'
        );
        $dashboard_url = admin_url('admin.php?page=adoration_scheduler_dashboard');

        ?>
        <div class="wrap" style="max-width:720px; margin-top:24px;">
            <div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:32px 36px;">
                <h1 style="margin-top:0;"><?php esc_html_e('Welcome to Adoration Scheduler', 'adoration-scheduler'); ?></h1>
                <p class="description" style="font-size:14px;">
                    <?php esc_html_e('A few things to set up before your first chapel hour goes live. This only appears once — you can always pick up where you left off from a checklist on the Dashboard.', 'adoration-scheduler'); ?>
                </p>

                <div style="margin:24px 0;">
                    <?php echo OnboardingChecklist::render_list($steps, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>

                <?php if ($all_done): ?>
                    <p style="background:#f0f6fc; border:1px solid #c5d9ed; border-radius:4px; padding:12px 16px;">
                        <?php esc_html_e('You\'re all set. Your schedule is live and ready for sign-ups.', 'adoration-scheduler'); ?>
                    </p>
                <?php endif; ?>

                <div style="display:flex; align-items:center; gap:16px; margin-top:8px;">
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="button button-primary">
                        <?php echo $all_done
                            ? esc_html__('Go to Dashboard', 'adoration-scheduler')
                            : esc_html__('Continue to Dashboard', 'adoration-scheduler'); ?>
                    </a>
                    <?php if (!$all_done): ?>
                        <a href="<?php echo esc_url($skip_url); ?>">
                            <?php esc_html_e('Skip for now', 'adoration-scheduler'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
