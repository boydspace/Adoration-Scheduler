<?php
/**
 * PHPUnit bootstrap for the "integration" test suite (see
 * phpunit-integration.xml.dist / `composer test:integration`).
 *
 * Unlike tests/bootstrap.php (Brain Monkey + FakeWpdb, no real WordPress or
 * database involved — see that file's docblock), this boots an ACTUAL
 * WordPress install against a REAL MySQL database via the wp-phpunit
 * Composer package, and loads the real adoration-scheduler.php plugin file
 * — not a stub. This is what tests/Integration/README.md calls out as
 * needed to catch things the unit suite fakes around: real dbDelta()
 * schema output, real SQL/JOIN/GROUP BY results, real WordPress hook
 * timing.
 *
 * One-time local setup + required env vars: see tests/Integration/README.md.
 */

$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendor_autoload)) {
    fwrite(STDERR, "\nMissing vendor/autoload.php — run `composer install` in the plugin directory first.\n\n");
    exit(1);
}
require_once $vendor_autoload;

// WP_CORE_DIR: the real WordPress install to boot. Locally, this plugin
// already lives inside one (Laragon), so default to auto-detecting it —
// .../wp-content/plugins/adoration-scheduler/tests, 4 levels up, is the WP
// root containing wp-load.php. CI has no such nesting (the repo is checked
// out standalone) and sets WP_CORE_DIR explicitly to a downloaded core
// tarball instead — see .github/workflows/tests.yml.
if (!getenv('WP_CORE_DIR')) {
    putenv('WP_CORE_DIR=' . dirname(__DIR__, 4));
}

// wp-phpunit's bootstrap.php looks for this as a defined PHP constant (not
// an env var) to find our tests/wp-tests-config.php (which itself reads
// WP_CORE_DIR, set above, to define ABSPATH).
if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', getenv('WP_TESTS_CONFIG_FILE_PATH') ?: (__DIR__ . '/wp-tests-config.php'));
}

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    // Set automatically by wp-phpunit/wp-phpunit's own Composer "files"
    // autoload once `composer require` has run — no manual path wiring
    // needed for the common case.
    $_tests_dir = getenv('WP_PHPUNIT__DIR');
}
if (!$_tests_dir) {
    fwrite(STDERR, "\nCouldn't find the wp-phpunit test scaffold. Run `composer install` (it requires wp-phpunit/wp-phpunit), or set WP_TESTS_DIR explicitly.\n\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads the real plugin — same file WordPress itself loads on activation —
 * so integration tests exercise the actual Autoloader, real hook
 * registration, and the bundled Action Scheduler, not a test double.
 */
function _adoration_scheduler_load_plugin_for_tests(): void
{
    require dirname(__DIR__) . '/adoration-scheduler.php';
}
tests_add_filter('muplugins_loaded', '_adoration_scheduler_load_plugin_for_tests');

require $_tests_dir . '/includes/bootstrap.php';
