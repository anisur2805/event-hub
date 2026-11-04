<?php
/**
 * Waitlist auto-promotion tests (ROADMAP §1.3): guarded promotion on admin
 * delete, FIFO order, capacity guard, promotion action.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP + DB) for delete_and_promote().
 */
class Waitlist_Promotion_Test extends WP_UnitTestCase {

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
	 * Seeds rows through the public create() path.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $status   'going' or 'waitlist'.
	 * @param int    $count    How many rows.
	 * @return array<int,int> Created row IDs, insertion order.
	 */
	private static function seed( int $event_id, string $status, int $count ): array {
		$repo = Plugin::instance()->repository();
		$ids  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $repo->create(
				sprintf( '%1$s %2$d', ucfirst( $status ), $i ),
				sprintf( '%1$s%2$d@example.com', $status, $i ),
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
	 * Accept: deleting a going row on a full event promotes waitlist #1 and
	 * keeps counts exact.
	 */
	public function test_full_event_delete_promotes_earliest_waitlist(): void {
		$event_id = self::make_event( '2' );
		$going    = self::seed( $event_id, 'going', 2 );
		$waiting  = self::seed( $event_id, 'waitlist', 3 );

		$result = Plugin::instance()->repository()->delete_and_promote( $going[0] );

		$this->assertIsArray( $result );
		$this->assertSame( $going[0], (int) $result['deleted']['id'] );
		$this->assertNotNull( $result['promoted'], 'Waitlist #1 must be promoted.' );
		$this->assertSame( $waiting[0], (int) $result['promoted']['id'] );
		$this->assertSame( 'going', $result['promoted']['status'] );

		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 2,
			'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id ),
			'Promotion must refill the freed seat exactly.'
		);
	}

	/**
	 * The action fires once per promotion with the promoted row + event ID.
	 */
	public function test_promotion_action_fires_with_row_and_event(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		$waiting  = self::seed( $event_id, 'waitlist', 1 );

		Plugin::instance()->repository()->delete_and_promote( $going[0] );

		$this->assertCount( 1, self::$captured_actions );
		$this->assertSame( (int) $waiting[0], (int) ( self::$captured_actions[0][0]['id'] ?? 0 ) );
		$this->assertSame( $event_id, self::$captured_actions[0][1] );
	}

	/**
	 * No action and no promotion when the deleted row was waitlist.
	 */
	public function test_waitlist_delete_promotes_nothing(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		$waiting  = self::seed( $event_id, 'waitlist', 3 );

		$result = Plugin::instance()->repository()->delete_and_promote( $waiting[0] );

		$this->assertIsArray( $result );
		$this->assertNotNull( $result['deleted'] );
		$this->assertNull( $result['promoted'], 'No seat freed: nothing may be promoted.' );
		$this->assertSame( array(), self::$captured_actions );
		$this->assertSame(
			array(
				'going'    => 1,
				'waitlist' => 2,
			'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);

		// The remaining waitlist order is untouched: #2 is now the earliest.
		unset( $going );
	}

	/**
	 * FIFO order: the earliest waitlist row wins even when its numeric ID is
	 * higher (id only breaks created_at ties).
	 */
	public function test_promotion_follows_created_at_fifo(): void {
		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 2 );

		$repo = Plugin::instance()->repository();

		// Forced backdating: created_at is second-resolution in tests; push
		// the second waitlist row behind the first deterministically.
		global $wpdb;
		$rows = $repo->list( $event_id, 10, 0, 'waitlist' );
		$ids  = wp_list_pluck( $rows['rsvps'], 'id' );
		$wpdb->update(
			$wpdb->prefix . 'eh_rsvps',
			array( 'created_at' => '2000-01-01 00:00:00' ),
			array( 'id' => (int) $ids[1] )
		);

		$repo->delete_and_promote( (int) $going[0] );

		$this->assertCount( 1, self::$captured_actions );
		$this->assertSame( (int) $ids[1], (int) ( self::$captured_actions[0][0]['id'] ?? 0 ), 'Backdated row must win FIFO.' );
	}

	/**
	 * Capacity lowered below the going count blocks promotion even though a
	 * seat "freed up" — the guard re-counts against current capacity.
	 */
	public function test_promotion_blocked_when_capacity_shrank(): void {
		$event_id = self::make_event( '2' );
		$going    = self::seed( $event_id, 'going', 2 );
		self::seed( $event_id, 'waitlist', 2 );

		update_post_meta( $event_id, 'eh_capacity', '1' );

		$result = Plugin::instance()->repository()->delete_and_promote( $going[0] );

		$this->assertIsArray( $result );
		$this->assertNull( $result['promoted'], 'Going (1) already meets capacity (1): no promotion.' );
		$this->assertSame( array(), self::$captured_actions );
		$this->assertSame(
			array(
				'going'    => 1,
				'waitlist' => 2,
			'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * One delete promotes exactly one seat even with multiple waiters.
	 */
	public function test_single_delete_promotes_exactly_one(): void {
		$event_id = self::make_event( '2' );
		$going    = self::seed( $event_id, 'going', 2 );
		$waiting  = self::seed( $event_id, 'waitlist', 3 );

		Plugin::instance()->repository()->delete_and_promote( (int) $going[0] );

		$this->assertCount( 1, self::$captured_actions );
		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 2,
			'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);

		// Second delete promotes the next one in line.
		Plugin::instance()->repository()->delete_and_promote( (int) $going[1] );

		$this->assertCount( 2, self::$captured_actions );
		$this->assertSame( (int) $waiting[1], (int) ( self::$captured_actions[1][0]['id'] ?? 0 ) );
	}

	/**
	 * Missing capacity meta is unlimited: promotion always proceeds.
	 */
	public function test_promotion_with_unlimited_capacity(): void {
		$event_id = self::make_event( '' );
		$going    = self::seed( $event_id, 'going', 1 );
		$waiting  = self::seed( $event_id, 'waitlist', 2 );

		Plugin::instance()->repository()->delete_and_promote( (int) $going[0] );

		$this->assertCount( 1, self::$captured_actions );
		$this->assertSame( (int) $waiting[0], (int) ( self::$captured_actions[0][0]['id'] ?? 0 ) );
	}

	/**
	 * Deleting an absent ID reports deleted = null and promotes nothing.
	 */
	public function test_missing_id_is_null_null(): void {
		$event_id = self::make_event( '1' );
		self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 1 );

		$result = Plugin::instance()->repository()->delete_and_promote( 999999 );

		$this->assertIsArray( $result );
		$this->assertNull( $result['deleted'] );
		$this->assertNull( $result['promoted'] );
		$this->assertSame( array(), self::$captured_actions );
	}

	/**
	 * REST DELETE surfaces the promoted row (admin serializer) and keeps the
	 * 404 contract for absent IDs.
	 */
	public function test_rest_delete_response_includes_promoted_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		$waiting  = self::seed( $event_id, 'waitlist', 1 );

		$request = new \WP_REST_Request( 'DELETE', '/event-hub/v1/rsvps/' . $going[0] );
		$request->set_url_params( array( 'id' => (string) $going[0] ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( $going[0], (int) $data['previous']['id'] );
		$this->assertNotNull( $data['promoted'] );
		$this->assertSame( (int) $waiting[0], (int) $data['promoted']['id'] );
		$this->assertSame( 'going', $data['promoted']['status'] );
		$this->assertArrayHasKey( 'email', $data['promoted'], 'Admin surface exposes email (gated route].' );
	}

	/**
	 * REST DELETE of a waitlist row has no promoted payload.
	 */
	public function test_rest_delete_waitlist_row_promoted_null(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$event_id = self::make_event( '1' );
		self::seed( $event_id, 'going', 1 );
		$waiting = self::seed( $event_id, 'waitlist', 1 );

		$request = new \WP_REST_Request( 'DELETE', '/event-hub/v1/rsvps/' . $waiting[0] );
		$request->set_url_params( array( 'id' => (string) $waiting[0] ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['promoted'] );
	}

	/**
	 * REST 404 contract kept for absent IDs.
	 */
	public function test_rest_delete_missing_id_404(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new \WP_REST_Request( 'DELETE', '/event-hub/v1/rsvps/999999' );
		$request->set_url_params( array( 'id' => '999999' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'eh_rsvp_not_found', $response->get_data()['code'] );
	}

	/* ---------------------------------------------------------------------
	 * DB-failure surfacing (fails via the `query` filter).
	 * ------------------------------------------------------------------- */

	/**
	 * The promotion UPDATE failing (real DB error, not a guard block) must
	 * surface as eh_db_error — never as a silent success with counts lying.
	 *
	 * @global \wpdb $wpdb
	 */
	public function test_failing_promotion_update_returns_db_error(): void {
		global $wpdb;

		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 1 );

		// The `query` filter swaps the SQL for a statement that actually
		// fails (missing table), so $wpdb->last_error becomes non-empty —
		// exactly the failure shape the repository must detect.
		$failing = static function ( string $query ) use ( $wpdb ) {
			if ( 0 === stripos( ltrim( $query ), 'UPDATE ' ) && false !== stripos( $query, "SET status = 'going'" ) ) {
				return 'SELECT * FROM ' . $wpdb->prefix . 'eh_rsvps_missing_table_abc';
			}

			return $query;
		};

		add_filter( 'query', $failing );

		$result = Plugin::instance()->repository()->delete_and_promote( (int) $going[0] );

		remove_filter( 'query', $failing );

		$this->assertWPError( $result, 'A failed promotion UPDATE must surface as WP_Error.' );
		$this->assertSame( 'eh_db_error', $result->get_error_code() );
		$this->assertSame(
			array(
				'going'    => 0,
				'waitlist' => 1,
			'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id ),
			'Delete committed, promotion failed: counts report reality.'
		);
		$this->assertSame( array(), self::$captured_actions, 'No action on failed promotion.' );
	}

	/**
	 * A failed DELETE surfaces as eh_db_error and the row stays intact —
	 * success is never faked.
	 *
	 * @global \wpdb $wpdb
	 */
	public function test_failing_delete_returns_db_error_and_keeps_row(): void {
		global $wpdb;

		$event_id = self::make_event( '1' );
		$going    = self::seed( $event_id, 'going', 1 );
		self::seed( $event_id, 'waitlist', 1 );

		$failing = static function ( string $query ) use ( $wpdb ) {
			if ( 0 === stripos( ltrim( $query ), 'DELETE FROM ' . $wpdb->prefix . 'eh_rsvps' ) ) {
				return 'DELETE FROM ' . $wpdb->prefix . 'eh_rsvps_missing_table_xyz WHERE id = 1';
			}

			return $query;
		};

		add_filter( 'query', $failing );

		$result = Plugin::instance()->repository()->delete_and_promote( (int) $going[0] );

		remove_filter( 'query', $failing );

		$this->assertWPError( $result, 'A failed DELETE must surface as WP_Error.' );
		$this->assertSame( 'eh_db_error', $result->get_error_code() );

		// The row still exists: nothing was masked.
		$this->assertSame(
			array(
				'going'    => 1,
				'waitlist' => 1,
			'checkedIn' => 0,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}
}
