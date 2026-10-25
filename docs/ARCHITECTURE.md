# Event Hub — Architecture

Greenfield WordPress plugin: `event-hub`. Events + RSVP with capacity-aware registration, admin RSVP management, ICS export, and three Interactivity API blocks.

Display works with JS disabled (all content is server-rendered). RSVP submission requires JS; a no-JS visitor sees the live count and a disabled button with an explanatory `noscript` hint (logged in DECISIONS.md #4).

## Stack

| Layer | Choice |
| --- | --- |
| PHP 8.1+ / WP 6.7+ | `declare(strict_types=1)`, PSR-4 `EventHub\` → `includes/` |
| DB | Custom table `{prefix}eh_rsvps`, versioned dbDelta migration, option `event_hub_db_version` |
| Frontend | @wordpress/scripts 37, TypeScript strict, Interactivity API (`viewScriptModule`, namespace `event-hub`), no jQuery |
| Tests | PHPUnit (WP test suite in wp-env), Jest, Playwright |
| CI | GitHub Actions matrix job: PHPCS, PHPStan L6, tsc, ESLint, PHPUnit, Jest; Playwright job |

## Directories

```text
event-hub.php          Plugin header + bootstrap (strict types, autoload, constants, instance)
includes/              PHP classes, PSR-4 EventHub\, one class per file, FileName = class name
  Plugin.php           Registers CPT/tax/meta, REST, blocks, patterns, assets, migrations, ICS/CSV routes
  Rsvp_Repository.php  All {prefix}eh_rsvps SQL; capacity-safe writes
  Rest_Controller.php  event-hub/v1 routes (thin; delegates to repository)
  Ics_Exporter.php     RFC 5545 writer
  Csv_Exporter.php     Admin CSV export + formula-injection neutralizer
  Render_Blocks.php    Shared server-render helpers for the three blocks
  Admin_Page.php       Admin menu + container for the React RSVP screen
  Migrations.php       Versioned dbDelta runner
src/                   TypeScript: blocks/, admin/, compiled to build/ by wp-scripts
build/                 Build output — required at runtime, committed for wp-env simplicity? No: built in CI/locally; DECISIONS.md #7
patterns/              patterns/events-grid.php, patterns/featured-event-hero.php
tests/                 phpunit/, jest/, e2e/
bin/                   seed.php (WP-CLI), load tests
docs/                  This file, API.md (REST contract), THREAT-MODEL.md, DECISIONS.md
```

## Runtime flow

1. `event-hub.php`: `declare(strict_types=1)`; Composer autoload (`EventHub\` → `includes/`); constants `EVENT_HUB_VERSION`, `EVENT_HUB_DB_VERSION`; requires `vendor/autoload.php` if present (dev), otherwise a small SPL autoloader fallback (decision #8) so the plugin runs without a dev install.
2. On `plugins_loaded`: instantiate `EventHub\Plugin`, which registers CPT, taxonomy, meta, REST controller, blocks, patterns, i18n, and the migration hook. Not a singleton — bootstrap owns the single instance; nothing uses a global container.
3. Database: on `admin_init` (and via WP-CLI `event-hub-migrate` command) compare `get_option('event_hub_db_version')` to `EVENT_HUB_DB_VERSION`; if lower, run migrations in order. Migration 1 (v1.0.0): `dbDelta()` CREATE TABLE `{prefix}eh_rsvps` + UNIQUE KEY `uniq_event_email` (event_id, email) + KEY event_id. Uninstall: `uninstall.php` drops the table, deletes options + all `eh_event` posts + their meta.
4. RSVP write path (race safety): `Rsvp_Repository::create()` runs ONE atomic statement set on the custom table — `INSERT ... SELECT` where the WHERE clause re-checks capacity inside the INSERT, guarded by the UNIQUE(event_id,email) key for duplicates. No check-then-insert gap; failed insert maps to `409` (duplicate) or `410`/`409` per API.md. Capacity NULL = unlimited; 0 = full. Decision #5 details the SQL.
5. Blocks (all dynamic `render.php`, apiVersion 3):
   - `event-hub/event-list` — attributes: `categoryId`, `postsPerPage`, `showFilters`. Server renders full markup + `data-wp-interactive="event-hub"`; filter `<select>` re-fetches via Interactivity Router-free fetch to REST `GET /event-hub/v1/events` (public read endpoint for count/list payloads, see API.md) and swaps card markup client-side; RSVP buttons are iAPI-bound and update the live count.
   - `event-hub/event-card` — presentational inner block used by list; also standalone (renders one event by `eventId` attribute). Shares helpers with list via `Render_Blocks`.
   - `event-hub/rsvp-button` — standalone button; attributes `eventId`. Interactivity store keeps per-event count + status; POSTs to `POST /event-hub/v1/rsvp` with the nonce from `wp_interactivity_state()`.
6. Admin: React screen at `admin.php?page=event-hub-rsvps`(React via `@wordpress/components`, built by wp-scripts), listing RSVPs per event with delete + CSV export link (server-side, nonce + capability; see API.md).
7. ICS: `GET /?eh_eventical=1&event={id}|all` (rewrite endpoint, see API.md) streams `text/calendar`.
8. theme.json integration: all block styles consume `var(--wp--preset--color--*)`, `--wp--preset--font-size--*`, `--wp--preset--spacing--*` only. Fallback values declared on `:root` in `build/style.css` so a classic theme without presets still renders legibly (decision #6).

## Concurrency

Capacity enforcement is a single `INSERT ... SELECT ... WHERE` statement (plus UNIQUE-key dedupe), so it is atomic per statement under InnoDB row locking without application-level transactions. Load test (Phase 5) back this claim.

## Internal contracts (cross-boundary seams — change only via Architect)

### DB DDL (`{prefix}eh_rsvps`)
```sql
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
event_id BIGINT UNSIGNED NOT NULL,
name VARCHAR(191) NOT NULL,
email VARCHAR(191) NOT NULL,
status VARCHAR(20) NOT NULL DEFAULT 'going',
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY uniq_event_email (event_id, email)
```
No standalone `event_id` key: `uniq_event_email`'s leftmost column covers per-event lookups.

### Capacity-safe write (Rsvp_Repository::create)
One statement + advisory lock (decision #14):
1. `SELECT GET_LOCK( CONCAT('eh_rsvp_', event_id), 2 )` — abort `409 eh_busy` if null.
2. Atomic insert:
```sql
INSERT INTO t (event_id, name, email, status)
SELECT %d, %s, %s, %s FROM DUAL
WHERE %s = 'waitlist'
   OR ( (SELECT COUNT(*) FROM t WHERE event_id = %d AND status = 'going')
        < COALESCE( (SELECT CAST(m.meta_value AS UNSIGNED) FROM postmeta m
                     WHERE m.post_id = %d AND m.meta_key = 'eh_capacity'
                       AND m.meta_value REGEXP '^[0-9]+$'), 999999999 ) )
```
Waitlist rows bypass capacity. Missing/empty capacity meta ⇒ unlimited. Duplicate email ⇒ unique-key error 1062 ⇒ catch `400 eh_duplicate`-style mapping (`409 eh_duplicate`).
3. `SELECT RELEASE_LOCK(...)` in `finally`.

### Render_Blocks (includes/class-render-blocks.php) public API — used by render.php files
- `summary( int|WP_Post $event ): array` → `{ id:int, title:string, permalink:string, excerpt:string, start:string(ISO8601), end:string, venue:string, capacity:int|null, counts:{going:int,waitlist:int}, rsvpOpen:bool, categories:string[] }`
- `render_card( array $summary ): string` — complete escaped card HTML (title link, datetime, venue, excerpt, RSVP button markup)
- `render_rsvp_button( int $event_id ): string` — RSVP form markup (below)

### iAPI store `event-hub`
Server state via `wp_interactivity_state('event-hub', [...])` in render.php:
`{ restUrl: string, nonce: string, user: { name:string, email:string }|null }` — `restUrl` is the REST **root** with trailing slash (`rest_url()`, e.g. `…/wp-json/`); every JS consumer appends `event-hub/v1/…` itself. Seeding the namespaced URL double-prefixes the fetch URL (404s browser-side; DECISIONS.md #32).
Button context per event: `{ eventId, capacity, counts:{going,waitlist}, mine:null|"going"|"waitlist", busy:false, feedback:"" }`
Client store (`src/blocks/.../view.js`):
- `actions.rsvp( status: 'going'|'waitlist' )` — generator; fetch POST `{restUrl}event-hub/v1/rsvp`; on 201 set `mine/counts/feedback("You're on the list.")` → success message + count text update; on 409 capacity_full → feedback "Full — waitlist available"; on 409 duplicate → mine=existing status + "Already registered"; on other errors → generic feedback. `busy` guards double submit.
- `actions.filter( event )` — for the list select: `yield import('@wordpress/interactivity-router')` then `actions.navigate( urlWith(event_cat=<slug>) )`; prevents default.
Markup contract: count line is directive-safe — `data-wp-text="context.countText"` (per-event context, resolvable in PHP so SSR text survives the server-side directive processor; a JS-only `state.*` getter resolves to null server-side and wipes the SSR text, DECISIONS.md #30). RSVP region context: `{ eventId, capacity, counts:{going,waitlist}, mine, busy, feedback, full, rsvpOpen, countText, name, email }`. Name + email inputs render when `rsvpOpen` and bind via `data-wp-bind--value` + `data-wp-on--input` to `context.name`/`context.email`. List region wraps in `data-wp-router-region="event-hub-list"`, carries the ListContext (`categories/selected/postsPerPage/foundText/busy`), a `role="status"` found-count line `data-wp-text="context.foundText"`, a GET `<form>` filter (no-JS reload via `?eh_category=slug`, JS submit swallowed by `actions.filterSubmit`), a grid with class `eh-list-grid`, and a `<template data-eh-card-tpl>` card skeleton (`data-eh-slot="title|titlelink|datetime|venue|excerpt|count|link"`) that filter.ts clones client-side.

### Admin page seam
PHP page `event-hub-rsvps` (backend `Admin_Page`) renders container `<div id="event-hub-rsvps-root">`, enqueues `build/admin/index.js` (+ `.asset.php` deps) and inline data `window.eventHubAdmin = { restUrl, nonce, events: [{id,title}], initialEventId }`. CSV export link points to `admin-post.php?action=eh_export_rsvps&event_id=…&_wpnonce=…` (handled in Csv_Exporter, `admin_post_eh_export_rsvps`, cap `edit_others_posts` + nonce `eh_export_rsvps`).
Frontend must build entry `src/admin/index.tsx` → `build/admin/index.js` (webpack entry added in webpack.config.js alongside the standard block entries).

### RSVP form without JS (decision #13)
Name + email inputs always visible; submit disabled without JS? No: the `<form>` keeps native GET-free semantics — submit is type="button" with `data-wp-on--click="actions.rsvp('going')"`, so without JS nothing posts; a `<noscript>` line explains "Enable JavaScript to RSVP."

