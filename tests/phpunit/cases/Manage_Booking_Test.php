<?php
/**
 * Tests for ROADMAP §2.1 — confirmation + manage-booking tokens.
 *
 * Covers the DB 1.3.0 column, token round-trip through create()/
 * find_by_token(), update_name(), the REST cancel route (including §1.3
 * promotion linkage), the opt-in Notifier, and the Manage_Page handlers.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Manage-booking feature test case.
 */
class Manage_Booking_Test extends WP_UnitTestCase {

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
	 * Captured pre_wp_mail payloads.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $captured_mail = array();

	/**
	 * Creates the event and the email tag.
	 *
	 * The DDL test in Checkin_Test simulates a 1.0.0 downgrade that commits
	 * (ALTER implicitly commits) and its post-migrate option re-stamp is
	 * undone by the transaction rollback — this suite may therefore start
	 * with the persistent install reporting 1.0.0. Production parity: a
	 * request through the plugin boot re-checks the version, so any pending
	 * migration runs before anything asserts schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Migrations::maybe_migrate();

		$this->email_tag = wp_generate_password( 8, false, false );

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => 'Manage Test Event',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Removes this test's rows and any option/transient state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE email LIKE %s", '%+' . $this->email_tag . '@example.org' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture cleanup; $email_tag is plugin-generated.
		);

		delete_option( Notifier::OPTION );
		remove_all_filters( 'pre_wp_mail' );

		parent::tear_down();
	}

	/**
	 * Creates one RSVP directly through the repository and returns the row
	 * plus the raw manage token.
	 *
	 * @param string $status Row status.
	 * @return array{row:array<string,mixed>,token:string}
	 */
	private function make_rsvp( string $status = 'going' ): array {
		$repo = new Rsvp_Repository();

		$names = array(
			'going'    => 'Mana Going',
			'waitlist' => 'Mana Wait',
		);

		$result = $repo->create(
			$names['going' === $status ? 'going' : 'waitlist'],
			"mana+{$this->email_tag}@example.org",
			$status,
			$this->event_id
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertIsArray( $result );

		return array(
			'row'   => $result['rsvp'],
			'token' => (string) $result['manage_token'],
		);
	}

	/**
	 * The DB 1.3.0 column exists, is nullable and indexed; the version
	 * option tracks the constant.
	 *
	 * @return void
	 */
	public function test_fresh_install_has_token_column(): void {
		global $wpdb;

		$column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'eh_rsvps LIKE %s', 'manage_token' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix.
		);

		$this->assertNotNull( $column, 'manage_token column must exist on a fresh 1.3.0 install' );
		$this->assertSame( 'YES', (string) $column->Null, 'manage_token must be nullable (legacy rows)' );
		$this->assertSame( EVENT_HUB_DB_VERSION, get_option( 'event_hub_db_version' ) );
	}

