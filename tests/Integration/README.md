# Integration tests (not implemented yet)

This directory is reserved for a later pass of tests that run against a
**real WordPress + MySQL install** — closer to production behavior than the
`tests/Unit` suite, but slower and dependent on a real database (including
in CI).

## Why this is separate from `tests/Unit`

The unit suite (see `../Unit/` and `../Support/AdorationTestCase.php` /
`../Support/FakeWpdb.php`) fakes WordPress functions with Brain Monkey and
uses an in-memory `$wpdb` double instead of a real database. That makes it
fast and runnable anywhere with just PHP + Composer — no WordPress install,
no MySQL — which is why it was built first. What it *can't* catch: real SQL
syntax errors, real `dbDelta()` schema behavior, real WordPress hook timing,
or anything that only breaks against actual MySQL semantics (collations,
real `JOIN`/`GROUP BY` results, transactions, etc.).

## How to set this up when it's time

The standard approach for a WordPress plugin is
[`wp-phpunit`](https://github.com/wp-phpunit/wp-phpunit) (a Composer package
providing the WordPress core test scaffold) plus a local MySQL database:

1. `composer require --dev wp-phpunit/wp-phpunit yoast/phpunit-polyfills`
2. A `tests/bootstrap-integration.php` that loads `wp-phpunit`'s
   `functions.php`, calls `tests_add_filter('muplugins_loaded', ...)` to load
   this plugin, then requires wp-phpunit's real `bootstrap.php`.
3. A real test database (a `WP_TESTS_DB_*` env-var-configured MySQL
   database — Laragon already has MySQL available locally).
4. A second `<testsuite name="integration">` entry in `phpunit.xml.dist`
   pointing at this directory (the entry already exists, pointing here, so
   this only needs a working bootstrap file to start passing tests).
5. Tests would extend `WP_UnitTestCase` instead of `AdorationTestCase`,
   getting a real (rolled-back-per-test) WordPress database.

## What to prioritize once this exists

Anything the unit suite explicitly can't verify because it fakes `$wpdb`:

- `includes/Core/Installer.php` — do the `dbDelta()` calls actually produce
  the expected columns/indexes on a real MySQL install?
- Real end-to-end signup flows through `admin-post.php` handlers.
- `PerpetualSlotGenerator::sync_window()` against a real multi-day slot
  table, including the closures-overlap SQL.
- The email pipeline end-to-end (`SignupsRepository::create()` →
  `EmailService` → `NotificationService` → `wp_mail()`) — the unit suite's
  regression test for the standing-commitment duplicate-email bug
  deliberately stops short of this (see
  `../Unit/Regression/StandingCommitmentEmailBugTest.php`'s docblock) because
  faithfully faking that whole chain in-memory was judged too fragile to
  trust without being able to execute it during development.
