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

> **Done (2026-11-05).** Dashboard widget (`Dashboard_Widget`, gated at
> registration on `edit_others_posts`; function_exists guard for non-wp-admin
> contexts) + server-rendered Admin_Page "Summary" tab (`?tab=summary`,
> whitelisted, React bundle skipped when active). Upcoming events with
> seat-based fill ("12 / 40 seats (30%)", clamped at 100%; unlimited when
> no capacity), waitlist depth in seats, recent RSVPs across all events
> with emails (shared `Rsvp_Repository::recent()` — the slice's one new
> query). Shared label helpers so both surfaces never drift. DECISIONS #42.
> Tests: `Dashboard_Test` (12) + 4 Stage5 probes.

Admin dashboard widget + admin-page summary tab: upcoming events, fill %, waitlist
depth, recent RSVPs (emails visible here — authenticated admin surface only).
**Files:** new `Dashboard_Widget.php`, `Admin_Page.php` tab.

### 3.2 Recurring events (series)
~~Parent event + occurrence CPTs or a `series_id` meta with per-occurrence RSVPs.
Touches schema, REST filtering, ICS and archive logic — plan before coding.~~
**done (2026-11-05)** — shipped with a DELIBERATE architecture change vs. both sketched
options (no second CPT, no `series_id` filter layer): the SEED is the first occurrence.
A series = any published `eh_event` with non-empty `eh_series_freq`; occurrences are
real published child posts (`post_parent` = seed ID). Zero changes to any read surface
(REST list, ICS, upcoming bar, calendar window, admin picker all keep querying
`eh_start`); every occurrence has its own RSVP list by construction. Engine is
additive-only (never edits or deletes children), 6-month rolling horizon, 200-spawn
run cap, twice-daily cron, deferred (shutdown) materialization after the editor's
4-key meta write lands, and children can never become seeds (no sub-series forks).

### 3.3 Calendar view block
~~Front-end month grid (Interactivity API) fed by the events REST route; builds on the
existing seeding pattern (`script_module_data_*`).~~ **done (2026-11-05)** — shipped as
`event-hub/event-calendar` with a DELIBERATE architecture change vs. the sketch: no REST
route is involved. One PHP render path (whole month server-side inside a
`data-wp-router-region`), prev/next are real `?eh_month=YYYY-MM` GET links that work
without JS; with JS the core interactivity-router re-renders the region (same pattern as
core pagination). Month window is site-timezone; known cap: 200 events/month.

### 3.4 WooCommerce paid events (knowingly out of scope for now)
RSVP gated by order/completion. Huge scope (HPOS, checkout blocks, refunds) — keep
parked unless explicitly requested. External review (2026-11-06) concurs: park it and
perfect the free-event experience first.

---

## 5. Competitive parity wave (2026-11-07, from the external review)

Order agreed with the user: WP-CLI extras (§4) FIRST, then these tiers, and ONLY THEN
the §4 .pot/RTL + PCP re-baseline (so translation + check runs happen once, on final
code).

### WP-CLI extras (§4)

