/**
 * BUG-1 browser verification: log into wp-admin, open the Event Hub RSVP
 * screen, click "Download CSV", and assert the downloaded file.
 *
 * Run: node scripts/verify-admin-csv.mjs <user> <pass>
 * Evidence: prints CSV header + row count; saves download to /tmp.
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';

const [user, pass] = process.argv.slice(2);
const base = 'http://localhost:10078';

const browser = await chromium.launch();
const page = await browser.newPage( { acceptDownloads: true } );

const consoleErrors = [];
page.on( 'console', ( msg ) => {
	if ( msg.type() === 'error' || msg.type() === 'warning' ) {
		consoleErrors.push( `${ msg.type() }: ${ msg.text() }` );
	}
} );
page.on( 'pageerror', ( err ) => consoleErrors.push( `pageerror: ${ err.message }` ) );

// 1. Log in.
await page.goto( `${ base }/wp-login.php`, { waitUntil: 'networkidle' } );
await page.fill( '#user_login', user );
await page.fill( '#user_pass', pass );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/ );

// 2. Open the Event Hub RSVP screen.
await page.goto( `${ base }/wp-admin/admin.php?page=event-hub-rsvps`, {
	waitUntil: 'networkidle',
} );

// 3. Assert boot data is sane (no undefined anywhere).
const boot = await page.evaluate( () => window.eventHubAdmin ?? null );
if ( ! boot ) {
	console.log( 'FAIL: window.eventHubAdmin missing' );
	process.exit( 1 );
}
const bootJson = JSON.stringify( boot );
console.log( 'boot keys:', Object.keys( boot ).join( ',' ) );
console.log(
	'exportUrls:',
	JSON.stringify( boot.exportUrls ).slice( 0, 160 )
);
console.log( 'undefined anywhere:', bootJson.includes( 'undefined' ) );

// 4. Click Download CSV and capture the download.
const downloadPromise = page.waitForEvent( 'download', { timeout: 15000 } );
await page.getByRole( 'link', { name: 'Download CSV' } ).click();
const download = await downloadPromise;
const path = await download.path();
const body = readFileSync( path, 'utf8' );
const lines = body.trim().split( '\n' );

console.log( 'suggested filename:', download.suggestedFilename() );
console.log( 'header row:', lines[ 0 ] );
console.log( 'total lines (incl header):', lines.length );
console.log( 'first data row:', lines[ 1 ] ?? '(none)' );
console.log( 'BOM present:', body.charCodeAt( 0 ) === 0xfeff );
console.log( 'console errors/warnings:', consoleErrors.length ? consoleErrors : 'none' );

await browser.close();
