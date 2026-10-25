/**
 * Shape of the `window.eventHubAdmin` object PHP prints on the admin page
 * (Admin_Page renders #event-hub-rsvps-root plus this inline data).
 */
export interface EventHubAdminData {
	restUrl: string;
	nonce: string;
	/** Published events for the picker. */
	events: { id: number; title: string }[];
	initialEventId: number;
	/** Complete nonced export URLs per event ID, built server-side (BUG-1). */
	exportUrls: Record< number, string >;
}

declare global {
	interface Window {
		eventHubAdmin?: EventHubAdminData;
	}
}

/** One row of GET /event-hub/v1/rsvps. */
export interface RsvpRow {
	id: number;
	eventId: number;
	name: string;
	email: string;
	status: 'going' | 'waitlist';
	createdAt: string;
}

/** Payload of GET /event-hub/v1/rsvps. */
export interface RsvpsResponse {
	rsvps: RsvpRow[];
	total: number;
	totalPages: number;
}

/** Filter value for the status select. */
export type StatusFilter = '' | 'going' | 'waitlist';
