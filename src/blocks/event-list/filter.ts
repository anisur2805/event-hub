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
	'title' | 'titlelink' | 'datetime' | 'venue' | 'excerpt' | 'count' | 'link';

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
	if (
		titlelink instanceof HTMLAnchorElement &&
		safeHref( event.permalink )
	) {
		titlelink.href = event.permalink;
	}
	if ( title ) {
		title.textContent = event.title;
	}

	const datetime = slot( card, 'datetime' );
	if ( datetime ) {
		datetime.textContent = event.start ? formatDateTime( event.start ) : '';
	}

	const venue = slot( card, 'venue' );
	if ( venue ) {
		venue.textContent = event.venue;
	}

	const excerpt = slot( card, 'excerpt' );
	if ( excerpt ) {
		excerpt.textContent = event.excerpt;
	}

	const going = event.counts?.going ?? 0;
	const count = slot( card, 'count' );
	if ( count ) {
		count.textContent = sprintf(
			/* translators: %s: number of attendees. */
			_n( '%s person going', '%s people going', going ),
			String( going )
		);
	}

	const link = slot( card, 'link' );
	if ( link instanceof HTMLAnchorElement && safeHref( event.permalink ) ) {
		link.href = event.permalink;
	}

	return fragment;
}
