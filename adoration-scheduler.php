<?php
/**
 * Plugin Name: Adoration Scheduler
 * Plugin URI: https://fatherboyd.com/adoration-scheduler
 * Description: A scheduling system for Eucharistic Adoration.
 * Version: 1.0.0
 * Author: Fr. Andy Boyd
 * Author URI: https://fatherboyd.com
 * Text Domain: adoration-scheduler
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

// Useful constants
if ( ! defined('ADORATION_SCHEDULER_FILE') ) {
    define('ADORATION_SCHEDULER_FILE', __FILE__);
}
if ( ! defined('ADORATION_SCHEDULER_DIR') ) {
    define('ADORATION_SCHEDULER_DIR', plugin_dir_path(__FILE__));
}
if ( ! defined('ADORATION_SCHEDULER_URL') ) {
    define('ADORATION_SCHEDULER_URL', plugin_dir_url(__FILE__));
}

add_action('wp_mail_failed', function($wp_error){
    error_log('[AdorationScheduler] wp_mail_failed: ' . $wp_error->get_error_message());
    error_log('[AdorationScheduler] wp_mail_failed data: ' . print_r($wp_error->get_error_data(), true));
});


// --- Bundle: Action Scheduler ----------------------------------------------
// Load bundled Action Scheduler (only if not already provided by another plugin).
if ( ! class_exists('ActionScheduler_Versions', false) ) {

    $as_dir  = ADORATION_SCHEDULER_DIR . 'libraries/action-scheduler/';
    $as_main = $as_dir . 'action-scheduler.php';
    $as_api  = $as_dir . 'functions.php';

    error_log('[AdorationScheduler] AS main exists? ' . (file_exists($as_main) ? 'YES' : 'NO'));
    error_log('[AdorationScheduler] AS api exists? ' . (file_exists($as_api) ? 'YES' : 'NO'));

    if (file_exists($as_main)) {
        require_once $as_main;
        error_log('[AdorationScheduler] Action Scheduler main loaded (bundled)');
    }

    // IMPORTANT: ensure the public API functions are loaded
    if (file_exists($as_api)) {
        require_once $as_api;
        error_log('[AdorationScheduler] Action Scheduler API loaded (bundled)');
    }

    // IMPORTANT: initialize Action Scheduler once WP is ready
    add_action('plugins_loaded', function () {
        if (function_exists('action_scheduler_initialize')) {
            action_scheduler_initialize();
            error_log('[AdorationScheduler] Action Scheduler initialized via action_scheduler_initialize()');
        } elseif (class_exists('ActionScheduler', false) && method_exists('ActionScheduler', 'init')) {
            ActionScheduler::init();
            error_log('[AdorationScheduler] Action Scheduler initialized via ActionScheduler::init()');
        } else {
            error_log('[AdorationScheduler] Action Scheduler init function not found');
        }

        // Diagnostics AFTER init
        error_log('[AdorationScheduler] AS funcs (after init): ' . (function_exists('as_schedule_single_action') ? 'YES' : 'NO'));
        error_log('[AdorationScheduler] AS AdminView class (after init): ' . (class_exists('ActionScheduler_AdminView', false) ? 'YES' : 'NO'));
        error_log('[AdorationScheduler] AS disable admin const: ' . (defined('ACTION_SCHEDULER_DISABLE_ADMIN_VIEW') ? (ACTION_SCHEDULER_DISABLE_ADMIN_VIEW ? 'TRUE' : 'FALSE') : 'NOT_DEFINED'));
    }, 0);

} else {
    error_log('[AdorationScheduler] Action Scheduler already loaded by another plugin');

    // Diagnostics AFTER plugins_loaded in case another plugin initializes it
    add_action('plugins_loaded', function () {
        error_log('[AdorationScheduler] AS funcs (after plugins_loaded): ' . (function_exists('as_schedule_single_action') ? 'YES' : 'NO'));
        error_log('[AdorationScheduler] AS AdminView class (after plugins_loaded): ' . (class_exists('ActionScheduler_AdminView', false) ? 'YES' : 'NO'));
    }, 0);
}
// --------------------------------------------------------------------------

// Load core files
require_once ADORATION_SCHEDULER_DIR . 'includes/Core/Constants.php';
require_once ADORATION_SCHEDULER_DIR . 'includes/Core/Autoloader.php';

// Register autoloader BEFORE loading classes that might refer to other classes
\AdorationScheduler\Core\Autoloader::register();

// Now load Plugin bootstrap
require_once ADORATION_SCHEDULER_DIR . 'includes/Core/Plugin.php';

// Activation/Deactivation hooks
register_activation_hook( ADORATION_SCHEDULER_FILE, [ \AdorationScheduler\Core\Plugin::class, 'activate' ] );
register_deactivation_hook( ADORATION_SCHEDULER_FILE, [ \AdorationScheduler\Core\Plugin::class, 'deactivate' ] );

// Boot plugin
add_action( 'plugins_loaded', [ \AdorationScheduler\Core\Plugin::class, 'init' ] );
