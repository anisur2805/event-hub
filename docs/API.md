# Event Hub — REST API Contract (`event-hub/v1`)

Every route: `permission_callback` explicit, permission_headers etc. All responses `application/json; charset=UTF-8`. Errors are WP_Error style:

```json
{ "code": "eh_capacity_full", "message": "This event is fully booked.", "data": { "status": 409 } }
```

## Authentication

- Public endpoints: no auth. The POST /rsvp endpoint accepts a nonce (`X-WP-Nonce` or `_eh_nonce` field) created with action `wp_rest` for CSRF mitigation on cookie-authenticated browsers; unauthenticated clients (no cookie) may POST without nonce. Additionally IP-based rate limiting applies (see THREAT-MODEL.md).
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

Success `201`:

```json
{ "rsvp": { "id": 12, "eventId": 55, "name": "Ada", "status": "going", "createdAt": "2026-10-25T14:03:00+00:00" }, "counts": { "going": 41, "waitlist": 3 } }
```
No email in the response body (PII rule).

Error codes:

| code | HTTP | When |
| --- | --- | --- |
| `eh_invalid_name/email/status`, `eh_missing_param` | 400 | validation |
| `eh_rest_cookie_invalid` (i.e., nonce check failed while cookies present) | 403 | CSRF guard |
| `eh_event_not_found` | 404 | no such published event |
| `eh_event_passed` | 409 | event end datetime in the past |
| `eh_duplicate` | 409 | same email already RSVP'd to this event |
| `eh_capacity_full` | 409 | going-capacity exhausted; body includes `counts` |
| `eh_rate_limited` | 429 | > 10 RSVP POSTs / 10 min / IP (transient) |

Retry-After: 600 header on 429.

### GET `/event-hub/v1/events` — public event list

Query: `categories` (comma-separated slugs), `perPage` 1..50 (default 10).

Response `200`: array of published, upcoming events (end >= now... see below), each:

```json
{ "id": 55, "title": "Launch", "excerpt": "…", "start": "ISO8601", "end": "ISO8601", "venue": "Main Hall", "capacity": 100, "counts": { "going": 41, "waitlist": 3 }, "categories": ["meetup"], "rsvpOpen": true }
```

`rsvpOpen` = published AND end in future AND (capacity null or going count < capacity). Emails never serialized. Sorting: start ASC. Events whose end has passed are excluded.

### GET `/event-hub/v1/rsvps` — capability `edit_others_posts`

Query: `eventId` (required), `page` >=1, `perPage` 1..100 (default 20), `status` (`going|waitlist`).

Response `200`: `{ "rsvps": [ { "id", "eventId", "name", "email", "status", "createdAt" } ], "total", "totalPages" }`. Emails allowed here (gated).

Errors: `400 eh_missing_event` when eventId missing; `403 rest_forbidden` when lacking cap.

### DELETE `/event-hub/v1/rsvps/{id}` — capability `edit_others_posts`

Success `200`: `{ "deleted": true, "previous": { "id", "eventId", "name", "email", "status", "createdAt" } }`.
Errors: `404 eh_rsvp_not_found`; `403 rest_forbidden`.

### GET `/event-hub/v1/events/{id}/counts` — public

`200 { "eventId": 55, "counts": { "going": 41, "waitlist": 3 } }` or `404 eh_event_not_found`.

## Non-REST endpoints

### ICS `GET /event-hub/v1/event/{id}/calendar.ics` and `/event-hub/v1/events/calendar.ics`
REST route (permission: public) returning `text/calendar; charset=utf-8` with `Content-Disposition: attachment`. RFC 5545: CRLF, folding at 75 octets, escaping per §3.4.1. `404 eh_event_not_found` for unknown ids. This avoids rewrite-rule mumbo-jumbo... (decision #9: ICS served through REST route, not a rewrite tag).

Also `GET /?eh_export=rsvps&event_id=...&_wpnonce=...` **admin-only** CSV export (`export.php`), capability `edit_others_posts` + nonce `eh_export_rsvps`. Streams `text/csv` with UTF-8 BOM. Formula injection neutralization: cells matching leading `= + - @ \t \r` get a leading `'`.

## Pagination / rate limit notes

Rate limit counts POST /rsvp only, keyed by IP via transients (network LWPD... see threat model). Gated GET/DELETE unlimited (logs, admin).

## Versioning

Namespace `event-hub/v1`. Breaking changes → `event-hub/v2`, v1 kept one minor release.
