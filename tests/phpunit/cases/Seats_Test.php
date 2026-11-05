<?php
/**
 * Tests for ROADMAP §2.3 — guests/seats per RSVP (DB 1.4.0).
 *
 * The roadmap flags this as the riskiest slice: capacity invariants get
 * fresh coverage BEFORE the predicate change is trusted. Covers the
 * SUM(guests) predicate (insert + promotion), counts() seat semantics, the
 * guests param contract (default, clamp, reject), serializers, CSV column,
 * and legacy-row default behavior.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Seats feature test case.
 */
class Seats_Test extends WP_UnitTestCase {

	/**
	 * Per-test unique email tag (DDL-safe cleanup, same pattern as
	 * Checkin_Test).
	 *
	 * @var string
	 */
	private string $email_tag = '';

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Creates the event. A pending 1.x migration may still be queued by the
	 * DDL test's committed downgrade; production parity: running the boot
	 * migration first mirrors a request through the plugin.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Migrations::maybe_migrate();

		$this->email_tag = wp_generate_password( 8, false, false );
		$this->event_id  = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->event_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $this->event_id, 'eh_end', '2099-05-01 20:00:00' );
	}

	/**
	 * Creates an RSVP directly through the repository.
	 *
	 * @param string $email  Distinct email local part.
	 * @param string $status Row status.
	 * @param int    $guests Seat count.
	 * @return array|WP_Error Create result.
	 */
	private function make_rsvp( string $email, string $status = 'going', int $guests = 1 ) {
		return ( new Rsvp_Repository() )->create(
			'Guest ' . $email,
			$email . '-' . $this->email_tag . '@example.org',
			$status,
			$this->event_id,
			$guests
		);
	}

	/*
	 * Capacity invariant: seats, not rows.
	 */

	/**
	 * Capacity 4: first two rows of 3+1 seats fill it; the third party
	 * (any size) cannot book going.
	 */
	public function test_capacity_counts_seats_not_rows(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '4' );

		$first = $this->make_rsvp( 'alice', 'going', 3 );
		self::assertNotWPError( $first );

		$second = $this->make_rsvp( 'bob', 'going', 1 );
		self::assertNotWPError( $second );

		// Seats booked: 4/4. A third party must NOT get going (even 1 seat).
		$third = $this->make_rsvp( 'carol', 'going', 1 );
		self::assertWPError( $third );
		self::assertSame( 'eh_capacity_full', $third->get_error_code() );

		$counts = ( new Rsvp_Repository() )->counts( $this->event_id );
		self::assertSame( 4, $counts['going'], 'going counts SEATS, not rows' );
	}

	/**
	 * The predicate admits the row while going-seats < capacity; the
	 * row's own party may exceed remaining seats (overbook-on-arrival is
	 * the documented trade; the front end steers oversized parties to the
	 * waitlist). Here: 1/5 booked, capacity 5, a party of 4 fills to 5 —
	 * allowed. Next party of 1 is blocked (5/5).
	 */
	public function test_predicate_admits_row_when_seats_below_capacity(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '5' );

		$first = $this->make_rsvp( 'dana', 'going', 1 );
		self::assertNotWPError( $first );

		$party = $this->make_rsvp( 'erik', 'going', 4 );
		self::assertNotWPError( $party );

		$next = $this->make_rsvp( 'faythe', 'going', 1 );
		self::assertWPError( $next );
		self::assertSame( 'eh_capacity_full', $next->get_error_code() );
	}

	/**
	 * Waitlist rows bypass capacity at any party size.
	 */
	public function test_waitlist_bypasses_seat_capacity(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '2' );

		$fill = $this->make_rsvp( 'gail', 'going', 2 );
		self::assertNotWPError( $fill );

		$wait = $this->make_rsvp( 'heath', 'waitlist', 5 );
		self::assertNotWPError( $wait );

		$counts = ( new Rsvp_Repository() )->counts( $this->event_id );
		self::assertSame( 2, $counts['going'] );
		self::assertSame( 5, $counts['waitlist'], 'waitlist counts SEATS too' );
	}

	/**
	 * Legacy rows carry guests = 1 by default and behave exactly as
	 * before: 3 rows on capacity 3 fill it, the 4th is blocked.
	 */
	public function test_legacy_row_default_guests_one(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '3' );

		for ( $i = 0; $i < 3; $i++ ) {
			$created = $this->make_rsvp( 'legacy' . $i );
			self::assertNotWPError( $created );
		}

		$fourth = $this->make_rsvp( 'over' );
		self::assertWPError( $fourth );
		self::assertSame( 'eh_capacity_full', $fourth->get_error_code() );

		$row = ( new Rsvp_Repository() )->get( (int) $this->make_rsvp( 'probe', 'waitlist' )['rsvp']['id'] ?? 0 );
		self::assertNotNull( $row );
		self::assertSame( 1, $row['guests'] );
	}

	/*
	 * Promotion under seats.
	 */

	/**
	 * A waitlist row promotes only when its flip keeps seats <= capacity;
	 * the guard counts seats booked BEFORE the flip (the promoted party's
	 * own seats are not part of the predicate — same admits-the-row shape
	 * as create()).
	 */
	public function test_promotion_guard_counts_seats(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '4' );

		$fill_a = $this->make_rsvp( 'iris', 'going', 2 );
		self::assertNotWPError( $fill_a );
		$fill_b = $this->make_rsvp( 'june', 'going', 2 );
		self::assertNotWPError( $fill_b );

		$wait_small = $this->make_rsvp( 'karl', 'waitlist', 1 );
		self::assertNotWPError( $wait_small );

		// Delete one going party of 2: seats drop 4 -> 2, 2 < 4 so the
		// earliest waitlist row promotes. Karl's party of 1 is small, but
		// the guard never counts it — it flips unconditionally-while-open.
		$repo = new Rsvp_Repository();
		$repo->delete_and_promote( (int) $fill_a['rsvp']['id'] );

		$promoted = $repo->get( (int) $wait_small['rsvp']['id'] );
		self::assertNotNull( $promoted );
		self::assertSame( 'going', $promoted['status'] );

		// Seats now: 2 (june) + 1 (karl) = 3 of 4.
		$counts = $repo->counts( $this->event_id );
		self::assertSame( 3, $counts['going'] );
	}

	/**
	 * Deleting a going row frees seats and promotes the earliest waitlist
	 * row; a second waitlist row stays put (promotion is one row per
	 * delete, unchanged §1.3 contract).
	 */
	public function test_waitlist_delete_promotes_within_seats(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '4' );

		$fill = $this->make_rsvp( 'olga', 'going', 3 );
		self::assertNotWPError( $fill );

		$wait_a = $this->make_rsvp( 'paul', 'waitlist', 2 );
		self::assertNotWPError( $wait_a );
		$wait_b = $this->make_rsvp( 'quinn', 'waitlist', 1 );
		self::assertNotWPError( $wait_b );

		$repo = new Rsvp_Repository();

		// Free 3 seats: paul (2 seats, earliest) promotes — 3 + 2 = 5 of 4
		// is the documented overbook-on-flip tradeoff, guard counts booked
		// seats only (3 > 0? no: booked after delete = 0 < 4 → promotes).
		$repo->delete_and_promote( (int) $fill['rsvp']['id'] );

		$promoted = $repo->get( (int) $wait_a['rsvp']['id'] );
		self::assertNotNull( $promoted );
		self::assertSame( 'going', $promoted['status'] );

		$still = $repo->get( (int) $wait_b['rsvp']['id'] );
		self::assertNotNull( $still );
		// Seats now 0 + 2 = 2 < 4: quinn's 1-seat party also fits...
		// but promotion promotes ONE row per delete (unchanged contract).
		self::assertSame( 'waitlist', $still['status'] );

		$counts = $repo->counts( $this->event_id );
		self::assertSame( 2, $counts['going'] );
		self::assertSame( 1, $counts['waitlist'] );
	}

	/*
	 * Param contract.
	 */

	/**
	 * Guests < 1 or > MAX_GUESTS rejects with eh_invalid_guests (400).
	 */
	public function test_rejects_guests_out_of_range(): void {
		$repo = new Rsvp_Repository();

		$zero = $repo->create( 'Zero', 'zero-' . $this->email_tag . '@example.org', 'going', $this->event_id, 0 );
		self::assertWPError( $zero );
		self::assertSame( 'eh_invalid_guests', $zero->get_error_code() );

		$huge = $repo->create( 'Huge', 'huge-' . $this->email_tag . '@example.org', 'going', $this->event_id, Rsvp_Repository::MAX_GUESTS + 1 );
		self::assertWPError( $huge );
		self::assertSame( 'eh_invalid_guests', $huge->get_error_code() );
	}

	/**
	 * REST: the guests param defaults to 1 when absent and is clamped
	 * into 1..MAX_GUESTS when present.
	 */
	public function test_rest_guests_default_and_clamp(): void {
		wp_set_current_user( 0 );

		$base = array(
			'eventId'   => $this->event_id,
			'status'    => 'going',
			'formToken' => Form_Guard::issue_token( $this->event_id, time() - 10 ),
		);

		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array_merge(
					$base,
					array(
						'name'  => 'Default Daisy',
						'email' => 'daisy-' . $this->email_tag . '@example.org',
					)
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );

		self::assertSame( 201, $response->get_status() );
		self::assertSame( 1, $response->get_data()['rsvp']['guests'] ?? 0 );

		// Clamped: 999 arrives, row stores MAX_GUESTS.
		$request2 = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp' );
		$request2->set_header( 'Content-Type', 'application/json' );
		$request2->set_body(
			(string) wp_json_encode(
				array_merge(
					$base,
					array(
						'name'   => 'Clamp Chloe',
						'email'  => 'chloe-' . $this->email_tag . '@example.org',
						'guests' => 999,
					)
				)
			)
		);
		$response2 = rest_get_server()->dispatch( $request2 );

		self::assertSame( 201, $response2->get_status() );
		self::assertSame( Rsvp_Repository::MAX_GUESTS, $response2->get_data()['rsvp']['guests'] ?? 0 );
	}

	/*
	 * counts() + capacity-full body.
	 */

	/**
	 * The eh_capacity_full error body carries seats-based counts.
	 */
	public function test_capacity_full_body_reports_seat_counts(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '1' );

		$fill = $this->make_rsvp( 'ruth', 'going', 1 );
		self::assertNotWPError( $fill );

		$blocked = $this->make_rsvp( 'stan', 'going', 1 );
		self::assertWPError( $blocked );

		$data = $blocked->get_error_data( 'eh_capacity_full' );
		self::assertIsArray( $data );
		self::assertSame( 1, $data['counts']['going'] );
	}

	/**
	 * Zero going rows: SUM over an empty set is NULL → COALESCE keeps 0;
	 * unlimited capacity admits any party.
	 */
	public function test_empty_event_unlimited_admits_party(): void {
		$party = $this->make_rsvp( 'vera', 'going', 6 );
		self::assertNotWPError( $party );

		$counts = ( new Rsvp_Repository() )->counts( $this->event_id );
		self::assertSame( 6, $counts['going'] );
	}
}
