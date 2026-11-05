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

> **Done (2026-11-04).** `Structured_Data` class on `wp_head` (covers both theme
> templates and the plugin fallback — see docs/DECISIONS.md #36). Reuses
> `Render_Blocks::summary()`; emits schema.org `Event` with startDate/endDate,
> Place location, featured image, excerpt description, `eventStatus: EventScheduled`,
> and (upcoming only) an `Offer` with `availability` InStock/SoldOut plus
> `maximumAttendeeCapacity`/`remainingAttendeeCapacity` on capacity-set events.
> No price/organizer fabricated; no emails (counts only). Start-less events print
> nothing. Tests: `Structured_Data_Test` (7 cases).

Emit schema.org `Event` markup (name, startDate/endDate, location, eventStatus,
`offers` with availability + capacity) on single-event pages. Zero privacy risk
(no emails, aggregates only). Cheap, measurable win for an events plugin.
**Files:** `templates/single-eh_event.php` or a `Structured_Data` class hooked to `wp_head`.
**Accept:** Rich Results test passes on a live event; past events emit correct `eventStatus`.

### 1.2 RSVP abuse hardening (honeypot + time trap + per-IP throttle)

> **Done in Phase 1 (2026-10).** Baseline correction: a per-IP transient throttle (10 POSTs / 10 min / IP, `eh_rate_limited` 429 + `Retry-After`) already existed when this phase started — the original "endpoint is protected only by the per-email UNIQUE key" claim was outdated. The Phase 1 delta was the honeypot field + stateless HMAC time-trap token (`Form_Guard`), wired through Render_Blocks → store.ts → Rest_Controller, with tests in `Phase1_Hardening_Test`. See THREAT-MODEL T15, API.md form-guard notes.

### 1.3 Waitlist auto-promotion

> **Done in Phase 2 (2026-11).** `Rsvp_Repository::delete_and_promote()` — delete +
> guarded promotion in one advisory-lock window; earliest waitlist row (FIFO) flips
> to `going` only while `going < capacity` re-checked inside the UPDATE (shrunken
> capacity safe). `event_hub_rsvp_promoted` fires after lock release; REST DELETE
> response gains additive `promoted` row (admin-gated). Tests: `Waitlist_Promotion_Test`.

When an RSVP is deleted (admin REST `DELETE` route already exists), promote the earliest
`waitlist` row to `going` atomically — inside the same transaction window as the delete —
and hook an action (`event_hub_rsvp_promoted`) for anything downstream.
**Files:** `Rsvp_Repository.php` (promo method), `Rest_Controller.php`.
**Accept:** deleting a "going" row on a full event promotes waitlist #1 and keeps counts exact.

---

## 2. Second wave (self-contained, medium effort)

### 2.1 RSVP confirmation + cancellation by token

> **Done (2026-11-04, DB 1.3.0).** Design diverged from the sketch below on
> purpose (DECISIONS #39): token = 32 random bytes per row, stored SHA-256-
> hashed (`manage_token` column), raw token visible only in the emailed
> manage link (`/?eh_manage=<token>`) — the sketch's salt-hash of
> email+event would have forced PII into URLs and made wp_salt a forgery
> key. `Notifier` (opt-in toggle on the admin page, `event_hub_confirmations`,
> default off; 10s phpmailer timeout; failure never fails the RSVP) sends the
> plain-text confirmation. `Manage_Page` (`?eh_manage=` on template_include)
> renders booking + no-JS cancel/rename forms (admin_post handlers,
> THREAT-MODEL T16). REST `POST /rsvp/cancel {token}` cancels through
> `delete_and_promote()` so §1.3 promotion fires identically. Tests:
> `Manage_Booking_Test` (11 cases).

### 2.2 Check-in / attendance tracking — **Done (2026-11-04, DB 1.2.0)**
Add `checked_in_at DATETIME NULL` (DB 1.2.0); admin quick-action
"Check in" per RSVP row (in the React admin app over `POST /rsvps/{id}/check-in`
— the roadmap's "list table" predates the React admin), counts surface
"checked in" separate from "going" (`checkedIn` third key in `counts()`), CSV
exporter gains the column; ICS unaffected.
**Files:** `Migrations.php`, `event-hub.php`, `Rsvp_Repository.php`, `Rest_Controller.php`, `Csv_Exporter.php`, `src/admin/{types.ts,index.tsx}`.
**Accept:** check-in survives on plain post-type CRUD (no HPOS surface); counts endpoint returns 3 numbers.

### 2.3 Guests / seats per RSVP

> **Done (2026-11-05, DB 1.4.0).** `guests INT UNSIGNED NOT NULL DEFAULT 1`;
> capacity math is seat-based (`SUM(guests)`) in `create()`,
> `delete_and_promote()`, and `counts()` — cards/bar/pill/JSON-LD/REST stay
> truthful with zero call-site changes. REST `guests` param (default 1,
> clamped 1..`MAX_GUESTS`=10); repository rejects out-of-range with 400
> `eh_invalid_guests`. Front-end seats `<select>` (context.guests +
> actions.setGuests + POST body); admin Seats column; CSV Seats column;
> Notifier + manage page Seats line. Documented tradeoff: the guard admits
> the row while booked-seats < capacity (a party may exceed remaining
> seats; oversized parties self-select to waitlist client-side). Tests:
> `Seats_Test` (10 cases) + updated CSV header assertion. DECISIONS #41.

One row can reserve N seats: `guests INT UNSIGNED NOT NULL DEFAULT 1` (DB 1.3.0).
Capacity math switches from row-count to `SUM(guests)` — the atomic insert's
`going < capacity` becomes a guarded conditional-sum (same `INSERT…SELECT…WHERE`
shape, subquery does the SUM). Front end gets an optional seats select.
**Files:** `Migrations.php`, `Rsvp_Repository.php`, `Render_Blocks.php`, rsvp-button block JS.
**Careful:** this is the riskiest change on the list — capacity invariants need fresh PHPUnit coverage first.

### 2.4 RSVP cutoff meta

> **Done (2026-11-05).** `eh_rsvp_open_hours` / `eh_rsvp_close_hours` post
> meta (hours before `eh_start`, 0 legal, '' = no boundary; capacity-style
> save schema). `Rsvp_Repository::rsvp_cutoffs()` is the single verdict
> source: the `create()` guard (409 `eh_rsvp_closed` / `eh_rsvp_not_open`)
> and `summary()`'s rsvpOpen fold + `cutoffClosed`/`cutoffNotOpen` keys both
> read it; Closed pill + inert closed/not-open button states; editor panel
> fields + inverted-window warning; store.ts maps both 409s. No DB bump.
> Tests: `Rsvp_Cutoff_Test` (12 cases). DECISIONS #40.

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

1. BUG 3 / 5 / 6-7 cleanups (§0) — **done, Phase 0**.
2. §1.2 hardening — **done, Phase 1**.
3. §1.3 waitlist promotion — **done, Phase 2**.
4. §1.1 JSON-LD — **done (2026-11-04)**; was independent, slotted after Phase 2.
5. Then pick by need: ~~2.1 (email flow; its cancellation now feeds §1.3's promotion)~~ **done (2026-11-04, DB 1.3.0)**; ~~2.2 (check-in)~~ **done (2026-11-04)**; ~~2.4 cutoffs~~ **done (2026-11-05)**; ~~2.3 seats~~ **done (2026-11-05, DB 1.4.0)**. Remaining: §3 (3.1 dashboard → 3.3 calendar block → 3.2 recurring → §4 compliance); 3.4 Woo stays parked.
