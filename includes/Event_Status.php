<?php
/**
 * Event status: cancelled / postponed (ROADMAP 5.3).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single read path for the eh_status meta and the status-generation
 * bookkeeping that makes attendee notices one-shot per transition.
 *
 * Contract (DECISIONS #49):
 * - '' (absent/unknown value) = scheduled — the pre-5.3 default everywhere.
 * - Junk values read as scheduled (read-side twin of the enum save schema).
 * - Generation counter: bumped on every TRANSITION to cancelled/postponed
 *   (a re-save of the same status never bumps). The attendee sweep emails
 *   rows whose status_notified_gen is behind the event generation and
 *   stamps them with it — so un-cancel + re-cancel re-notifies, while a
 *   repeated save never re-spams.
 */
final class Event_Status {

	/**
	 * Post meta key holding the status ('' | 'cancelled' | 'postponed').
	 *
	 * @var string
	 */
	public const META = 'eh_status';

	/**
	 * Post meta key holding the notice generation (internal, not in REST).
	 *
	 * @var string
	 */
	public const GENERATION_META = 'eh_status_generation';

	/**
	 * RSVP table column stamped with the last generation the attendee was
	 * notified about (DB 1.6.0).
	 *
	 * @var string
	 */
	public const COLUMN = 'status_notified_gen';

	/**
	 * All non-scheduled statuses.
	 *
	 * @var string[]
	 */
	public const STATUSES = array( 'cancelled', 'postponed' );

	/**
	 * The event's validated status: '' (scheduled), 'cancelled' or
	 * 'postponed'.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function get( int $event_id ): string {
		$raw = trim( (string) get_post_meta( $event_id, self::META, true ) );

		return in_array( $raw, self::STATUSES, true ) ? $raw : '';
	}

	/**
	 * True when RSVPs must be refused: a cancelled event takes no more
	 * RSVPs. Postponed stays open — people may still join for the new date;
	 * the pill/JSON-LD tell them the date is unsettled.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public static function blocked( int $event_id ): bool {
		return 'cancelled' === self::get( $event_id );
	}

	/**
	 * Current generation for the event (0 when never set).
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	public static function generation( int $event_id ): int {
		return (int) get_post_meta( $event_id, self::GENERATION_META, true );
	}

	/**
	 * Schema.org eventStatus IRI for the event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Full IRI (always a valid scheduled-family value).
	 */
	public static function schema_status( int $event_id ): string {
		return match ( self::get( $event_id ) ) {
			'cancelled' => 'https://schema.org/EventCancelled',
			'postponed' => 'https://schema.org/EventPostponed',
			default     => 'https://schema.org/EventScheduled',
		};
	}

	/**
	 * Bumps the event's generation after a transition into a non-scheduled
	 * status. Returns the new generation.
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	public static function bump_generation( int $event_id ): int {
		$next = self::generation( $event_id ) + 1;

		update_post_meta( $event_id, self::GENERATION_META, (string) $next );

		return $next;
	}

	/**
	 * Published events whose attendees are owed a status notice: currently
	 * cancelled/postponed with at least one transition recorded. Events
	 * back to scheduled are excluded (un-cancel is silent by design — the
	 * next transition re-notifies), and their rows simply stay stamped at
	 * the older generation.
	 *
	 * @return array<int,int>
	 */
	public static function unswept_event_ids(): array {
		$events = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded cron sweep, hourly continuation covers the rest.
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the transitional-event filter IS the feature.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META,
						'value'   => self::STATUSES,
						'compare' => 'IN',
					),
					array(
						'key'     => self::GENERATION_META,
						'value'   => 1,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return array_map( 'intval', $events );
	}
}
