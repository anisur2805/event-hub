# Event Hub — REST API Contract (`event-hub/v1`)

Every route: `permission_callback` explicit, permission_headers etc. All responses `application/json; charset=UTF-8`. Errors are WP_Error style:

```json
{ "code": "eh_capacity_full", "message": "This event is fully booked.", "data": { "status": 409 } }
```

## Authentication

- Public endpoints: no auth. The POST /rsvp endpoint accepts a nonce (`X-WP-Nonce` header or `_wpnonce` field) created with action `wp_rest` for CSRF mitigation on cookie-authenticated browsers. Additionally IP-based rate limiting applies (see THREAT-MODEL.md). Since Phase 1 every POST — with or without cookies — must also carry a server-rendered `formToken` (honeypot empty); the guard notes under the RSVP route are normative (see THREAT-MODEL.md T15).
- Gated endpoints require WP auth cookie or Application Password, capability `edit_others_posts` (decision #2).

## Routes

### POST `/event-hub/v1/rsvp` — public RSVP

Request `application/json` (or form-encoded):

| Field | Type | Constraints |
| --- | --- | --- |
| eventId | integer | >0, existing `eh_event` post, `publish` status, else `404` `eh_event_not_found` |
| name | string | 1..100 chars after trim, else `400` `eh_invalid_name` |
| email | string | `sanitize_email()` result must be valid, else `400` `eh_invalid_email` |
| status | string | optional; `going` (default) or `waitlist`, else `400` `eh_invalid_status` |
| guests | integer | optional (default 1); seats this row reserves, clamped into 1..10 (ROADMAP 2.3, `Rsvp_Repository::MAX_GUESTS`). Capacity counts SEATS — a party larger than the remaining seats books `going` only while booked seats < capacity, else it lands on the waitlist path the same as any full event |
| phone | string | optional (default ''); attendee phone, `sanitize_text_field()`, no pattern gate (formats too varied — `Rsvp_Repository::MAX_PHONE` (32) is the gate; past it `400` `eh_invalid_phone`). Never echoed in the 201 body (PII rule); visible to organizers in the admin list and CSV |
| note | string | optional (default ''); a note for the organizer, `sanitize_textarea_field()` (newlines kept); past `Rsvp_Repository::MAX_NOTE` (500) `400` `eh_invalid_note`. Same visibility rule as phone |
| formToken | string | **required.** `{timestamp}.{hmac}` token rendered into the RSVP form (`event_hub_form_ts` hidden input, HMAC-signed with the nonce salt, bound to the event). Must be `3s..24h` old at submit, else `400` `eh_form_token` |
| honeypot | string | must be empty/absent; any non-empty value = bot, `400` `eh_honeypot` (checked before formToken and before the rate meter) |

Success `201`:

```json
{ "rsvp": { "id": 12, "eventId": 55, "name": "Ada", "status": "going", "guests": 2, "createdAt": "2026-10-25T14:03:00+00:00" }, "counts": { "going": 41, "waitlist": 3 } }
```
No email in the response body (PII rule).

Error codes:

| code | HTTP | When |
| --- | --- | --- |
| `eh_invalid_name/email/status`, `eh_missing_param` | 400 | validation |
| `eh_rest_cookie_invalid` (i.e., nonce check failed while cookies present) | 403 | CSRF guard |
| `eh_event_not_found` | 404 | no such published event |
| `eh_event_passed` | 409 | event end datetime in the past |
| `eh_rsvp_closed` | 409 | inside the close window (ROADMAP 2.4: `eh_rsvp_close_hours` hours before `eh_start`; 0 = at start) — gates going AND waitlist |
| `eh_rsvp_not_open` | 409 | before the open window (`eh_rsvp_open_hours` hours before `eh_start`; 0 = at start). Missing/unparseable `eh_start` or cutoff meta keeps the window open (never blocks on missing data) |
| `eh_duplicate` | 409 | same email already RSVP'd to this event |
| `eh_capacity_full` | 409 | going-capacity exhausted; body includes `counts` |
| `eh_honeypot` | 400 | honeypot field non-empty (bot classifier; message is deliberately generic) |
| `eh_form_token` | 400 | token missing/forged/wrong-event/too-fast (<3s)/stale (>24h) — one code for every failure mode (no oracle) |
| `eh_rate_limited` | 429 | > 10 RSVP POSTs / 10 min / IP (transient) |

Retry-After: 600 header on 429.

### GET `/event-hub/v1/events` — public event list

Query: `categories` (comma-separated slugs), `perPage` 1..50 (default 10).

Response `200`: array of published, upcoming events (end >= now... see below), each:

```json
{ "id": 55, "title": "Launch", "excerpt": "…", "start": "ISO8601", "end": "ISO8601", "venue": "Main Hall", "capacity": 100, "counts": { "going": 41, "waitlist": 3 }, "categories": ["meetup"], "rsvpOpen": true }
```

`rsvpOpen` = published AND end in future AND (capacity null or going count < capacity) AND RSVP window open (ROADMAP 2.4: not before `eh_rsvp_open_hours` and not at/after `eh_rsvp_close_hours` before start, when those meta are set). The list payload reflects cutoff state but does not expose the raw hours meta — read it from post meta if needed. Emails never serialized. Sorting: start ASC. Events whose end has passed are excluded.

### GET `/event-hub/v1/rsvps` — capability `edit_others_posts`

Query: `eventId` (required), `page` >=1, `perPage` 1..100 (default 20), `status` (`going|waitlist`).

Response `200`: `{ "rsvps": [ { "id", "eventId", "name", "email", "status", "phone", "note", "guests", "createdAt", "checkedInAt": string|null } ], "total", "totalPages" }`. Emails allowed here (gated); `phone`/`note` (DB 1.7.0) read '' when not given.

Errors: `400 eh_missing_event` when eventId missing; `403 rest_forbidden` when lacking cap.

### POST `/event-hub/v1/rsvps/{id}/check-in` — capability `edit_others_posts`

Organizer attendance stamp (ROADMAP §2.2). Body: `checkedIn` (bool, default
`true`; `false` clears). Success `200`: `{ "updated": true, "rsvp": { … } }`
with the gated row shape above (`checkedInAt` ISO 8601 or `null`).

Never touches `status` or any capacity state; attendance is not status-bound
(a waitlist row can carry a check-in stamp in queries, though the admin UI
only stamps from the list). No advisory lock: the write touches only
`checked_in_at`, which no capacity computation consumes.

Errors: `404 eh_rsvp_not_found`; `403 rest_forbidden`; `500 eh_db_error`.

### DELETE `/event-hub/v1/rsvps/{id}` — capability `edit_others_posts`

Success `200`: `{ "deleted": true, "previous": { "id", "eventId", "name", "email", "status", "createdAt" }, "promoted": { … } | null }`.

Waitlist auto-promotion (ROADMAP §1.3): when the deleted row was `going` and
capacity allows, the earliest `waitlist` row (FIFO by `created_at`, id
tiebreak) is promoted to `going` inside the same advisory-lock window; the
response echoes it as `promoted` (same shape as `previous`, admin-gated) and
`event_hub_rsvp_promoted` fires with `(promoted_row, event_id)` for
downstream listeners. `promoted` is `null` when nothing was promoted —
waitlisted row deleted, no waiters, or capacity exhausted/shrunken.

Errors: `404 eh_rsvp_not_found`; `403 rest_forbidden`; `409 eh_busy` when the
event is mid-write (advisory-lock timeout); `500 eh_db_error` when a write
fails (delete or promotion) — the row's state is reported, never masked.

### GET `/event-hub/v1/events/{id}/counts` — public

`200 { "eventId": 55, "counts": { "going": 41, "waitlist": 3, "checkedIn": 12 } }` or `404 eh_event_not_found`. `checkedIn` counts rows with a check-in stamp across statuses (since DB 1.2.0).

### POST `/event-hub/v1/rsvp/cancel` — public, token-gated (ROADMAP §2.1)

Attendee-side cancellation via the manage-booking token (DECISIONS #39). Body:
`{ "token": "<64-hex manage token>" }`. The token IS the credential — the
permission callback resolves the row from it; there is no capability or
cookie nonce on this route (no session exists; THREAT-MODEL T16).

Success `200`:

```json
{ "cancelled": true, "promoted": { "id": 41, "name": "Wait One", "status": "going", … } | null, "counts": { "going": 41, "waitlist": 2, "checkedIn": 0 } }
```

Cancellation runs through the same `delete_and_promote()` as admin deletes:
waitlist promotion (§1.3) and `event_hub_rsvp_promoted` behave identically.
`promoted` uses the public serializer (no email, PII rule).

Errors: `400 eh_manage_missing` (token absent), `404 eh_manage_invalid`
(unknown/garbage token, or the row vanished mid-request), `500 eh_db_error`.

## Manage-booking page (front end, non-REST)

`GET /?eh_manage=<token>` renders the token-gated manage page
(`includes/templates/manage-rsvp.php`): booking summary + no-JS forms for
"update name" and "cancel my RSVP", posting to `admin_post` handlers
(`eh_manage_rename` / `eh_manage_cancel`) that re-verify the token and a
per-action nonce against the rendered page. Unknown tokens render the same
chrome with the invalid message (no enumeration oracle).
`Cache-Control: no-store`; the page is `noindex`.

Status/feedback rides the `eh_state` query arg (`cancelled|saved|invalid|expired|error`)
on redirects — a fixed whitelist, never user input.

The confirmation email (opt-in, `Admin_Page → Confirmation emails` toggle,
option `event_hub_confirmations`, default off) is plain text and carries the
one-time-visible raw manage token as the link `/?eh_manage=<token>`. Token
storage is SHA-256-only (`manage_token` column, DB 1.3.0): the raw token
cannot be recovered from the database, so a leaked DB dump cannot mint
manage links. Rows created before DB 1.3.0 (and rows created while the
toggle is off — see DECISIONS #39) have no token and remain admin-managed.

## Non-REST endpoints

### ICS `GET /event-hub/v1/event/{id}/calendar.ics` and `/event-hub/v1/events/calendar.ics`
REST route (permission: public) returning `text/calendar; charset=utf-8` with `Content-Disposition: attachment`. RFC 5545: CRLF, folding at 75 octets, escaping per §3.4.1. `404 eh_event_not_found` for unknown ids. This avoids rewrite-rule mumbo-jumbo... (decision #9: ICS served through REST route, not a rewrite tag).

Also `GET /?eh_export=rsvps&event_id=...&_wpnonce=...` **admin-only** CSV export (`export.php`), capability `edit_others_posts` + nonce `eh_export_rsvps`. Streams `text/csv` with UTF-8 BOM. Formula injection neutralization: cells matching leading `= + - @ \t \r` get a leading `'`.

## Pagination / rate limit notes

Rate limit counts POST /rsvp only, keyed by IP via transients (network LWPD... see threat model). Gated GET/DELETE unlimited (logs, admin).

Form guard (Phase 1): the honeypot + formToken checks run in this order inside POST /rsvp: event-independent validation (400s) → honeypot → formToken → per-IP meter. Tokens are stateless (`event-hub/v1` has no storage). Reusing a token inside its 24h window is allowed; the meter, not the token, caps volume. Cookie-less clients (curl) must send formToken too — it replaced the old "unauthenticated clients may POST without nonce" allowance.

## Versioning

Namespace `event-hub/v1`. Breaking changes → `event-hub/v2`, v1 kept one minor release.
