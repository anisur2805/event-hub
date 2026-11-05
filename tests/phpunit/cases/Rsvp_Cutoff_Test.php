<?php
/**
 * Tests for ROADMAP §2.4 — RSVP window cutoffs (open/close hours meta).
 *
 * Covers the parser contract, the rsvp_cutoffs() verdict matrix, the fold
 * into summary()'s rsvpOpen + pill, the repository write guard (both 409
 * codes), and the no-cutoff legacy behavior.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Cutoff feature test case.
 */
class Rsvp_Cutoff_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with a far-future start; optionally stamps
	 * extra meta (cutoff hours).
	 *
	 * @param array<string,string> $meta Extra meta (cutoff hours) to stamp.
	 * @return int Post ID.
	 */
	private static function make_event( array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $post_id, 'eh_end', '2099-05-01 20:00:00' );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * POSTs an RSVP through the REST server.
	 *
	 * @param array<string,mixed> $body JSON body.
	 * @return mixed WP_REST_Response|WP_Error.
	 */
	private static function post( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid RSVP body for $event_id (token backdated past MIN_FILL_SECS —
	 * a fresh token is "too fast" and 400s before any cutoff logic).
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string,mixed>
	 */
	private static function valid_body( int $event_id ): array {
		return array(
			'eventId'   => $event_id,
			'name'      => 'Window Visitor',
			'email'     => 'window-' . $event_id . '@example.com',
			'status'    => 'going',
			'formToken' => Form_Guard::issue_token( $event_id, time() - 10 ),
		);
	}

	/*
	 * parse_hours() contract (twin of parse_capacity, BUG 5 shape).
	 */

	/**
	 * Digits parse, junk/empty/missing fall back to no-cutoff.
	 */
	public function test_parse_hours_contract(): void {
		self::assertSame( 5, Rsvp_Repository::parse_hours( '5' ) );
		self::assertSame( 0, Rsvp_Repository::parse_hours( '0' ) );
		self::assertSame( 720, Rsvp_Repository::parse_hours( '0720' ) );

		self::assertNull( Rsvp_Repository::parse_hours( '' ) );
		self::assertNull( Rsvp_Repository::parse_hours( ' ' ) );
		self::assertNull( Rsvp_Repository::parse_hours( '12abc' ) );
		self::assertNull( Rsvp_Repository::parse_hours( '-3' ) );
		self::assertNull( Rsvp_Repository::parse_hours( '3.5' ) );
	}

	/*
	 * Verdict matrix.
	 */

	/**
	 * No cutoff meta: both verdicts open (legacy behavior unchanged).
	 */
	public function test_no_cutoff_meta_stays_open(): void {
		$event_id = self::make_event();

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertFalse( $cutoffs['closed'] );
		self::assertFalse( $cutoffs['not_open'] );
	}

	/**
	 * Missing eh_start: cutoffs are inert (conservative missing-data rule).
	 */
	public function test_missing_start_keeps_window_open(): void {
		$event_id = self::make_event(
			array(
				'eh_rsvp_open_hours'  => '24',
				'eh_rsvp_close_hours' => '2',
			)
		);

		delete_post_meta( $event_id, 'eh_start' );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertFalse( $cutoffs['closed'] );
		self::assertFalse( $cutoffs['not_open'] );
	}

	/**
	 * Far-future start with close=2: open on both boundaries.
	 */
	public function test_future_event_with_close_cutoff_is_open(): void {
		$event_id = self::make_event( array( 'eh_rsvp_close_hours' => '2' ) );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertFalse( $cutoffs['closed'] );
		self::assertFalse( $cutoffs['not_open'] );
	}

	/**
	 * Start within the close window (start minus 2h is already past):
	 * closed = true. Boundary is inclusive: at exactly the deadline the
	 * window is closed (<= comparison).
	 */
	public function test_start_inside_close_window_is_closed(): void {
		$event_id = self::make_event( array( 'eh_rsvp_close_hours' => '2' ) );

		// Start one hour from now: start - 2h sits in the past → closed.
		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertTrue( $cutoffs['closed'] );
		self::assertFalse( $cutoffs['not_open'] );
	}

	/**
	 * Close=0 closes exactly at start (before the event is past).
	 */
	public function test_zero_close_closes_at_start(): void {
		$event_id = self::make_event( array( 'eh_rsvp_close_hours' => '0' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 30 ) );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertFalse( $cutoffs['closed'] );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() - 5 ) );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertTrue( $cutoffs['closed'] );
	}

	/**
	 * Open-hours boundary: start farther out than the open cutoff means the
	 * window has not opened; inside it, not_open flips false.
	 */
	public function test_open_cutoff_boundary(): void {
		$event_id = self::make_event( array( 'eh_rsvp_open_hours' => '48' ) );

		// Start 72h out: start - 48h is still future → not open.
		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 72 * HOUR_IN_SECONDS ) );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertTrue( $cutoffs['not_open'] );
		self::assertFalse( $cutoffs['closed'] );

		// Start 24h out: start - 48h is past → open.
		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS ) );

		$cutoffs = ( new Rsvp_Repository() )->rsvp_cutoffs( $event_id );

		self::assertFalse( $cutoffs['not_open'] );
	}

	/*
	 * summary() fold + pill.
	 */

	/**
	 * Cutoff-closed event: rsvpOpen false, cutoffClosed true, Closed pill.
	 */
	public function test_summary_folds_cutoff_closed(): void {
		$event_id = self::make_event( array( 'eh_rsvp_close_hours' => '2' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$summary = Render_Blocks::summary( $event_id );

		self::assertFalse( $summary['rsvpOpen'] );
		self::assertTrue( $summary['cutoffClosed'] );
		self::assertFalse( $summary['cutoffNotOpen'] );
	}

	/**
	 * Not-open event: rsvpOpen false, cutoffNotOpen true.
	 */
	public function test_summary_folds_cutoff_not_open(): void {
		$event_id = self::make_event( array( 'eh_rsvp_open_hours' => '48' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 72 * HOUR_IN_SECONDS ) );

		$summary = Render_Blocks::summary( $event_id );

		self::assertFalse( $summary['rsvpOpen'] );
		self::assertTrue( $summary['cutoffNotOpen'] );
		self::assertFalse( $summary['cutoffClosed'] );
	}

	/**
	 * The pill reports Closed for a cutoff-closed, non-past event.
	 */
	public function test_status_pill_reports_closed(): void {
		$event_id = self::make_event( array( 'eh_rsvp_close_hours' => '2' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$summary = Render_Blocks::summary( $event_id );

		$url = Render_Blocks::render_rsvp_button( $event_id );

		self::assertFalse( $summary['rsvpOpen'] );
		self::assertTrue( $summary['cutoffClosed'] );

		// Inert markup: no interactive region, no form inputs, no button.
		self::assertStringNotContainsString( 'data-wp-interactive', $url );
		self::assertStringNotContainsString( 'type="button"', $url );
		self::assertStringContainsString( 'RSVPs are closed.', $url );
	}

	/**
	 * A not-open event renders the not-opened notice (not the closed one).
	 */
	public function test_button_renders_not_open_notice(): void {
		$event_id = self::make_event( array( 'eh_rsvp_open_hours' => '48' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 72 * HOUR_IN_SECONDS ) );

		$url = Render_Blocks::render_rsvp_button( $event_id );

		self::assertStringContainsString( 'RSVPs have not opened yet.', $url );
		self::assertStringNotContainsString( 'RSVPs are closed.', $url );
	}

	/*
	 * REST write guard.
	 */

	/**
	 * POST going + waitlist both 409 eh_rsvp_closed when inside the close
	 * window.
	 */
	public function test_rest_create_blocked_when_closed(): void {
		$event_id = self::make_event( array( 'eh_rsvp_close_hours' => '2' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		foreach ( array( 'going', 'waitlist' ) as $status ) {
			$response = self::post(
				array_merge(
					self::valid_body( $event_id ),
					array(
						'status' => $status,
						'email'  => 'closed-' . $status . '-' . $event_id . '@example.com',
					)
				)
			);

			self::assertSame( 409, $response->get_status() );
			self::assertSame( 'eh_rsvp_closed', $response->get_data()['code'] );
		}
	}

	/**
	 * POST 409 eh_rsvp_not_open before the window opens.
	 */
	public function test_rest_create_blocked_when_not_open(): void {
		$event_id = self::make_event( array( 'eh_rsvp_open_hours' => '48' ) );

		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 72 * HOUR_IN_SECONDS ) );

		$response = self::post( self::valid_body( $event_id ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'eh_rsvp_not_open', $response->get_data()['code'] );
	}

	/**
	 * Within an open window: 201 unchanged (cutoffs leave the happy path
	 * intact). Window is [start-200h, start-2h]; now+3h sits inside it.
	 */
	public function test_rest_create_succeeds_inside_window(): void {
		$event_id = self::make_event(
			array(
				'eh_rsvp_close_hours' => '2',
				'eh_rsvp_open_hours'  => '200',
			)
		);

		// Now + 3h: inside [start-200h, start-2h].
		update_post_meta( $event_id, 'eh_start', wp_date( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ) );

		$response = self::post( self::valid_body( $event_id ) );

		self::assertSame( 201, $response->get_status() );
	}

	/**
	 * No cutoff meta: REST behavior unchanged (legacy 201).
	 */
	public function test_rest_create_unaffected_without_cutoffs(): void {
		$event_id = self::make_event();

		$response = self::post( self::valid_body( $event_id ) );

		self::assertSame( 201, $response->get_status() );
	}
}
