<?php
/**
 * WordPress test-suite DB config, consumed by
 * vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php (via
 * WP_TESTS_CONFIG_FILE_PATH — see tests/bootstrap-integration.php).
 *
 * Every value is read from an env var so the SAME file works locally
 * (Laragon defaults below) and in CI (workflow sets the env vars
 * explicitly) with no edits. This is a dedicated TEST database — never
 * point it at the real site DB in wp-config.php, since the WP test
 * scaffold wraps each test in a transaction it rolls back, and drops/
 * recreates tables on some runs.
 */

define('DB_NAME',     getenv('WP_TESTS_DB_NAME') ?: 'adoration_scheduler_tests');
define('DB_USER',     getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: '');
define('DB_HOST',     getenv('WP_TESTS_DB_HOST') ?: 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Adoration Scheduler Test Suite');

define('WP_PHP_BINARY', 'php');

// Where the real WordPress core files live — tests/bootstrap-integration.php
// resolves this before requiring wp-phpunit's own bootstrap, so it's always
// set (with a local-auto-detect default, or an explicit CI env var) by the
// time this file is read.
$wp_core_dir = getenv('WP_CORE_DIR');
define('ABSPATH', rtrim((string) $wp_core_dir, '/\\') . '/');