> **Done (2026-11-06, docs/DECISIONS.md #46).** `wp event-hub list` (Cli.php) and
> `wp event-hub rsvp export <id>` (Cli_Rsvp.php); the export rides Csv_Exporter's
> COLUMNS + csv_row() so CLI output cannot drift from the admin stream.

### Tier 1 — plugin feels incomplete without these
- **5.1 Venue & Organizer as first-class entities** — CPTs (`eh_venue`, `eh_organizer`)
  reusable across events, address etc.; JSON-LD `location`/`organizer` enriched from
  them; event meta picks one of each.

  > **Done (2026-11-06, docs/DECISIONS.md #47).** CPTs under the Events menu
  > (no front-end routes), `eh_venue_id`/`eh_organizer_id` link meta with
  > legacy free-text fallback on every surface, JSON-LD Place+address and
  > organizer enrichment, editor comboboxes, series spawn inheritance.
- **5.2 Reminders & notifications** — cron reminder N hours before start; attendee
  notice on cancel/reschedule; organizer notification on new RSVP.

  > **Done (2026-11-06, DB 1.5.0, docs/DECISIONS.md #48).** One master toggle
  > (`event_hub_notifications`, default off) behind three email paths:
  > organizer notice (new `event_hub_rsvp_created` on the REST 201 path),
  > cancellation notice (`event_hub_rsvp_cancelled` from delete_and_promote),
  > and the hourly reminder tick (`Reminders`, `reminded_at` column =
  > one-shot; stamp-only-on-wp_mail-accept; rows created inside the window
  > skipped+stamped; per-event `eh_reminder_hours` with '0' = off, junk =
  > 24h default). Reschedule notices deferred to 5.3 (status meta is their
  > slotted home). Tests: `Reminders_Test` (7).
- **5.3 Event status** — Cancelled/Postponed meta surfaced on card + single + JSON-LD
  `eventStatus` (supersedes #36's always-`EventScheduled`).

  > **Done (2026-11-07, DB 1.6.0, docs/DECISIONS.md #49).** `eh_status` enum
  > meta; Cancelled > Postponed > Past pill ladder (PHP + JS mirror); inert
  > RSVP form naming the state; 409 `eh_event_cancelled` guard (postponed
  > stays open); JSON-LD eventStatus + Offer dropped when not scheduled;
  > reminder stage skips non-scheduled events; ICS STATUS:CANCELLED (+
  > summary prefixes); generation-counter notice dedupe riding the 5.2
  > tick (`status_notified_gen` column; re-cancel re-notifies); series
  > children follow the seed both ways, cancelled seeds pause spawning.
  > Tests: `Event_Status_Test` (14).
- **5.4 GDPR** — register WP Privacy `wp_privacy_personal_data_exporters`/`erasers`
  against the `eh_rsvps` table (PII: name, email).
  > Shipped (DECISIONS #50): exporter = all rows for the email as one
  > grouped item (Event/Name/Email/Status/Seats/Registered/Checked-in);
  > eraser ANONYMIZES in place — name → 'Erased', email → `erased-{id}`
  > (no @, so every mail-path `is_email()` gate rejects it), token NULL,
  > both stamp columns maxed — never delete (deleting frees capacity and
  > would fire the cancellation mail AT the person being erased). Both
  > sweeps stamp+skip undeliverable rows (self-healing, no livelock).
  > No DB bump (existing columns only). Tests: `Privacy_Test` (7).
  > **done (2026-11-07)**.

### Tier 2 — parity
- **5.5** ~~Search & filter in Event List (keyword/date/venue/category).~~
  > **done (2026-11-07, DECISIONS #57)** — shared `upcoming_query_args()`
  > builder (block + REST), `eh_q`/`eh_list_month`/`eh_venue` GET vars;
  > calendar's `eh_month` deliberately NOT reused. Tests: `List_Search_Filter_Test` (13).
- **5.6** Virtual events — online URL shown only to confirmed attendees + emails.
  > **done (2026-11-07, DECISIONS #58)** — `_eh_online_url` protected meta +
  > token-gated REST read, status-gated email branches (the waitlist-reminder
  > leak caught in stage-0), public fact-only surfaces, editor panel field.
  > Tests: `Virtual_Event_Test` (11). Per-event timezone SPLIT OUT to its own
  > next slice (see deferral note below).
- **Per-event timezone** (own slice, split from 5.6).
  > **done (2026-11-07, DECISIONS #59)** — `eh_timezone` meta ('' = site
  > zone, no migration), one resolver + one offset-emitter feeding two
  > consumer families (display via offset ISO; instant consumers via
  > explicit zone parse), SQL windows widened ±1-day supersets re-filtered
  > exactly in PHP via the shared `summary_in_month()`. Editor zone select.
  > Tests: `Event_Timezone_Test` (11).
- **5.7** Waitlist management + who's-in notices.
  > **Shipped 2026-11-07 (DECISIONS #60).** The "who's-in notices" half
  > predates the slice (5A.2 promotion email + Waitlist #N); 5.7 IS the
  > manual per-person verb: `promote()` + `POST /rsvps/{id}/promote` +
  > admin Promote button, sharing ONE guarded flip + per-event advisory
  > lock with the auto FIFO path. FIFO is deliberately bypassed — that is
  > the point of manual promotion. Tests: `Waitlist_Management_Test` (7).
  > No DB bump.

### 5A. Review wave P1 (2026-11-07, second external review — verified against code)

> Both "gaps in spirit" VERIFIED before adoption: (1) the manage token exists only in
> the confirmation email and confirmations default OFF — with stock settings nobody can
> reach the manage page; (2) `event_hub_rsvp_promoted` fires but Notifier has no
> promotion mail (send_confirmation/reminders/cancel_notice/organizer_notice/
> status_change exist; no send_* for promotion), and the manage page shows "Waitlist"
> with no position. Approved scope: P1 only (4 items), review's suggested order.

- ~~**5A.1 Success panel + token in the 201**~~ **done (2026-11-07, DECISIONS #53)** —
  `manageToken` rides the `POST /rsvp` 201 body (creator-only by construction,
  never logged); hidden-at-SSR success panel with the manage link, copy button
  and cancel hint; works with confirmations OFF (that is the point); no DB change.
- ~~**5A.2 Promotion email + waitlist position**~~ **done (2026-11-07, DECISIONS #54)** —
  `Notifier::send_promotion_notice()` on `event_hub_rsvp_promoted`, gated by
  `event_hub_notifications` (notice mail, not confirmations); manage page renders
  "Waitlist #N" (FIFO `waitlist_position()` repo helper; going rows stay plain).
  No DB change.
- ~~**5A.3 Add to Google / Outlook buttons**~~ **done (2026-11-07, DECISIONS #55)** —
  plain GET links over the same summary() data the ICS feed uses
  (`calendar_links()`; provider-native UTC datetime forms, per-value
  rawurlencode, entity-decoded title/venue, permalink in details; hidden for
  past/unparseable/no-start events). No API keys, no new deps, no DB change.
- ~~**5A.4 External registration URL**~~ **done (2026-11-07, DECISIONS #56)** —
  per-event `eh_external_url` (plain string meta, read-side gate
  `Render_Blocks::external_url()`: http/https + host or ''); when valid, the
  RSVP region folds to one outbound Register link (no iAPI region, count line
  stays); past/cancelled/cutoff notices outrank it; summary() exposes it;
  series meta_copy carries it; editor commits only plausible absolute URLs.

### 5B. Deferred from the review (not adopted now)
- Email attendees (admin compose), duplicate-event row action, phone check-in screen,
  settings screen with editable email copy, per-event phone/note fields (DB 1.7.0 +
  privacy pairing), calendar list view, venue directions link — revisit after 5A.
  > **Duplicate-event shipped 2026-11-07 (DECISIONS #61)** — `Event_Duplicator`,
  > Copy row action, draft copy with the spawn-aligned meta carry list; series +
  > status + RSVP rows deliberately skip.
  > **Settings screen + email copy + Event List list layout shipped 2026-11-07
  > (DECISIONS #62)** — tab 3 "Settings" (toggles moved there), per-email
  > subject/intro overrides under option `event_hub_email_copy` (empty =
  > built-in copy), Event List `layout` attribute with a compact `render_row()`
  > path; card template gated to grid.
  > **Calendar list view shipped 2026-11-07 (DECISIONS #63)** — Event Calendar
  > `view` attribute; list mode renders the month through the same
  > `render_row()`; nav/label/count shared, calendar.ts untouched.
  > **Venue directions link shipped 2026-11-07 (DECISIONS #64)** —
  > `eh_venue_directions_url` on venues + `Venue_Meta_Box` organizer surface;
  > 5A.4 read-gate twin; single-event venue line anchors when set. Maps deep
  > links are never fabricated (DECISIONS #64 rationale).
  > **Phone check-in shipped 2026-11-07 (DECISIONS #67)** — `POST
  > /events/{id}/check-in` (organizer-gated) + "Check in by phone" panel;
  > suffix digit compare (country code on either side, 7-digit floor both
  > sides), ambiguity 409 reported never guessed, stamps via
  > `set_checked_in()`. **§5B COMPLETE — all 7 deferred items shipped.**
- Email attendees (admin compose): **shipped 2026-11-07, in §5B order after
  the phone/note fields (DECISIONS #66)** — `POST /events/{id}/email`
  (organizer-gated), one wp_mail per recipient, audience filter, no
  persistence; admin compose panel in the RSVPs screen.
- Per-event phone/note fields: **shipped 2026-11-07, in §5B order after the
  venue link (DECISIONS #65, DB 1.7.0)** — optional `phone`/`attendee_note`
  columns with storage-cap guards, form fields, organizer surfaces
  (admin list + CSV) and the privacy export/erase pairing.
- Approval mode REJECTED (would corrupt the atomic seat math). Ticketing/Zoom/Maps
  embeds/front-end submission rejected (projects of their own).
- Per-event timezone: deferred to 5.6 (virtual events) where the wall-clock contract
  must be revisited anyway. → **Re-split 2026-11-07:** 5.6 shipped without it —
  the timezone change touches the wall-clock contract everywhere, so it lands as
  its OWN slice. → **Shipped 2026-11-07 (DECISIONS #59), before 5.7.**

### Tier 3 — later
- **5.10** Front-end event submission (spam/moderation burden).
- **5.11** iCal import; mobile-friendly check-in UI over the existing REST endpoint.
- **5.12** Integration hooks (documented actions/filters) instead of built-in Zapier/
  Mailchimp connectors.

### Findings resolution (user-approved 2026-11-06, do alongside Tier 1)

> **Done (2026-11-06, docs/DECISIONS.md #45).** Waitlist labels pluralized with real
> `_n()`/`_nx()` forms at all three PHP sites + both JS sites (view_strings() seeds
> both msgids); Stage-3 assertions carry the three-key counts shape; Stage-5 probes
> gated behind `EH_STAGE5=1`.
- Waitlist labels change to explicit seat language ("N seats waiting") — semantics stay
  seat-based (correct under §2.3), only wording matters.
- Fix the 2 stale Stage-3 counts-shape assertions (three-key incl. checkedIn).
- Wire the EH_STAGE5 gate the Stage5 header already promises.

---

## 4. Compliance / polish (cheap, pre-release hygiene)

- **.pot template + RTL check** — `wp i18n make-pot`, verify `--eh-*` layout under RTL.
  > Shipped (DECISIONS #51): POT generated (295 msgids, JS strings via build
  > references; duplicate-comment warning resolved by harmonizing the
  > `%s event found` translator comment); RTL was already logical-property
  > clean — added the missing `wp_style_add_data( 'rtl', 'replace' )`
  > wiring for both handles. **done (2026-11-07)**.
- **PHPStan/PCP re-baseline** — run Plugin Check after each slice above, not just at release.
  > Shipped (DECISIONS #51): PCP now reports ZERO errors on every runtime
  > file — removed the example.com Plugin URI, ABSPATH guards on patterns +
  > CLI stub, new .org-format readme.txt (Tested up to 7.1). Remaining
  > errors are dev-infrastructure files only; warning classes documented
  > as justified (#51). **done (2026-11-07)**.
- **WP-CLI extras** — `wp event-hub rsvp export <event>` using `Csv_Exporter`, `wp event-hub list` counts.
- **Uninstall audit** — confirm `Migrations::uninstall()` covers every new option/column added by any slice.
  > Shipped (DECISIONS #52): audit caught a latent FATAL (`\Migrations`
  > global-namespace call against the namespaced class — real deletions
  > would have died mid-uninstall) plus two gaps: eh_event_category terms
  > now removed, migration cron unscheduled. Every sweep asserted by
  > Uninstall_Test (DDL exempt — core testcase query filter, see #52).
  > **done (2026-11-07)**. §4 complete.

---

## Suggested order

1. BUG 3 / 5 / 6-7 cleanups (§0) — **done, Phase 0**.
2. §1.2 hardening — **done, Phase 1**.
3. §1.3 waitlist promotion — **done, Phase 2**.
4. §1.1 JSON-LD — **done (2026-11-04)**; was independent, slotted after Phase 2.
5. Then pick by need: ~~2.1 (email flow; its cancellation now feeds §1.3's promotion)~~ **done (2026-11-04, DB 1.3.0)**; ~~2.2 (check-in)~~ **done (2026-11-04)**; ~~2.4 cutoffs~~ **done (2026-11-05)**; ~~2.3 seats~~ **done (2026-11-05, DB 1.4.0)**; ~~3.1 dashboard~~ **done (2026-11-05)**; ~~3.3 calendar block~~ **done (2026-11-05)**; ~~3.2 recurring events~~ **done (2026-11-05)**. ~~§4 WP-CLI extras~~ **done (2026-11-06)** + findings resolution **done (2026-11-06)**. ~~5.1 venue/organizer~~ **done (2026-11-06)**; ~~5.2 reminders~~ **done (2026-11-06, DB 1.5.0)**; ~~5.3 event status~~ **done (2026-11-07, DB 1.6.0)**; ~~5.4 GDPR~~ **done (2026-11-07)**. ~~§4 .pot/RTL + PCP re-baseline~~ **done (2026-11-07, DECISIONS #51)**; ~~§4 uninstall audit~~ **done (2026-11-07, DECISIONS #52 — caught a latent uninstall fatal)**. Tier 1 + §4 COMPLETE; §3.4 Woo stays parked. 6. **Review wave P1 (5A): ~~5A.1 success panel/token~~ **done (2026-11-07)** → ~~5A.2 promotion email + waitlist position~~ **done (2026-11-07)** → ~~5A.3 add-to-calendar~~ **done (2026-11-07)** → ~~5A.4 external URL~~ **done (2026-11-07)** — review wave P1 COMPLETE (approved 2026-11-07; both spirit-gaps verified against code before adoption). Then Tier 2 (5.5/5.6/5.7) by need: ~~5.5~~ **done (2026-11-07, #57)**; ~~5.6~~ **done (2026-11-07, #58)**; ~~per-event timezone~~ **done (2026-11-07, #59)**; ~~5.7 manual promotion~~ **done (2026-11-07, #60)** — Tier 2 COMPLETE. Next: §5B deferred items (duplicate-event **done 2026-11-07, #61**; settings screen + email copy + Event List layout **done 2026-11-07, #62**; calendar list view **done 2026-11-07, #63**; venue directions link **done 2026-11-07, #64**; per-event phone/note fields **done 2026-11-07, #65, DB 1.7.0**; email attendees compose **done 2026-11-07, #66**; phone check-in **done 2026-11-07, #67 — §5B COMPLETE**), then Tier 3 (5.10–5.12).
