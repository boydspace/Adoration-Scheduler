# Integration tests

Runs against a **real WordPress + MySQL install** — closer to production
behavior than the `tests/Unit` suite, but slower and dependent on a real
database (including in CI).

## Why this is separate from `tests/Unit`

The unit suite (see `../Unit/` and `../Support/AdorationTestCase.php` /
`../Support/FakeWpdb.php`) fakes WordPress functions with Brain Monkey and
uses an in-memory `$wpdb` double instead of a real database. That's fast
and runnable anywhere with just PHP + Composer — no WordPress install, no
MySQL. What it can't catch: real SQL syntax errors, real `dbDelta()`
schema behavior, real WordPress hook timing, or anything that only breaks
against actual MySQL semantics (collations, real `JOIN`/`GROUP BY`
results, transactions, etc.) — this suite exists for those.

## One-time local setup

1. `composer install` — pulls in `wp-phpunit/wp-phpunit` (the WordPress
   core test scaffold, as a Composer package instead of the old SVN-based
   install script) and `yoast/phpunit-polyfills`.
2. Create a **dedicated** test database — never point this at a real
   site's database, since WordPress's test harness wraps each test in a
   transaction it rolls back, and can drop/recreate tables:
   ```
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS adoration_scheduler_tests"
   ```
3. Run it:
   ```
   composer test:integration
   ```

That's it locally — `tests/bootstrap-integration.php` auto-detects the
WordPress install this plugin already lives inside (Laragon or similar) as
`WP_CORE_DIR`, and `tests/wp-tests-config.php` defaults to
`root`/(no password)/`localhost`, matching this project's local dev
convention (see `wp-config.php`).

## Environment variables

Every value has a local-friendly default, but can be overridden (this is
how CI configures it — see `.github/workflows/tests.yml`):

| Variable                    | Default (local)                 | Purpose                                    |
|------------------------------|----------------------------------|---------------------------------------------|
| `WP_CORE_DIR`                | auto-detected parent WP install  | Real WordPress core files to boot           |
| `WP_TESTS_DB_NAME`           | `adoration_scheduler_tests`      | Dedicated test database                     |
| `WP_TESTS_DB_USER`           | `root`                           |                                              |
| `WP_TESTS_DB_PASSWORD`       | `` (empty)                       |                                              |
| `WP_TESTS_DB_HOST`           | `localhost`                      |                                              |
| `WP_TESTS_CONFIG_FILE_PATH`  | `tests/wp-tests-config.php`      | wp-phpunit's required DB config file        |

## Why PHPUnit is pinned to `^9.6`

`wp-phpunit/wp-phpunit`'s `WP_UnitTestCase::setUp()` unconditionally calls
a legacy `expectDeprecated()` shim that reaches into
`PHPUnit\Util\Test::parseTestMethodAnnotations()` — a method PHPUnit 10.3+
removed entirely in favor of attribute-based metadata. That breaks every
single integration test with a hard fatal (`Call to undefined method`)
under PHPUnit 10.x, regardless of `yoast/phpunit-polyfills`. `composer.json`
pins `phpunit/phpunit` to `^9.6` project-wide (both suites share one
PHPUnit version) until wp-phpunit ships a PHPUnit-10-compatible release.

## What's covered so far

- `Core/InstallerSchemaTest.php` — runs `Installer::install()` against the
  real test database and asserts the expected tables/columns actually
  exist (dbDelta output, not assumed).
- `Domain/Repositories/SignupsRepositoryIntegrationTest.php` — real
  fixture rows through `SignupsRepository::list_for_slot()` (a real JOIN)
  and `hours_report_by_person()` (a real 3-table JOIN + GROUP BY + SQL
  duration math), plus the real-DB duplicate-signup rejection path.

## What to prioritize next

Anything else the unit suite explicitly can't verify because it fakes
`$wpdb`:

- Real end-to-end signup flows through `admin-post.php` handlers.
- `PerpetualSlotGenerator::sync_window()` against a real multi-day slot
  table, including the closures-overlap SQL.
- The email pipeline end-to-end (`SignupsRepository::create()` →
  `EmailService` → `NotificationService` → `wp_mail()`) — the unit suite's
  regression test for the standing-commitment duplicate-email bug
  deliberately stops short of this (see
  `../Unit/Regression/StandingCommitmentEmailBugTest.php`'s docblock)
  because faithfully faking that whole chain in-memory was judged too
  fragile to trust without being able to execute it during development.
  wp-phpunit's WordPress test scaffold intercepts `wp_mail()` (mock
  mailer, no real send attempt), so this is now practical to add here.
