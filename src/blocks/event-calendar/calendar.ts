/**
 * Event calendar month navigation — registered as extra actions on the shared
 * `event-hub` store (deep-merge, same pattern as the event-list filter).
 *
 * Progressive enhancement (core pagination pattern, Context7-verified against
 * the Gutenberg interactivity docs): the block server-renders a full month
 * inside a router region; the prev/next links carry `?eh_month=YYYY-MM` and
 * work WITHOUT JS. With JS this action preventDefaults the click and hands
 * the URL to the interactivity router, which re-renders every
 * `data-wp-router-region` in the response — one render code path (PHP), no
 * client grid rebuild, no REST surface change.
 */
import { store, withSyncEvent } from '@wordpress/interactivity';
import { type EventHubStore } from '../../interactivity/types';

const { state } = store< EventHubStore >( 'event-hub', {
	actions: {
		/**
		 * Client-side month navigation: swallow the native GET navigation
		 * and let the router region swap the rendered month in.
		 */
		calendarNav: withSyncEvent( function* (
			event: Event
		): Generator< unknown, void, unknown > {
			event.preventDefault();

			// `currentTarget` is the <a>; target could be a text node inside.
			const link =
				event?.currentTarget instanceof HTMLAnchorElement
					? event.currentTarget
					: null;

			if ( ! link?.href ) {
				return;
			}

			const router = ( yield import(
				/* webpackChunkName: "interactivity-router" */
				'@wordpress/interactivity-router'
			) ) as {
				actions: { navigate: ( url: string ) => Promise< void > };
			};

			yield router.actions.navigate( link.href );
		} ),
	},
} );

// `state` is unused here today (server state covers restUrl/nonce); the
// destructured binding keeps the store() type parameter honest.
void state;
