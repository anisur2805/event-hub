<?php
/**
 * Schema.org Event structured data (ROADMAP 1.1).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints schema.org Event JSON-LD on single-event pages.
 *
 * Emits only data the plugin actually stores — no price, currency, or
 * organizer is fabricated. Capacity numbers surface as schema.org-native
 * maximum/remaining attendee capacity on the Offer, so no commerce fields
 * are invented. Attendee emails never appear: aggregates only.
 *
 * Runs on wp_head (not the fallback template) because a theme's own single
 * template always wins over Single_Template — wp_head is the only hook that
 * covers both rendering paths.
 */
final class Structured_Data {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'print_json_ld' ), 20 );
	}

	/**
	 * Prints the JSON-LD graph on single-event pages; nothing elsewhere.
	 *
	 * @return void
	 */
	public function print_json_ld(): void {
		if ( ! is_singular( 'eh_event' ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$s = Render_Blocks::summary( $post );

		if ( empty( $s['id'] ) ) {
			return;
		}

		$graph = $this->graph( $s );

		if ( null === $graph ) {
			return;
		}

		wp_print_inline_script_tag(
			(string) wp_json_encode(
				$graph,
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			),
			array( 'type' => 'application/ld+json' )
		);
	}

	/**
	 * Builds the Event graph array from a summary; null when no start date
	 * exists (startDate is the one fact Google requires — without it the
	 * markup would be a broken Event, so nothing is printed).
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return array<string,mixed>|null
	 */
	public function graph( array $s ): ?array {
		$start = (string) $s['start'];

		if ( '' === $start ) {
			return null;
		}

		$event = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Event',
			'name'      => (string) $s['title'],
			'startDate' => $start,
		);

		$end = (string) $s['end'];

		if ( '' !== $end ) {
			$event['endDate'] = $end;
		}

		$description = (string) $s['excerpt'];

		if ( '' !== $description ) {
			$event['description'] = $description;
		}

		$image = self::image_url( (int) $s['id'] );

		if ( null !== $image ) {
			$event['image'] = $image;
		}

		// Location (5.1): a linked venue enriches the Place with its address;
		// the legacy free-text venue falls back to a name-only Place.
		// 5.6: a virtual event declares OnlineEventAttendanceMode — the
		// joining URL itself is NEVER emitted (it is a confirmed-attendee
		// secret; JSON-LD is world-readable). The location Place still
		// renders when a venue is ALSO linked (hybrid shape).
		$is_virtual = Render_Blocks::is_virtual( (int) $s['id'] );

		if ( $is_virtual ) {
			$event['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
		}

		$venue_id = Render_Blocks::linked_entity( (int) $s['id'], 'eh_venue_id', 'eh_venue' );

		if ( 0 !== $venue_id['id'] ) {
			$location = array(
				'@type' => 'Place',
				'name'  => $venue_id['title'],
			);

			$address = trim( (string) get_post_meta( $venue_id['id'], 'eh_venue_address', true ) );

			if ( '' !== $address ) {
				$location['address'] = $address;
			}

			$event['location'] = $location;
		} else {
			$venue = trim( (string) $s['venue'] );

			if ( '' !== $venue ) {
				$event['location'] = array(
					'@type' => 'Place',
					'name'  => $venue,
				);
			}
		}

		// Organizer (5.1): emitted only when an organizer entity is linked —
		// never fabricated from the legacy string (DECISIONS #36 honesty rule:
		// Person vs Organization cannot be inferred from free text).
		$organizer = Render_Blocks::linked_entity( (int) $s['id'], 'eh_organizer_id', 'eh_organizer' );

		if ( 0 !== $organizer['id'] ) {
			$event['organizer'] = array(
				'@type' => 'Organization',
				'name'  => $organizer['title'],
			);
		}

		// Status (5.3): the meta maps to the schema.org eventStatus family.
		// #36's honesty rule extends: a cancelled/postponed event must not
		// advertise availability, so offers drop entirely for it.
		$event['eventStatus'] = Event_Status::schema_status( (int) $s['id'] );

		if ( '' !== Event_Status::get( (int) $s['id'] ) ) {
			return $event;
		}
		if ( self::is_past( $s ) ) {
			// An ended event must not advertise availability.
			return $event;
		}

		$capacity = null !== $s['capacity'] ? (int) $s['capacity'] : null;
		$going    = (int) $s['counts']['going'];
		$full     = null !== $capacity && $going >= $capacity;

		$offer = array(
			'@type'        => 'Offer',
			'url'          => (string) $s['permalink'],
			'availability' => $full ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
		);

		if ( null !== $capacity ) {
			$offer['maximumAttendeeCapacity']   = $capacity;
			$offer['remainingAttendeeCapacity'] = max( 0, $capacity - $going );
		}

		$event['offers'] = $offer;

		return $event;
	}

	/**
	 * Featured-image URL for the event, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private static function image_url( int $post_id ): ?string {
		$url = get_the_post_thumbnail_url( $post_id, 'large' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * True when the event's end (or start, when no end exists) has passed.
	 * Mirrors Render_Blocks::is_past() semantics over the summary payload.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return bool
	 */
	private static function is_past( array $s ): bool {
		$end_ref = self::to_timestamp( (string) $s['end'] );

		if ( null === $end_ref ) {
			$end_ref = self::to_timestamp( (string) $s['start'] );
		}

		return null !== $end_ref && $end_ref < time();
	}

	/**
	 * Parses an ISO 8601 string into a Unix timestamp, or null.
	 *
	 * @param string $iso ISO 8601 datetime.
	 * @return int|null
	 */
	private static function to_timestamp( string $iso ): ?int {
		if ( '' === trim( $iso ) ) {
			return null;
		}

		$datetime = date_create_immutable( $iso );

		return false !== $datetime ? $datetime->getTimestamp() : null;
	}
}
