<?php
/**
 * Tests for ROADMAP §2.2 — check-in tracking.
 *
 * Covers the DB 1.2.0 column (fresh install + upgrade path), the repository
 * set/checkin-clear contract, 3-key counts, the REST check-in route gates,
 * and the CSV export column.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Check-in feature test case.
 */
class Checkin_Test extends WP_UnitTestCase {

	/**
	 * Per-test unique email tag (see set_up() for why).
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
	 * Rsvp row IDs seeded per test.
	 *
	 * @var array<int,int>
	 */
	private array $rsvp_ids = array();

	/**
	 * Creates the event and, on demand later, its RSVPs.
	 *
	 * Rows are NOT created here: the upgrade test issues DDL, which
	 * implicitly commits anything already inserted — and a tear_down DELETE
	 * cannot clean rows that were committed mid-test (the suite's final
	 * ROLLBACK undoes the delete itself). Tests that need rows call
	 * seed_rows() in their body; DDL tests never do.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->email_tag = wp_generate_password( 8, false, false );

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => 'Checkin Test Event',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Lazily creates the three standard RSVPs (two going, one waitlist).
	 * Emails carry a per-test unique suffix so a leaked row — from any
	 * historical run — can never collide with a later set_up.
	 *
	 * @return void
	 */
	private function seed_rows(): void {
		if ( array() !== $this->rsvp_ids ) {
			return;
		}

		$repo = new Rsvp_Repository();

		$going_a = $repo->create( 'Alice Going', "alice+{$this->email_tag}@example.org", 'going', $this->event_id );
		$going_b = $repo->create( 'Bob Going', "bob+{$this->email_tag}@example.org", 'going', $this->event_id );
		$wait_c  = $repo->create( 'Carol Wait', "carol+{$this->email_tag}@example.org", 'waitlist', $this->event_id );

		$this->rsvp_ids = array(
			(int) $going_a['rsvp']['id'],
			(int) $going_b['rsvp']['id'],
			(int) $wait_c['rsvp']['id'],
		);
	}

	/**
	 * Removes this test's rows explicitly (belt for the suite's rollback
	 * suspenders; a no-op for DDL tests that never seeded).
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		$cleanup = "DELETE FROM {$table} WHERE email LIKE '%+{$this->email_tag}@example.org'";

		$wpdb->query( $cleanup ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture cleanup; $email_tag is plugin-generated, not user input.

		parent::tear_down();
	}

	/**
	 * The DB 1.2.0 column exists with DATETIME NULL semantics: fresh rows are
	 * NULL, the version option is at 1.2.0.
	 *
	 * @return void
	 */
	public function test_fresh_install_has_column_and_version(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		$column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'checked_in_at' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
		);

		$this->assertNotNull( $column, 'checked_in_at column must exist on a fresh install' );
		$this->assertSame( 'YES', (string) $column->Null, 'column must be nullable' );

