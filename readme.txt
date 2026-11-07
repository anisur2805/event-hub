=== Event Hub ===
Contributors: (this is a personal plugin)
Tags: events, rsvp, waitlist, calendar, interactivity api
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events + RSVP with capacity-aware registration, waitlist auto-promotion, ICS/CSV export, and Interactivity API blocks.

== Description ==

Event Hub adds an events custom post type with capacity-aware RSVP
registration. When an event fills up, signups flip to a FIFO waitlist and
delete/cancel actions auto-promote the next row inside one advisory-lock
window.

Features:

* Events CPT (`eh_event`) with start/end datetimes, venue and organizer
  entities, capacity, cutoff windows, and an `eh_event_category` taxonomy.
* Capacity-aware RSVP — atomic `INSERT … SELECT … WHERE going < capacity`.
* Waitlist auto-promotion on every removal path.
* Blocks — Event List (grid of cards or compact list), Event Card, Event
  Categories, Calendar, RSVP Button — built on the WordPress Interactivity
  API (server-rendered, progressive enhancement; counts and forms work
  without JavaScript).
* Recurring events (daily/weekly/monthly) as real child posts.
* Per-event timezone override on top of the site timezone.
* Event status (cancelled / postponed) with attendee notices and JSON-LD.
* One-click duplicate: copies an event as a draft, carrying its dates,
  venue, organizer and settings.
* Pre-event reminder emails (opt-in) and status-notice emails (opt-in).
* Settings screen with email copy overrides — rewrite the subject and
  opening paragraph of all five attendee emails; empty fields keep the
  built-in copy.
* Admin RSVP manager with check-in tracking, CSV export, and WP-CLI export.
* ICS calendar feed; per-event CSV export.
* Privacy: WP personal-data exporter and eraser (anonymize in place).
* Abuse hardening — honeypot + HMAC time-trap token, per-IP rate limiting.

== Installation ==

1. Upload the `event-hub` folder to `/wp-content/plugins/`.
2. Activate through the Plugins screen.
3. Add events under Events → Add New, then place the Event List block or the
   patterns anywhere.

== Frequently Asked Questions ==

= Does registration work without JavaScript? =

Yes. Every surface is server-rendered; the Interactivity API layer only
enhances it.

= Are attendee emails public? =

No. Public responses carry aggregates only; emails appear solely on admin
surfaces and in the opt-in emails the plugin sends.

== Changelog ==

= 1.0.0 =
* Initial release.
