<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Services\PeopleImportExportService;
use AdorationScheduler\Utils\XlsxWriter;

class PeopleImportExportPage {

    public function render(): void {
        if ( ! current_user_can('adoration_manage_people') && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $preview_token = isset($_GET['preview_token']) ? sanitize_text_field(wp_unslash((string)$_GET['preview_token'])) : '';
        $preview_rows = $preview_token !== '' ? PeopleImportExportService::get_preview($preview_token) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import / Export', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_people_tabs('adoration_scheduler_people_import_export'); ?>

            <?php if ($preview_rows !== null): ?>
                <?php $this->render_preview($preview_token, $preview_rows); ?>
            <?php else: ?>
                <?php if ($preview_token !== ''): ?>
                    <div class="notice notice-warning"><p><?php esc_html_e('That import session has expired. Please upload the file again.', 'adoration-scheduler'); ?></p></div>
                <?php endif; ?>
                <?php $this->render_export_section(); ?>
                <?php $this->render_import_section(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_export_section(): void {
        ?>
        <div class="card" style="max-width:700px; padding:16px 20px; margin-top:16px;">
            <h2><?php esc_html_e('Export', 'adoration-scheduler'); ?></h2>
            <p><?php esc_html_e('Download the full roster: title, name, email, phone, parish, approval status, substitute opt-in.', 'adoration-scheduler'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(PeopleImportExportService::ACTION_EXPORT_CSV); ?>" />
                <?php wp_nonce_field('adoration_people_export'); ?>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Export CSV', 'adoration-scheduler'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <input type="hidden" name="action" value="<?php echo esc_attr(PeopleImportExportService::ACTION_EXPORT_XLSX); ?>" />
                <?php wp_nonce_field('adoration_people_export'); ?>
                <button type="submit" class="button button-secondary" <?php disabled(!XlsxWriter::is_available()); ?>><?php esc_html_e('Export XLSX', 'adoration-scheduler'); ?></button>
            </form>
            <?php if (!XlsxWriter::is_available()): ?>
                <p class="description"><?php esc_html_e('XLSX export/import needs the PHP zip extension, which isn\'t available on this server. CSV works either way.', 'adoration-scheduler'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_import_section(): void {
        ?>
        <div class="card" style="max-width:700px; padding:16px 20px; margin-top:16px;">
            <h2><?php esc_html_e('Import', 'adoration-scheduler'); ?></h2>
            <p>
                <?php esc_html_e('Upload a .csv or .xlsx file with columns: Title, First Name, Last Name, Email, Phone, Parish (Title and Parish are optional). A file exported from this page works directly — extra columns are ignored.', 'adoration-scheduler'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Matching is by email: a new email creates a new person; an existing email with the same name fills in blank fields; an existing email with a different name is flagged as a conflict and skipped, never silently overwritten. Nothing changes until you review the parsed rows and confirm.', 'adoration-scheduler'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo esc_attr(PeopleImportExportService::ACTION_IMPORT_START); ?>" />
                <?php wp_nonce_field('adoration_people_import_start'); ?>
                <p>
                    <input type="file" name="import_file" accept=".csv,.xlsx" required />
                </p>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Upload & Review', 'adoration-scheduler'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_preview(string $token, array $rows): void {
        $counts = ['new' => 0, 'update' => 0, 'conflict' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            if (isset($counts[$status])) $counts[$status]++;
        }

        $importable = $counts['new'] + $counts['update'];
        ?>
        <div class="card" style="max-width:100%; padding:16px 20px; margin-top:16px;">
            <h2><?php esc_html_e('Review before importing', 'adoration-scheduler'); ?></h2>
            <p>
                <?php echo esc_html(sprintf(
                    /* translators: 1: new count, 2: update count, 3: conflict count, 4: error count */
                    __('%1$d new, %2$d to update, %3$d conflicts, %4$d errors. Only "new" and "update" rows will be imported.', 'adoration-scheduler'),
                    $counts['new'], $counts['update'], $counts['conflict'], $counts['error']
                )); ?>
            </p>

            <div style="overflow-x:auto;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Line', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Title', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('First', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Last', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Email', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Phone', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Parish', 'adoration-scheduler'); ?></th>
                            <th><?php esc_html_e('Note', 'adoration-scheduler'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $status = (string)($r['status'] ?? '');
                        $badge_map = [
                            'new'      => ['#00a32a', __('New', 'adoration-scheduler')],
                            'update'   => ['#2271b1', __('Update', 'adoration-scheduler')],
                            'conflict' => ['#dba617', __('Conflict', 'adoration-scheduler')],
                            'error'    => ['#d63638', __('Error', 'adoration-scheduler')],
                        ];
                        $badge = $badge_map[$status] ?? ['#666', $status];
                        $color = $badge[0];
                        $label = $badge[1];
                        ?>
                        <tr>
                            <td><?php echo esc_html((string)($r['line'] ?? '')); ?></td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr($color); ?>;">
                                    <?php echo esc_html($label); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html((string)($r['title'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['first'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['last'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['email'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['phone'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['parish'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['message'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p style="margin-top:16px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(PeopleImportExportService::ACTION_IMPORT_COMMIT); ?>" />
                    <input type="hidden" name="preview_token" value="<?php echo esc_attr($token); ?>" />
                    <?php wp_nonce_field('adoration_people_import_commit_' . $token); ?>
                    <button type="submit" class="button button-primary" <?php disabled($importable === 0); ?>>
                        <?php echo esc_html(sprintf(
                            /* translators: %d: number of rows that will be imported */
                            __('Confirm Import (%d)', 'adoration-scheduler'),
                            $importable
                        )); ?>
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(PeopleImportExportService::ACTION_IMPORT_CANCEL); ?>" />
                    <input type="hidden" name="preview_token" value="<?php echo esc_attr($token); ?>" />
                    <?php wp_nonce_field('adoration_people_import_commit_' . $token); ?>
                    <button type="submit" class="button"><?php esc_html_e('Cancel', 'adoration-scheduler'); ?></button>
                </form>
            </p>
        </div>
        <?php
    }
}