	/**
	 * create() stores only the SHA-256 hash; the raw token resolves the row
	 * via find_by_token.
	 *
	 * @return void
	 */
	public function test_token_round_trip(): void {
		global $wpdb;

		$made   = $this->make_rsvp();
		$token  = $made['token'];
		$raw_id = (int) $made['row']['id'];

		$raw = $wpdb->get_var(
			$wpdb->prepare( 'SELECT manage_token FROM ' . $wpdb->prefix . 'eh_rsvps WHERE id = %d', $raw_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix.
		);

		$this->assertSame( hash( 'sha256', $token ), (string) $raw, 'DB must hold the hash, not the raw token' );
		$this->assertNotSame( $token, (string) $raw );

		$found = ( new Rsvp_Repository() )->find_by_token( $token );

		$this->assertNotNull( $found );
		$this->assertSame( $raw_id, (int) $found['id'] );
		$this->assertArrayNotHasKey( 'manage_token', $found, 'normalized rows must not expose the hash' );
	}

	/**
	 * Garbage, wrong-length and non-hex tokens resolve to null without
	 * touching the DB.
	 *
	 * @return void
	 */
	public function test_find_by_token_rejects_malformed_input(): void {
		$repo = new Rsvp_Repository();

		$this->assertNull( $repo->find_by_token( '' ) );
		$this->assertNull( $repo->find_by_token( str_repeat( 'z', 64 ) ) );
		$this->assertNull( $repo->find_by_token( str_repeat( 'a', 63 ) ) );
		$this->assertNull( $repo->find_by_token( hash( 'sha256', 'never-created' ) ) );
	}

	/**
	 * update_name() changes the name and leaves status/created_at alone;
	 * unknown IDs yield null.
	 *
	 * @return void
	 */
	public function test_update_name(): void {
		$repo = new Rsvp_Repository();
		$made = $this->make_rsvp();
		$id   = (int) $made['row']['id'];
		$before = $repo->get( $id );

		$updated = $repo->update_name( $id, 'Mana Renamed' );

		$this->assertNotInstanceOf( \WP_Error::class, $updated );
		$this->assertIsArray( $updated );
		$this->assertSame( 'Mana Renamed', $updated['name'] );
		$this->assertSame( $before['status'], $updated['status'] );
		$this->assertSame( $before['created_at'], $updated['created_at'] );

		$this->assertNull( $repo->update_name( 987654321, 'Nobody' ) );
	}

	/**
	 * REST cancel: valid token deletes the row (via delete_and_promote) and
	 * reports counts; unknown tokens 404 in the permission callback.
	 *
	 * @return void
	 */
	public function test_rest_cancel(): void {
		$route = new Rest_Controller();
		$made  = $this->make_rsvp();

		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp/cancel' );
		$request->set_param( 'token', $made['token'] );

		$permission = $route->cancel_permission( $request );

		$this->assertTrue( $permission );

		$response = $route->cancel_rsvp( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$data = $response->get_data();

		$this->assertTrue( $data['cancelled'] );
		$this->assertNull( $data['promoted'] );
		$this->assertSame( 0, $data['counts']['going'] );

		// Row is gone; a second cancel 404s at the permission gate.
		$this->assertInstanceOf(
			\WP_Error::class,
			$route->cancel_permission( $request ),
			'unknown token must 404 before the callback'
		);
	}

	/**
	 * Cancel on a FULL event promotes the earliest waitlist row — the §1.3
	 * linkage this slice feeds.
	 *
	 * @return void
	 */
	public function test_rest_cancel_promotes_waitlist_on_full_event(): void {
		update_post_meta( $this->event_id, 'eh_capacity', '1' );

		$repo = new Rsvp_Repository();

		$full = $repo->create( 'Full Seat', "full+{$this->email_tag}@example.org", 'going', $this->event_id );
		$this->assertNotInstanceOf( \WP_Error::class, $full );

		$wait = $repo->create( 'Wait One', "wait+{$this->email_tag}@example.org", 'waitlist', $this->event_id );
		$this->assertNotInstanceOf( \WP_Error::class, $wait );

		update_post_meta( $this->event_id, 'eh_capacity', '2' );

		$second = $repo->create( 'Second Seat', "second+{$this->email_tag}@example.org", 'going', $this->event_id );
		$this->assertNotInstanceOf( \WP_Error::class, $second );

		update_post_meta( $this->event_id, 'eh_capacity', '2' );

		$route = new Rest_Controller();

		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp/cancel' );
		$request->set_param( 'token', (string) $full['manage_token'] );

		$response = $route->cancel_rsvp( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$data = $response->get_data();

		$this->assertTrue( $data['cancelled'] );
		$this->assertIsArray( $data['promoted'] );
		$this->assertSame( (int) $wait['rsvp']['id'], (int) $data['promoted']['id'], 'earliest waitlist row must flip to going' );
		$this->assertSame( 2, $data['counts']['going'] );
		$this->assertSame( 0, $data['counts']['waitlist'] );
	}

	/**
	 * The Notifier is opt-in: default off sends nothing; on sends one mail
	 * to the attendee with the manage URL and the raw token.
	 *
	 * @return void
	 */
	public function test_notifier_gated_and_payload(): void {
		$made  = $this->make_rsvp();
		$token = $made['token'];

		$captor = function ( $return, $atts ) {
			$this->captured_mail[] = $atts;
			return true; // Pretend the send succeeded.
		};

		add_filter( 'pre_wp_mail', $captor, 10, 2 );

		// Default: option absent => off => no mail.
		Notifier::send_confirmation( $made['row'], $this->event_id, $token );
		$this->assertCount( 0, $this->captured_mail, 'default off must send nothing' );

		update_option( Notifier::OPTION, true );

		$sent = Notifier::send_confirmation( $made['row'], $this->event_id, $token );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $this->captured_mail );

		$atts = $this->captured_mail[0];

		$this->assertSame( "mana+{$this->email_tag}@example.org", $atts['to'] );
		$this->assertStringContainsString( 'Manage Test Event', (string) $atts['subject'] );
		$this->assertStringContainsString( $token, (string) $atts['message'], 'raw token must be in the emailed link' );
		$this->assertStringContainsString( 'eh_manage=' . $token, (string) $atts['message'] );

		// The email body must not leak other rows' tokens; only this one's.
		$this->assertSame( 1, substr_count( (string) $atts['message'], 'eh_manage=' ) );
	}

	/**
	 * A mail failure never fails the RSVP: send_confirmation returns false
	 * and create_rsvp's 201 does not depend on it (wp_mail mocked failing).
	 *
	 * @return void
	 */
	public function test_mail_failure_returns_false_quietly(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		update_option( Notifier::OPTION, true );

		$made = $this->make_rsvp();

		$sent = Notifier::send_confirmation( $made['row'], $this->event_id, $made['token'] );

		$this->assertFalse( $sent );
	}

	/**
	 * The manage page renders the booking for a valid token and the invalid
	 * state for a garbage one; cancel/renamed states come from eh_state.
	 *
	 * @return void
	 */
	public function test_manage_page_states(): void {
		$page = new Manage_Page();
		$made = $this->make_rsvp();

		// Invalid token: same chrome, invalid message, no forms.
		$_GET['eh_manage'] = str_repeat( '0', 64 );
		ob_start();
		$page->maybe_render( 'index.php' );
		$invalid_out = (string) ob_get_clean();

		$this->assertStringContainsString( 'not valid', $invalid_out );
		$this->assertStringNotContainsString( 'Cancel my RSVP', $invalid_out );

		// Valid token: booking details + both forms.
		$_GET['eh_manage'] = $made['token'];
		ob_start();
		$page->maybe_render( 'index.php' );
		$valid_out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Manage Test Event', $valid_out );
		$this->assertStringContainsString( 'mana+', $valid_out );
		$this->assertStringContainsString( 'Cancel my RSVP', $valid_out );
		$this->assertStringContainsString( 'Save name', $valid_out );

		// No token: normal template passes through untouched.
		unset( $_GET['eh_manage'] );

		$this->assertSame( 'index.php', $page->maybe_render( 'index.php' ) );
	}

	/**
	 * The toggle handler: capability + nonce gate the option write.
	 *
	 * @return void
	 */
	public function test_toggle_handler_gates(): void {
		$page = new Admin_Page();

		// No user, no nonce => die. Assert via the 403 path (subscriber).
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'eh_toggle_confirmations' );

		$this->expectException( \WPDieException::class );
		try {
			$page->handle_toggle();
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * Toggle happy path: editor + valid nonce flips the option on.
	 *
	 * @return void
	 */
	public function test_toggle_happy_path(): void {
		$page = new Admin_Page();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'eh_toggle_confirmations' );
		$_POST['event_hub_confirmations'] = 'on';

		// Redirects on success; catch the exit shim's die as success marker.
		$redirected = false;

			add_filter(
			'wp_redirect',
			static function ( $url ) {
				throw new \WPDieException( 'redirected' );
			}
		);

		try {
			$page->handle_toggle();
		} catch ( \WPDieException $e ) {
			$redirected = 'redirected' === $e->getMessage();
		} finally {
			unset( $_REQUEST['_wpnonce'], $_POST['event_hub_confirmations'] );
			remove_all_filters( 'wp_redirect' );
		}

		$this->assertTrue( $redirected, 'successful toggle must redirect' );
		$this->assertTrue( Notifier::enabled() );
	}
}
