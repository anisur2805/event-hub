<?php
/**
 * Time-trap token guard against scripted form floods (Phase 1 hardening).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Honeypot + time-trap tokens for the public RSVP form (docs/THREAT-MODEL.md T15).
 *
 * The renderer embeds a timestamp token HMAC-signed with the nonce salt; the
 * REST layer refuses POSTs whose token is absent, forged, older than
 * MAX_AGE (page-cache-safe) or fresher than MIN_FILL_SECS (instant submits).
 * Tokens are stateless — no storage, reuse within max age is accepted and
 * the per-IP rate limit stays the load control.
 */
final class Form_Guard {

	/**
	 * Honeypot form-field name (bots autofill it, humans never see it).
	 *
	 * @var string
	 */
	public const HONEYPOT_FIELD = 'event_hub_website';

	/**
	 * Time-trap token form-field / REST param name.
	 *
	 * @var string
	 */
	public const TOKEN_FIELD = 'event_hub_form_ts';

	/**
	 * JSON body key carrying the token (store.ts).
	 *
	 * @var string
	 */
	public const TOKEN_PARAM = 'formToken';

	/**
	 * JSON body key carrying the honeypot value (store.ts).
	 *
	 * @var string
	 */
	public const HONEYPOT_PARAM = 'honeypot';

	/**
	 * Minimum seconds between form render and accepted submit.
	 *
	 * @var int
	 */
	public const MIN_FILL_SECS = 3;

	/**
	 * Maximum token age: stale beyond this (cached page) is rejected.
	 *
	 * @var int
	 */
	public const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Issues a token as if the form were rendered now.
	 *
	 * @param int      $event_id  Event post ID the form belongs to.
	 * @param int|null $issued_at Render timestamp; null = now (tests backdate).
	 * @return string "{timestamp}.{hmac}".
	 */
	public static function issue_token( int $event_id, ?int $issued_at = null ): string {
		$issued_at = $issued_at ?? time();

		return $issued_at . '.' . self::mac( $event_id, $issued_at );
	}

	/**
	 * Verifies a submitted token for an event at inspection time $now.
	 *
	 * @param string   $token    Raw submitted token.
	 * @param int      $event_id Event the token must be bound to.
	 * @param int|null $now      Verification timestamp; null = now (tests).
	 * @return true|\WP_Error True when valid; eh_form_token WP_Error otherwise.
	 */
	public static function verify_token( string $token, int $event_id, ?int $now = null ) {
		$now = $now ?? time();

		if ( 1 !== preg_match( '/^([0-9]{1,10})\.([0-9a-f]{64})$/', $token, $m ) ) {
			return self::fail();
		}

		$issued_at = (int) $m[1];

		if ( ! hash_equals( self::mac( $event_id, $issued_at ), $m[2] ) ) {
			return self::fail();
		}

		$age = $now - $issued_at;

		if ( $age < self::MIN_FILL_SECS || $age > self::MAX_AGE ) {
			return self::fail();
		}

		return true;
	}

	/**
	 * True when the honeypot POST value betrays a bot: any non-empty string.
	 * A real browser submits the field untouched (empty string) — even a
	 * whitespace-only value means something filled it. Missing (curl-style
	 * payloads) is a token problem, not a honeypot hit.
	 *
	 * @param mixed $value Submitted honeypot value.
	 * @return bool
	 */
	public static function is_bot_honeypot( $value ): bool {
		return is_string( $value ) && '' !== $value;
	}

	/**
	 * HMAC over the event binding + issue time, keyed with the nonce salt.
	 *
	 * @param int $event_id  Event post ID.
	 * @param int $issued_at Issue timestamp.
	 * @return string Hex digest.
	 */
	private static function mac( int $event_id, int $issued_at ): string {
		return hash_hmac( 'sha256', 'eh_form|' . $event_id . '|' . $issued_at, wp_salt( 'nonce' ) );
	}

	/**
	 * The single rejection shape (same code for every way a token fails —
	 * no oracle separating forged from stale from too-fast).
	 *
	 * @return \WP_Error
	 */
	private static function fail(): \WP_Error {
		return new \WP_Error(
			'eh_form_token',
			__( 'This RSVP form has expired — please reload the page and try again.', 'event-hub' ),
			array( 'status' => 400 )
		);
	}
}
