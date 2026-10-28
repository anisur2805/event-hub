<?php
/**
 * Phase 1 hardening regressions: honeypot + time-trap token (T15) guarding
 * the public RSVP endpoint.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP + DB) for Form_Guard + the REST gate.
 */
class Phase1_Hardening_Test extends WP_UnitTestCase {

	/**
	 * Creates a published upcoming event.
	 *
	 * @param string $capacity Capacity meta ('' = unlimited).
	 * @return int Post ID.
	 */
	private static function make_event( string $capacity = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $post_id, 'eh_end', '2099-05-01 20:00:00' );
		update_post_meta( $post_id, 'eh_capacity', $capacity );

		return $post_id;
	}

	/**
	 * POSTs an RSVP through the REST server.
	 *
	 * @param array<string,mixed> $body  JSON body.
	 * @param string              $route Route path.
	 * @return mixed WP_REST_Response|WP_Error.
	 */
	private static function post( array $body, string $route = '/event-hub/v1/rsvp' ) {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid body for $event_id (token freshly issued).
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $extra    Extra body keys (honeypot, stale token...).
	 * @return array<string,mixed>
	 */
	private static function valid_body( int $event_id, array $extra = array() ): array {
		return array_merge(
			array(
				'eventId'   => $event_id,
				'name'      => 'Honest Visitor',
				'email'     => 'honest@example.com',
				'status'    => 'going',
				'formToken' => Form_Guard::issue_token( $event_id ),
			),
			$extra
		);
	}

	/* ---------------------------------------------------------------------
	 * Form_Guard unit contract.
	 * ------------------------------------------------------------------- */

	/**
	 * Token round-trip: fresh issue verifies; HMAC bindings are enforced.
	 */
	public function test_token_round_trip_and_bindings(): void {
		$event_id = self::make_event();

		// A MIN_FILL_SECS-old issue verifies (aged tokens are the honest case;
		// a just-issued one is refused as too-fast — see time-window test).
		$this->assertTrue(
			Form_Guard::verify_token( Form_Guard::issue_token( $event_id, time() - 10 ), $event_id )
		);

		// Forged signature (right shape, wrong mac).
		$this->assertInstanceOf(
			\WP_Error::class,
			Form_Guard::verify_token( (string) time() . '.' . str_repeat( 'ab', 32 ), $event_id )
		);

		// Cross-event replay: token issued for one event refused on another.
		$other = self::make_event();
		$this->assertInstanceOf(
			\WP_Error::class,
			Form_Guard::verify_token( Form_Guard::issue_token( $event_id ), $other )
		);

		// Malformed shapes.
		foreach ( array( '', 'nope', (string) time(), (string) time() . '.deadbeef' ) as $junk ) {
			$this->assertInstanceOf( \WP_Error::class, Form_Guard::verify_token( $junk, $event_id ), "Token '$junk' must fail" );
		}
	}

	/**
	 * Time windows: submits faster than MIN_FILL_SECS and tokens older than
	 * MAX_AGE are both refused; the boundaries themselves verify.
	 */
	public function test_token_time_windows(): void {
		$event_id = self::make_event();
		$now      = time();

		// Too fast: issued 1s ago (boundary-1).
		$this->assertInstanceOf(
			\WP_Error::class,
			Form_Guard::verify_token( Form_Guard::issue_token( $event_id, $now - 1 ), $event_id, $now )
		);

		// Exactly MIN_FILL_SECS old: accepted.
		$this->assertTrue(
			Form_Guard::verify_token( Form_Guard::issue_token( $event_id, $now - Form_Guard::MIN_FILL_SECS ), $event_id, $now )
		);

		// Exactly MAX_AGE old: accepted (page-cache edge).
		$this->assertTrue(
			Form_Guard::verify_token( Form_Guard::issue_token( $event_id, $now - Form_Guard::MAX_AGE ), $event_id, $now )
		);

		// Stale: MAX_AGE + 1s.
		$this->assertInstanceOf(
			\WP_Error::class,
			Form_Guard::verify_token( Form_Guard::issue_token( $event_id, $now - Form_Guard::MAX_AGE - 1 ), $event_id, $now )
		);
	}

	/**
	 * Honeypot verdict: non-empty (even whitespace) = bot; missing/empty =
	 * not a honeypot hit (those are token-problem requests).
	 */
	public function test_honeypot_classification(): void {
		$this->assertTrue( Form_Guard::is_bot_honeypot( 'http://spam.example' ) );
		$this->assertTrue( Form_Guard::is_bot_honeypot( '   ' ) );
		$this->assertFalse( Form_Guard::is_bot_honeypot( '' ) );
		$this->assertFalse( Form_Guard::is_bot_honeypot( null ) );
		$this->assertFalse( Form_Guard::is_bot_honeypot( array( 'x' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * REST gate behavior.
	 * ------------------------------------------------------------------- */

	/**
	 * Missing token → 400 eh_form_token; wrong event's token → same.
	 */
	public function test_rest_rejects_missing_and_foreign_tokens(): void {
		$event_id = self::make_event();
		$other    = self::make_event();

		$missing = self::post(
			array(
				'eventId' => $event_id,
				'name'    => 'No Token',
				'email'   => 'no-token@example.com',
			)
		);
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'eh_form_token', $missing->get_data()['code'] );

		$foreign = self::post( self::valid_body( $event_id, array( 'eventId' => $event_id, 'formToken' => Form_Guard::issue_token( $other ) ) ) );
		$this->assertSame( 400, $foreign->get_status() );
		$this->assertSame( 'eh_form_token', $foreign->get_data()['code'] );

		// Nothing was written.
		$this->assertSame(
			array( 'going' => 0, 'waitlist' => 0 ),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * Honeypot hits 400 eh_honeypot — and the check precedes the token
	 * check (bot floods burn nothing but a 400).
	 */
	public function test_rest_honeypot_hits_before_token_check(): void {
		$event_id = self::make_event();

		$hit = self::post(
			self::valid_body(
				$event_id,
				array(
					'honeypot' => 'http://spam.example',
					'formToken' => 'garbage',
				)
			)
		);

		$this->assertSame( 400, $hit->get_status() );
		$this->assertSame( 'eh_honeypot', $hit->get_data()['code'] );
		$this->assertSame(
			array( 'going' => 0, 'waitlist' => 0 ),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * TOO-FAST submits (fresh page, instant POST) are 400 eh_form_token.
	 */
	public function test_rest_rejects_instant_submits(): void {
		$event_id = self::make_event();

		// Token issued NOW: age 0 < MIN_FILL_SECS → refused.
		$fast = self::post( self::valid_body( $event_id ) );

		$this->assertSame( 400, $fast->get_status() );
		$this->assertSame( 'eh_form_token', $fast->get_data()['code'] );
	}

	/**
	 * The honest flow: valid token aged ≥ MIN_FILL_SECS → 201 + counts,
	 * and the rendered card embeds the two guard inputs.
	 */
	public function test_rest_accepts_honest_flow_and_markup_has_inputs(): void {
		$event_id = self::make_event();

		$ok = self::post(
			self::valid_body( $event_id, array( 'formToken' => Form_Guard::issue_token( $event_id, time() - 10 ) ) )
		);

		$this->assertSame( 201, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertSame( array( 'going' => 1, 'waitlist' => 0 ), $ok->get_data()['counts'] );

		// No email in the 201 body (standing PII rule).
		$this->assertStringNotContainsString( 'honest@example.com', (string) wp_json_encode( $ok->get_data() ) );

		$html = Render_Blocks::render_rsvp_button( $event_id );

		$this->assertStringContainsString( 'name="event_hub_website"', $html );
		$this->assertStringContainsString( 'name="event_hub_form_ts"', $html );
		$this->assertStringContainsString( 'event-hub-rsvp__hp', $html );

		// The rendered token verifies against the event. Pull the whole
		// <input> tag first (attributes span lines), then its value. Verify at
		// a simulated post-fill time: the token is issued at render (age 0,
		// too-fast for an instant verify), a human submits MIN_FILL_SECS+ later.
		$this->assertSame( 1, preg_match( '/<input[^>]*event_hub_form_ts[^>]*>/', $html, $tag ) );
		$this->assertSame( 1, preg_match( '/value="([^"]+)"/', $tag[0], $m ) );
		$this->assertTrue( Form_Guard::verify_token( $m[1], $event_id, time() + 10 ) );
	}

	/**
	 * Flood contract (T8 re-verified): 10 accepted POSTs per IP, the 11th is
	 * 429 — the guard checks run before the meter, so all 10 need real tokens.
	 */
	public function test_rest_flood_is_rate_limited_at_ten(): void {
		$event_id = self::make_event();
		$token    = Form_Guard::issue_token( $event_id, time() - 10 );

		$last = null;

		for ( $i = 0; $i < 10; $i++ ) {
			$last = self::post(
				self::valid_body(
					$event_id,
					array(
						'email'     => sprintf( 'flood%d@example.com', $i ),
						'formToken' => $token,
					)
				)
			);
			$this->assertSame( 201, $last->get_status(), "POST $i should pass guard+limit" );
		}

		$eleventh = self::post( self::valid_body( $event_id, array( 'email' => 'flood10@example.com', 'formToken' => $token ) ) );
		$this->assertSame( 429, $eleventh->get_status() );
		$this->assertSame( 'eh_rate_limited', $eleventh->get_data()['code'] );
	}
}
