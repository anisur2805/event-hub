<?php
/**
 * Phase 0 bug-fix regressions: ICS past-event exclusion (BUG 3), capacity
 * parse contract (BUG 5), clamped public capacity labels (BUG 6/7).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP + DB) for the Phase 0 fixes.
 */
class Phase0_Fixes_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with start/end/capacity meta.
	 *
	 * @param string                    $start    Start datetime (site format).
	 * @param string                    $end      End datetime ('' = none).
	 * @param string                    $capacity Capacity meta ('' = unlimited).
	 * @param array{going?:int,waitlist?:int} $counts RSVP counts to seed.
	 * @return int Post ID.
	 */
	private static function make_event( string $start, string $end = '', string $capacity = '', array $counts = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'eh_start', $start );

		if ( '' !== $end ) {
			update_post_meta( $post_id, 'eh_end', $end );
		}

		update_post_meta( $post_id, 'eh_capacity', $capacity );

		$going    = $counts['going'] ?? 0;
		$waitlist = $counts['waitlist'] ?? 0;

		if ( $going > 0 || $waitlist > 0 ) {
			self::seed_rsvps( $post_id, $going, $waitlist );
		}

		return $post_id;
	}

	/**
	 * Inserts going/waitlist RSVP rows through the repository.
	 *
	 * @param int $post_id  Event post ID.
	 * @param int $going    Going rows.
	 * @param int $waitlist Waitlist rows.
	 * @return void
	 */
	private static function seed_rsvps( int $post_id, int $going, int $waitlist ): void {
		$repo = Plugin::instance()->repository();

		for ( $i = 0; $i < $going; $i++ ) {
			$repo->create( sprintf( 'Going %d', $i ), sprintf( 'going%d@example.com', $i ), 'going', $post_id );
		}

		for ( $i = 0; $i < $waitlist; $i++ ) {
			$repo->create( sprintf( 'Wait %d', $i ), sprintf( 'wait%d@example.com', $i ), 'waitlist', $post_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * BUG 3 — ICS excludes events whose end is in the past.
	 * ------------------------------------------------------------------- */

	/**
	 * build() drops ended events, keeps ongoing and upcoming ones.
	 */
	public function test_ics_build_excludes_ended_events(): void {
		$past     = self::make_event( '2020-01-01 09:00:00', '2020-01-01 11:00:00' );
		$ongoing  = self::make_event(
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
		);
		$upcoming = self::make_event( '2099-05-01 18:00:00', '2099-05-01 20:00:00' );

		$ics = ( new Ics_Exporter() )->build( array( $past, $ongoing, $upcoming ) );

		$this->assertStringNotContainsString( 'Dummy', $ics );
		$this->assertSame( 2, substr_count( $ics, 'BEGIN:VEVENT' ), 'Ended event must be excluded, ongoing + upcoming included.' );
	}

	/**
	 * An event with no parseable end never counts as ended (no-end
	 * contract: indeterminate stays included).
	 */
	public function test_ics_keeps_events_without_end(): void {
		$event = self::make_event( '2099-05-01 18:00:00', '' );

		$this->assertFalse( Ics_Exporter::has_ended( '' ) );
		$this->assertStringContainsString( 'BEGIN:VEVENT', ( new Ics_Exporter() )->build( array( $event ) ) );
	}

	/* ---------------------------------------------------------------------
	 * BUG 5 — capacity parse contract.
	 * ------------------------------------------------------------------- */

	/**
	 * parse_capacity(): digit strings parse, empty = unlimited, junk = null
	 * (treated as unlimited at runtime, warned about in the admin list).
	 */
	public function test_parse_capacity_contract(): void {
		$this->assertSame( 40, Render_Blocks::parse_capacity( '40' ) );
		$this->assertSame( 0, Render_Blocks::parse_capacity( '0' ) );
		$this->assertNull( Render_Blocks::parse_capacity( '' ) );
		$this->assertNull( Render_Blocks::parse_capacity( '  ' ) );
		$this->assertNull( Render_Blocks::parse_capacity( '12abc' ) );
		$this->assertNull( Render_Blocks::parse_capacity( '-5' ) );
		$this->assertNull( Render_Blocks::parse_capacity( '4.5' ) );
	}

	/* ---------------------------------------------------------------------
	 * BUG 6/7 — public capacity wording clamps to capacity.
	 * ------------------------------------------------------------------- */

	/**
	 * Over-capacity legacy state (capacity cut after publish): the public
	 * label clamps to "N of N spots filled" instead of "N+ of N".
	 */
	public function test_capacity_label_clamps_when_going_exceeds_capacity(): void {
		$event_id = self::make_event( '2099-05-01 18:00:00', '2099-05-01 20:00:00', '4' );

		// Simulate legacy over-capacity state directly (the atomic insert
		// prevents new over-capacity rows; pre-reduction rows persist).
		global $wpdb;
		$table = $wpdb->prefix . 'eh_rsvps';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (event_id, name, email, status, created_at) VALUES (%d, %s, %s, 'going', %s), (%d, %s, %s, 'going', %s), (%d, %s, %s, 'going', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from prefix.
				$event_id, 'A', 'a@example.com', current_time( 'mysql' ),
				$event_id, 'B', 'b@example.com', current_time( 'mysql' ),
				$event_id, 'C', 'c@example.com', current_time( 'mysql' )
			)
		);

		$summary = Render_Blocks::summary( $event_id );
		$this->assertSame( 3, $summary['counts']['going'] );

		// Now cut capacity below the stored going count: 3 going, capacity 2.
		update_post_meta( $event_id, 'eh_capacity', '2' );

		$method = new \ReflectionMethod( Render_Blocks::class, 'capacity_label' );
		$method->setAccessible( true );
		$label = (string) $method->invoke( null, Render_Blocks::summary( $event_id ) );

		// Label clamps going to capacity (3 stored, capacity 2 → "2 of 2").
		$this->assertSame( '2 of 2 spots filled', $label );
		$this->assertStringContainsString( 'aria-valuetext="2 of 2 spots filled"', (string) Render_Blocks::render_card( Render_Blocks::summary( $event_id ) ) );
	}

	/**
	 * Admin Capacity column distinguishes true unlimited (empty meta) from
	 * legacy junk (warned, still unlimited at runtime).
	 */
	public function test_admin_capacity_column_warns_on_invalid_meta(): void {
		$event_id = self::make_event( '2099-05-01 18:00:00', '2099-05-01 20:00:00', '' );

		$columns = new Admin_Columns();

		ob_start();
		$columns->column_content( 'eh_capacity', $event_id );
		$this->assertStringContainsString( 'Unlimited', (string) ob_get_clean() );

		update_post_meta( $event_id, 'eh_capacity', 'somewhere-between' );

		ob_start();
		$columns->column_content( 'eh_capacity', $event_id );
		$cell = (string) ob_get_clean();

		$this->assertStringContainsString( 'Invalid', $cell );
		$this->assertStringContainsString( 'somewhere-between', $cell );
	}

	/**
	 * Full event: the waitlist button is accompanied by the name/email
	 * inputs (regression: inputs rendered only when rsvpOpen, so "Join
	 * waitlist" had nothing to type into — user-reported).
	 */
	public function test_waitlist_button_renders_name_email_inputs(): void {
		$event_id = self::make_event(
			'2099-05-01 18:00:00',
			'2099-05-01 20:00:00',
			'2',
			array( 'going' => 2, 'waitlist' => 1 )
		);

		$out = Render_Blocks::render_rsvp_button( $event_id );

		$this->assertStringContainsString( 'Join waitlist', $out );
		$this->assertStringContainsString( 'name="event_hub_name"', $out );
		$this->assertStringContainsString( 'name="event_hub_email"', $out );
	}

	/**
	 * Past events render inert markup: closed notice + count line (when the
	 * event drew attendees), but NO interactive region, form or feedback slot
	 * (the old markup painted a stray outlined box on past cards —
	 * user-reported).
	 */
	public function test_past_event_rsvp_renders_inert_markup(): void {
		$attended = self::make_event( '2020-05-01 18:00:00', '2020-05-01 20:00:00', '40' );

		// Rsvp_Repository::create() rightly refuses ended events
		// (eh_event_passed 409 — make_event()'s counts seeding is declined),
		// so simulate legacy attendee rows directly, as the BUG 6/7 test does.
		global $wpdb;
		$table = $wpdb->prefix . 'eh_rsvps';
		foreach (
			array(
				array( 'A', 'a-att@example.com', 'going' ),
				array( 'B', 'b-att@example.com', 'going' ),
				array( 'C', 'c-att@example.com', 'waitlist' ),
			) as $row
		) {
			$wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- test-only seed into plugin table.
				'event_id'   => $attended,
				'name'       => $row[0],
				'email'      => $row[1],
				'status'     => $row[2],
				'created_at' => current_time( 'mysql' ),
			) );
		}

		$out = Render_Blocks::render_rsvp_button( $attended );

		$this->assertStringContainsString( 'This event has already taken place.', $out );
		$this->assertStringContainsString( '2 going · 1 waitlisted', $out );
		$this->assertStringNotContainsString( 'data-wp-interactive', $out );
		$this->assertStringNotContainsString( 'event_hub_name', $out );
		$this->assertStringNotContainsString( 'event-hub-rsvp__feedback', $out );

		$empty = self::make_event( '2020-05-01 18:00:00', '2020-05-01 20:00:00', '' );

		$out2 = Render_Blocks::render_rsvp_button( $empty );

		$this->assertStringContainsString( 'This event has already taken place.', $out2 );
		$this->assertStringNotContainsString( 'event-hub-rsvp__count', $out2 );
	}
}
