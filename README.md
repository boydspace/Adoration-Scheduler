<p align="center">
  <img src="images/adoration-scheduler-logo.png" alt="Adoration Scheduler" width="720">
</p>

Adoration Scheduler turns WordPress into a complete Eucharistic Adoration scheduling system for parishes. It brings scheduling, signups, substitute coordination, communications, and access control together in one WordPress admin experience — with a secure public portal adorers can use without ever creating a WordPress account.

## Highlights

### Scheduling and slots
- Run one-time or multi-day events, weekly perpetual (round-the-clock) adoration, and monthly nth-weekday devotions (e.g. First Friday, Last Sunday) — all from one plugin.
- Support multiple chapels or locations, including overnight hours that roll past midnight.
- Generate adoration time slots automatically, with a daily background job keeping perpetual and monthly schedules filled out on a rolling window.
- Set minimum and maximum adorers per hour, and schedule closures (holidays, cancellations) that are respected automatically going forward.

### Signups and substitutes
- Public signup form — no WordPress account required.
- Standing weekly commitments for perpetual schedules, with per-date skip/cancel.
- Replacement requests let an adorer flag an hour as needing coverage without cancelling outright.
- Direct-to-person swap requests let an adorer ask one specific person to cover an hour privately, or open the request to the whole community of opted-in substitutes.
- A "Coverage Needed" board and an "Asked of You" view keep everyone aware of open requests.
- Waitlists: signing up for a full hour offers a spot in line instead of a dead end, and whoever's been waiting longest is automatically confirmed the moment someone cancels — no admin action needed.

### Accounts and access
- Secure "magic link" email sign-in, with an optional password as a second sign-in method.
- A compact `[adoration_mini_login]` sign-in widget (email field, submit button, optional password toggle, no explanatory copy) for dropping into a front page or sidebar, alongside the full `[adoration_magic_link]` form for a dedicated sign-in page.
- Optional approval-gated access, so a parish can require a short request before adorers can use the portal.
- A modular "My Adoration" portal built from composable shortcodes — standing hours, upcoming signups, profile card, replacement requests, announcements — so a parish can lay out its own page.
- Self-service profile editing, including clergy titles and parish affiliation.
- Self-service "Download My Data" export and "Delete My Account" — an adorer can download a copy of their profile, standing hours, and signup history, or anonymize their own account, cancelling future hours and revoking sign-in access without disturbing past coverage history.

### Communications
- Automatic email confirmations, reminders, and cancellations — every outgoing email is editable from Email Templates, with merge tags and a send-test tool.
- Optional SMS text reminders via Twilio, alongside the existing email reminder — a parish enables SMS as an option, but each adorer separately chooses whether they want it, on their own "Reminder Preferences" dashboard widget.
- Per-person reminder preferences: each adorer chooses which channel(s) (email, text, both, or neither) and how many hours before their hour they're reminded — configurable lead time avoids, for example, a text arriving at 3am the day before a 3am hour.
- Daily coverage-gap alert emails warning staff about unfilled hours coming up soon, with a configurable time window and recipient address.
- Parish announcements shortcode for the portal.
- Admin email log with resend tools.
- Personal iCal subscribe feed so an adorer's own confirmed hours stay synced to their phone or computer calendar automatically.
- Public "open hours" board per schedule, showing fill status with no adorer names, no signup controls, and no calendar-subscribe link — just the hours, safe to advertise on a public page. Either a per-date list, or (for a weekly perpetual schedule) a compact weekly grid — one row per time, one column per day.

