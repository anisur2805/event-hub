/**
 * Event Hub RSVP store — client entry.
 *
 * The PHP side renders the markup (data-wp-interactive="event-hub" regions,
 * one context per event, inputs with data-wp-on--input, buttons with
 * data-wp-on--click) and seeds global server state via
 * wp_interactivity_state(). Importing this module registers the shared
 * store; the event list additionally pulls in the filter actions (filter.ts).
 */
import '../../interactivity/store';
import '../event-list/filter';
