<?php
/**
 * PHPUnit bootstrap for the "unit" test suite.
 *
 * Deliberately does NOT load WordPress or connect to a real database — see
 * tests/Support/AdorationTestCase.php (Brain Monkey stubs the WP functions
 * this plugin calls) and tests/Support/FakeWpdb.php (an in-memory $wpdb
 * double). That's what makes this suite runnable in plain `composer test`
 * with no WordPress install, no MySQL, and no PHP CLI beyond PHP itself.
 *
 * Reuses the plugin's own class loading (includes/Core/Autoloader.php)
 * rather than a separate composer PSR-4 map, so tests are always exercising
 * the exact same autoloading the live plugin uses.
 */

$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendor_autoload)) {
    fwrite(STDERR, "\nMissing vendor/autoload.php — run `composer install` in the plugin directory first.\n\n");
    exit(1);
}
require_once $vendor_autoload;

if (!defined('ABSPATH')) {
    // Any non-empty value satisfies every file's `if (!defined('ABSPATH')) exit;`
    // guard. Tests never actually touch the filesystem path this points to.
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('ADORATION_SCHEDULER_DIR')) {
    define('ADORATION_SCHEDULER_DIR', dirname(__DIR__) . '/');
}
if (!defined('ADORATION_SCHEDULER_FILE')) {
    define('ADORATION_SCHEDULER_FILE', ADORATION_SCHEDULER_DIR . 'adoration-scheduler.php');
}
if (!defined('ADORATION_SCHEDULER_URL')) {
    define('ADORATION_SCHEDULER_URL', 'http://example.test/wp-content/plugins/adoration-scheduler/');
}

require_once ADORATION_SCHEDULER_DIR . 'includes/Core/Autoloader.php';
\AdorationScheduler\Core\Autoloader::register();

// WordPress's $wpdb result-format constants — several repositories pass
// these to get_row()/get_results() as the $output_type argument.
if (!defined('ARRAY_A'))  define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N'))  define('ARRAY_N', 'ARRAY_N');
if (!defined('OBJECT'))   define('OBJECT', 'OBJECT');
if (!defined('OBJECT_K')) define('OBJECT_K', 'OBJECT_K');

// A handful of files reference these time constants directly.
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS'))   define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))    define('DAY_IN_SECONDS', 86400);
if (!defined('WEEK_IN_SECONDS'))   define('WEEK_IN_SECONDS', 604800);
