# Event Hub — Threat Model

## Assets & trust boundaries

| Asset | Sensitivity |
| --- | --- |
| Attendee emails (custom table) | PII — public disclosure = critical |
| RSVP writes | integrity — capacity/duplicate guarantees |
| Admin context | privilege boundary (cookie auth + caps) |
| ICS/CSV outputs | injection channels into other apps (calendar clients, Excel) |

Trust boundary 1: anonymous internet ↔ REST public routes. Boundary 2: authenticated low-priv user ↔ gated routes. Boundary 3: admin CSV/ICS consumers (export injection).

## Threats & countermeasures (STRIDE thumbnailed)

| # | Threat | Mitigation in design |
| --- | --- | --- |
| T1 | SQLi via name/email/eventId | `$wpdb->prepare()` everywhere; ids via `absint`; status whitelisted |
| T2 | Stored XSS via event title/venue or RSVP name rendered on frontend/emails(?) | escape at output (`esc_html`/`esc_attr`); venue is plain text meta |
| T3 | Reflected XSS via REST error reflection | WP_REST_Server escapes; we never echo raw input outside REST |
| T4 | CSRF on RSVP POST | nonce `wp_rest` required when cookie present; stateless otherwise + rate limit |
| T5 | CSRF on DELETE/CSV export | nonce (`wp_rest` for DELETE, dedicated `eh_export_rsvps` for CSV) + capability |
| T6 | IDOR on DELETE /rsvps/{id} | capability `edit_others_posts`; no other-user scoping concept (all RSVPs admin-managed) |
| T7 | PII leakage via public routes / REST index / search | public serializers return counts only; emails only in gated route + CSV |
| T8 | Rate-limit bypass (IP rotation, X-Forwarded-For spoofing) | keyed on `$_SERVER['REMOTE_ADDR']` only (never XFF); best-effort |
| T9 | CSV formula injection | leading `= + - @ %`-cells prefixed with `'` and space-trimmed (docs/API.md) |
| T10 | ICS content/header injection (CRLF in title/venue smuggling additional properties) | text escaping per RFC 5545 §3.4.1 + no raw folds; structured property values only |
| T11 | Oversized payloads | REST JSON limits + per-field maxlength + server-side validation |
| T12 | Privilege escalation via meta registration | `auth_callback` on every meta → `current_user_can('edit_post', $post_id)`; no meta shown in public REST |
| T13 | Unicode/malformed JSON abuse | `wp_json_decode` failure → 400; mb-safe validation (grapheme-aware length) |
| T14 | Uninstall leftovers | uninstall.php drops table + options + posts + transients |

## Open items for Phase 6 attack pass
- verify T8 fix claim (no XFF trust)
- verify no email in any public payload incl. `/` REST index & search fields
- ICS folding + escaping fuzz; CSV with hostile cells
- concurrent duplicate + overbooking (also Phase 5)
