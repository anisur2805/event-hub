<?php
/**
 * ROADMAP 5.7 regressions: manual waitlist promotion — the organizer's
 * per-person "let them in" verb. The flip shares ONE guarded UPDATE and
 * ONE advisory lock with the automatic FIFO path (delete_and_promote),
 * so both promotion sources obey the same capacity contract, and the
 * 5A.2 promotion notice rides the same event_hub_rsvp_promoted action.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Manual waitlist promotion tests.
 */
class Waitlist_Management_Test extends WP_UnitTestCase {

	/**
	 * Promoted-row captures from the event_hub_rsvp_promoted action.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public static array $captured_actions = array();

	/**
	 * Creates a published event with start/end/capacity meta.
	 *
	 * @param string $capacity Capacity meta ('' = unlimited).
	 * @return int Post ID.
	 */
	private static function make_event( string $capacity = '1' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $post_id, 'eh_end', '2099-05-01 20:00:00' );
		update_post_meta( $post_id, 'eh_capacity', $capacity );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Waitlist Mgmt Event',
			)
		);

		return $post_id;
	}

	/**
	 * Seeds rows through the public create() path.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $status   'going' or 'waitlist'.
	 * @param int    $count    How many rows.
	 * @return array<int,int> Created row IDs.
	 */
	private static function seed( int $event_id, string $status, int $count ): array {
		$repo = Plugin::instance()->repository();
		$ids  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $repo->create(
				sprintf( 'Wm %1$s %2$d', ucfirst( $status ), $i ),
				sprintf( 'wm%1$s%2$d-%3$d@example.com', $status, $i, wp_rand( 1, PHP_INT_MAX ) ),
				$status,
				$event_id
			);

			if ( is_array( $result ) ) {
				$ids[] = (int) $result['rsvp']['id'];
			}
		}

		return $ids;
	}

	/**
	 * Resets action capture before every test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		self::$captured_actions = array();

		add_action(
			'event_hub_rsvp_promoted',
			static function ( $promoted, $event_id ): void {
				self::$captured_actions[] = array( $promoted, $event_id );
			},
			10,
			2
		);
	}

	/**
	 * REST helper: dispatch the promote route as the current user.
	 *
	 * @param int $row_id RSVP row ID.
	 * @return \WP_REST_Response
	 */
	private static function post_promote( int $row_id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvps/' . $row_id . '/promote' );
		$request->set_url_params( array( 'id' => (string) $row_id ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The organizer's verb: a DESIGNATED waitlist row flips to going while
	 * a seat is free, bypassing FIFO order (the whole point of manual
	 * promotion) — an earlier waitlist row stays waiting.
	 *
	 * @return void
	 */
	public function test_promote_flips_designated_row_bypassing_fifo(): void {
		$event_id = self::make_event( '2' );
		self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 1 ); // FIFO-first row, NOT chosen.
		$chosen = self::seed( $event_id, 'waitlist', 1 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = self::post_promote( (int) $chosen[0] );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertTrue( $data['promoted'] );
		$this->assertSame( (int) $chosen[0], (int) $data['rsvp']['id'] );
		$this->assertSame( 'going', $data['rsvp']['status'] );

		$repo = Plugin::instance()->repository();

		$persisted = $repo->get( (int) $chosen[0] );

		$this->assertIsArray( $persisted );
		$this->assertSame( 'going', $persisted['status'] );

		// The action fired once for the chosen row only.
		$this->assertCount( 1, self::$captured_actions );
		$this->assertSame( (int) $chosen[0], (int) ( self::$captured_actions[0][0]['id'] ?? 0 ) );
		$this->assertSame( $event_id, self::$captured_actions[0][1] );
	}

	/**
	 * Capacity guard: at capacity the flip is blocked and reported as
	 * eh_capacity_full 409; the row stays waitlisted; no action fires.
	 *
	 * @return void
	 */
	public function test_promote_blocked_when_event_full(): void {
		$event_id = self::make_event( '1' );
		self::seed( $event_id, 'going', 1 );
		$waitlist = self::seed( $event_id, 'waitlist', 1 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = self::post_promote( (int) $waitlist[0] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'eh_capacity_full', $response->get_data()['code'] );

		$persisted = Plugin::instance()->repository()->get( (int) $waitlist[0] );

		$this->assertIsArray( $persisted );
		$this->assertSame( 'waitlist', $persisted['status'] );
		$this->assertSame( array(), self::$captured_actions );
	}

	/**
	 * Seat math: with capacity 2 and one going row, one free seat remains
	 * — the first waiter flips; the next is guard-blocked. The shared
	 * seats-before-flip predicate counts going SEATS (SUM(guests)), not
	 * rows, so a waitlist row's seats only count once flipped.
	 *
	 * @return void
	 */
	public function test_promote_row_counts_going_seats_not_waitlist_rows(): void {
		$event_id = self::make_event( '2' );
		self::seed( $event_id, 'going', 1 ); // 1 seat taken.
		$waitlist = self::seed( $event_id, 'waitlist', 2 ); // Two 1-guest waiters.

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// 1 free seat remains: the flip goes through.
		$this->assertSame( 200, self::post_promote( (int) $waitlist[0] )->get_status() );

		// Now at capacity: the next waiter is guard-blocked and still waits.
		$response = self::post_promote( (int) $waitlist[1] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'eh_capacity_full', $response->get_data()['code'] );

		$this->assertSame(
			array(
				'going'     => 2,
				'waitlist'  => 1,
				'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * Error matrix: unknown row 404; going row 409 eh_not_waitlist;
	 * cancelled event 409 eh_event_cancelled; subscriber 403; anon 403.
	 *
	 * @return void
	 */
	public function test_promote_error_matrix(): void {
		$event_id = self::make_event( '2' );
		$going    = self::seed( $event_id, 'going', 1 ); // Capacity 2: both fit.
		$waitlist = self::seed( $event_id, 'waitlist', 1 );

		// Unknown row: 404.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$missing = self::post_promote( 999999 );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'eh_not_found', $missing->get_data()['code'] );

		// Going row: 409 eh_not_waitlist.
		$resp = self::post_promote( (int) $going[0] );

		$this->assertSame( 409, $resp->get_status() );
		$this->assertSame( 'eh_not_waitlist', $resp->get_data()['code'] );

		// Cancelled event: 409 eh_event_cancelled.
		update_post_meta( $event_id, Event_Status::META, 'cancelled' );
		$resp2 = self::post_promote( (int) $waitlist[0] );

		$this->assertSame( 409, $resp2->get_status() );
		$this->assertSame( 'eh_event_cancelled', $resp2->get_data()['code'] );

		// Clean up so fixture state doesn't leak into later asserts.
		update_post_meta( $event_id, Event_Status::META, '' );

		// Subscriber: 403; anonymous: 403.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, self::post_promote( (int) $waitlist[0] )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 403, self::post_promote( (int) $waitlist[0] )->get_status() );
	}

	/**
	 * Postponed events still promote (parity with create(): postponed
	 * stays open, cancelled does not) — the party carries to the new date.
	 *
	 * @return void
	 */
	public function test_postponed_event_still_promotes(): void {
		$event_id = self::make_event( '2' );
		self::seed( $event_id, 'going', 1 );
		$waitlist = self::seed( $event_id, 'waitlist', 1 );

		update_post_meta( $event_id, Event_Status::META, 'postponed' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 200, self::post_promote( (int) $waitlist[0] )->get_status() );
		$this->assertCount( 1, self::$captured_actions );
	}

	/**
	 * The 5A.2 promotion notice rides the manual path: with notifications
	 * on and an accepting mail transport, promoting sends the "You are in"
	 * mail to the promoted attendee (same event_hub_rsvp_promoted wiring).
	 *
	 * @return void
	 */
	public function test_manual_promote_sends_promotion_notice(): void {
		$event_id = self::make_event( '2' );
		self::seed( $event_id, 'going', 1 );

		// A known-email waitlist row (seed() randomizes addresses; this
		// test asserts the recipient).
		$created = Plugin::instance()->repository()->create( 'Wm Promoted', 'wm-promoted@example.com', 'waitlist', $event_id );

		if ( ! is_array( $created ) ) {
			self::fail( 'RSVP create failed.' );
		}

		update_option( Reminders::OPTION, true );
		update_option( Notifier::OPTION, true );

		$emails = array();

		add_filter(
			'pre_wp_mail',
			static function ( $pre, array $atts ) use ( &$emails ) {
				$emails[] = $atts;

				return true;
			},
			10,
			2
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 200, self::post_promote( (int) $created['rsvp']['id'] )->get_status() );

		$this->assertCount( 1, $emails, 'Exactly the promotion notice goes out.' );
		$this->assertSame(
			sprintf( 'You are in: %s', 'Waitlist Mgmt Event' ),
			$emails[0]['subject']
		);
		// The recipient is the promoted attendee, not the organizer.
		$this->assertSame( 'wm-promoted@example.com', (string) ( $emails[0]['to'] ?? '' ) );
	}

	/**
	 * The manual flip must respect a DB failure the same way the automatic
	 * path does: eh_db_error, row untouched, no action. Reuses the
	 * auto-path test's `query`-filter injection on the shared flip.
	 *
	 * @return void
	 */
	public function test_failing_flip_returns_db_error(): void {
		global $wpdb;

		$event_id = self::make_event( '2' );
		self::seed( $event_id, 'going', 1 );
		$waitlist = self::seed( $event_id, 'waitlist', 1 );

		$failing = static function ( string $query ) use ( $wpdb ) {
			if ( 0 === stripos( ltrim( $query ), 'UPDATE ' ) && false !== stripos( $query, "SET status = 'going'" ) ) {
				return 'SELECT * FROM ' . $wpdb->prefix . 'eh_rsvps_missing_table_abc';
			}

			return $query;
		};

		add_filter( 'query', $failing );

		$result = Plugin::instance()->repository()->promote( (int) $waitlist[0] );

		remove_filter( 'query', $failing );

		$this->assertWPError( $result );
		$this->assertSame( 'eh_db_error', $result->get_error_code() );

		$persisted = Plugin::instance()->repository()->get( (int) $waitlist[0] );

		$this->assertIsArray( $persisted );
		$this->assertSame( 'waitlist', $persisted['status'] );
		$this->assertSame( array(), self::$captured_actions );
	}

	/**
	 * Fixture hygiene: rows die with the test (transactions rollback);
	 * options restored explicit for safety — no PUBLIC facade needed.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		update_option( Reminders::OPTION, false );
		update_option( Notifier::OPTION, false );
		remove_all_filters( 'pre_wp_mail' );

		parent::tearDown();
	}
}
