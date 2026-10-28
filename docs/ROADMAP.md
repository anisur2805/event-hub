# Roadmap — candidate next features

Prioritized backlog for Event Hub, grounded in the current surface: CPT `eh_event` +
`eh_event_category`; blocks event-list / event-card / event-categories / rsvp-button
(Interactivity API); REST routes (create RSVP anonymous, events, counts, RSVP list/delete,
single + calendar ICS); CSV exporter; admin columns + RSVPs deep-link; `eh_rsvps` table
(id, event_id, name, email, status, created_at — UNIQUE event_id+email) at DB version 1.0.0;
WP-CLI `event-hub-migrate`.

Standing constraints that bound every idea below:
- Attendee emails are **never** exposed through public endpoints — count-only responses stay the contract.
- Seconds on RSVP writes stay atomic (`INSERT … SELECT … WHERE going < capacity`).
- Anything schema-touching bumps `EVENT_HUB_DB_VERSION` and rides the existing `Migrations` machinery.

---

## 0. Known deferred fixes (do first — small, already analyzed)

> **Done in Phase 0 (2026-10):** BUG 3 (ICS excludes ended events), BUG 5 contract (parse_capacity + admin warning), BUG 6/7 (clamped labels). Kept here one release for reference.

| Ref | Item | Files |
|---|---|---|
| BUG 3 | ICS feed serves past events (should filter `eh_end >= now`, matching the archive/REST rule) | `Ics_Exporter.php`, `Rest_Controller.php` |
| BUG 5 | Non-numeric capacity stays "unlimited" by contract — decide: reject at save, or document + admin warning | `Editor_Panel.php`, `Render_Blocks.php`, `Rsvp_Repository.php` |
| BUG 6/7 | Capacity wording not clamped ("45 of 40 spots filled", user-visible "8 of 4") — clamp visible label + `aria-valuetext` serverside | `Render_Blocks.php` |

---

## 1. Shortlist (recommended next slice — highest value per effort)

### 1.1 Event JSON-LD structured data (SEO)
Emit schema.org `Event` markup (name, startDate/endDate, location, eventStatus,
`offers` with availability + capacity) on single-event pages. Zero privacy risk
(no emails, aggregates only). Cheap, measurable win for an events plugin.
**Files:** `templates/single-eh_event.php` or a `Structured_Data` class hooked to `wp_head`.
**Accept:** Rich Results test passes on a live event; past events emit correct `eventStatus`.

### 1.2 RSVP abuse hardening (honeypot + time trap + per-IP throttle)

> **Done in Phase 1 (2026-10).** Baseline correction: a per-IP transient throttle (10 POSTs / 10 min / IP, `eh_rate_limited` 429 + `Retry-After`) already existed when this phase started — the original "endpoint is protected only by the per-email UNIQUE key" claim was outdated. The Phase 1 delta was the honeypot field + stateless HMAC time-trap token (`Form_Guard`), wired through Render_Blocks → store.ts → Rest_Controller, with tests in `Phase1_Hardening_Test`. See THREAT-MODEL T15, API.md form-guard notes.

### 1.3 Waitlist auto-promotion
When an RSVP is deleted (admin REST `DELETE` route already exists), promote the earliest
`waitlist` row to `going` atomically — inside the same transaction window as the delete —
and hook an action (`event_hub_rsvp_promoted`) for anything downstream.
**Files:** `Rsvp_Repository.php` (promo method), `Rest_Controller.php`.
**Accept:** deleting a "going" row on a full event promotes waitlist #1 and keeps counts exact.

---

## 2. Second wave (self-contained, medium effort)

### 2.1 RSVP confirmation + cancellation by token
Confirmation flow for anonymous RSVPs: after `create_rsvp`, store a signed token
(hash of email+event+salt) and offer an emailed "manage booking" link → cancel my
RSVP / update name. Cancellation then feeds 1.3's promotion. Emails go only to the
attendee's own address; nothing public changes.
**Files:** new `Notifier.php` (+ `wp_mail` wrapper with timeout-safe failure), token
column via DB 1.1.0 migration, `Rest_Controller.php` cancel route, template.
**Depends on:** decision — opt-in settings page toggle ("send confirmations") in `Admin_Page.php`.

### 2.2 Check-in / attendance tracking
Add `checked_in_at DATETIME NULL` (DB 1.2.0); admin list-table quick-action
"Check in" per RSVP row, counts surface "checked in" separate from "going"; CSV
exporter gains the column; ICS unaffected.
**Files:** `Migrations.php`, `Rsvp_Repository.php`, `Admin_Page.php`, `Csv_Exporter.php`, `Admin_Columns.php`.
**Accept:** check-in survives on plain post-type CRUD (no HPOS surface); counts endpoint returns 3 numbers.

### 2.3 Guests / seats per RSVP
One row can reserve N seats: `guests INT UNSIGNED NOT NULL DEFAULT 1` (DB 1.3.0).
Capacity math switches from row-count to `SUM(guests)` — the atomic insert's
`going < capacity` becomes a guarded conditional-sum (same `INSERT…SELECT…WHERE`
shape, subquery does the SUM). Front end gets an optional seats select.
**Files:** `Migrations.php`, `Rsvp_Repository.php`, `Render_Blocks.php`, rsvp-button block JS.
**Careful:** this is the riskiest change on the list — capacity invariants need fresh PHPUnit coverage first.

### 2.4 RSVP cutoff meta
"RSVP opens/closes X hours before start" event meta; `rsvpOpen` derivation and the
REST `create_rsvp` guard both honor it. No schema change (post meta).
**Files:** `Editor_Panel.php`, `Render_Blocks.php`, `Rest_Controller.php`.

---

## 3. Larger bets (own milestone each)

### 3.1 Organizer dashboard
Admin dashboard widget + admin-page summary tab: upcoming events, fill %, waitlist
depth, recent RSVPs (emails visible here — authenticated admin surface only).
**Files:** new `Dashboard_Widget.php`, `Admin_Page.php` tab.

### 3.2 Recurring events (series)
Parent event + occurrence CPTs or a `series_id` meta with per-occurrence RSVPs.
Touches schema, REST filtering, ICS and archive logic — plan before coding.

### 3.3 Calendar view block
Front-end month grid (Interactivity API) fed by the events REST route; builds on the
existing seeding pattern (`script_module_data_*`).

### 3.4 WooCommerce paid events (knowingly out of scope for now)
RSVP gated by order/completion. Huge scope (HPOS, checkout blocks, refunds) — keep
parked unless explicitly requested.

---

## 4. Compliance / polish (cheap, pre-release hygiene)

- **.pot template + RTL check** — `wp i18n make-pot`, verify `--eh-*` layout under RTL.
- **PHPStan/PCP re-baseline** — run Plugin Check after each slice above, not just at release.
- **WP-CLI extras** — `wp event-hub rsvp export <event>` using `Csv_Exporter`, `wp event-hub list` counts.
- **Uninstall audit** — confirm `Migrations::uninstall()` covers every new option/column added by any slice.

---

## Suggested order

1. BUG 3 / 5 / 6-7 cleanups (§0) — closes known rough edges.
2. §1.2 hardening — the public endpoint is the one currently-abusable surface.
3. §1.3 waitlist promotion — tiny, sets up cancellation.
4. §1.1 JSON-LD — independent, can slot anywhere.
5. Then pick by need: 2.1 (email flow) or 2.2 (check-in) first; 2.3 (seats) only with fresh capacity tests.
