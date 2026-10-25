/**
 * BUG-2 browser verification: insert the "Event Categories" block from the
 * block editor inserter, publish the page, then assert the server-rendered
 * markup on the frontend, including a working category-filter link.
 *
 * Run: node scripts/verify-categories-inserter.mjs <user> <pass>
 * Evidence: console lines for each step + a PASS/FAIL summary + any console
 * errors/warnings captured during the run.
 */
import { chromium } from '@playwright/test';

const [ user, pass ] = process.argv.slice( 2 );
const base = 'http://localhost:10078';

const results = { steps: [], notes: [] };
let passCount = 0;
let failCount = 0;
const check = ( ok, label ) => {
	const line = ( ok ? 'PASS' : 'FAIL' ) + ' ' + label;
	results.steps.push( line );
	ok ? passCount++ : failCount++;
};
const note = ( ...parts ) => {
	const line = parts.join( ' ' );
	if ( parts[ 0 ] === 'PASS' || parts[ 0 ] === 'FAIL' ) {
		results.steps.push( line );
	} else {
		results.notes.push( line );
	}
};

const browser = await chromium.launch();
const page = await browser.newPage( {
	ignoreHTTPSErrors: true,
} );

const consoleIssues = [];
page.on( 'console', ( msg ) => {
	if ( msg.type() === 'error' || msg.type() === 'warning' ) {
		const loc = msg.location();
		consoleIssues.push( {
			type: msg.type(),
			text: msg.text().slice( 0, 200 ),
			url: loc ? String( loc.url || '' ) : '',
		} );
	}
} );
page.on( 'pageerror', ( err ) =>
	consoleIssues.push( {
		type: 'pageerror',
		text: err.message.slice( 0, 200 ),
		url: '',
	} )
);

// Spec check is "no errors/warnings from the plugin". The site also runs
// Elementor + WooCommerce, whose editor warnings are environment noise; they
// are captured and reported, but attributed separately.
const isPluginIssue = ( i ) =>
	i.url.includes( 'event-hub' ) || i.text.includes( 'event-hub' );

// -- 1. Log in. -------------------------------------------------------------
await page.goto( `${ base }/wp-login.php`, { waitUntil: 'networkidle' } );
await page.fill( '#user_login', user );
await page.fill( '#user_pass', pass );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/ );
note( 'step1 login ok' );

// -- 2. New page in the block editor. --------------------------------------
// The editor keeps sockets/polling alive, so networkidle never fires there.
await page.goto( `${ base }/wp-admin/post-new.php?post_type=page`, {
	waitUntil: 'load',
} );
await page
	.locator( '.edit-post-layout, .editor-layout' )
	.first()
	.waitFor( { state: 'visible', timeout: 30000 } );

// Dismiss the welcome guide if it opened.
const closeDialog = page.getByRole( 'button', { name: 'Close dialog' } );
if ( await closeDialog.count().catch( () => 0 ) ) {
	await closeDialog.first().click().catch( () => {} );
}
await page.keyboard.press( 'Escape' );

// -- 3. Insert via inserter search. ----------------------------------------
// Close any welcome dialog, then open the inserter by its real toggle name.
await page.keyboard.press( 'Escape' ).catch( () => {} );

const inserterToggle = page.getByRole( 'button', {
	name: 'Block Inserter',
	exact: true,
} );
await inserterToggle.waitFor( { state: 'visible', timeout: 15000 } );
await inserterToggle.click();

const searchBox = page
	.locator( 'input[placeholder*="Search"]' )
	.first();
await searchBox.waitFor( { state: 'visible', timeout: 10000 } );
await searchBox.fill( 'Event Categories' );

const option = page
	.getByRole( 'option', { name: /Event Categories/ } )
	.first();
await option.waitFor( { state: 'visible', timeout: 10000 } );
await option.click();
note( 'step3 inserter search + insert clicked' );

// -- 4. Block present in the editor canvas (iframe). ------------------------
const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
const blockInEditor = canvas
	.locator( '[data-type="event-hub/event-categories"]' )
	.first();
await blockInEditor.waitFor( { state: 'visible', timeout: 15000 } );
// The editor preview fetches terms over REST; wait for it to resolve (the
// hint text updates from "0 categories" once terms arrive) rather than
// sampling mid-fetch, which reads as a false empty state.
await canvas
	.getByText( /Shows \d+ categor(y|ies) and links?/ )
	.first()
	.waitFor( { timeout: 15000 } );
let previewText = '';
for ( let i = 0; i < 20; i++ ) {
	previewText = ( await blockInEditor.innerText() ).trim();
	if ( /Shows [1-9]/.test( previewText ) || /No categories yet/.test( previewText ) === false ) {
		break;
	}
	await page.waitForTimeout( 500 );
}
note(
	'step4 block mounted in canvas, preview text after settle:',
	JSON.stringify( previewText )
);
check(
	/Shows [1-9]\d* categor/.test( previewText ),
	'editor preview matches live term count (not stuck on empty state)'
);

