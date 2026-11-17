<?php
/**
 * Plugin Name: Adoration Scheduler
 * Plugin URI: https://fatherboyd.com/adoration-scheduler
 * Description: A scheduling system for Eucharistic Adoration.
 * Version: 1.0.12
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
    define('ADORATION_SCHEDULER_VERSION', '1.0.12');
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
// Action Scheduler registers its available version and initializes the selected
// version itself. Only load our bundled copy when no provider has registered.
if ( ! class_exists('ActionScheduler_Versions', false) ) {

    $adoration_scheduler_as_dir  = ADORATION_SCHEDULER_DIR . 'libraries/action-scheduler/';
    $adoration_scheduler_as_main = $adoration_scheduler_as_dir . 'action-scheduler.php';

    if (file_exists($adoration_scheduler_as_main)) {
        require_once $adoration_scheduler_as_main;
    }
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