### Administration
- Consolidated, simplified admin menu.
- Signup audit trail for accountability.
- Built-in Pages & Shortcodes diagnostic tool to confirm the portal is wired up correctly.
- Privacy controls for what's shown on public listings: counts only, first name only, first name + last initial, or full names.
- Bulk roster import and export in CSV or XLSX (Excel) format, with a review-before-you-commit preview that flags new people, updates, and email conflicts.
- Schedules list export in CSV or XLSX.
- Printable rosters — a clean, chapel-binder-friendly page per schedule and date range, grouped by date/time with names and phone numbers, ready to print or save as a PDF from the browser.
- Coverage Report — hours served per person and month-by-month fill rate over any date range, each with CSV export, for stewardship recognition and year-end reports.
- First-run Setup Wizard shown once right after activation, plus a persistent "Finish setting up" checklist on the Dashboard for anyone who skips it — walks a new install through creating a schedule, adding hours, activating it, and deciding on the approval gate.
- Accessibility pass on the public-facing signup pages and the "My Adoration" portal: properly labeled form fields, keyboard-trapped and focus-returning modals, screen-reader-friendly table headers and button names, and status indicators that don't rely on color alone.
- No-account adorers: an admin can add someone directly to a one-time signup or a standing weekly commitment with no email address at all, for adorers who don't want (or can't use) an online account. If they later decide they want one, giving them a real email from the People page automatically emails them a "you can now sign in" notice.

### Attendance and check-in
- Three no-login ways to record who actually showed up: a self-report "I'm here" / "I'm leaving" link in the confirmation and reminder emails and on the My Adoration portal, a per-chapel kiosk page (printable as a QR code for the chapel entrance) showing who's on the clock right now, and an admin Attendance page for marking present/absent by hand.
- Attendance admin page — review check-in status for any date range and schedule, with one-click "Mark Present" / "Mark Absent" for hours nobody self-reported.
- Optional No-Show Alerts digest, off by default, that emails staff when a confirmed hour started a while ago with nobody checked in — a safety net for an unstaffed chapel, separate from the Coverage Alerts digest that warns about hours nobody signed up for at all.

## Requirements

- WordPress 6.2 or newer
- PHP 8.0 or newer
- MySQL or MariaDB
- No external services required

## Installation

1. Place the plugin in `wp-content/plugins/adoration-scheduler`.
2. Activate **Adoration Scheduler** from the WordPress Plugins screen.
3. On activation, the plugin automatically creates its database tables, a default chapel, a "My Adoration" page, and schedules its daily background jobs.
4. Open **Adoration Scheduler** in the WordPress admin menu to create a chapel and your first schedule.

## Project information

- Designed and developed by Fr. Andrew M. Boyd
- Plugin website: [fatherboyd.com/adoration-scheduler](https://fatherboyd.com/adoration-scheduler)
- Repository: [github.com/boydspace/adoration-scheduler](https://github.com/boydspace/adoration-scheduler)
- Translation domain: `adoration-scheduler`
- Public PHP constants and classes: `ADORATION_SCHEDULER_*` and `AdorationScheduler\*`

## Testing

Two PHPUnit suites, each with its own config and bootstrap:

- **Unit** (`composer test`) — Brain Monkey fakes WordPress functions and
  `tests/Support/FakeWpdb.php` fakes `$wpdb`, so this runs anywhere with
  just PHP + Composer, no WordPress install or MySQL needed. Covers the
  plugin's highest-risk pure logic (overnight slot-generation math, signup
  dedupe rules, the standing-commitment duplicate-email regression).
- **Integration** (`composer test:integration`) — boots a real WordPress
  install against a real MySQL database via the `wp-phpunit` Composer
  package, and loads the actual plugin file. Covers what the unit suite
  can't: real `dbDelta()` schema output, and real SQL/JOIN/GROUP BY
  results. See `tests/Integration/README.md` for the one-time local setup
  (a dedicated test database) and required environment variables.

```
composer install
composer test               # unit — fast, no setup needed
composer test:integration   # integration — needs a local test DB, see below
```

CI runs both on every push via `.github/workflows/tests.yml`: the unit
suite across PHP 8.0–8.3, and the integration suite once against a MySQL
service container.

## Roadmap

No open items at the moment — SMS reminders (the last roadmap item) shipped. Future work will be tracked here as it comes up.

## Development status

Adoration Scheduler has reached its 1.0 release. As with any WordPress plugin, test upgrades on a staging site before applying them where irreplaceable parish scheduling data is at stake.

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.
