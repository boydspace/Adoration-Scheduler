<?php
namespace AdorationScheduler\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the "unit" suite. Wires up Brain Monkey (fakes WordPress
 * functions in-process, no WordPress install needed) and stubs the handful
 * of WP functions almost every class under test touches — sanitizers,
 * escapers, i18n no-ops, and a few others — so individual test classes only
 * need to stub the WP functions specific to what they're testing.
 *
 * Brain Monkey intercepts *function calls*, not class methods — that's why
 * $wpdb needs its own double (see FakeWpdb) rather than being mocked the
 * same way.
 */
abstract class AdorationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stub_common_wp_functions();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stubs that are safe defaults for nearly every test: sanitizers and
     * escapers that just need to behave like plain string pass-throughs
     * (good enough for asserting on logic, not for testing WP's actual
     * sanitization rules — that belongs in the integration suite), i18n
     * functions that just return their input, and a couple of the
     * WP-provided globals classes under test frequently branch on.
     */
    protected function stub_common_wp_functions(): void
    {
        $passthrough = static fn($value) => is_string($value) ? trim($value) : $value;

        Functions\when('sanitize_text_field')->alias($passthrough);
        Functions\when('sanitize_key')->alias(
            static fn($value) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value))
        );
        Functions\when('sanitize_email')->alias($passthrough);
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('absint')->alias(static fn($value) => abs((int) $value));

        Functions\when('esc_html')->alias($passthrough);
        Functions\when('esc_attr')->alias($passthrough);
        Functions\when('esc_url')->alias($passthrough);
        Functions\when('esc_html__')->alias(static fn($text, $domain = null) => $text);
        Functions\when('esc_attr__')->alias(static fn($text, $domain = null) => $text);
        Functions\when('__')->alias(static fn($text, $domain = null) => $text);
        Functions\when('_e')->alias(static function ($text, $domain = null) { echo $text; });
        Functions\when('esc_html_e')->alias(static function ($text, $domain = null) { echo $text; });

        Functions\when('is_email')->alias(
            static fn($email) => (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL)
        );

        // Deterministic timezone (UTC) rather than falling through to
        // get_option('timezone_string'), which isn't stubbed by default.
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('current_time')->alias(static function ($type) {
            return $type === 'timestamp' ? time() : gmdate($type === 'mysql' ? 'Y-m-d H:i:s' : $type);
        });

        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn(null);

        // Most tests don't care about actual mail delivery; individual tests
        // override this with a call-count assertion where the point IS the
        // email behavior (see the standing-commitment regression test).
        Functions\when('wp_mail')->justReturn(true);

        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('home_url')->alias(static fn($path = '') => 'http://example.test' . $path);
        Functions\when('admin_url')->alias(static fn($path = '') => 'http://example.test/wp-admin/' . $path);

        Functions\when('wp_kses_post')->alias($passthrough);
        // NOTE: deliberately NOT stubbing error_log() — it's a real PHP
        // built-in (internal) function, not a WordPress one, and Brain
        // Monkey/Patchwork can't redefine internals without an explicit
        // patchwork.json opt-in ("Please include {"redefinable-internals":
        // ["error_log"]}..."). It works fine unstubbed — every call site
        // wraps it in a try/catch anyway, so a real write to the PHP error
        // log during a test run is harmless.
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0) => json_encode($data, $options)
        );
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('wp_specialchars_decode')->alias($passthrough);
    }

    /**
     * Installs a fresh FakeWpdb as the global $wpdb and returns it, for tests
     * that exercise repository/service code paths touching the database.
     */
    protected function fake_wpdb(): FakeWpdb
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }
}
