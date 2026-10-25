/**
 * Event Hub event-list client entry.
 *
 * Registers the shared `event-hub` store (RSVP actions) plus the list filter
 * actions. Same registration as the rsvp-button entry, so a page with both
 * blocks still only ever registers the store once (module identity).
 */
import '../../interactivity/store';
import './filter';
