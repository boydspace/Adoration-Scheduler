<?php
/**
 * Plugin Name: Adoration Scheduler
 * Plugin URI: https://fatherboyd.com/adoration-scheduler
 * Description: A scheduling system for Eucharistic Adoration.
 * Version: 1.0.9
 * Author: Fr. Andy Boyd
 * Author URI: https://fatherboyd.com
 * Text Domain: adoration-scheduler
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

// Useful constants
if ( ! defined('ADORATION_SCHEDULER_VERSION') ) {
    define('ADORATION_SCHEDULER_VERSION', '1.0.9');
}
if ( ! defined('ADORATION_SCHEDULER_FILE') ) {
    define('ADORATION_SCHEDULER_FILE', __FILE__);
}
if ( ! defined('ADORATION_SCHEDULER_DIR') ) {
    define('ADORATION_SCHEDULER_DIR', plugin_dir_path(__FILE__));
}
if ( ! defined('ADORATION_SCHEDULER_URL') ) {
    define('ADORATION_SCHEDULER_URL', plugin_dir_url(__FILE__));
}

// --- Bundle: Action Scheduler ----------------------------------------------
// Load bundled Action Scheduler (only if not already provided by another plugin).
if ( ! class_exists('ActionScheduler_Versions', false) ) {

    $as_dir  = ADORATION_SCHEDULER_DIR . 'libraries/action-scheduler/';
    $as_main = $as_dir . 'action-scheduler.php';
    $as_api  = $as_dir . 'functions.php';

    if (file_exists($as_main)) {
        require_once $as_main;
    }

    // IMPORTANT: ensure the public API functions are loaded
    if (file_exists($as_api)) {
        require_once $as_api;
    }

    // IMPORTANT: initialize Action Scheduler once WP is ready
    add_action('plugins_loaded', function () {
        if (function_exists('action_scheduler_initialize')) {
            action_scheduler_initialize();
        } elseif (class_exists('ActionScheduler', false) && method_exists('ActionScheduler', 'init')) {
            ActionScheduler::init();
        }
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
