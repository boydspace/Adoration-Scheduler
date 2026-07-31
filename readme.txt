=== Adoration Scheduler ===
Contributors: boydspace
Tags: adoration, eucharistic adoration, scheduling, church, parish
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin website: https://fatherboyd.com/adoration-scheduler

A powerful yet pastoral scheduling system for Eucharistic Adoration, built specifically for Catholic parishes.

== Description ==

Adoration Scheduler is a comprehensive scheduling plugin designed to help Catholic parishes manage Eucharistic Adoration signups with clarity, accountability, and reverence.

Built from real parish needs, Adoration Scheduler supports one-time events, weekly perpetual (round-the-clock) adoration, and monthly nth-weekday devotions (such as First Friday), across multiple chapels or locations — with automatic email notifications and a secure, modular "My Adoration" portal where adorers can manage their commitments without needing a WordPress account.

This plugin prioritizes:

* Ease of use for parish staff
* Clear communication with adorers
* Safe handling of personal data
* Reliability for long-running schedules

No external services are required for core functionality. SMS text reminders (Twilio) and anti-spam verification (Cloudflare Turnstile) are optional features a site administrator can separately choose to enable — see "External Services" below.

For documentation, updates, and support, visit the plugin's website: https://fatherboyd.com/adoration-scheduler

== Key Features ==

* Three schedule types: single/multi-day events, weekly perpetual adoration with standing commitments, and monthly nth-weekday devotions (e.g. First Friday, Last Sunday)
* Support for multiple chapels / locations, including overnight hours
* Automatic generation of adoration time slots, with a daily background sync for perpetual and monthly schedules
* Minimum and maximum adorers per hour, with schedule closures (holidays, cancellations) respected automatically
* Public signup form — no WordPress account required
* Secure "magic link" email sign-in, with an optional password as a second sign-in method
* Optional approval-gated access: parishes can require a short access request before adorers can view or use the portal
* A modular "My Adoration" portal built from composable shortcodes (standing hours & upcoming signups, profile card, replacement requests, announcements, and more) so parishes can lay out their own page
* Replacement requests: an adorer can flag an hour as needing coverage without cancelling outright, either asking the whole community of opted-in substitutes or asking one specific person directly
* Daily coverage-gap alert emails, warning parish staff about unfilled hours coming up soon
* Parish announcements shortcode for the portal, with an optional public front-page feed and an image-carousel slider when several are live
* Email confirmations, reminders, and cancellations, plus an admin email log and resend tools
* Optional SMS text reminders via Twilio, with each adorer separately choosing their own channel(s) and reminder lead time
* No-account adorers: admins can add someone to a one-time signup or a standing weekly commitment with no email address at all, for adorers who don't want an online account
* Signup audit trail for accountability
* Privacy controls for public listings and a consolidated, simplified admin menu

== Included Shortcodes ==

* `[adoration_schedule]`
  Displays a public signup schedule for events, perpetual weekly hours, or monthly occurrences.

* `[adoration_my_schedule]`
  Standing hours and upcoming signups, with cancel/skip and replacement-request actions.

* `[adoration_needed_replacements]`
  The community "Coverage Needed" list, an "Asked of You" section for direct requests, and recently fulfilled requests.

* `[adoration_my_replacement_requests]`
  The signed-in adorer's own open replacement requests, with an undo/reopen action.

* `[adoration_profile_card]`
  The adorer's profile (name, title, parish, contact info) with self-service editing.

* `[adoration_account_status]`
  A short summary of the adorer's account and access status.

* `[adoration_next_adoration_hour]`
  A quick "your next commitment" widget.

* `[adoration_announcements]`
  Parish announcements posted from the admin dashboard, shown to signed-in members in the admin-controlled order (Up/Down buttons on the Announcements page). Renders as a UIkit slider, one at a time, when more than one is live.

* `[adoration_public_announcements]`
  The public counterpart — announcements an admin has marked "Show on public front page," visible to everyone, no sign-in required. Safe to place on the homepage or any public page.

* `[adoration_magic_link]`
  The sign-in form (magic link email and/or password).

* `[adoration_request_access]`
  The access-request form, shown when the approval gate is enabled.

Each of these can be placed independently on any page — a parish is not required to use them all in one place. The plugin automatically creates a default "My Adoration" page on install combining the core pieces, and keeps that page working even if shortcodes are edited or removed.

== Installation ==

1. Upload the `adoration-scheduler` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. On activation, the plugin will automatically:
   * Create required database tables
   * Create a default "Main Chapel"
   * Create a "My Adoration" page
   * Schedule its daily background jobs (slot generation, coverage alerts, session cleanup)
4. Visit **Adoration Scheduler** in the WordPress admin menu to begin setup.

No additional configuration is required to get started.

== Usage ==