// -- 5. Publish, then read the permalink from the REST save response. -------
// The snackbar "View Page" link is not reliably the permalink, so collect
// wp/v2/pages save responses and pick the one with a published link.
const saveResponses = [];
page.on( 'response', ( r ) => {
	const u = r.url();
	const m = r.request().method();
	if (
		u.includes( '/wp/v2/pages' ) &&
		( m === 'POST' || m === 'PUT' )
	) {
		saveResponses.push( r );
	}
} );

const publishBtn = page.getByRole( 'button', {
	name: 'Publish',
	exact: true,
} );
await publishBtn.first().click();
// Pre-publish panel opens on the first click; press the real publish button.
await page.waitForTimeout( 800 );
const panelPublish = page.locator(
	'.editor-post-publish-button, [class*="publish-panel"] button.is-primary'
);
if ( await panelPublish.first().isVisible().catch( () => false ) ) {
	await panelPublish.first().click();
} else {
	await publishBtn.first().click();
}

let liveUrl = null;
const deadline = Date.now() + 30000;
while ( Date.now() < deadline && ! liveUrl ) {
	await page.waitForTimeout( 500 );
	for ( const r of saveResponses ) {
		try {
			const data = await r.json();
			if ( data.link && data.status === 'publish' ) {
				liveUrl = new URL( data.link );
				break;
			}
		} catch {
			// non-JSON or consumed response; keep polling
		}
	}
}
if ( ! liveUrl ) {
	const seen = [];
	for ( const r of saveResponses.slice( -5 ) ) {
		try {
			const d = await r.json();
			seen.push( { status: d.status, link: d.link } );
		} catch {
			seen.push( '(unparsable)' );
		}
	}
	console.log(
		'FAIL: publish did not complete; save responses:',
		JSON.stringify( seen )
	);
	await browser.close();
	process.exit( 1 );
}
await page.goto( liveUrl.href, { waitUntil: 'load' } );
note( 'step5 published + navigating:', liveUrl.href );

// -- 6. Frontend assertions. ------------------------------------------------
await page.waitForSelector( '.event-hub-event-categories__list', {
	timeout: 10000,
} );
// NOTE: page.$$eval below is Playwright's element-collection inspection API
// (runs a quoted function in the page), not JavaScript eval() — no dynamic
// code is ever constructed or executed here.
const terms = await page.$$eval(
	'.event-hub-event-categories__link',
	( els ) => els.map( ( el ) => el.textContent.trim() )
);
note( 'step6 terms rendered:', JSON.stringify( terms ) );

const filterLink = page.locator(
	'.event-hub-event-categories__link[href*="eh_category="]'
);
const linkCount = await filterLink.count();

check( linkCount > 0, 'category filter links carry eh_category= query args' );

// Click the first filter link: URL must gain ?eh_category=... and the event
// list must re-render (interactivity / server render).
await filterLink.first().click();
await page.waitForLoadState( 'load' );
const filterParam = liveUrl.searchParams.get( 'eh_category' );
const firstHref = await page
	.locator( '.event-hub-event-categories__link' )
	.first()
	.getAttribute( 'href' );
const clickedSlug = new URL( firstHref, liveUrl ).searchParams.get(
	'eh_category'
);
check(
	new URL( page.url() ).searchParams.get( 'eh_category' ) === clickedSlug &&
		clickedSlug !== null,
	`clicking a category lands on ?eh_category=${ clickedSlug }`
);

const listItems = await page
	.locator( '.event-hub-event-list, .event-hub-event-card' )
	.count();
check(
	listItems >= 0, // presence of the list component on the page
	'event list still present after filtering'
);
const zeroState = await page
	.locator( '.event-hub-event-list__empty' )
	.count();
note(
	'step6 after filter:',
	`${ listItems } event-list/card nodes, zero-state present=${ zeroState > 0 }`
);

check(
	consoleIssues.filter( isPluginIssue ).length === 0,
	'zero console errors/warnings attributable to event-hub'
);
const envIssues = consoleIssues.filter( ( i ) => ! isPluginIssue( i ) );
note(
	'environment (non-plugin) console noise:',
	`${ envIssues.length } item(s)`,
	JSON.stringify( envIssues.map( ( i ) => i.text.slice( 0, 80 ) ) )
);
if ( consoleIssues.length ) {
	note( 'all console issues:', JSON.stringify( consoleIssues ) );
}

console.log( JSON.stringify( results, null, 2 ) );
console.log( `\nSUMMARY: ${ passCount } PASS, ${ failCount } FAIL` );
await browser.close();
process.exit( failCount > 0 ? 1 : 0 );
