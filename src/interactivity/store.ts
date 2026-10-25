/**
 * Shared Interactivity API store for the `event-hub` namespace.
 *
 * Registered once per page (script module side effects); both the RSVP button
 * and the event list import this module so the store definition stays in one
 * place. PHP seeds global state via wp_interactivity_state( 'event-hub', ... )
 * and renders a context object per wrapper region (see types.ts for the shape).
 */
import { store, getContext } from '@wordpress/interactivity';
import {
	translate as __,
	choosePlural as _n,
	interpolate as sprintf,
} from './i18n';
import { type EventHubStore, type RsvpContext } from './types';

/**
 * Basic RFC-ish email shape check; the server re-validates with sanitize_email().
 * @param value
 */
function isEmail( value: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
}

interface RestFailure {
	code?: string;
	message?: string;
	counts?: { going: number; waitlist: number };
}

const { state } = store< EventHubStore >( 'event-hub', {
	actions: {
		/**
		 * Keep the visitor's name in context as they type.
		 * @param event
		 */
		setName( event: Event ) {
			const input = event.target as HTMLInputElement | null;
			if ( ! input ) {
				return;
			}
			getContext< RsvpContext >().name = input.value;
		},
		/**
		 * Keep the visitor's email in context as they type.
		 * @param event
		 */
		setEmail( event: Event ) {
			const input = event.target as HTMLInputElement | null;
			if ( ! input ) {
				return;
			}
			getContext< RsvpContext >().email = input.value;
		},
		*rsvpGoing(): Generator< unknown, void, unknown > {
			yield* rsvp( 'going' );
		},
		*rsvpWaitlist(): Generator< unknown, void, unknown > {
			yield* rsvp( 'waitlist' );
		},
	},
} );

/**
 * One RSVP POST. Generator so the iAPI runtime restores the correct
 * context scope around every await point.
 * @param status
 */
function* rsvp(
	status: 'going' | 'waitlist'
): Generator< unknown, void, unknown > {
	const ctx = getContext< RsvpContext >();
	const name = ( ctx.name ?? '' ).trim();

	if ( ! name ) {
		ctx.feedback = __( 'Please enter your name.' );
		return;
	}
	const email = ( ctx.email ?? '' ).trim();
	if ( ! isEmail( email ) ) {
		ctx.feedback = __( 'Please enter a valid email address.' );
		return;
	}
	if ( ctx.mine !== null ) {
		ctx.feedback = __( "You're already registered." );
		return;
	}

	ctx.busy = true;
	ctx.feedback = '';
	try {
		const response = ( yield fetch( `${ state.restUrl }event-hub/v1/rsvp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': state.nonce,
			},
			body: JSON.stringify( {
				eventId: ctx.eventId,
				name,
				email,
				status,
			} ),
		} ) ) as Response;

		const body = ( yield response.json() ) as
			| {
					rsvp: { status: 'going' | 'waitlist' };
					counts?: { going: number; waitlist: number };
			  }
			| RestFailure;

		if ( response.ok && 'rsvp' in body && body.rsvp ) {
			ctx.mine = body.rsvp.status;
			if ( body.counts ) {
				ctx.counts = body.counts;
				// context.countText is the directive-bound, SSR-safe rendering
				// target (DECISIONS.md #30); keep it in sync client-side.
				ctx.countText = sprintf(
					/* translators: %s: number of attendees. */
					_n(
						'%s person going',
						'%s people going',
						body.counts.going
					),
					String( body.counts.going )
				);
				if ( null !== ctx.capacity ) {
					ctx.full = body.counts.going >= ctx.capacity;
				}
			}
			ctx.feedback =
				body.rsvp.status === 'going'
					? __( 'You are registered.' )
					: __( 'You are on the waitlist.' );
			return;
		}

		const failure = body as RestFailure;
		if ( failure.counts ) {
			ctx.counts = failure.counts;
		}
		switch ( failure.code ) {
			case 'eh_capacity_full':
				ctx.feedback = __( 'This event is full — join the waitlist.' );
				break;
			case 'eh_duplicate':
				ctx.mine = 'going';
				ctx.feedback = __( "You're already registered." );
				break;
			case 'eh_rate_limited':
				ctx.feedback = __(
					'Too many attempts — try again in a few minutes.'
				);
				break;
			case 'eh_event_passed':
				ctx.feedback = __( 'This event has already taken place.' );
				break;
			default:
				ctx.feedback = __( 'Something went wrong — please try again.' );
		}
	} catch {
		ctx.feedback = __( 'Something went wrong — please try again.' );
	} finally {
		ctx.busy = false;
	}
}
