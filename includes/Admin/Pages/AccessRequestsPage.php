<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\PersonsRepository;
use AdorationScheduler\Services\AccessGateService;

if ( ! defined('ABSPATH') ) exit;

/**
 * "Access Requests" — a focused queue for new-registration review, split
 * out from the dense All People table specifically so Accept/Reject are
 * unmissable (big buttons, one card per person) rather than small
 * row-action links a busy admin can skim right past.
 *
 * Reuses the exact same backend as the People list's row/bulk actions —
 * PeopleAdminActionsService::handle_set_approval() via the
 * `adoration_set_person_approval` admin_post action — this page is purely
 * a friendlier front end onto logic that already existed.
 */
class AccessRequestsPage {

    public function render(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $repo = new PersonsRepository();

        // Oldest request first (FIFO) — whoever has been waiting longest
        // shows up at the top, same convention as a support ticket queue.
        $pending = $repo->list_all_people_with_stats(200, 0, '', PersonsRepository::STATUS_PENDING);
        usort($pending, function ($a, $b) {
            $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
            return $ta <=> $tb;
        });

        $back_url = admin_url('admin.php?page=adoration_scheduler_people');
        $gate_enabled = AccessGateService::is_gate_enabled();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Access Requests', 'adoration-scheduler'); ?></h1>
            <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                <?php echo esc_html__('Back to People', 'adoration-scheduler'); ?>
            </a>
            <hr class="wp-header-end" />
            <?php \AdorationScheduler\Admin\Menu::render_people_tabs('adoration_scheduler_people_access_requests'); ?>

            <?php if ( ! $gate_enabled ): ?>
                <div class="notice notice-info" style="margin-top:12px;">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to Access & Privacy settings */
                            esc_html__('The approval gate is currently off, so new sign-ups are approved automatically and this queue should normally stay empty. Turn it on from %s if you want to review people before they get access.', 'adoration-scheduler'),
                            '<a href="' . esc_url(admin_url('admin.php?page=adoration_scheduler_access')) . '">' . esc_html__('Access & Privacy', 'adoration-scheduler') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (empty($pending)): ?>
                <p style="margin-top:16px;"><?php echo esc_html__('No pending access requests right now.', 'adoration-scheduler'); ?></p>
            <?php else: ?>
                <div style="margin-top:16px; max-width:900px;">
                    <?php foreach ($pending as $p): ?>
                        <?php echo $this->render_card($p); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_card(array $p): string {
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return '';

        $title = trim((string)($p['title'] ?? ''));
        $first = trim((string)($p['first_name'] ?? ''));
        $last  = trim((string)($p['last_name'] ?? ''));
        $name  = trim(trim($title . ' ' . $first) . ' ' . $last);
        if ($name === '') $name = '(unnamed)';

        $parish = trim((string)($p['parish'] ?? ''));
        $email  = trim((string)($p['email'] ?? ''));
        $phone  = trim((string)($p['phone'] ?? ''));

        $created_raw = (string)($p['created_at'] ?? '');
        $ts = $created_raw ? strtotime($created_raw) : false;
        $requested_lbl = $ts ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '';

        $view_url = add_query_arg([
            'page'      => 'adoration_scheduler_people',
            'action'    => 'view',
            'person_id' => $id,
        ], admin_url('admin.php'));

        $accept_nonce = wp_nonce_field('adoration_set_person_approval_' . $id, '_wpnonce', true, false);
        $reject_nonce = $accept_nonce; // same nonce action, both forms target the same person id

        ob_start();
        ?>
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:14px 16px; border:1px solid #ccd0d4; background:#fff; margin-bottom:10px;">
            <div>
                <strong style="font-size:14px;"><a href="<?php echo esc_url($view_url); ?>"><?php echo esc_html($name); ?></a></strong>
                <?php if ($parish !== ''): ?>
                    <div class="description"><?php echo esc_html($parish); ?></div>
                <?php endif; ?>
                <div class="description">
                    <?php echo esc_html($email !== '' ? $email : '—'); ?>
                    <?php if ($phone !== ''): ?> &middot; <?php echo esc_html($phone); ?><?php endif; ?>
                </div>
                <?php if ($requested_lbl !== ''): ?>
                    <div class="description" style="margin-top:4px;">
                        <?php
                        printf(
                            /* translators: %s: date/time the request was submitted */
                            esc_html__('Requested %s', 'adoration-scheduler'),
                            esc_html($requested_lbl)
                        );
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex; gap:8px; flex-shrink:0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <?php echo $accept_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <input type="hidden" name="action" value="adoration_set_person_approval" />
                    <input type="hidden" name="page" value="adoration_scheduler_people_access_requests" />
                    <input type="hidden" name="person_id" value="<?php echo (int)$id; ?>" />
                    <input type="hidden" name="approval_status" value="<?php echo esc_attr(PersonsRepository::STATUS_APPROVED); ?>" />
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html__('Accept', 'adoration-scheduler'); ?>
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <?php echo $reject_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <input type="hidden" name="action" value="adoration_set_person_approval" />
                    <input type="hidden" name="page" value="adoration_scheduler_people_access_requests" />
                    <input type="hidden" name="person_id" value="<?php echo (int)$id; ?>" />
                    <input type="hidden" name="approval_status" value="<?php echo esc_attr(PersonsRepository::STATUS_REJECTED); ?>" />
                    <button type="submit" class="button"
                        onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Reject this person\'s access request?', 'adoration-scheduler'))); ?>);">
                        <?php echo esc_html__('Reject', 'adoration-scheduler'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
