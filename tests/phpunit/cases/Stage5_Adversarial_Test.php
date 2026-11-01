<?php
/**
 * Stage-5 adversarial probes (run with EH_STAGE5=1): attack the promotion
 * path — races, spoofed payloads, cross-event theft, trash interactions,
 * invariant violations. Reported findings go to the user before fixes.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Red-team suite for delete_and_promote().
 */
class Stage5_Adversarial_Test extends WP_UnitTestCase {

	/**
	 * Promoted-row captures.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public static array $captured = array();

	/**
	 * Creates a published event with meta.
	 *
	 * @param string $capacity Capacity meta.
	 * @return int Post ID.
	 */
	private static function make_event( string $capacity = '2' ): int {
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
	 * Seeds via the public create path.
	 *
	 * @param int    $event_id Event.
	 * @param string $status   Status.
	 * @param int    $count    How many.
	 * @return array<int,int> Row IDs.
	 */
	private static function seed( int $event_id, string $status, int $count ): array {
		$repo = Plugin::instance()->repository();
		$ids  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $repo->create(
				sprintf( 'Atk %s %d', $status, $i ),
				sprintf( 'atk-%s-%d@example.com', $status, $i ),
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
	 * Attempts a direct status update on a row (simulating tampering paths).
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		self::$captured = array();

		add_action(
			'event_hub_rsvp_promoted',
			static function ( $promoted, $event_id ): void {
				self::$captured[] = array( $promoted, $event_id );
			},
			10,
			2
		);
	}

	/**
	 * ATTACK 1: delete a going RSVP on event A while event B waits — must
	 * never promote a row from the wrong event (cross-event theft).
	 */
	public function test_no_cross_event_promotion(): void {
		$a = self::make_event( '1' );
		$b = self::make_event( '1' );

		$a_going   = self::seed( $a, 'going', 1 );
		$b_wait    = self::seed( $b, 'waitlist', 2 );
		$a_waiting = self::seed( $a, 'waitlist', 1 );

		Plugin::instance()->repository()->delete_and_promote( (int) $a_going[0] );

		$this->assertCount( 1, self::$captured );
		$this->assertSame( (int) $a_waiting[0], (int) self::$captured[0][0]['id'], 'Must promote event A waitlist head, never B.' );
		$this->assertSame( $a, self::$captured[0][1] );

		// B untouched (never seeded a going row: its correct state is 0 going).
		$this->assertSame(
			array(
				'going'    => 0,
				'waitlist' => 2,
			),
			Plugin::instance()->repository()->counts( $b )
		);
		unset( $b_wait );
	}

	/**
	 * ATTACK 2: non-integer / negative / absurd ID attack through the REST
	 * layer (path regex already constrains, but sanitizer layer double-check).
	 */
	public function test_rest_absurd_ids(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( '0', '99999999999999999999', '-1' ) as $bad ) {
			$request = new \WP_REST_Request( 'DELETE', '/event-hub/v1/rsvps/' . $bad );
			$request->set_url_params( array( 'id' => $bad ) );
			$response = rest_get_server()->dispatch( $request );

			// Absent-ID contract, never a 500, never a promotion.
			$this->assertSame( 404, $response->get_status(), "ID {$bad} must 404." );
		}

		$this->assertSame( array(), self::$captured );
	}

	/**
	 * ATTACK 3: unauthorized delete attempts — subscriber, editor without
	 * cap on multisite terms is fine, but anonymous must be rejected and a
	 * lucky ID guessed must still not promote.
	 */
	public function test_anonymous_delete_blocked(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 2 );

		wp_set_current_user( 0 );

		$request = new \WP_REST_Request( 'DELETE', '/event-hub/v1/rsvps/' . $going[0] );
		$request->set_url_params( array( 'id' => (string) $going[0] ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), 'Anonymous delete must be forbidden.' );
		$this->assertSame( array(), self::$captured, 'No promotion on blocked delete.' );

		// Row still present.
		$this->assertSame(
			array(
				'going'    => 1,
				'waitlist' => 2,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * ATTACK 4: concurrent double-delete of the SAME id — second call must
	 * be null/null (or 404 upstream), never a double promotion.
	 */
	public function test_double_delete_same_id(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 3 );

		$repo = Plugin::instance()->repository();

		$first  = $repo->delete_and_promote( (int) $going[0] );
		$second = $repo->delete_and_promote( (int) $going[0] );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNull( $second['deleted'], 'Second delete of the same ID must be absent.' );
		$this->assertNull( $second['promoted'] );
		$this->assertCount( 1, self::$captured );
	}

	/**
	 * ATTACK 5: capacity meta tampering shapes — negative, zero, gigantic —
	 * must never let promotions overflow real seats. Going rows are inserted
	 * directly here (the legacy-row attack shape: rows created before the
	 * capacity meta was tampered with).
	 *
	 * @global \wpdb $wpdb
	 */
	public function test_hostile_capacity_shapes(): void {
		global $wpdb;

		$repo = Plugin::instance()->repository();

		// Zero capacity: a legacy going row already violates it; deleting it
		// must NOT promote (going count 1 is not < 0).
		$e1 = self::make_event( '0' );

		$wpdb->insert(
			$wpdb->prefix . 'eh_rsvps',
			array(
				'event_id' => $e1,
				'name'     => 'Legacy Going',
				'email'    => 'legacy-zero@example.com',
				'status'   => 'going',
			)
		);
		$g1 = (int) $wpdb->insert_id;
		self::seed( $e1, 'waitlist', 1 );

		$result1 = $repo->delete_and_promote( $g1 );
		$this->assertIsArray( $result1 );
		$this->assertNull( $result1['promoted'], 'capacity 0: no promotion possible.' );
		$this->assertSame(
			array(
				'going'    => 0,
				'waitlist' => 1,
			),
			$repo->counts( $e1 )
		);

		self::$captured = array();

		// Negative capacity string — regex '^[0-9]+$' rejects it, so the
		// documented contract applies: unparsable => unlimited (999999999).
		$e2 = self::make_event( '-5' );
		$g2 = self::seed( $e2, 'going', 1 );
		self::seed( $e2, 'waitlist', 1 );
		$repo->delete_and_promote( (int) $g2[0] );
		$this->assertCount( 1, self::$captured, 'Unparsable capacity => unlimited by contract: promotion proceeds.' );

		self::$captured = array();

		// A 23-digit capacity string must not fatal the promotion path
		// (CAST to UNSIGNED saturates); promotion proceeds under unlimited.
		$e3 = self::make_event( '99999999999999999999999' );
		$g3 = self::seed( $e3, 'going', 1 );
		self::seed( $e3, 'waitlist', 1 );
		$result3 = $repo->delete_and_promote( (int) $g3[0] );

		$this->assertIsArray( $result3 );
		$this->assertNotNull( $result3['deleted'] );
		$this->assertSame(
			array(
				'going'    => 1,
				'waitlist' => 0,
			),
			$repo->counts( $e3 ),
			'No fatal on absurd capacity; states stay exact.'
		);
	}

	/**
	 * ATTACK 6: event trashed AFTER RSVPs exist — delete_and_promote must
	 * still work (admin may clean up RSVPs after trashing an event); the
	 * method itself has no event-status gate (only create() does).
	 */
	public function test_promotion_on_trashed_event(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 1 );

		wp_trash_post( $event_id );

		$result = Plugin::instance()->repository()->delete_and_promote( (int) $going[0] );

		// Accepted behavior here: promotion proceeds (admin surface); assert
		// no fatal + exact counts either way.
		$this->assertIsArray( $result );
		$this->assertNotNull( $result['deleted'] );
		$this->assertSame(
			$result['promoted'] ? array( 'going' => 1, 'waitlist' => 0 ) : array( 'going' => 0, 'waitlist' => 1 ),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * ATTACK 7: promotion action listener throws — must not corrupt state
	 * (lock already released; throw happens after commit; WP reactors the
	 * exception but rows are consistent).
	 */
	public function test_listener_exception_leaves_state_consistent(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 1 );

		add_action(
			'event_hub_rsvp_promoted',
			static function (): void {
				throw new \RuntimeException( 'listener bomb' );
			},
			5
		);

		try {
			Plugin::instance()->repository()->delete_and_promote( (int) $going[0] );
			$this->fail( 'PHP exceptions propagate (by design; WP converts to 500 at REST boundary).' );
		} catch ( \RuntimeException $e ) {
			$this->addToAssertionCount( 1 );
		}

		// State must be committed despite the listener bomb.
		$this->assertSame(
			array( 'going' => 1, 'waitlist' => 0 ),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}
}
