<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Domain\Repositories\SchedulesRepository;
use AdorationScheduler\Domain\Repositories\SignupsRepository;
use AdorationScheduler\Domain\Repositories\SlotsRepository;
use AdorationScheduler\Domain\Services\OnboardingChecklist;
use AdorationScheduler\Services\AccessGateService;

if ( ! defined('ABSPATH') ) exit;

/**
 * Main plugin dashboard — the landing page when an admin clicks "Adoration
 * Scheduler" in the sidebar. Pulls together the handful of things a staff
 * member actually needs to check day to day (pending access requests,
 * hours about to go unfilled, open replacement requests) into one place,
 * each backed by repository methods that already existed for other
 * features (coverage alerts, the approval gate, replacement requests) —
 * this page is purely a new front end onto them, no new business logic.
 */
class DashboardPage {

    public function render(): void {
        if ( ! current_user_can('manage_options')
            && ! current_user_can('adoration_manage_schedules')
        ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        // "Hide this checklist" — dismiss the onboarding card and reload the
        // Dashboard with a clean query string. Handled before any output so
        // we can redirect. Mirrors SetupWizardPage's "Skip for now" handling.
        if ( isset($_GET['adoration_hide_checklist']) && $_GET['adoration_hide_checklist'] === '1' ) {
            check_admin_referer('adoration_dashboard_hide_checklist');
            OnboardingChecklist::dismiss();
            wp_safe_redirect( admin_url('admin.php?page=adoration_scheduler_dashboard') );
            exit;
        }

        $persons_repo   = new PersonsRepository();
        $schedules_repo = new SchedulesRepository();
        $signups_repo   = new SignupsRepository();
        $slots_repo     = new SlotsRepository();

        $pending_count  = (int) $persons_repo->count_by_approval_status(PersonsRepository::STATUS_PENDING);
        $approved_count = (int) $persons_repo->count_by_approval_status(PersonsRepository::STATUS_APPROVED);
        $total_people   = (int) $persons_repo->count_all_people();
        $gate_enabled   = AccessGateService::is_gate_enabled();

        $open_replacements = (int) $signups_repo->count_open_replacement_requests();

        $window_hours = 48;
        if (class_exists(CoverageAlertsSettingsPage::class)) {
            $opts = CoverageAlertsSettingsPage::get_options();
            $window_hours = (int) ($opts['window_hours'] ?? 48);
            if ($window_hours < 1) $window_hours = 48;
        }
        $urgent_slots = $slots_repo->find_open_urgent_slots($window_hours, false);
        $urgent_count = count($urgent_slots);
        $urgent_preview = array_slice($urgent_slots, 0, 5);

        $schedule_counts = $schedules_repo->admin_counts_by_status();
        $active_schedules = (int) ($schedule_counts['active'] ?? 0);

        $onboarding_steps = OnboardingChecklist::steps();
        $onboarding_incomplete = false;
        foreach ($onboarding_steps as $onboarding_step) {
            if (empty($onboarding_step['done'])) { $onboarding_incomplete = true; break; }
        }
        $show_onboarding_card = $onboarding_incomplete && ! OnboardingChecklist::is_dismissed();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Dashboard', 'adoration-scheduler'); ?></h1>
            <hr class="wp-header-end" />

            <?php if ($show_onboarding_card): ?>
                <div style="background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1; border-radius:4px; padding:16px 20px; margin-top:16px; max-width:1100px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:16px;">
                        <h2 style="margin:0;"><?php esc_html_e('Finish setting up', 'adoration-scheduler'); ?></h2>
                        <a href="<?php echo esc_url( wp_nonce_url(
                            admin_url('admin.php?page=adoration_scheduler_dashboard&adoration_hide_checklist=1'),
                            'adoration_dashboard_hide_checklist'
                        ) ); ?>" class="description">
                            <?php esc_html_e('Hide this checklist', 'adoration-scheduler'); ?>
                        </a>
                    </div>
                    <?php echo OnboardingChecklist::render_list($onboarding_steps, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-top:16px; max-width:1100px;">

                <?php echo $this->stat_card(
                    __('Access Requests', 'adoration-scheduler'),
                    (string)$pending_count,
                    $pending_count > 0 ? __('waiting for review', 'adoration-scheduler') : __('all caught up', 'adoration-scheduler'),
                    admin_url('admin.php?page=adoration_scheduler_people_access_requests'),
                    __('Review requests', 'adoration-scheduler'),
                    $pending_count > 0 ? '#dba617' : '#00a32a'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <?php echo $this->stat_card(
                    sprintf(
                        /* translators: %d: number of hours */
                        __('Coverage Gaps (next %dh)', 'adoration-scheduler'),
                        $window_hours
                    ),
                    (string)$urgent_count,
                    $urgent_count > 0 ? __('unfilled hours coming up', 'adoration-scheduler') : __('fully covered', 'adoration-scheduler'),
                    admin_url('admin.php?page=adoration_scheduler_signups'),
                    __('View signups', 'adoration-scheduler'),
                    $urgent_count > 0 ? '#d63638' : '#00a32a'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <?php echo $this->stat_card(
                    __('Open Replacement Requests', 'adoration-scheduler'),
                    (string)$open_replacements,
                    $open_replacements > 0 ? __('waiting for a substitute', 'adoration-scheduler') : __('none open', 'adoration-scheduler'),
                    admin_url('admin.php?page=adoration_scheduler_signups'),
                    __('View signups', 'adoration-scheduler'),
                    $open_replacements > 0 ? '#dba617' : '#00a32a'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <?php echo $this->stat_card(
                    __('Active Schedules', 'adoration-scheduler'),
                    (string)$active_schedules,
                    __('currently running', 'adoration-scheduler'),
                    admin_url('admin.php?page=adoration_scheduler_schedules'),
                    __('View schedules', 'adoration-scheduler'),
                    '#646970'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <?php echo $this->stat_card(
                    __('People', 'adoration-scheduler'),
                    (string)$total_people,
                    sprintf(
                        /* translators: %d: number of approved people */
                        __('%d approved', 'adoration-scheduler'),
                        $approved_count
                    ),
                    admin_url('admin.php?page=adoration_scheduler_people'),
                    __('View people', 'adoration-scheduler'),
                    '#646970'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <?php echo $this->stat_card(
                    __('Coverage Report', 'adoration-scheduler'),
                    '',
                    __('hours served & fill rate', 'adoration-scheduler'),
                    admin_url('admin.php?page=adoration_scheduler_coverage_report'),
                    __('View report', 'adoration-scheduler'),
                    '#646970'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            </div>

            <?php if ( ! $gate_enabled && $pending_count === 0 ): ?>
                <p class="description" style="margin-top:16px;">
                    <?php
                    printf(
                        /* translators: %s: link to Access & Privacy settings */
                        esc_html__('The approval gate is off, so new sign-ups are approved automatically. Turn it on from %s if you want to review people before they get access.', 'adoration-scheduler'),
                        '<a href="' . esc_url(admin_url('admin.php?page=adoration_scheduler_access')) . '">' . esc_html__('Access & Privacy', 'adoration-scheduler') . '</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ( ! empty($urgent_preview)): ?>
                <h2 style="margin-top:32px;"><?php echo esc_html__('Coming up unfilled', 'adoration-scheduler'); ?></h2>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Date & Time', 'adoration-scheduler'); ?></th>
                            <th><?php echo esc_html__('Chapel', 'adoration-scheduler'); ?></th>
                            <th><?php echo esc_html__('Schedule', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($urgent_preview as $slot): ?>
                            <?php
                            $start_raw = (string)($slot['start_at'] ?? '');
                            $ts = $start_raw ? strtotime($start_raw) : false;
                            $when = $ts ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '—';
                            $chapel = (string)($slot['chapel_name'] ?? '—');
                            $schedule = (string)($slot['schedule_name'] ?? '—');
                            ?>
                            <tr>
                                <td><?php echo esc_html($when); ?></td>
                                <td><?php echo esc_html($chapel); ?></td>
                                <td><?php echo esc_html($schedule); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($urgent_count > count($urgent_preview)): ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %d: number of additional unfilled hours not shown */
                            esc_html__('+ %d more', 'adoration-scheduler'),
                            $urgent_count - count($urgent_preview)
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function stat_card(string $label, string $number, string $sub, string $url, string $link_label, string $accent): string {
        ob_start();
        ?>
        <div style="border:1px solid #ccd0d4; border-left:4px solid <?php echo esc_attr($accent); ?>; background:#fff; padding:16px;">
            <div class="description" style="margin:0 0 4px; text-transform:uppercase; font-size:11px; letter-spacing:.03em;"><?php echo esc_html($label); ?></div>
            <div style="font-size:28px; font-weight:600; line-height:1.2;"><?php echo esc_html($number); ?></div>
            <div class="description" style="margin:2px 0 10px;"><?php echo esc_html($sub); ?></div>
            <a href="<?php echo esc_url($url); ?>" class="button button-small"><?php echo esc_html($link_label); ?></a>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
