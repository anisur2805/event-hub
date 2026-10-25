/**
 * PROBLEM-3 visual verification: publish a fresh page with the redesigned
 * event list + categories blocks, screenshot desktop/tablet/mobile, and
 * exercise the client-side filter path (which renders cards via the new
 * template slots).
 *
 * Run: EH_LIVE_URL="http://localhost:10078/redesign-check/" node scripts/verify-redesign.mjs
 * Evidence: /tmp/eh-shot-{1280,768,360}.png + console PASS/FAIL lines.
 */
import { chromium } from '@playwright/test';

const base = 'http://localhost:10078';
const liveUrl = process.env.EH_LIVE_URL;
if ( ! liveUrl ) {
	console.error( 'FAIL: EH_LIVE_URL env not set' );
	process.exit( 1 );
}

const results = [];
const report = ( ok, label, extra = '' ) => {
	results.push( `${ ok ? 'PASS' : 'FAIL' } ${ label }${ extra ? ' — ' + extra : '' }` );
};

const browser = await chromium.launch();
const page = await browser.newPage( {
	viewport: { width: 1280, height: 900 },
	ignoreHTTPSErrors: true,
} );

const consoleIssues = [];
page.on( 'console', ( msg ) => {
	if ( msg.type() === 'error' || msg.type() === 'warning' ) {
		const url = msg.location()?.url || '';
		if ( url.includes( 'event-hub' ) ) {
			consoleIssues.push( `${ msg.type() }: ${ msg.text().slice( 0, 160 ) }` );
		}
	}
} );
page.on( 'pageerror', ( err ) => {
	consoleIssues.push( `pageerror: ${ err.message.slice( 0, 160 ) }` );
} );

// The redesign page is public (list, filter, categories, RSVP form all
// render for logged-out visitors), so this script never logs in — exercise
// exactly what an anonymous visitor sees.

// 1. Frontend at three widths.
const checks = async ( width, path ) => {
	await page.setViewportSize( { width, height: 900 } );
	await page.goto( liveUrl, { waitUntil: 'load' } );
	await page.waitForSelector( '.event-hub-card', { timeout: 15000 } );
	await page.screenshot( { path, fullPage: false } );

	const out = { width, cards: 0 };
	out.cards = await page.locator( '.event-hub-card' ).count();
	out.pills = await page.locator( '.event-hub-card__pill' ).count();
	out.dates = await page.locator( '.event-hub-card__day' ).count();
	out.bars = await page.locator( '.event-hub-card__progress' ).count();
	return out;
};

const d = await checks( 1280, '/tmp/eh-shot-1280.png' );
const t = await checks( 768, '/tmp/eh-shot-768.png' );
const m = await checks( 360, '/tmp/eh-shot-360.png' );

report( d.cards > 0, 'cards render at 1280', `cards=${ d.cards } pills=${ d.pills } dates=${ d.dates } bars=${ d.bars }` );
report( d.pills === d.cards && d.dates === d.cards, 'every 1280 card has pill + date block' );
report( t.cards === d.cards, 'cards render at 768', `cards=${ t.cards }` );
report( m.cards === d.cards, 'cards render at 360', `cards=${ m.cards }` );

// 3. Client-side filter path: pick a category from the select and let the
// iAPI store re-render cards through the template.
const hasFilter = await page.locator( '#event-hub-filter' ).count();
if ( hasFilter > 0 ) {
	const before = await page.locator( '.event-hub-card' ).count();
	await page.selectOption( '#event-hub-filter', { label: await page.locator( '#event-hub-filter option:not([value=""])' ).first().textContent() } );
	await page.waitForTimeout( 1500 );
	const after = await page.locator( '.event-hub-card' ).count();
	const undefinedLinks = await page.locator( 'a[href*="/undefined"], a[href="undefined"]' ).count();
	report( true, 'client filter ran', `before=${ before } after=${ after }` );
	report( undefinedLinks === 0, 'no undefined hrefs after client filter (Find-1 regression check)' );
	const pillAfter = await page.locator( '.event-hub-card__pill' ).count();
	report( pillAfter === after, 'client-rendered cards carry pills', `pills=${ pillAfter }` );
} else {
	report( true, 'no filter select on page (skipped client filter checks)' );
}

// 4. Focus visibility: tab into the first card link.
await page.keyboard.press( 'Tab' );
const focusVisible = await page.evaluate( () => {
	const el = document.activeElement;
	if ( ! el ) {
		return false;
	}
	const st = getComputedStyle( el );
	return st.outlineStyle !== 'none' && parseFloat( st.outlineWidth || '0' ) > 0;
} );
report( focusVisible, 'first focus receives a visible outline' );

report( consoleIssues.length === 0, 'zero event-hub console errors/warnings', consoleIssues.join( ' | ' ) );

// 5. Accessibility quick probe: progressbar semantics.
const barProbe = await page.evaluate( () => {
	const bar = document.querySelector( '.event-hub-card__progress' );
	if ( ! bar ) {
		return 'no-bar-on-page';
	}
	return {
		max: bar.getAttribute( 'aria-valuemax' ),
		now: bar.getAttribute( 'aria-valuenow' ),
		text: bar.getAttribute( 'aria-valuetext' ),
	};
} );
report( barProbe !== 'no-bar-on-page' && !! barProbe.now, 'progressbar exposes ARIA values', JSON.stringify( barProbe ) );
report(
	barProbe !== 'no-bar-on-page' &&
		typeof barProbe === 'object' &&
		! /%\d+\$[sd]/.test( barProbe.text || '' ),
	'aria-valuetext is interpolated (no raw placeholders)',
	`text="${ typeof barProbe === 'object' ? barProbe.text : barProbe }"`
);

// 6. Slot text sanity: the "N going" count must be real text, not a %s
// template passed through by the client renderer.
const rawCount = await page.evaluate( () => {
	const el = document.querySelector( '[data-eh-slot="count"]' );
	return el ? el.textContent : null;
} );
report( rawCount !== null && ! /%[sd]/.test( rawCount ), 'count text interpolated', `text="${ rawCount }"` );

console.log( results.join( '\n' ) );
const fails = results.filter( ( r ) => r.startsWith( 'FAIL' ) ).length;
console.log( `\nSUMMARY: ${ results.length - fails } PASS, ${ fails } FAIL` );
await browser.close();
process.exit( fails > 0 ? 1 : 0 );
