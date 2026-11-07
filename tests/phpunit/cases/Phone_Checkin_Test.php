<?php
/**
 * Phone check-in desk tests (ROADMAP §5B, DECISIONS #67).
 *
 * Covers POST /event-hub/v1/events/{id}/check-in: digit-for-digit matching
 * against going rows, the 7-digit identifiability floor, 0/1/many match
 * outcomes (404 / 200 / 409), the edit_others_posts gate, waitlist rows
 * never matching, and idempotent re-stamping.
 *
 * @package event-hub
 */

use EventHub\Rsvp_Repository;

/**
 * Phone check-in REST + repository test case.
 */
class Phone_Checkin_Test extends WP_UnitTestCase {
	/**
	 * Admin user (edit_others_posts).
	 *
	 * @var WP_User
	 */
	private WP_User $admin;

	/**
	 * Subscriber (no caps).
	 *
	 * @var WP_User
	 */
	private WP_User $subscriber;

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Set up a published event, an admin and a subscriber.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin = self::factory()->user->create_and_get(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->admin->ID );

		$this->subscriber = self::factory()->user->create_and_get(
			array( 'role' => 'subscriber' )
		);

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Phone Checkin Event',
			)
		);
	}

	/**
	 * POST the check-in route as the current user.
	 *
	 * @param string $phone Phone number to submit.
	 * @return WP_REST_Response|WP_Error
	 */
	private function post( string $phone ) {
		$request = new WP_REST_Request( 'POST', '/event-hub/v1/events/' . $this->event_id . '/check-in' );
		$request->set_param( 'phone', $phone );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Digit-for-digit matching: +1 (555) 010-1234 must match the stored
	 * 5550101234 — spaces, parens, dashes and the leading + all drop.
	 *
	 * @return void
	 */
	public function test_digit_normalization_matches_formatted_input(): void {
		new Rsvp_Repository()->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '5550101234' );

		$response = $this->post( '+1 (555) 010-1234' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['checkedIn'] );
		$this->assertSame( 'Ada', $data['rsvp']['name'] );
		$this->assertSame( 'ada@example.com', $data['rsvp']['email'] );
		$this->assertNotEmpty( $data['rsvp']['checkedInAt'] );
	}

	/**
	 * Formatting variance on the STORED side too: stored +1 (555) 010-1234,
	 * typed 5550101234.
	 *
	 * @return void
	 */
	public function test_digit_normalization_matches_formatted_stored_value(): void {
		new Rsvp_Repository()->create( 'Grace', 'grace@example.com', 'going', $this->event_id, 1, '+1 (555) 010-1234' );

		$response = $this->post( '5550101234' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Grace', $data['rsvp']['name'] );
	}

	/**
	 * Below the 7-digit floor nothing is identifiable: the route 404s even
	 * when a stored row shares those digits.
	 *
	 * @return void
	 */
	public function test_short_input_404s_without_searching(): void {
		$created = new Rsvp_Repository()->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '5550101234' );
		$ada_id  = is_array( $created ) ? (int) $created['rsvp']['id'] : 0;

		$response = $this->post( '555' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_phone_not_found', $response->get_data()['code'] );

		// The row must be untouched — no silent stamping off a fragment.
		$row = new Rsvp_Repository()->get( $ada_id );
		$this->assertNotNull( $row );
		$this->assertNull( $row['checked_in_at'] ?? null );
	}

	/**
	 * Floor is symmetric: a stored 1-digit fragment must never ride the tail
	 * of a full-length needle (every needle ending in that digit would
	 * otherwise match).
	 *
	 * @return void
	 */
	public function test_stored_short_fragment_never_rides_needle_tail(): void {
		new Rsvp_Repository()->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '4' );

		$response = $this->post( '5559991234' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_phone_not_found', $response->get_data()['code'] );
	}

	/**
	 * Country code on the INPUT side only: stored "555 010 1234", typed
	 * with the +1 prefix — suffix compare absorbs it.
	 *
	 * @return void
	 */
	public function test_country_code_prefix_on_input_matches(): void {
		new Rsvp_Repository()->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '555 010 1234' );

		$response = $this->post( '+1 555 010 1234' );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * No going attendee carries the number → 404 eh_phone_not_found.
	 *
	 * @return void
	 */
	public function test_unknown_phone_404s(): void {
		new Rsvp_Repository()->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '5550101234' );

		$response = $this->post( '5559991234' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_phone_not_found', $response->get_data()['code'] );
	}

	/**
	 * Waitlist rows never match — the desk checks in arriving GOING
	 * attendees, not the waitlist.
	 *
	 * @return void
	 */
	public function test_waitlist_rows_never_match(): void {
		$created = new Rsvp_Repository()->create( 'Waiter', 'waiter@example.com', 'waitlist', $this->event_id, 1, '5550101234' );
		$wait_id = is_array( $created ) ? (int) $created['rsvp']['id'] : 0;

		$response = $this->post( '5550101234' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_phone_not_found', $response->get_data()['code'] );

		$row = new Rsvp_Repository()->get( $wait_id );
		$this->assertNotNull( $row );
		$this->assertNull( $row['checked_in_at'] ?? null );
	}

	/**
	 * Shared number → 409 eh_phone_ambiguous with the match count; nobody is
	 * stamped (report, never guess).
	 *
	 * @return void
	 */
	public function test_ambiguous_phone_409s_without_stamping(): void {
		$repo = new Rsvp_Repository();
		$ada  = $repo->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '5550101234' );
		$bell = $repo->create( 'Bell', 'bell@example.com', 'going', $this->event_id, 1, '(555) 010 1234' );
		$cy   = $repo->create( 'Cy', 'cy@example.com', 'going', $this->event_id, 1, '555.010.1234' );

		$response = $this->post( '+1-555-010-1234' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'eh_phone_ambiguous', $response->get_data()['code'] );
		$this->assertSame( 3, $response->get_data()['data']['count'] );

		foreach ( array( $ada, $bell, $cy ) as $created ) {
			$row_id = is_array( $created ) ? (int) $created['rsvp']['id'] : 0;
			$row    = $repo->get( $row_id );
			$this->assertNotNull( $row );
			$this->assertNull( $row['checked_in_at'] ?? null, "Row {$row['name']} must not be stamped on ambiguity." );
		}
	}

	/**
	 * Success path stamps through the SAME set_checked_in() contract as the
	 * table button, and re-submitting is an idempotent no-op that still
	 * returns the row.
	 *
	 * @return void
	 */
	public function test_success_stamps_and_is_idempotent(): void {
		$repo = new Rsvp_Repository();
		$repo->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '5550101234' );

		$first  = $this->post( '555-010-1234' );
		$second = $this->post( '+15550101234' );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['checkedIn'] );
		$this->assertSame(
			$first->get_data()['rsvp']['checkedInAt'],
			$second->get_data()['rsvp']['checkedInAt']
		);
	}

	/**
	 * Match is scoped to the event: the same phone on another event's going
	 * row must not leak in.
	 *
	 * @return void
	 */
	public function test_match_scoped_to_event(): void {
		$other_event = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Other Event',
			)
		);
		new Rsvp_Repository()->create( 'Ada', 'ada@example.com', 'going', $other_event, 1, '5550101234' );

		$response = $this->post( '5550101234' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_phone_not_found', $response->get_data()['code'] );
	}

	/**
	 * Non-admins get rest_forbidden.
	 *
	 * @return void
	 */
	public function test_requires_admin_capability(): void {
		wp_set_current_user( $this->subscriber->ID );
		$response = $this->post( '5550101234' );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Logged-out visitors get 403 — same gate shape as the compose route
	 * (admin_permission has no separate 401 branch).
	 *
	 * @return void
	 */
	public function test_requires_authentication(): void {
		wp_set_current_user( 0 );
		$response = $this->post( '5550101234' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * Blank phone → 400 eh_missing_phone (route-level required catches the
	 * absent case; whitespace-only still reaches the callback).
	 *
	 * @return void
	 */
	public function test_blank_phone_400s(): void {
		$response = $this->post( '   ' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'eh_missing_phone', $response->get_data()['code'] );
	}

	/**
	 * Unknown event id → 404 eh_event_not_found (same as the email route).
	 *
	 * @return void
	 */
	public function test_unknown_event_404s(): void {
		$request = new WP_REST_Request( 'POST', '/event-hub/v1/events/999999/check-in' );
		$request->set_param( 'phone', '5550101234' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_event_not_found', $response->get_data()['code'] );
	}

	/**
	 * Wrong post type (a page id) → 404, mirroring the email route.
	 *
	 * @return void
	 */
	public function test_non_event_post_type_404s(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$request = new WP_REST_Request( 'POST', '/event-hub/v1/events/' . $page_id . '/check-in' );
		$request->set_param( 'phone', '5550101234' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_event_not_found', $response->get_data()['code'] );
	}

	/**
	 * A checked-in row remains findable: re-checking finds it again among the
	 * GOING rows (stamping does not exclude the row from future searches).
	 *
	 * @return void
	 */
	public function test_already_checked_in_row_still_found(): void {
		$repo = new Rsvp_Repository();
		$repo->create( 'Ada', 'ada@example.com', 'going', $this->event_id, 1, '5550101234' );
		$this->post( '5550101234' );

		$again = $this->post( '555-010-1234' );

		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 'Ada', $again->get_data()['rsvp']['name'] );
	}
}