1. Create one or more chapels/locations.
2. Create an adoration schedule — choose Event, Perpetual, or Monthly — and define its dates and hours.
3. Publish the schedule and embed it using `[adoration_schedule]`.
4. Adorers sign up without creating an account.
5. Adorers receive secure sign-in links (and may optionally set a password) to manage their commitments from the "My Adoration" portal.
6. Parish staff manage signups, replacement requests, and coverage alerts from the admin dashboard.

== Frequently Asked Questions ==

= Do adorers need WordPress accounts? =

No. Adorers access their commitments via a secure, expiring magic-link email (optionally with a password as a second sign-in method), without ever creating a WordPress user account.

= Can I require approval before someone can sign in? =

Yes. An optional access-request/approval gate can be enabled from the admin settings, so new adorers request access first and a parish staff member approves them before they can use the portal.

= What's the difference between the schedule types? =

Event schedules are for one-time or multi-day occasions with specific dates. Perpetual schedules are for ongoing weekly adoration (e.g. round-the-clock), where adorers can take a standing weekly commitment. Monthly schedules are for a recurring nth-weekday devotion, such as "First Friday" or "Last Sunday," where each occurrence is its own one-time signup.

= How do replacement requests work? =

An adorer who can't make their scheduled hour can request a replacement instead of cancelling outright. By default this notifies the parish and everyone who has opted in as a substitute. An adorer can also ask one specific person directly — that request stays private to the two of them (plus parish staff) until it's covered or reopened to the whole community.

= Where can I get more information or support? =

Visit https://fatherboyd.com/adoration-scheduler for documentation and updates.

== External Services ==

This plugin optionally connects to Twilio's SMS API to deliver text message reminders. This only happens if:

1. A site administrator enables SMS reminders and enters their own Twilio account credentials (Account SID, Auth Token, and phone number) under Adoration Scheduler > SMS Reminders, AND
2. An individual adorer separately opts in to text reminders on their own "Reminder Preferences" dashboard widget.

If both conditions are met, this plugin sends the adorer's phone number and a reminder message (which includes their name and adoration schedule details) to Twilio's API (api.twilio.com) so it can be delivered as a text message. No data is sent to Twilio for any adorer who has not opted in, and no data is sent to Twilio at all unless an administrator has configured it.

Twilio Terms of Service: https://www.twilio.com/legal/tos
Twilio Privacy Policy: https://www.twilio.com/legal/privacy

This plugin optionally uses Cloudflare Turnstile to help block spam and automated submissions on the public signup form. This only happens if a site administrator enables it and enters their own Turnstile site/secret keys under Adoration Scheduler > Anti-Spam Settings. When enabled, each public signup submission sends the visitor's Turnstile response token and IP address to Cloudflare's verification API (challenges.cloudflare.com) to confirm the submission is not automated. No data is sent to Cloudflare unless an administrator has turned this on.

Cloudflare Terms of Service: https://www.cloudflare.com/website-terms/
Cloudflare Privacy Policy: https://www.cloudflare.com/privacypolicy/

== Changelog ==

= 1.0.3 =
* WordPress.org submission readiness: UIkit (used for a few modal dialogs) is now bundled with the plugin instead of loaded from a third-party CDN, per the plugin directory's guidelines.
* Documentation: disclosed Cloudflare Turnstile (optional anti-spam verification) in the "External Services" section, and trimmed the readme's tag list to the directory's 5-tag limit.

= 1.0.2 =
* WordPress.org submission readiness: added the required License header to the plugin's main file, and removed heredoc syntax (not permitted by the Plugin Check tool) in favor of standard strings.

= 1.0.1 =
* Security fix: CSV/XLSX exports (People, Schedules, Coverage Report, Email Log) now neutralize spreadsheet formula injection in exported data.
* Security fix: the Twilio Auth Token and Turnstile Secret Key settings fields no longer re-display the saved secret; leaving them blank on save now keeps the existing value instead of requiring it to be retyped.

= 1.0.0 =
* Three schedule types: single/multi-day events, weekly perpetual adoration with standing commitments, and monthly nth-weekday devotions.
* Secure magic-link email sign-in with an optional password, and an optional approval-gated access system.
* A modular "My Adoration" portal built from composable shortcodes.
* Replacement requests, including direct-to-person requests, plus waitlists with automatic promotion when a spot opens up.
* Editable email templates with merge tags and a send-test tool, plus optional SMS text reminders via Twilio with per-person channel and lead-time preferences.
* Coverage-gap and no-show alert digests for parish staff.
* Parish announcements, with an optional public front-page feed.
* Attendance and check-in: self-report links, a per-chapel kiosk page, and an admin Attendance page.
* Bulk roster import/export (CSV/XLSX), printable rosters, and a Coverage Report with CSV export.
* No-account adorers: admins can add someone to the schedule with no email address at all.
* Self-service "Download My Data" export and "Delete My Account."
* First-run Setup Wizard and a "Finish setting up" Dashboard checklist.
* Signup audit trail, admin email log with resend tools, and an accessibility pass on public-facing pages.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
