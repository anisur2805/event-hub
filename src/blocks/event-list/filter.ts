/**
 * Event list filter actions — registered as extra actions on the shared
 * `event-hub` store.
 *
 * Decided approach (DECISIONS.md #21): category-filtered cards are rebuilt
 * client-side by cloning the server-rendered <template data-eh-card-tpl>
 * element inside the list region. Only slot placeholders get content
 * (textContent / checked attributes) — remote strings are never parsed as
 * HTML. Filtered cards expose a plain permalink link instead of the inline
 * RSVP form, so no store wiring has to be recreated in JS; the unfiltered
 * initial render keeps the full server-rendered RSVP forms. Without JS the
 * select sits in a GET <form> and triggers a native reload (PHP side).
 */
import { store, getContext, withSyncEvent } from '@wordpress/interactivity';
import {
	translate as __,
	choosePlural as _n,
	interpolate as sprintf,
} from '../../interactivity/i18n';
import {
	type EventHubStore,
	type ListContext,
	type EventSummary,
} from '../../interactivity/types';

/** Slot names understood by the card template. */
type SlotName =
	| 'title'
	| 'titlelink'
	| 'day'
	| 'month'
	| 'datetime'
	| 'venue'
	| 'excerpt'
	| 'categories'
	| 'pill'
	| 'barfill'
	| 'count'
	| 'link';

function slot( card: ParentNode, name: SlotName ): HTMLElement | null {
	return card.querySelector< HTMLElement >( `[data-eh-slot="${ name }"]` );
}

/**
 * Only absolute http(s) URLs are safe to assign to href.
 * @param url
 */
function safeHref( url: string ): boolean {
	try {
		const parsed = new URL( url, window.location.href );
		return parsed.protocol === 'http:' || parsed.protocol === 'https:';
	} catch {
		return false;
	}
}

/**
 * Format an ISO 8601 datetime for display; falls back to the raw value.
 * @param iso
 */
function formatDateTime( iso: string ): string {
	const parsed = new Date( iso );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return iso;
	}
	return parsed.toLocaleString( undefined, {
		weekday: 'short',
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );
}

const { state } = store< EventHubStore >( 'event-hub', {
	actions: {
		/**
		 * Overrides the earlier placeholder: re-fetch and swap the cards.
		 * The second store() call deep-merges these two actions into the
		 * already-registered `event-hub` namespace (documented iAPI merge
		 * behavior), keeping one store per page.
		 */
		filterCategory: withSyncEvent( function* (
			event: Event
		): Generator< unknown, void, unknown > {
			const select = event.target as HTMLSelectElement | null;
			const selected = select?.value ?? '';
			const ctx = getContext< ListContext >();
			ctx.selected = selected;

			const region = select?.closest(
				'[data-wp-interactive="event-hub"]'
			);
			// The card skeleton template must live inside the region but
			// outside the grid (see HANDOFF in the build report).
			const tpl = region?.querySelector< HTMLTemplateElement >(
				'template[data-eh-card-tpl]'
			);
			const grid =
				region?.querySelector< HTMLElement >( '.eh-list-grid' );
			if ( ! grid || ! tpl ) {
				return;
			}

			ctx.busy = true;
			ctx.hasError = false;
			try {
				const restUrl = state.restUrl ?? '';
				const base = restUrl.endsWith( '/' )
					? restUrl
					: `${ restUrl }/`;
				const params = new URLSearchParams();
				if ( selected ) {
					params.set( 'categories', selected );
				}
				const perPage = Math.min(
					Math.max( ctx.postsPerPage || 6, 1 ),
					50
				);
				params.set( 'perPage', String( perPage ) );

				const response = ( yield fetch(
					`${ base }event-hub/v1/events?${ params.toString() }`
				) ) as Response;
				if ( ! response.ok ) {
					ctx.hasError = true;
					ctx.foundText = __(
						'Could not load events — please try again.'
					);
					return;
				}
				const events = ( yield response.json() ) as EventSummary[];

				grid.replaceChildren(
					...events.map( ( item ) => renderCard( item, tpl.content ) )
				);
				ctx.foundText = sprintf(
					/* translators: %s: number of events found. */
					_n( '%s event found', '%s events found', events.length ),
					String( events.length )
				);
			} catch {
				ctx.hasError = true;
				ctx.foundText = __(
					'Could not load events — please try again.'
				);
			} finally {
				ctx.busy = false;
			}
		} ),
		/**
		 * Progressive enhancement: the select is inside a GET <form> so
		 * no-JS visitors reload the page; with JS the submit is swallowed.
		 */
		filterSubmit: withSyncEvent( function preventSubmit( event: Event ) {
			event.preventDefault();
		} ),
	},
} );

/**
 * Build one card DOM node from the template clone. Only slots receive
 * content (textContent, href after protocol check) — strings from the REST
 * response are never parsed as HTML.
 * @param event
 * @param templateContent
 */
