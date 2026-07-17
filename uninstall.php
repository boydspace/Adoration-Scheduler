<?php
/**
 * Adoration Scheduler – Uninstall
 *
 * IMPORTANT:
 * This plugin intentionally does NOT delete data on uninstall.
 * Parish data (schedules, signups, people, pages) is preserved by default.
 *
 * Future versions may include an optional "Delete all data" setting.
 */

if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

// If we ever add a "delete data on uninstall" option, it would be checked here.
// For now, do nothing intentionally.
