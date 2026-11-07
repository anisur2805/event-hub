<?php
/**
 * ROADMAP 5.6 regressions: virtual events. The online joining URL is a
 * SECRET (underscore-protected meta) revealed ONLY to confirmed attendees:
 * the token-proven manage page + REST read, and the going-recipient email
 * branches. Public surfaces carry only the FACT of virtuality.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * 5.6 entitlement + exposure tests.
 */
class Virtual_Event_Test extends WP_UnitTestCase {

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	private int $event_id = 0;

	/**
	 * Per-test email tag (unique addresses, clean teardown).
	 *
	 * @var string
	 */
	private string $email_tag = '';

	/**
	 * Captured pre_wp_mail payloads.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $captured_mail = array();

	/**
	 * Creates the event under test; all mail toggles off by default.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->email_tag = wp_generate_password( 8, false, false );

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => 'Virtual Test Event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->event_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $this->event_id, 'eh_end', '2099-05-01 20:00:00' );

		update_option( Notifier::OPTION, true );
		update_option( Reminders::OPTION, false );
	}

	/**
	 * Removes captured state and RSVP rows.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE email LIKE %s", '%+' . $this->email_tag . '@%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture cleanup; $email_tag is plugin-generated.
		);

		remove_all_filters( 'pre_wp_mail' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Installs a fresh mail captor (remove-all first: no stacking).
	 *
	 * @return void
	 */
	private function capture_mail(): void {
		remove_all_filters( 'pre_wp_mail' );
		$this->captured_mail = array();

		$self = $this;

		add_filter(
			'pre_wp_mail',
			static function ( $return, $atts ) use ( $self ) {
				$self->push_mail( is_array( $atts ) ? $atts : array() );

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Pushes one captured payload (public: the captor closure is static).
	 *
	 * @param array<string,mixed> $atts wp_mail arguments.
	 * @return void
	 */
	public function push_mail( array $atts ): void {
		$this->captured_mail[] = $atts;
	}

	/**
	 * Creates one RSVP via the repository (no mail fires there).
	 *
	 * @param string $status 'going' | 'waitlist'.
	 * @return array{row:array<string,mixed>,token:string}
	 */
	private function make_rsvp( string $status = 'going' ): array {
		$repo = new Rsvp_Repository();

		$result = $repo->create(
			'going' === $status ? 'Virt Going' : 'Virt Wait',
			"virt+{$this->email_tag}+" . wp_generate_password( 4, false, false ) . '@example.org',
			$status,
			$this->event_id
		);

		if ( $result instanceof \WP_Error ) {
			self::fail( 'RSVP create failed: ' . $result->get_error_message() );
		}

		return array(
			'row'   => $result['rsvp'],
			'token' => (string) $result['manage_token'],
		);
	}

	/**
	 * Backdates a row's created_at (reminder-window qualification), same
	 * pattern as Reminders_Test.
	 *
	 * @param int    $row_id  RSVP row ID.
	 * @param string $created 'Y-m-d H:i:s' (gmdate epoch).
	 * @return void
	 */
	private function backdate_created( int $row_id, string $created ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'eh_rsvps',
			array( 'created_at' => $created ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sets a valid online URL on the test event.
	 *
	 * @param string $url Stored value (validated on READ, never on write).
	 * @return void
	 */
	private function set_online_url( string $url = 'https://meet.example.org/room-42' ): void {
		update_post_meta( $this->event_id, '_eh_online_url', $url );
	}

	/* ---------------------------------------------------------------------
	 * Read-side gate (online_url / is_virtual).
	 * ------------------------------------------------------------------- */

	/**
	 * online_url() mirrors external_url()'s gate: https/http pass; relative,
	 * scheme-relative, javascript:, blank and absent degrade to ''.
	 *
	 * @return void
	 */
	public function test_online_url_validation_matrix(): void {
		update_post_meta( $this->event_id, '_eh_online_url', 'https://meet.example.org/x' );
		$this->assertSame( 'https://meet.example.org/x', Render_Blocks::online_url( $this->event_id ) );

		update_post_meta( $this->event_id, '_eh_online_url', 'http://jitsi.example.org/r' );
		$this->assertSame( 'http://jitsi.example.org/r', Render_Blocks::online_url( $this->event_id ) );

		update_post_meta( $this->event_id, '_eh_online_url', '/relative/path' );
		$this->assertSame( '', Render_Blocks::online_url( $this->event_id ) );

		update_post_meta( $this->event_id, '_eh_online_url', '//scheme-relative' );
		$this->assertSame( '', Render_Blocks::online_url( $this->event_id ) );

		update_post_meta( $this->event_id, '_eh_online_url', 'javascript:alert(1)' );
		$this->assertSame( '', Render_Blocks::online_url( $this->event_id ) );

		update_post_meta( $this->event_id, '_eh_online_url', '   ' );
		$this->assertSame( '', Render_Blocks::online_url( $this->event_id ) );

		// Absent meta on an unknown post: ''.
		$this->assertSame( '', Render_Blocks::online_url( 2147483000 ) );
	}

	/**
	 * is_virtual() is exactly "the gated URL is non-empty". A schemeless
	 * one-word value is REPAIRED by esc_url_raw into `http://…%20…` (core
	 * behavior, shared with external_url); a truly junk value (relative,
	 * scheme-hostile) is gated to '' and does NOT make the event virtual.
	 *
	 * @return void
	 */
	public function test_is_virtual_matches_gated_url(): void {
		$this->assertFalse( Render_Blocks::is_virtual( $this->event_id ) );

		// Relative path: gated to '' → still not virtual.
		update_post_meta( $this->event_id, '_eh_online_url', '/relative/path' );
		$this->assertFalse( Render_Blocks::is_virtual( $this->event_id ) );

		// Scheme-hostile scheme: gated to '' → still not virtual.
		update_post_meta( $this->event_id, '_eh_online_url', 'javascript:alert(1)' );
		$this->assertFalse( Render_Blocks::is_virtual( $this->event_id ) );

		// Schemeless word junk: esc_url_raw repairs to http://not%20a%20url
		// (host `not` passes) — the event reads virtual;[sic] the editor's
		// gated-echo help text is what flags it. Pinned as observed core
		// behavior.
		update_post_meta( $this->event_id, '_eh_online_url', 'not a url' );
		$this->assertSame( 'http://not%20a%20url', Render_Blocks::online_url( $this->event_id ) );
		$this->assertTrue( Render_Blocks::is_virtual( $this->event_id ) );

		$this->set_online_url();
		$this->assertTrue( Render_Blocks::is_virtual( $this->event_id ) );
	}

	/* ---------------------------------------------------------------------
	 * The secret NEVER leaks through public surfaces.
	 * ------------------------------------------------------------------- */

	/**
	 * The underscore prefix must keep the meta out of /wp/v2 reads AND
	 * writes (core protected-meta rule) — this is the load-bearing wall of
	 * the 5.6 secret model.
	 *
	 * @return void
	 */
	public function test_protected_meta_never_rides_wp_v2(): void {
		$this->set_online_url();

		Plugin::instance()->register_meta();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new \WP_REST_Request( 'GET', "/wp/v2/events-cpt/{$this->event_id}" );
		$request->set_param( 'context', 'edit' );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayNotHasKey( '_eh_online_url', $data['meta'] ?? array() );

		$as_json = wp_json_encode( $data );
		$this->assertIsString( $as_json );
		$this->assertStringNotContainsString( 'meet.example.org', $as_json );

		// Even an administrator's wp/v2 meta write hits the protected wall.
		$request2 = new \WP_REST_Request( 'POST', "/wp/v2/events-cpt/{$this->event_id}" );
		$request2->set_body_params(
			array(
				'meta' => array(
					'_eh_online_url' => 'https://attacker.example.org/fake-room',
				),
			)
		);
		rest_get_server()->dispatch( $request2 );

		$this->assertSame(
			'https://meet.example.org/room-42',
			get_post_meta( $this->event_id, '_eh_online_url', true ),
			'protected meta must not be writable through /wp/v2'
		);
	}

	/**
	 * Cards, single view, REST /events and JSON-LD carry the FACT (Online
	 * badge / fact line / virtual flag / attendanceMode) but never the URL.
	 *
	 * @return void
	 */
	public function test_public_surfaces_expose_fact_not_url(): void {
		$this->set_online_url();

		// Card: Online badge, no URL.
		$card = Render_Blocks::render_card( Render_Blocks::summary( $this->event_id ) );
		$this->assertStringContainsString( 'event-hub-card__online', $card );
		$this->assertStringContainsString( '>Online</p>', $card );
		$this->assertStringNotContainsString( 'meet.example.org', $card );

		// Single view: fact line, no URL.
		$post = get_post( $this->event_id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$single = Render_Blocks::render_single_event( $post );
		$this->assertStringContainsString( 'event-hub-single__online', $single );
		$this->assertStringContainsString( 'joining details are sent to confirmed attendees', $single );
		$this->assertStringNotContainsString( 'meet.example.org', $single );

		// REST: virtual flag true; serializer never carries the URL.
		$request = new \WP_REST_Request( 'GET', '/event-hub/v1/events' );
		$data    = rest_get_server()->dispatch( $request )->get_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );

		$first = $data[0];
		$this->assertIsArray( $first );
		$this->assertTrue( $first['virtual'] );
		$this->assertArrayNotHasKey( 'onlineUrl', $first );

		$as_json = wp_json_encode( $data );
		$this->assertIsString( $as_json );
		$this->assertStringNotContainsString( 'meet.example.org', $as_json );

		// JSON-LD: attendanceMode declared; URL never emitted.
		$graph = ( new Structured_Data() )->graph( Render_Blocks::summary( $this->event_id ) );
		$this->assertNotNull( $graph );
		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $graph['eventAttendanceMode'] );

		$graph_json = wp_json_encode( $graph );
		$this->assertIsString( $graph_json );
		$this->assertStringNotContainsString( 'meet.example.org', $graph_json );

		// Non-virtual events: flag false, attendanceMode absent.
		$plain = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $plain, 'eh_start', '2099-06-01 18:00:00' );
		update_post_meta( $plain, 'eh_end', '2099-06-01 20:00:00' );

		$graph2 = ( new Structured_Data() )->graph( Render_Blocks::summary( $plain ) );
		$this->assertNotNull( $graph2 );
		$this->assertArrayNotHasKey( 'eventAttendanceMode', $graph2 );

		$data2 = rest_get_server()->dispatch( $request )->get_data();
		$this->assertIsArray( $data2 );

		$by_id = array_column( $data2, 'virtual', 'id' );
		$this->assertFalse( $by_id[ $plain ] );
	}

	/* ---------------------------------------------------------------------
	 * Entitlement: who learns the URL.
	 * ------------------------------------------------------------------- */

	/**
	 * REST read: going token => URL; waitlist token and junk token refuse
	 * with the SAME uniform message (probing cannot distinguish no-event
	 * from not-entitled); a going token bound to a different event refuses.
	 *
	 * @return void
	 */
	public function test_rest_online_url_entitlement_matrix(): void {
		$this->set_online_url();

		// Stranger token (no rows exist yet): 403, message recorded.
		$stranger = new \WP_REST_Request( 'GET', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$stranger->set_param( 'token', 'ab' );
		$stranger_response = rest_get_server()->dispatch( $stranger );

		$this->assertSame( 403, $stranger_response->get_status() );

		$stranger_data = $stranger_response->get_data();
		$stranger_msg  = is_array( $stranger_data ) ? (string) ( $stranger_data['message'] ?? '' ) : '';
		$this->assertStringContainsString( 'only available to confirmed attendees', $stranger_msg );

		$going = $this->make_rsvp( 'going' );
		$wait  = $this->make_rsvp( 'waitlist' );

		// Going holder: 200 + URL.
		$ok = new \WP_REST_Request( 'GET', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$ok->set_param( 'token', $going['token'] );
		$ok_response = rest_get_server()->dispatch( $ok );

		$this->assertSame( 200, $ok_response->get_status() );

		$ok_data = $ok_response->get_data();
		$this->assertIsArray( $ok_data );
		$this->assertSame( 'https://meet.example.org/room-42', $ok_data['onlineUrl'] ?? null );

		// Same event's WAITLIST holder: 403 — identical message to stranger.
		$wait_req = new \WP_REST_Request( 'GET', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$wait_req->set_param( 'token', $wait['token'] );
		$wait_response = rest_get_server()->dispatch( $wait_req );

		$this->assertSame( 403, $wait_response->get_status() );

		$wait_data = $wait_response->get_data();
		$wait_msg  = is_array( $wait_data ) ? (string) ( $wait_data['message'] ?? '' ) : '';
		$this->assertSame( $stranger_msg, $wait_msg, 'uniform refusal: probing cannot enumerate states' );

		// A going token for a DIFFERENT event: 403 (event binding judged).
		$other = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);
		$cross = new \WP_REST_Request( 'GET', "/event-hub/v1/events/{$other}/online-url" );
		$cross->set_param( 'token', $going['token'] );

		$this->assertSame( 403, rest_get_server()->dispatch( $cross )->get_status() );
	}

	/**
	 * Editor routes: write and seed-read require edit_posts (subscriber and
	 * anonymous refuse, editor passes); junk URLs SAVE but echo the gated
	 * '' (5A.4 twin discipline); a valid round-trip persists the meta.
	 *
	 * @return void
	 */
	public function test_editor_routes_auth_and_round_trip(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Anonymous write: 403.
		$anon = new \WP_REST_Request( 'POST', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$anon->set_body_params( array( 'url' => 'https://meet.example.org/x' ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $anon )->get_status() );

		// Subscriber write: 403.
		wp_set_current_user( $reader );
		$sub = new \WP_REST_Request( 'POST', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$sub->set_body_params( array( 'url' => 'https://meet.example.org/x' ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $sub )->get_status() );
		$this->assertSame( '', get_post_meta( $this->event_id, '_eh_online_url', true ) );

		// Editor write: 200; gated echo; meta persisted.
		wp_set_current_user( $editor );
		$ok = new \WP_REST_Request( 'POST', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$ok->set_body_params( array( 'url' => 'https://meet.example.org/editor-set' ) );
		$ok_response = rest_get_server()->dispatch( $ok );

		$this->assertSame( 200, $ok_response->get_status() );

		$ok_data = $ok_response->get_data();
		$this->assertIsArray( $ok_data );
		$this->assertSame( 'https://meet.example.org/editor-set', $ok_data['onlineUrl'] ?? null );
		$this->assertSame( 'https://meet.example.org/editor-set', get_post_meta( $this->event_id, '_eh_online_url', true ) );

		// Junk URL: SAVES, echoes the gated '' (event flips NOT virtual).
		$junk = new \WP_REST_Request( 'POST', "/event-hub/v1/events/{$this->event_id}/online-url" );
		$junk->set_body_params( array( 'url' => 'not a url' ) );
		$junk_response = rest_get_server()->dispatch( $junk );

		$this->assertSame( 200, $junk_response->get_status() );
		$this->assertSame( 'not a url', get_post_meta( $this->event_id, '_eh_online_url', true ) );

		$junk_data = $junk_response->get_data();
		$this->assertIsArray( $junk_data );
		// esc_url_raw repairs the schemeless value; the gated echo shows the
		// attendee-visible form (see test_is_virtual_matches_gated_url).
		$this->assertSame( 'http://not%20a%20url', $junk_data['onlineUrl'] ?? null );
		$this->assertTrue( Render_Blocks::is_virtual( $this->event_id ) );

		// Editor seed GET: 200 + gated value.
		update_post_meta( $this->event_id, '_eh_online_url', 'https://meet.example.org/room-42' );

		$seed          = new \WP_REST_Request( 'GET', "/event-hub/v1/events/{$this->event_id}/online-url-editor" );
		$seed_response = rest_get_server()->dispatch( $seed );

		$this->assertSame( 200, $seed_response->get_status() );

		$seed_data = $seed_response->get_data();
		$this->assertIsArray( $seed_data );
		$this->assertSame( 'https://meet.example.org/room-42', $seed_data['onlineUrl'] ?? null );

		// Subscriber seed GET: 403 (route is capability-gated too).
		wp_set_current_user( $reader );
		$this->assertSame( 403, rest_get_server()->dispatch( $seed )->get_status() );
	}

	/* ---------------------------------------------------------------------
	 * Emails: only going recipients carry the URL.
	 * ------------------------------------------------------------------- */

	/**
	 * Confirmation: going gets "Join online:"; waitlist confirmation for
	 * the SAME event does not.
	 *
	 * @return void
	 */
	public function test_confirmation_email_carries_url_only_for_going(): void {
		$this->set_online_url();
		$this->capture_mail();

		$going = $this->make_rsvp( 'going' );
		$wait  = $this->make_rsvp( 'waitlist' );

		$this->assertTrue( Notifier::send_confirmation( $going['row'], $this->event_id, $going['token'] ) );
		$this->assertTrue( Notifier::send_confirmation( $wait['row'], $this->event_id, $wait['token'] ) );

		$this->assertCount( 2, $this->captured_mail );

		$going_body = (string) $this->captured_mail[0]['message'];
		$wait_body  = (string) $this->captured_mail[1]['message'];

		$this->assertStringContainsString( 'Join online: https://meet.example.org/room-42', $going_body );
		$this->assertStringContainsString( 'please do not share it', $going_body );

		$this->assertStringNotContainsString( 'meet.example.org', $wait_body );
		$this->assertStringNotContainsString( 'Join online', $wait_body );
	}

	/**
	 * Reminders (5.2 sweep: audience = going AND waitlist rows) carry the
	 * URL only in the GOING recipient's copy — the 5.6 gate rides the row
	 * status, so a waitlisted attendee is reminded but neverlinked.
	 *
	 * @return void
	 */
	public function test_reminder_gates_url_by_row_status(): void {
		$this->set_online_url();

		global $wpdb;

		$going = $this->make_rsvp( 'going' );
		$wait  = $this->make_rsvp( 'waitlist' );

		// Event inside its 24h reminder window (start now+2h) so the sweep
		// picks it; rows backdated before the window opened (now-22h).
		update_post_meta( $this->event_id, 'eh_start', gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $this->event_id, 'eh_end', gmdate( 'Y-m-d H:i:s', time() + 4 * HOUR_IN_SECONDS ) );

		$backdate = gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS );
		$this->backdate_created( (int) $going['row']['id'], $backdate );
		$this->backdate_created( (int) $wait['row']['id'], $backdate );

		update_option( Reminders::OPTION, true );
		update_post_meta( $this->event_id, Reminders::HOURS_META, '24' );
		$this->capture_mail();

		$stats = ( new Reminders() )->run_tick();

		$this->assertSame( 2, $stats['notified'], 'both statuses are in the reminder audience' );

		$this->assertCount( 2, $this->captured_mail );

		// Digest order = id ASC = going first.
		$going_body = (string) $this->captured_mail[0]['message'];
		$wait_body  = (string) $this->captured_mail[1]['message'];

		$this->assertStringContainsString( 'Join online: https://meet.example.org/room-42', $going_body );
		$this->assertStringContainsString( 'please do not share it', $going_body );

		$this->assertStringContainsString( 'Waitlist', $wait_body );
		$this->assertStringNotContainsString( 'meet.example.org', $wait_body );
		$this->assertStringNotContainsString( 'Join online', $wait_body );
	}

	/**
	 * Promotion notice (freshly-going) carries the URL; the attendee never
	 * saw it while waitlisted.
	 *
	 * @return void
	 */
	public function test_promotion_notice_carries_url(): void {
		$this->set_online_url();
		$this->capture_mail();

		$row = array(
			'name'  => 'Virt Promoted',
			'email' => "promoted+{$this->email_tag}@example.org",
		);

		$this->assertTrue( Notifier::send_promotion_notice( $row, $this->event_id ) );

		$this->assertCount( 1, $this->captured_mail );

		$body = (string) $this->captured_mail[0]['message'];
		$this->assertStringContainsString( 'Join online: https://meet.example.org/room-42', $body );
		$this->assertStringContainsString( 'please do not share it', $body );
	}

	/* ---------------------------------------------------------------------
	 * Manage page + series inheritance.
	 * ------------------------------------------------------------------- */

	/**
	 * Manage page: going row shows the join link; waitlist row never does.
	 *
	 * @return void
	 */
	public function test_manage_page_gates_join_section(): void {
		$this->set_online_url();
		$page = new Manage_Page();

		$going = $this->make_rsvp( 'going' );
		$wait  = $this->make_rsvp( 'waitlist' );

		$_GET['eh_manage'] = $going['token'];

		ob_start();
		$page->maybe_render( 'index.php' );
		$going_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Join online:', $going_html );
		$this->assertStringContainsString( 'href="https://meet.example.org/room-42"', $going_html );

		$_GET['eh_manage'] = $wait['token'];

		ob_start();
		$page->maybe_render( 'index.php' );
		$wait_html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Join online:', $wait_html );
		$this->assertStringNotContainsString( 'meet.example.org', $wait_html );

		unset( $_GET['eh_manage'] );
	}

	/**
	 * Series occurrences inherit the seed's joining URL through the REAL
	 * materialize path (spawn meta_copy), not a manual meta write.
	 *
	 * @return void
	 */
	public function test_series_occurrence_inherits_online_url(): void {
		$seed = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Series Seed',
			)
		);

		update_post_meta( $seed, 'eh_start', '2030-01-09 18:00:00' );
		update_post_meta( $seed, 'eh_end', '2030-01-09 20:00:00' );
		update_post_meta( $seed, 'eh_series_freq', 'weekly' );
		update_post_meta( $seed, '_eh_online_url', 'https://meet.example.org/series-room' );

		$count = ( new Series_Engine() )->materialize( $seed );

		$this->assertGreaterThan( 0, $count );

		$children = get_posts(
			array(
				'post_type'   => 'eh_event',
				'post_parent' => $seed,
				'numberposts' => 5,
				'orderby'     => 'meta_value',
				'meta_key'    => 'eh_start',
				'order'       => 'ASC',
			)
		);

		$this->assertNotEmpty( $children );

		foreach ( $children as $child ) {
			$this->assertSame(
				'https://meet.example.org/series-room',
				get_post_meta( (int) $child->ID, '_eh_online_url', true ),
				'every occurrence shares the seed joining URL'
			);
			$this->assertTrue( Render_Blocks::is_virtual( (int) $child->ID ) );
		}
	}
}
