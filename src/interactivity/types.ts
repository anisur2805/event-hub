/**
 * Shared iAPI types for the `event-hub` store.
 *
 * The PHP side seeds global server state via wp_interactivity_state() and
 * renders one context object per RSVP wrapper region (see
 * includes/class-render-blocks.php). This file is the single source of truth
 * for that shape on the client side.
 */

/** Values of the `status` column / RSVP REST field. */
export type RsvpStatus = 'going' | 'waitlist';

/** Event summary as serialized by GET /event-hub/v1/events (public fields only). */
export interface EventSummary {
	id: number;
	title: string;
	permalink: string;
	excerpt: string;
	start: string;
	end: string;
	venue: string;
	capacity: number | null;
	counts: { going: number; waitlist: number };
	categories: string[];
	categoryNames: string[];
	rsvpOpen: boolean;
}

/** Server state seeded by wp_interactivity_state( 'event-hub', ... ). */
export interface ServerState {
	restUrl: string;
	nonce: string;
	user: { name: string; email: string } | null;
	/** msgid -> translated string, pre-translated by Render_Blocks::view_strings(). */
	i18n: Record< string, string >;
}

/**
 * Context rendered on every `[data-wp-interactive="event-hub"]` RSVP wrapper.
 * PHP renders these defaults; the client mutates them in place.
 */
export interface RsvpContext {
	eventId: number;
	capacity: number | null;
	counts: { going: number; waitlist: number };
	mine: null | RsvpStatus;
	busy: boolean;
	feedback: string;
	/** Directive-bound count line ("N going · M waitlisted"), SSR-safe. */
	countText: string;
	/** True when capacity is set and reached; swaps the button to waitlist. */
	full: boolean;
	/** Server-side open/closed flag; false also hides the name/email inputs. */
	rsvpOpen: boolean;
	/** True when the event is over; drives past-aware count-line text. */
	past: boolean;
	/** Name typed by the visitor; kept in context via actions.setName. */
	name: string;
	/** Email typed by the visitor; kept in context via actions.setEmail. */
	email: string;
}

/** REST error payload: { code, message, data: { status } }. */
export interface RestError {
	code: string;
	message: string;
	data?: { status?: number; params?: Record< string, string > };
	counts?: { going: number; waitlist: number };
}

/** Success payload of POST /event-hub/v1/rsvp (no email — PII rule). */
export interface RsvpResponse {
	rsvp: {
		id: number;
		eventId: number;
		name: string;
		status: RsvpStatus;
		createdAt: string;
	};
	counts: { going: number; waitlist: number };
}

/** Category option for the list filter select (REST /wp/v2 shape, trimmed). */
export interface CategoryOption {
	id: number;
	name: string;
	slug: string;
}

/**
 * Context rendered on the event-list interactive region. The filter select,
 * card template and the "N events found" announcer read it.
 */
export interface ListContext {
	/** Filter options rendered by PHP (id, name, slug). */
	categories: CategoryOption[];
	/** Slug currently selected in the filter; '' = all events. */
	selected: string;
	/** Number of posts per page chosen in the editor (query cap). */
	postsPerPage: number;
	/** Live-region text announcing the filtered result count. */
	foundText: string;
	/** True while a filtered fetch is in flight. */
	busy: boolean;
	/** True after a failed filter fetch; recolors the announcer text. */
	hasError: boolean;
}

/**
 * Full `event-hub` store type: server state (restUrl/nonce/user via
 * wp_interactivity_state) + client getters, merged into `state`.
 */
export interface EventHubStore {
	state: ServerState;
	actions: {
		setName: ( event: Event ) => void;
		setEmail: ( event: Event ) => void;
		rsvpGoing: () => Generator< unknown, void, unknown >;
		rsvpWaitlist: () => Generator< unknown, void, unknown >;
		filterCategory: ( event: Event ) => Generator< unknown, void, unknown >;
		filterSubmit: ( event: Event ) => void;
	};
}
