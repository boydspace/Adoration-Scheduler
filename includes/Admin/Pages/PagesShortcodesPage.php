<?php
namespace AdorationScheduler\Admin\Pages;

use AdorationScheduler\Domain\Repositories\SchedulesRepository;

if ( ! defined('ABSPATH') ) exit;

/**
 * "Pages & Shortcodes" diagnostic page.
 *
 * Read-only. Lists every front-end page the plugin depends on, whether that
 * page currently exists/is published, whether its content contains the
 * required shortcode, and whether that shortcode is even registered with
 * WordPress right now (the last check is what would have caught the
 * "[adoration_my_adoration] printing as literal text" bug immediately,
 * since a registration failure shows up here as "NOT REGISTERED").
 */
class PagesShortcodesPage
{
    private const MY_ADORATION_OPT  = 'adoration_scheduler_my_adoration_page_id';
    private const MY_ADORATION_SLUG = 'my-adoration';

    private const REQUEST_ACCESS_OPT  = 'adoration_scheduler_request_access_page_id';
    private const REQUEST_ACCESS_SLUG = 'request-access';

    public function render(): void
    {
        global $shortcode_tags;

        // ---- 1) Live shortcode registration status -------------------------
        // Note: [adoration_my_adoration] was retired in favor of the 7 modular
        // shortcodes below (each independently composable on any page). It's
        // intentionally NOT registered anymore, so it's deliberately left out
        // of this list rather than showing a permanent false-positive error.
        $tracked_shortcodes = [
            'adoration_schedule'                 => 'Perpetual / event schedule grid',
            'adoration_magic_link'                => 'Magic-link request/verify form',
            'adoration_request_access'            => 'Approval-gate registration form',
            'adoration_account_status'            => 'My Adoration portal: account/approval status banner',
            'adoration_profile_card'              => 'My Adoration portal: profile card + edit contact info',
            'adoration_next_adoration_hour'       => 'My Adoration portal: next upcoming hour summary',
            'adoration_announcements'             => 'My Adoration portal: announcements feed',
            'adoration_my_schedule'                => 'My Adoration portal: upcoming signups list',
            'adoration_my_replacement_requests'   => 'My Adoration portal: my replacement requests',
            'adoration_needed_replacements'       => 'My Adoration portal: open replacement requests to claim',
            'adoration_open_hours'                 => 'Public open-hours board (no names) + calendar subscribe link',
            'adoration_calendar_subscribe'          => 'My Adoration portal: personal calendar subscribe link',
        ];

        $registration = [];
        $any_missing  = false;
        foreach ($tracked_shortcodes as $tag => $label) {
            $is_registered = is_array($shortcode_tags) && array_key_exists($tag, $shortcode_tags);
            if (!$is_registered) {
                $any_missing = true;
            }
            $registration[$tag] = [
                'label'      => $label,
                'registered' => $is_registered,
            ];
        }

        // ---- 2) Pull every published page's content once -------------------
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $pages = (array) $wpdb->get_results(
            "SELECT ID, post_title, post_name, post_content, post_status
             FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'",
            ARRAY_A
        );

        // ---- 3) My Adoration portal page ------------------------------------
        $saved_id = (int) get_option(self::MY_ADORATION_OPT, 0);
        $my_page  = null;

        if ($saved_id > 0) {
            foreach ($pages as $p) {
                if ((int)$p['ID'] === $saved_id) {
                    $my_page = $p;
                    break;
                }
            }
            if ($my_page === null) {
                // Saved id doesn't point at a published page (trashed/draft/deleted).
                $maybe = get_post($saved_id);
                if ($maybe) {
                    $my_page = [
                        'ID'           => $maybe->ID,
                        'post_title'   => $maybe->post_title,
                        'post_name'    => $maybe->post_name,
                        'post_content' => $maybe->post_content,
                        'post_status'  => $maybe->post_status,
                    ];
                }
            }
        }

        if ($my_page === null) {
            foreach ($pages as $p) {
                if ($p['post_name'] === self::MY_ADORATION_SLUG) {
                    $my_page = $p;
                    break;
                }
            }
        }

        // The portal page is built from a stack of 7 modular shortcodes (see
        // Installer::MY_ADORATION_SHORTCODE), not one single shortcode anymore.
        // Check each independently so a partial/customized page shows exactly
        // which pieces are present rather than a single pass/fail flag.
        $my_adoration_shortcode_tags = [
            'adoration_account_status'          => 'Account/approval status',
            'adoration_profile_card'            => 'Profile card',
            'adoration_next_adoration_hour'     => 'Next adoration hour',
            'adoration_announcements'           => 'Announcements',
            'adoration_my_schedule'             => 'My schedule',
            'adoration_my_replacement_requests' => 'My replacement requests',
            'adoration_needed_replacements'     => 'Needed replacements',
        ];

        $my_page_shortcode_presence = [];
        $my_page_present_count      = 0;
        foreach ($my_adoration_shortcode_tags as $tag => $label) {
            $present = $my_page && function_exists('has_shortcode')
                ? has_shortcode((string)$my_page['post_content'], $tag)
                : false;
            if ($present) {
                $my_page_present_count++;
            }
            $my_page_shortcode_presence[$tag] = [
                'label'   => $label,
                'present' => $present,
            ];
        }
        $my_page_has_shortcode = $my_page_present_count > 0;
        $my_page_has_all_shortcodes = $my_page_present_count === count($my_adoration_shortcode_tags);

        // ---- 3b) Request Access page -----------------------------------------
        $ra_saved_id = (int) get_option(self::REQUEST_ACCESS_OPT, 0);
        $ra_page     = null;

        if ($ra_saved_id > 0) {
            foreach ($pages as $p) {
                if ((int)$p['ID'] === $ra_saved_id) {
                    $ra_page = $p;
                    break;
                }
            }
            if ($ra_page === null) {
                $maybe = get_post($ra_saved_id);
                if ($maybe) {
                    $ra_page = [
                        'ID'           => $maybe->ID,
                        'post_title'   => $maybe->post_title,
                        'post_name'    => $maybe->post_name,
                        'post_content' => $maybe->post_content,
                        'post_status'  => $maybe->post_status,
                    ];
                }
            }
        }

        if ($ra_page === null) {
            foreach ($pages as $p) {
                if ($p['post_name'] === self::REQUEST_ACCESS_SLUG) {
                    $ra_page = $p;
                    break;
                }
            }
        }

        $ra_page_has_shortcode = $ra_page && function_exists('has_shortcode')
            ? has_shortcode((string)$ra_page['post_content'], 'adoration_request_access')
            : false;

        // ---- 4) Schedules -> required [adoration_schedule slug="..."] ------
        $schedulesRepo = new SchedulesRepository();
        $schedules     = $schedulesRepo->list_all(200, false);

        $schedule_rows = [];
        foreach ($schedules as $s) {
            $slug = (string)($s['slug'] ?? '');
            $found_on = [];

            if ($slug !== '') {
                $pattern = '/\[adoration_schedule(?:\s[^\]]*)?\bslug\s*=\s*["\']' . preg_quote($slug, '/') . '["\']/i';
                foreach ($pages as $p) {
                    if (preg_match($pattern, (string)$p['post_content'])) {
                        $found_on[] = $p;
                    }
                }
            }

            $schedule_rows[] = [
                'schedule'  => $s,
                'found_on'  => $found_on,
            ];
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pages & Shortcodes', 'adoration-scheduler'); ?></h1>
            <?php \AdorationScheduler\Admin\Menu::render_settings_tabs('adoration_scheduler_pages_shortcodes'); ?>
            <p class="description">
                <?php esc_html_e('A live check of the front-end pages this plugin depends on: whether the page exists, whether the required shortcode is in its content, and whether that shortcode is actually registered with WordPress right now.', 'adoration-scheduler'); ?>
            </p>

            <?php if ($any_missing): ?>
                <div class="notice notice-error" style="padding:12px;">
                    <p style="margin:0 0 6px;">
                        <strong><?php esc_html_e('One or more Adoration Scheduler shortcodes are not currently registered.', 'adoration-scheduler'); ?></strong>
                    </p>
                    <p style="margin:0;">
                        <?php esc_html_e('This usually means a PHP error happened earlier in plugin startup, before that shortcode was reached. Check your site’s PHP error log (or WP_DEBUG_LOG) for a fatal error, then reload this page.', 'adoration-scheduler'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Shortcode Registration', 'adoration-scheduler'); ?></h2>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Shortcode', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Used for', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registration as $tag => $info): ?>
                        <tr>
                            <td><code>[<?php echo esc_html($tag); ?>]</code></td>
                            <td><?php echo esc_html($info['label']); ?></td>
                            <td>
                                <?php if ($info['registered']): ?>
                                    <span style="color:#2271b1;font-weight:600;">✅ <?php esc_html_e('Registered', 'adoration-scheduler'); ?></span>
                                <?php else: ?>
                                    <span style="color:#d63638;font-weight:600;">❌ <?php esc_html_e('NOT REGISTERED', 'adoration-scheduler'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:32px;"><?php esc_html_e('My Adoration Portal', 'adoration-scheduler'); ?></h2>
            <p class="description">
                <?php esc_html_e('The portal is built from 7 composable shortcodes (the retired all-in-one [adoration_my_adoration] has been replaced by these). A site can mix, reorder, or drop pieces on this page, so each one is checked independently below.', 'adoration-scheduler'); ?>
            </p>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Page', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Published', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Shortcodes present', 'adoration-scheduler'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <?php if ($my_page): ?>
                                <a href="<?php echo esc_url(get_edit_post_link((int)$my_page['ID'])); ?>">
                                    <?php echo esc_html((string)$my_page['post_title']); ?>
                                </a>
                                &nbsp;
                                <a href="<?php echo esc_url(get_permalink((int)$my_page['ID'])); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('View', 'adoration-scheduler'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color:#d63638;">
                                    <?php esc_html_e('No page found (expected slug: /my-adoration)', 'adoration-scheduler'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($my_page && ($my_page['post_status'] ?? '') === 'publish'): ?>
                                ✅ <?php esc_html_e('Yes', 'adoration-scheduler'); ?>
                            <?php elseif ($my_page): ?>
                                ⚠️ <?php echo esc_html((string)$my_page['post_status']); ?>
                            <?php else: ?>
                                ❌ <?php esc_html_e('N/A', 'adoration-scheduler'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$my_page): ?>
                                &mdash;
                            <?php elseif ($my_page_has_all_shortcodes): ?>
                                <span style="color:#2271b1;font-weight:600;">✅ <?php esc_html_e('All 7 present', 'adoration-scheduler'); ?></span>
                            <?php elseif ($my_page_has_shortcode): ?>
                                <span style="color:#b26200;font-weight:600;">⚠️ <?php echo esc_html(sprintf(
                                    /* translators: %1$d: count present, %2$d: total count */
                                    __('%1$d of %2$d present', 'adoration-scheduler'),
                                    $my_page_present_count,
                                    count($my_adoration_shortcode_tags)
                                )); ?></span>
                            <?php else: ?>
                                <span style="color:#d63638;font-weight:600;">❌ <?php esc_html_e('None found in page content', 'adoration-scheduler'); ?></span>
                            <?php endif; ?>
                            <?php if ($my_page): ?>
                                <ul style="margin:8px 0 0; list-style:disc; padding-left:20px;">
                                    <?php foreach ($my_page_shortcode_presence as $tag => $info): ?>
                                        <li>
                                            <?php if ($info['present']): ?>
                                                ✅
                                            <?php else: ?>
                                                ❌
                                            <?php endif; ?>
                                            <code>[<?php echo esc_html($tag); ?>]</code>
                                            <span class="description">&mdash; <?php echo esc_html($info['label']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top:32px;"><?php esc_html_e('Request Access Page', 'adoration-scheduler'); ?></h2>
            <p class="description">
                <?php esc_html_e('A stable entry point for the approval gate (Settings → Access & Privacy) — useful for a bulletin, nav menu link, or QR code. Not required: the same form already appears automatically in place of any gated schedule/dashboard page.', 'adoration-scheduler'); ?>
            </p>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Page', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Published', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Shortcode in content', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Required shortcode', 'adoration-scheduler'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <?php if ($ra_page): ?>
                                <a href="<?php echo esc_url(get_edit_post_link((int)$ra_page['ID'])); ?>">
                                    <?php echo esc_html((string)$ra_page['post_title']); ?>
                                </a>
                                &nbsp;
                                <a href="<?php echo esc_url(get_permalink((int)$ra_page['ID'])); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('View', 'adoration-scheduler'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color:#d63638;">
                                    <?php esc_html_e('No page found (expected slug: /request-access)', 'adoration-scheduler'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ra_page && ($ra_page['post_status'] ?? '') === 'publish'): ?>
                                ✅ <?php esc_html_e('Yes', 'adoration-scheduler'); ?>
                            <?php elseif ($ra_page): ?>
                                ⚠️ <?php echo esc_html((string)$ra_page['post_status']); ?>
                            <?php else: ?>
                                ❌ <?php esc_html_e('N/A', 'adoration-scheduler'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ra_page && $ra_page_has_shortcode): ?>
                                ✅ <?php esc_html_e('Present', 'adoration-scheduler'); ?>
                            <?php elseif ($ra_page): ?>
                                ❌ <?php esc_html_e('Missing from page content', 'adoration-scheduler'); ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><code>[adoration_request_access]</code></td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top:32px;"><?php esc_html_e('Schedules → Public Pages', 'adoration-scheduler'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Schedule', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Required shortcode', 'adoration-scheduler'); ?></th>
                        <th><?php esc_html_e('Found on', 'adoration-scheduler'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedule_rows)): ?>
                        <tr><td colspan="4"><?php esc_html_e('No schedules yet.', 'adoration-scheduler'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($schedule_rows as $row): ?>
                        <?php
                        $s    = $row['schedule'];
                        $slug = (string)($s['slug'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html((string)($s['name'] ?? '(untitled)')); ?></strong>
                                <br>
                                <span class="description"><?php echo esc_html((string)($s['type'] ?? 'event')); ?></span>
                            </td>
                            <td><?php echo esc_html((string)($s['status'] ?? '')); ?></td>
                            <td>
                                <?php if ($slug !== ''): ?>
                                    <code>[adoration_schedule slug="<?php echo esc_html($slug); ?>"]</code>
                                <?php else: ?>
                                    <span style="color:#d63638;"><?php esc_html_e('No slug set', 'adoration-scheduler'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['found_on'])): ?>
                                    <?php foreach ($row['found_on'] as $p): ?>
                                        <div>
                                            ✅
                                            <a href="<?php echo esc_url(get_edit_post_link((int)$p['ID'])); ?>">
                                                <?php echo esc_html((string)$p['post_title']); ?>
                                            </a>
                                            &nbsp;
                                            <a href="<?php echo esc_url(get_permalink((int)$p['ID'])); ?>" target="_blank" rel="noopener">
                                                <?php esc_html_e('View', 'adoration-scheduler'); ?>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color:#d63638;">❌ <?php esc_html_e('Not found on any published page', 'adoration-scheduler'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
