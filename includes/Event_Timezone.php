<?php
/**
 * Per-event timezone resolution (ROADMAP: per-event timezone slice).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves an event's IANA timezone for interpreting its stored wall-clock
 * eh_start / eh_end. The meta is read-side validated: anything that is not
 * a usable DateTimeZone identifier falls back to the site timezone, and
 * '' / missing meta means "site timezone" (so pre-existing rows keep their
 * exact meaning with no data migration).
 */
final class Event_Timezone {

	/**
	 * Meta key holding the event's IANA timezone identifier ('' = site zone).
	 *
	 * @var string
	 */
	public const META = 'eh_timezone';

	/**
	 * Resolves the event's zone. Junk / unknown identifiers degrade to the
	 * site timezone — the same read-side tolerance as every other eh_ meta.
	 *
	 * @param int $event_id Event post ID.
	 * @return \DateTimeZone
	 */
	public static function for_event( int $event_id ): \DateTimeZone {
		$raw = trim( (string) get_post_meta( $event_id, self::META, true ) );

		if ( '' !== $raw ) {
			try {
				return new \DateTimeZone( $raw );
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- deliberate fall-through to the site default.
				// Unknown identifier: fall through to the site default.
			}
		}

		return wp_timezone();
	}

	/**
	 * Save-side normalizer for the meta schema (5A.4 twin discipline): ''
	 * passes through (site zone), a usable identifier passes through, and
	 * anything else degrades to '' — never stored as junk. The read-side
	 * fallback (for_event) makes already-stored junk behave identically.
	 *
	 * @param string $value Raw submitted value.
	 * @return string The normalized identifier ('' = site zone).
	 */
	public static function sanitize_zone( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		try {
			new \DateTimeZone( $value );
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the catch IS the validation result.
			return '';
		}

		return $value;
	}

	/**
	 * Parses a stored wall-clock string IN the event's zone and returns an
	 * ISO 8601 string bearing that zone's offset (or '' unparseable). Both
	 * storage shapes accepted (editor ISO with T, WP-CLI site format) — the
	 * meta_iso() contract. Offset-bearing ISO makes every downstream
	 * DateTimeImmutable consumer zone-correct without knowing about events.
	 *
	 * @param string        $raw  Stored value.
	 * @param \DateTimeZone $zone Zone to interpret the wall clock in.
	 * @return string ISO 8601 with offset, or ''.
	 */
	public static function to_offset_iso( string $raw, \DateTimeZone $zone ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// The editor stores ISO (Y-m-d\TH:i:s); WP-CLI/imports often write
		// site format (Y-m-d H:i:s). Accept both, interpret in $zone.
		$datetime = \date_create_immutable_from_format( 'Y-m-d\TH:i:s', $raw, $zone );

		if ( false === $datetime ) {
			$datetime = \date_create_immutable_from_format( 'Y-m-d H:i:s', $raw, $zone );
		}

		if ( false === $datetime ) {
			return '';
		}

		return $datetime->format( 'c' );
	}
}
