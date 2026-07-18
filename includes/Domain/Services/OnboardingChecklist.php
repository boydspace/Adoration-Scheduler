<?php
namespace AdorationScheduler\Domain\Services;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * OnboardingChecklist
 *
 * Single source of truth for "is this install actually set up yet?" — used
 * by both SetupWizardPage (shown once, on first activation) and the
 * persistent checklist card on DashboardPage (shown afterward, for anyone
 * who skipped the wizard or wants to revisit it). Both render the exact
 * same steps() data so there's only one place that defines what "done"
 * means for each step.
 *
 * Deliberately computes completion LIVE from the real tables every time,
 * rather than storing "step N complete" flags anywhere — stored progress
 * flags drift (e.g. an admin could delete their only schedule after
 * checking it off, and a flag-based checklist would never notice).
 *
 * Every step maps to something genuinely unconfigured on a fresh install,
 * verified against the actual code paths rather than assumed:
 * - A chapel always exists after activation (Installer::ensure_main_chapel_exists()),
 *   so "create a chapel" isn't a real gap and isn't a step here.
 * - Schedules default to status='draft' (ScheduleCreationService), so a
 *   schedule can exist and still not be live — that's its own step.
 * - The approval gate defaults to off (AccessGateService::is_gate_enabled()),
 *   but every parish should make that call consciously at least once.
 */
class OnboardingChecklist
{
    private const OPT_DISMISSED = 'adoration_scheduler_onboarding_dismissed';

    /**
     * @return array<int, array{key:string, title:string, description:string, done:bool, url:string}>
     */
    public static function steps(): array
    {
        global $wpdb;

        $schedules_repo = new SchedulesRepository();
        $schedules = $schedules_repo->list_all(200, false);

        $schedule_count = count($schedules);
        $active_count   = 0;
        foreach ($schedules as $s) {
            if ((string)($s['status'] ?? '') === 'active') $active_count++;
        }

        $segments_table = $wpdb->prefix . 'adoration_segments';
        $hours_configured = false;
        if (self::table_exists($segments_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $hours_configured = ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$segments_table}")) > 0;
        }

        $privacy_reviewed = get_option('adoration_scheduler_access_options', null) !== null;

        return [
            [
                'key'         => 'create_schedule',
                'title'       => __('Create your first schedule', 'adoration-scheduler'),
                'description' => __('A schedule is the container for a chapel\'s hours — perpetual (weekly recurring), one-time/event dates, or monthly (e.g. First Friday).', 'adoration-scheduler'),
                'done'        => $schedule_count > 0,
                'url'         => admin_url('admin.php?page=adoration_scheduler_add_new'),
            ],
            [
                'key'         => 'configure_hours',
                'title'       => __('Add hours to your schedule', 'adoration-scheduler'),
                'description' => __('Weekly Hours, event dates, or Monthly Occurrence — whichever matches how your chapel actually runs.', 'adoration-scheduler'),
                'done'        => $hours_configured,
                'url'         => admin_url('admin.php?page=adoration_scheduler_schedules'),
            ],
            [
                'key'         => 'activate_schedule',
                'title'       => __('Turn your schedule on', 'adoration-scheduler'),
                'description' => __('New schedules start as Draft on purpose, so hours aren\'t public before you\'re ready — switch it to Active once it looks right.', 'adoration-scheduler'),
                'done'        => $active_count > 0,
                'url'         => admin_url('admin.php?page=adoration_scheduler_schedules'),
            ],
            [
                'key'         => 'review_privacy',
                'title'       => __('Decide on the approval gate', 'adoration-scheduler'),
                'description' => __('Choose whether new sign-ups need admin approval before they can see or claim hours — off by default (open access).', 'adoration-scheduler'),
                'done'        => $privacy_reviewed,
                'url'         => admin_url('admin.php?page=adoration_scheduler_access'),
            ],
        ];
    }

    public static function is_complete(): bool
    {
        foreach (self::steps() as $step) {
            if (empty($step['done'])) return false;
        }
        return true;
    }

    public static function is_dismissed(): bool
    {
        return (bool) get_option(self::OPT_DISMISSED, false);
    }

    public static function dismiss(): void
    {
        update_option(self::OPT_DISMISSED, 1, false);
    }

    /**
     * Shared markup for both SetupWizardPage (full-page, $compact=false) and
     * the Dashboard card (compact, $compact=true) — one place renders the
     * list so the two contexts can never drift out of sync with each other.
     */
    public static function render_list(array $steps, bool $compact = false): string
    {
        ob_start();
        $item_padding = $compact ? '10px 0' : '16px 0';
        ?>
        <ul style="list-style:none; margin:0; padding:0;">
            <?php foreach ($steps as $step): ?>
                <li style="display:flex; align-items:flex-start; gap:12px; padding:<?php echo esc_attr($item_padding); ?>; border-bottom:1px solid #f0f0f1;">
                    <span style="flex:0 0 auto; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; margin-top:2px; <?php echo !empty($step['done']) ? 'background:#00a32a; color:#fff;' : 'background:#f0f0f1; color:#787c82; border:1px solid #dcdcde;'; ?>">
                        <?php echo !empty($step['done']) ? '&#10003;' : ''; ?>
                    </span>
                    <span style="flex:1 1 auto;">
                        <strong style="<?php echo !empty($step['done']) ? 'text-decoration:line-through; color:#787c82;' : ''; ?>">
                            <?php echo esc_html((string)($step['title'] ?? '')); ?>
                        </strong>
                        <?php if (!$compact): ?>
                            <p class="description" style="margin:4px 0 0;"><?php echo esc_html((string)($step['description'] ?? '')); ?></p>
                        <?php endif; ?>
                    </span>
                    <?php if (empty($step['done'])): ?>
                        <a href="<?php echo esc_url((string)($step['url'] ?? '')); ?>" class="button button-small" style="flex:0 0 auto;">
                            <?php esc_html_e('Go', 'adoration-scheduler'); ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return (string) ob_get_clean();
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }
}