		$this->assertSame( EVENT_HUB_DB_VERSION, get_option( 'event_hub_db_version' ) );
	}

	/**
	 * The upgrade path adds the column: downgrade to 1.0.0, drop the column,
	 * re-run migrate() — the column must come back.
	 *
	 * @return void
	 */
	public function test_upgrade_from_1_0_0_adds_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		// Simulate the 1.0.0 schema.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN checked_in_at" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture DDL on a test table.
		update_option( 'event_hub_db_version', '1.0.0' );

		Migrations::migrate();

		$column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'checked_in_at' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
		);

		$this->assertNotNull( $column, 'migrate() must (re)add checked_in_at for 1.0.0 installs' );
		$this->assertSame( EVENT_HUB_DB_VERSION, get_option( 'event_hub_db_version' ) );

		/*
		 * Leave the persistent DB consistent: the transactional wrapper rolls
		 * back to the savepoint taken at set_up(), but the ALTER + the 1.0.0
		 * option write above were already committed by the DDL. Re-stamp the
		 * current version outside that commit window so a later test (or the
		 * next suite run against this persistent install) never sees the
		 * simulated downgrade.
		 */
		update_option( 'event_hub_db_version', EVENT_HUB_DB_VERSION );
	}

	/**
	 * Stamping an RSVP writes a timestamp; clearing removes it. status and
	 * created_at are never touched.
	 *
	 * @return void
	 */
	public function test_set_and_clear_checkin(): void {
		$repo = new Rsvp_Repository();
		$this->seed_rows();
		$id     = $this->rsvp_ids[0];
		$before = $repo->get( $id );

		$result = $repo->set_checked_in( $id, true );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['checked_in_at'], 'stamped row must carry a timestamp' );

		// Clearing yields NULL.
		$cleared = $repo->set_checked_in( $id, false );

		$this->assertNotInstanceOf( \WP_Error::class, $cleared );
		$this->assertNull( $cleared['checked_in_at'] );

		// Immutable fields: status + created_at untouched by both writes.
		$after = $repo->get( $id );

		$this->assertSame( $before['status'], $after['status'] );
		$this->assertSame( $before['created_at'], $after['created_at'] );
	}

	/**
	 * The counts() shape gains checkedIn as the third key, counted across
	 * statuses (a checked-in waitlist row still counts — attendance is not
	 * status-bound in this slice).
	 *
	 * @return void
	 */
	public function test_counts_include_checked_in(): void {
		$repo = new Rsvp_Repository();
		$this->seed_rows();

		// Baseline: zero checked in.
		$counts = $repo->counts( $this->event_id );

		$this->assertSame(
			array(
				'going'     => 2,
				'waitlist'  => 1,
				'checkedIn' => 0,
			),
			$counts
		);

		// Check in one going AND the waitlist row — both count.
		$repo->set_checked_in( $this->rsvp_ids[0], true );
		$repo->set_checked_in( $this->rsvp_ids[2], true );

		$counts = $repo->counts( $this->event_id );

		$this->assertSame( 2, $counts['checkedIn'] );
		$this->assertSame( 2, $counts['going'] );
		$this->assertSame( 1, $counts['waitlist'] );
	}

	/**
	 * Unknown IDs behave: repository returns null, REST returns 404.
	 *
	 * @return void
	 */
	public function test_unknown_rsvp_id(): void {
		$repo = new Rsvp_Repository();

		$this->assertNull( $repo->set_checked_in( 987654321, true ) );

		$route = new Rest_Controller();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request  = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvps/987654321/check-in' );
		$response = $route->check_in_rsvp( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'eh_rsvp_not_found', $response->get_error_code() );
	}

	/**
	 * The check-in route is admin-gated: subscriber gets 403 via the
	 * permission callback, editor passes.
	 *
	 * @return void
	 */
	public function test_rest_permission_gate(): void {
		$route = new Rest_Controller();

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$permission = $route->admin_permission();

		$this->assertInstanceOf( \WP_Error::class, $permission );
		$this->assertSame( 'rest_forbidden', $permission->get_error_code() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertTrue( $route->admin_permission() );
	}

	/**
	 * The REST round-trip: callback stamps, echoes the row with checkedInAt
	 * ISO 8601; clearing returns null.
	 *
	 * @return void
	 */
	public function test_rest_round_trip(): void {
		$route = new Rest_Controller();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->seed_rows();
		$id = $this->rsvp_ids[1];

		$stamp = $route->check_in_rsvp( $this->make_request( $id ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $stamp );

		$data = $stamp->get_data();

		$this->assertTrue( $data['updated'] );
		$this->assertNotEmpty( $data['rsvp']['checkedInAt'] );
		$this->assertStringContainsString( 'T', (string) $data['rsvp']['checkedInAt'], 'checkedInAt must be ISO 8601' );
		$this->assertSame( 'going', $data['rsvp']['status'], 'check-in must not touch status' );

		$clear = $route->check_in_rsvp( $this->make_request( $id, false ) );

		$data = $clear->get_data();

		$this->assertNull( $data['rsvp']['checkedInAt'] );
	}

	/**
	 * CSV export carries the Checked In At column: empty before, ISO after.
	 *
	 * @return void
	 */
	public function test_csv_export_includes_checkin_column(): void {
		$repo = new Rsvp_Repository();
		$this->seed_rows();
		$repo->set_checked_in( $this->rsvp_ids[0], true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_GET['action']   = Csv_Exporter::ACTION;
		$_GET['event_id'] = (string) $this->event_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Csv_Exporter::NONCE_ACTION );

		ob_start();
		$exporter = new Csv_Exporter();
		$exporter->handle_request();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Checked In At', $out );

		$split = preg_split( '/\r\n|\r|\n/', trim( $out ) );
		$lines = is_array( $split ) ? $split : array();

		$this->assertCount( 4, $lines, 'header + 3 rows' );
		$this->assertStringContainsString( "alice+{$this->email_tag}@example.org", $lines[1] );
		$this->assertStringContainsString( 'T', $lines[1], 'checked-in row must carry the ISO timestamp' );
	}

	/**
	 * Builds a REST request for the check-in callback.
	 *
	 * @param int      $id        RSVP row ID (URL param).
	 * @param bool     $checked   checkedIn body value.
	 * @param int|null $user_id   Optional user to cap.
	 * @return \WP_REST_Request
	 */
	private function make_request( int $id, bool $checked = true, ?int $user_id = null ): \WP_REST_Request {
		if ( null !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvps/' . $id . '/check-in' );
		$request->set_url_params( array( 'id' => (string) $id ) );
		$request->set_body( wp_json_encode( array( 'checkedIn' => $checked ) ) );
		$request->set_param( 'checkedIn', $checked );

		return $request;
	}
}