function renderCard(
	event: EventSummary,
	templateContent: DocumentFragment
): Node {
	// Guard against missing/invalid permalinks (would surface as "undefined"
	// hrefs, Find-1) before cloning anything.
	if ( ! event.permalink || ! safeHref( event.permalink ) ) {
		return document.createDocumentFragment();
	}

	const fragment = templateContent.cloneNode( true ) as DocumentFragment;
	const card = fragment.firstElementChild;
	if ( ! card ) {
		return document.createDocumentFragment();
	}

	// Filtered cards are plain markup — drop iAPI wiring cloned from the
	// template so the runtime doesn't try to hydrate fresh nodes.
	card.querySelectorAll(
		'[data-wp-interactive], [data-wp-on--click]'
	).forEach( ( el ) => {
		el.removeAttribute( 'data-wp-interactive' );
		[ ...el.attributes ]
			.filter( ( attr ) => attr.name.startsWith( 'data-wp-' ) )
			.forEach( ( attr ) => el.removeAttribute( attr.name ) );
	} );

	const title = slot( card, 'title' );
	const titlelink = slot( card, 'titlelink' );
	if ( titlelink instanceof HTMLAnchorElement ) {
		titlelink.href = event.permalink;
	}
	if ( title ) {
		title.textContent = event.title;
	}

	const day = slot( card, 'day' );
	if ( day ) {
		day.textContent = datePart( event.start, 'day' );
	}

	const month = slot( card, 'month' );
	if ( month ) {
		month.textContent = datePart( event.start, 'month' );
	}

	const pill = slot( card, 'pill' );
	if ( pill ) {
		const p = pillFor( event );
		pill.textContent = p.label;
		pill.classList.add( `event-hub-card__pill--${ p.key }` );
	}

	const datetime = slot( card, 'datetime' );
	if ( datetime ) {
		datetime.textContent = event.start ? formatDateTime( event.start ) : '';
	}

	const venue = slot( card, 'venue' );
	if ( venue ) {
		venue.textContent = event.venue;
		// PHP omits the element entirely when empty; drop it here too.
		if ( ! event.venue ) {
			venue.remove();
		}
	}

	const excerpt = slot( card, 'excerpt' );
	if ( excerpt ) {
		excerpt.textContent = event.excerpt;
		if ( ! event.excerpt ) {
			excerpt.remove();
		}
	}

	const categories = slot( card, 'categories' );
	if ( categories ) {
		const names = event.categoryNames ?? [];
		categories.textContent = names.join( ', ' );
		if ( names.length === 0 ) {
			categories.remove();
		}
	}

	const going = event.counts?.going ?? 0;
	const capacity = event.capacity ?? null;

	// Capacity bar: only kept when the PHP side would render one.
	const fill = slot( card, 'barfill' );
	const bar = fill?.closest( '.event-hub-card__progress' );
	if ( bar instanceof HTMLElement ) {
		if ( capacity === null || capacity <= 0 ) {
			bar.remove();
		} else {
			const pct = Math.min( 100, Math.round( ( going / capacity ) * 100 ) );
			bar.setAttribute( 'aria-valuemax', String( capacity ) );
			bar.setAttribute(
				'aria-valuenow',
				String( Math.min( going, capacity ) )
			);
			bar.setAttribute(
				'aria-valuetext',
				sprintf(
					/* translators: 1: number going, 2: total capacity. */
					__( '%1$s of %2$s spots filled' ),
					String( going ),
					String( capacity )
				)
			);
			if ( fill instanceof HTMLElement ) {
				fill.style.width = `${ pct }%`;
			}
		}
	}

	const count = slot( card, 'count' );
	if ( count ) {
		count.textContent = sprintf(
			/* translators: %s: number of attendees. */
			_n( '%s person going', '%s people going', going ),
			String( going )
		);
	}

	const link = slot( card, 'link' );
	if ( link instanceof HTMLAnchorElement ) {
		link.href = event.permalink;
	}

	return fragment;
}

/**
 * One date part of an ISO 8601 value; empty string when absent/invalid.
 * @param iso
 * @param part
 */
function datePart( iso: string, part: 'day' | 'month' ): string {
	const parsed = new Date( iso );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return '';
	}
	if ( part === 'day' ) {
		return String( parsed.getDate() );
	}
	return parsed.toLocaleString( undefined, { month: 'short' } );
}

/**
 * Status pill for a REST event; mirrors the PHP status_pill() precedence:
 * Past > Waitlist (full with list takers) > Sold out (full) > Few spots
 * left (>=80%) > Open.
 * @param event
 */
function pillFor( event: EventSummary ): {
	key: string;
	label: string;
} {
	const capacity = event.capacity ?? null;
	const going = event.counts?.going ?? 0;
	const waitlist = event.counts?.waitlist ?? 0;

	// Past check: end (or start when no end) before now.
	const endRef = event.end ? Date.parse( event.end ) : NaN;
	const endTs = Number.isNaN( endRef )
		? Date.parse( event.start )
		: endRef;
	if ( ! Number.isNaN( endTs ) && endTs < Date.now() ) {
		return { key: 'past', label: __( 'Past' ) };
	}

	if ( capacity !== null && going >= capacity ) {
		if ( waitlist > 0 ) {
			return { key: 'waitlist', label: __( 'Waitlist' ) };
		}
		return { key: 'soldout', label: __( 'Sold out' ) };
	}

	if ( capacity !== null && capacity > 0 && going / capacity >= 0.8 ) {
		return { key: 'few', label: __( 'Few spots left' ) };
	}

	return { key: 'open', label: __( 'Open' ) };
}
