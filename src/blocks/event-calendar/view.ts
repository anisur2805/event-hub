/**
 * Event Hub event-calendar client entry.
 *
 * Registers the shared `event-hub` store (RSVP actions + i18n) plus the
 * calendar month-navigation action. Same double-registration pattern as the
 * event-list entry: a page with several Event Hub blocks still registers the
 * store exactly once (module identity).
 */
import '../../interactivity/store';
import './calendar';
