<?php
/**
 * Tab: Overview
 *
 * Expected variables in scope:
 * - $schedule (array)
 * - $slots_total (int)
 * - $slots_active (int)
 * - $slots_inactive (int)
 */

if ( ! defined('ABSPATH') ) exit;
?>

<h2><?php esc_html_e('Overview', 'adoration-scheduler'); ?></h2>

<table class="widefat striped" style="max-width: 900px;">
    <tbody>
        <tr>
            <th><?php esc_html_e('Name', 'adoration-scheduler'); ?></th>
            <td><?php echo esc_html((string)($schedule['name'] ?? '')); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Slug', 'adoration-scheduler'); ?></th>
            <td><code><?php echo esc_html((string)($schedule['slug'] ?? '')); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Type', 'adoration-scheduler'); ?></th>
            <td><?php echo esc_html((string)($schedule['type'] ?? '')); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
            <td><?php echo esc_html((string)($schedule['status'] ?? '')); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Privacy', 'adoration-scheduler'); ?></th>
            <td><?php echo esc_html((string)($schedule['privacy_mode'] ?? '')); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Dates', 'adoration-scheduler'); ?></th>
            <td>
                <?php
                $start = (string)($schedule['start_date'] ?? '');
                $end   = (string)($schedule['end_date'] ?? '');
                $range = trim($start . ' → ' . $end);
                echo esc_html($range !== '→' && $range !== '' ? $range : '—');
                ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Slots in DB', 'adoration-scheduler'); ?></th>
            <td>
                <strong><?php echo (int)$slots_total; ?></strong>
                &nbsp;—&nbsp;
                <?php esc_html_e('Active:', 'adoration-scheduler'); ?>
                <strong><?php echo (int)$slots_active; ?></strong>
                &nbsp;|&nbsp;
                <?php esc_html_e('Inactive:', 'adoration-scheduler'); ?>
                <strong><?php echo (int)$slots_inactive; ?></strong>
            </td>
        </tr>
    </tbody>
</table>
