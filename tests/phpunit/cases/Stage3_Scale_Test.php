<?php
/**
 * Stage-3 scale tests (run with EH_STAGE3=1; skipped otherwise so the
 * default suite stays fast): hundreds of dummy attendees, promotion
 * invariants + FIFO exactness + timing under volume.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Bulk-seeding and promotion-under-volume checks.
 */
class Stage3_Scale_Test extends WP_UnitTestCase {

	/**
	 * Row count for the bulk-seeded event.
	 *
	 * @var int
	 */
	private const DUMMY_COUNT = 500;

	/**
	 * Skips unless explicitly requested (EH_STAGE3=1).
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( '1' !== (string) getenv( 'EH_STAGE3' ) ) {
			$this->markTestSkipped( 'Scale stage: set EH_STAGE3=1 to run.' );
		}
	}

	/**
	 * Creates a published event with meta.
	 *
	 * @param string $capacity Capacity meta.
	 * @return int Post ID.
	 */
	private static function make_event( string $capacity ): int {
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
	 * Bulk-seeds RSVPs through the repository (dedup-safe emails).
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $status   'going' or 'waitlist'.
	 * @param int    $count    How many rows.
	 * @return array<int,int> Row IDs in insertion order.
	 */
	private static function bulk_seed( int $event_id, string $status, int $count ): array {
		$repo = Plugin::instance()->repository();
		$ids  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $repo->create(
				sprintf( 'Dummy %s %d', $status, $i ),
				sprintf( 'dummy-%s-%d@example.com', $status, $i ),
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
	 * 500 attendees: 450 going / 50 waitlisted on a full event; delete all
	 * 450 going rows one by one and demand that every promotion is the exact
	 * FIFO successor, counts stay exact, and no promotion ever overshoots
	 * capacity mid-drain.
	 */
	public function test_full_drain_promotes_exact_fifo_succession(): void {
		$capacity = 450;
		$event_id = self::make_event( (string) $capacity );

		$t0 = microtime( true );
		$going    = self::bulk_seed( $event_id, 'going', $capacity );
		$waiting  = self::bulk_seed( $event_id, 'waitlist', 50 );
		$seed_secs = microtime( true ) - $t0;

		$this->assertCount( $capacity, $going, 'All going seeds must land.' );
		$this->assertCount( 50, $waiting, 'All waitlist seeds must land.' );

		$repo = Plugin::instance()->repository();

		$promise_order = array_merge( $waiting, array() );

		$t1      = microtime( true );
		$deleted = 0;
		$promos  = 0;

		foreach ( $going as $gid ) {
			$result = $repo->delete_and_promote( $gid );

			$this->assertIsArray( $result );
			$this->assertNotNull( $result['deleted'] );
			++$deleted;

			// Exactly 50 promotions should ever fire (one per freed seat
			// while someone waits); after the waitlist is drained, further
			// deletes must NOT promote.
			if ( null !== $result['promoted'] ) {
				++$promos;
				$expected = array_shift( $promise_order );
				$this->assertSame( $expected, (int) $result['promoted']['id'], 'Promotion must follow exact FIFO order.' );
			}

			// Per-step invariants: going never exceeds capacity, and every
			// delete removes exactly one row net (promotion only moves a
			// row between statuses, it never adds one).
			$counts = $repo->counts( $event_id );

			$this->assertLessThanOrEqual( $capacity, $counts['going'], 'Going must never exceed capacity mid-drain.' );
			$this->assertSame(
				$capacity + 50 - $deleted,
				$counts['going'] + $counts['waitlist'],
				'Total rows must fall by exactly one per delete.'
			);
		}

		$drain_secs = microtime( true ) - $t1;

		$this->assertSame( 50, $promos, 'Exactly one promotion per freed seat until waitlist is empty.' );
		$this->assertSame( array(), $promise_order, 'Every waitlisted row must have been promoted, in order.' );

		// End state: all 450 original going rows are gone; the 50 promoted
		// waitlisted attendees hold the only going seats left (each of the
		// first 50 deletes handed its freed seat to the FIFO head; the other
		// 400 seats emptied with nobody waiting).
		$this->assertSame(
			array(
				'going'     => 50,
				'waitlist'  => 0,
				'checkedIn' => 0,
			),
			$repo->counts( $event_id ),
			'End state: only the 50 promoted rows remain going.'
		);

		// 1000 creates + 450 delete/promote cycles should stay well under a
		// second each on test hardware; a runaway lock timeout or O(n) scan
		// per delete shows up here.
		$this->assertLessThan( 30.0, $seed_secs, 'Bulk seeding 1000 rows took too long.' );
		$this->assertLessThan( 30.0, $drain_secs, '450 delete+promote cycles took too long.' );

		fwrite(
			STDOUT,
			sprintf(
				"\n[stage3] seed 1000 rows: %.2fs; drain 450 deletes with 50 promotions: %.2fs\n",
				$seed_secs,
				$drain_secs
			)
		);
	}

	/**
	 * Unique-key integrity at volume: 200 duplicate create attempts against a
	 * seeded event all fail cleanly (eh_duplicate) and leave counts exact.
	 */
	public function test_bulk_duplicate_attempts_fail_cleanly(): void {
		$event_id = self::make_event( '1000' );

		$repo = Plugin::instance()->repository();
		$this->assertIsArray( $repo->create( 'Anchor', 'anchor@example.com', 'going', $event_id ) );

		$dups = 0;

		for ( $i = 0; $i < 200; $i++ ) {
			$result = $repo->create( 'Anchor', 'anchor@example.com', 'going', $event_id );

			if ( is_wp_error( $result ) && 'eh_duplicate' === $result->get_error_code() ) {
				++$dups;
			}
		}

		$this->assertSame( 200, $dups, 'Every duplicate attempt must map to eh_duplicate.' );
		$this->assertSame(
			array(
				'going'     => 1,
				'waitlist'  => 0,
				'checkedIn' => 0,
			),
			$repo->counts( $event_id )
		);
	}
}
