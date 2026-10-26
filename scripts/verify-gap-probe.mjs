import { chromium } from 'playwright';

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
await page.goto( 'http://localhost:10078/events/', { waitUntil: 'networkidle' } );

const probe = await page.evaluate( () => {
	const grid = document.querySelector( '.event-hub-archive__grid' );
	const nav = document.querySelector( '.navigation.pagination' );
	const header = document.querySelector( '.event-hub-archive__header' );
	const cards = document.querySelectorAll( '.event-hub-archive__grid > *' );

	const cs = ( el, prop ) => el ? getComputedStyle( el ).getPropertyValue( prop ).trim() : null;

	let lastRowBottom = null;
	let firstRowTop = null;
	if ( grid && cards.length ) {
		const tops = [ ...cards ].map( c => c.getBoundingClientRect().top );
		const bottoms = [ ...cards ].map( c => c.getBoundingClientRect().bottom );
		firstRowTop = Math.min( ...tops );
		// Cards in the first row share (roughly) the min top; find the max
		// bottom among cards in that first row.
		const minTop = firstRowTop;
		lastRowBottom = Math.max(
			...bottoms.filter( ( _b, i ) => Math.abs( tops[ i ] - minTop ) < 2 )
		);
	}

	return {
		gridGap: grid ? getComputedStyle( grid ).gap : null,
		gridRowGap: grid ? getComputedStyle( grid ).rowGap : null,
		gridColGap: grid ? getComputedStyle( grid ).columnGap : null,
		ehGapVar: cs( grid, '--eh-gap' ),
		spacing40: getComputedStyle( document.documentElement ).getPropertyValue( '--wp--preset--spacing--40' ).trim(),
		headerMarginBottom: header ? cs( header, 'marginBottom' ) : null,
		navMarginTop: nav ? cs( nav, 'marginTop' ) : null,
		navTop: nav ? nav.getBoundingClientRect().top : null,
		lastRowBottom,
		gapGridToNav: nav && null !== lastRowBottom ? +( nav.getBoundingClientRect().top - lastRowBottom ).toFixed( 1 ) : null,
	};
} );

console.log( JSON.stringify( probe, null, 2 ) );

await page.screenshot( { path: '/tmp/eh-gap-before.png', fullPage: true } );
await browser.close();
