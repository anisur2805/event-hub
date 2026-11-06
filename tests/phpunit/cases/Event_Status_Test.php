<?php
/**
 * ROADMAP 5.3 regressions: eh_status read path, pill ladder precedence,
 * cancelled-write guard, JSON-LD eventStatus, generation-based status
 * notices, series propagation, and the ICS STATUS property.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP) for the 5.3 event-status slice.
 */
class Event_Status_Test extends WP_UnitTestCase {

	/**
	 * Captured status-changed action payloads.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private static array $status_changed = array();

	/**
	 * Resets state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		self::$status_changed = array();
		update_option( Reminders::OPTION, false );
	}

	/**
	 * Creates a published event with start meta.
	 *
	 * @param string $start 'Y-m-d H:i:s' site time ('' = none).
	 * @return int Post ID.
	 */
	private static function make_event( string $start = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		if ( '' !== $start ) {
			update_post_meta( $post_id, 'eh_start', $start );
		}

		return $post_id;
	}

	/**
	 * Read path: valid values pass; junk/absent read as scheduled.
	 */
	public function test_get_validates_against_the_enum(): void {
		$event = self::make_event();

		$this->assertSame( '', Event_Status::get( $event ), 'absent = scheduled' );

		update_post_meta( $event, Event_Status::META, 'cancelled' );
		$this->assertSame( 'cancelled', Event_Status::get( $event ) );

		update_post_meta( $event, Event_Status::META, 'postponed' );
		$this->assertSame( 'postponed', Event_Status::get( $event ) );

		update_post_meta( $event, Event_Status::META, 'junk' );
		$this->assertSame( '', Event_Status::get( $event ), 'junk = scheduled' );

		update_post_meta( $event, Event_Status::META, '' );
		$this->assertSame( '', Event_Status::get( $event ) );
	}

	/**
	 * The REST meta schema rejects garbage writes (save-time twin of the
	 * read-side validation).
	 *
	 * Core's run_teardown() unregisters ALL meta keys after every test, and
	 * the plugin registers them once on init — so any test issuing /wp/v2
	 * meta requests must re-register first (idempotent). This is a test-
	 * environment artifact only: in production init fires every request.
	 */
	public function test_rest_meta_schema_rejects_invalid_status(): void {
		Plugin::instance()->register_meta();

		$event   = self::make_event();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'POST', "/wp/v2/events-cpt/{$event}" );
		$request->set_body_params( array( 'meta' => array( 'eh_status' => 'banana' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'junk status must fail the meta schema' );

		// The validenum values pass schema and persist.
		$request2 = new \WP_REST_Request( 'POST', "/wp/v2/events-cpt/{$event}" );
		$request2->set_body_params( array( 'meta' => array( 'eh_status' => 'cancelled' ) ) );
		$response2 = rest_get_server()->dispatch( $request2 );

		$this->assertSame( 200, $response2->get_status() );
		$this->assertSame( 'cancelled', get_post_meta( $event, Event_Status::META, true ) );

		wp_set_current_user( 0 );
	}

	/**
	 * cancelled blocks create() with 409 eh_event_cancelled; postponed
	 * stays open (people may still join for the new date).
	 */
	public function test_create_guard_blocks_cancelled_only(): void {
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );

		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$result = Plugin::instance()->repository()->create( 'Probe', 'probe@example.org', 'going', $event );

		$this->assertWPError( $result );
		$this->assertSame( 'eh_event_cancelled', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data( 'eh_event_cancelled' )['status'] ?? 0 );

		update_post_meta( $event, Event_Status::META, 'postponed' );

		$result2 = Plugin::instance()->repository()->create( 'Probe 2', 'probe2@example.org', 'going', $event );

		$this->assertNotWPError( $result2, 'postponed events take RSVPs' );
	}

	/**
	 * Pill ladder: status outranks past/cutoff/capacity states; labels and
	 * keys are the 5.3 vocabulary. Reached through the card inventory
	 * render (the pill markup is inside render_card()).
	 */
	public function test_pill_ladder_status_outranks_past(): void {
		// Past + cancelled: pill must be Cancelled (a cancelled event never
		// "took place").
		$past = self::make_event( gmdate( 'Y-m-d H:i:s', time() - 10 * HOUR_IN_SECONDS ) );
		update_post_meta( $past, Event_Status::META, 'cancelled' );

		$html = Render_Blocks::render_card( Render_Blocks::summary( get_post( $past ) ) );

		$this->assertStringContainsString( 'pill--cancelled', $html );
		$this->assertStringContainsString( 'Cancelled', $html );

		// Upcoming + postponed.
		$soon = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 10 * HOUR_IN_SECONDS ) );
		update_post_meta( $soon, Event_Status::META, 'postponed' );

		$html2 = Render_Blocks::render_card( Render_Blocks::summary( get_post( $soon ) ) );

		$this->assertStringContainsString( 'pill--postponed', $html2 );
		$this->assertStringContainsString( 'Postponed', $html2 );

		// Status outranks a cutoff-closed state.
		update_post_meta( $soon, 'eh_rsvp_close_hours', '4' );
		$html3 = Render_Blocks::render_card( Render_Blocks::summary( get_post( $soon ) ) );

		$this->assertStringContainsString( 'pill--postponed', $html3 );
	}

	/**
	 * rsvpOpen folds to false for both non-scheduled statuses (the form is
	 * gone); postponed keeps the waitlist-relevant surface inert too — the
	 * date is unsettled, signups wait for the announcement.
	 */
	public function test_summary_rsvp_open_folds_for_status(): void {
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );

		$s = Render_Blocks::summary( get_post( $event ) );
		$this->assertTrue( $s['rsvpOpen'], 'sanity: scheduled event is open' );
		$this->assertSame( '', $s['status'] );
		$this->assertArrayHasKey( 'status', $s );

		update_post_meta( $event, Event_Status::META, 'cancelled' );
		$s2 = Render_Blocks::summary( get_post( $event ) );
		$this->assertFalse( $s2['rsvpOpen'] );
		$this->assertSame( 'cancelled', $s2['status'] );

		update_post_meta( $event, Event_Status::META, 'postponed' );
		$s3 = Render_Blocks::summary( get_post( $event ) );
		$this->assertFalse( $s3['rsvpOpen'] );
		$this->assertSame( 'postponed', $s3['status'] );
	}

	/**
	 * The RSVP button section is inert for cancelled (no iAPI region, no
	 * form) and names the state.
	 */
	public function test_rsvp_button_is_inert_for_status(): void {
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );

		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$html = Render_Blocks::render_rsvp_button( $event );

		$this->assertStringNotContainsString( 'data-wp-interactive', $html, 'cancelled events render no interactive region' );
		$this->assertStringContainsString( 'This event has been cancelled.', $html );

		update_post_meta( $event, Event_Status::META, 'postponed' );

		$html2 = Render_Blocks::render_rsvp_button( $event );

		$this->assertStringNotContainsString( 'data-wp-interactive', $html2 );
		$this->assertStringContainsString( 'postponed', $html2 );
	}

	/**
	 * JSON-LD: eventStatus maps the meta; a non-scheduled state drops the
	 * Offer (a cancelled event must not advertise availability) — #36
	 * honesty rule extended.
	 */
	public function test_json_ld_event_status_and_offer_drop(): void {
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, 'eh_capacity', '10' );

		$s     = Render_Blocks::summary( get_post( $event ) );
		$graph = ( new Structured_Data() )->graph( $s );

		$this->assertSame( 'https://schema.org/EventScheduled', $graph['eventStatus'] );
		$this->assertArrayHasKey( 'offers', $graph );

		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$s2     = Render_Blocks::summary( get_post( $event ) );
		$graph2 = ( new Structured_Data() )->graph( $s2 );

		$this->assertSame( 'https://schema.org/EventCancelled', $graph2['eventStatus'] );
		$this->assertArrayNotHasKey( 'offers', $graph2, 'cancelled events must not advertise availability' );

		update_post_meta( $event, Event_Status::META, 'postponed' );

		$s3     = Render_Blocks::summary( get_post( $event ) );
		$graph3 = ( new Structured_Data() )->graph( $s3 );

		$this->assertSame( 'https://schema.org/EventPostponed', $graph3['eventStatus'] );
		$this->assertArrayNotHasKey( 'offers', $graph3 );
	}

	/**
	 * Generation bump: transitions into non-scheduled statuses bump; re-save
	 * of the same status does not; un-cancel fires nothing.
	 */
	public function test_transition_bumps_generation_once_per_real_transition(): void {
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$event = self::make_event();

		update_post_meta( $event, Event_Status::META, 'junk' );
		$this->assertSame( 0, Event_Status::generation( $event ), 'junk write bumps nothing' );

		update_post_meta( $event, Event_Status::META, 'cancelled' );
		$this->assertSame( 1, Event_Status::generation( $event ) );

		// Re-save of the SAME status: update_metadata() short-circuits the
		// no-op write, so the listener never fires.
		update_post_meta( $event, Event_Status::META, 'cancelled' );
		$this->assertSame( 1, Event_Status::generation( $event ), 'same-value write bumps nothing (core no-op path)' );

		// to scheduled (un-cancel): silent by design.
		update_post_meta( $event, Event_Status::META, '' );
		$this->assertSame( 1, Event_Status::generation( $event ), 'un-cancel bumps nothing' );

		// Re-cancel: re-notifies.
		update_post_meta( $event, Event_Status::META, 'cancelled' );
		$this->assertSame( 2, Event_Status::generation( $event ) );

		wp_set_current_user( 0 );
	}

	/**
	 * The status sweep emails rows behind the generation, stamps them with
	 * the current gen on accepted mail only, and re-notifies after
	 * un-cancel + re-cancel. Toggle-off = no sweep.
	 */
	public function test_status_sweep_generational_dedupe(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => true
		);

		update_option( Reminders::OPTION, true );

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );
		Plugin::instance()->repository()->create( 'Gen Probe', 'gen-probe@example.org', 'going', $event );

		update_post_meta( $event, Event_Status::META, 'cancelled' ); // gen 1.

		$engine = new Reminders();
		$stats  = $engine->run_tick();

		$this->assertSame( 1, $stats['status']['sent'], 'first sweep notifies the row' );

		global $wpdb;

		$gen_stamped = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status_notified_gen FROM {$wpdb->prefix}eh_rsvps WHERE email = %s",
				'gen-probe@example.org'
			)
		);

		$this->assertSame( 1, $gen_stamped );

		// Second tick: deduped.
		$this->assertSame( 0, $engine->run_tick()['status']['sent'], 'generation dedupe' );

		// Un-cancel (silent) then re-cancel (gen 2) → re-notify.
		update_post_meta( $event, Event_Status::META, '' );
		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$this->assertSame( 1, $engine->run_tick()['status']['sent'], 're-cancel re-notifies' );
		$this->assertSame( 0, $engine->run_tick()['status']['sent'] );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Stamp-only-on-accept: a refused mail leaves the row unstamped and the
	 * sweep retries.
	 */
	public function test_status_sweep_stamps_only_on_accept(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => false
		);

		update_option( Reminders::OPTION, true );

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );
		Plugin::instance()->repository()->create( 'Stamp Probe', 'stamp-probe@example.org', 'going', $event );

		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$engine = new Reminders();
		$stats  = $engine->run_tick();

		$this->assertSame( 0, $stats['status']['sent'] );

		global $wpdb;

		$gen = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status_notified_gen FROM {$wpdb->prefix}eh_rsvps WHERE email = %s",
				'stamp-probe@example.org'
			)
		);

		$this->assertSame( 0, $gen, 'refused mail must not stamp' );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * The reminder stage never mails a cancelled event ("coming up" to a
	 * cancelled event would be absurd).
	 */
	public function test_reminder_stage_skips_cancelled_events(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => true
		);

		update_option( Reminders::OPTION, true );

		// Event inside its reminder window (start +2h, window 24h).
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '24' );

		$result = Plugin::instance()->repository()->create( 'Rem Skip', 'rem-skip@example.org', 'going', $event );
		$this->assertNotWPError( $result );

		global $wpdb;

		$row_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}eh_rsvps WHERE email = %s", 'rem-skip@example.org' )
		);

		// Created 30h ago = a genuine reminder target (before window open).
		$wpdb->update(
			$wpdb->prefix . 'eh_rsvps',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);

		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$stats = ( new Reminders() )->run_tick();

		$this->assertSame( 0, $stats['notified'], 'cancelled event mails no reminders' );
		$this->assertSame( 1, $stats['status']['sent'], 'status notice still reaches the row' );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Series propagation: children follow the seed both ways; each child
	 * bumps its own generation (sweeps its own rows).
	 */
	public function test_series_children_follow_seed_status(): void {
		update_option( 'event_hub_confirmations', false );
		update_option( Reminders::OPTION, false );

		$seed = self::make_event( gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $seed, 'eh_series_freq', 'weekly' );

		// Spawn occurrences through the engine's own path (returns the count,
		// not an ID — fetch a real child directly).
		$engine   = new Series_Engine();
		$spawned  = $engine->materialize( $seed );

		$this->assertGreaterThan( 0, $spawned, 'materialize spawned occurrences' );

		$children = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'post_parent'    => $seed,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$this->assertNotEmpty( $children, 'seed has at least one occurrence child' );

		$child_id = (int) $children[0];

		update_post_meta( $seed, Event_Status::META, 'cancelled' );

		$this->assertSame( 'cancelled', Event_Status::get( (int) $child_id ), 'child inherits the seed status' );
		$this->assertSame( 1, Event_Status::generation( (int) $child_id ), 'child bumps its own generation' );

		// Un-cancel: children follow back to scheduled.
		update_post_meta( $seed, Event_Status::META, '' );

		$this->assertSame( '', Event_Status::get( (int) $child_id ), 'un-cancel propagates' );
	}

	/**
	 * ICS: cancelled events carry STATUS:CANCELLED and the CANCELLED:
	 * summary prefix; postponed flags the summary; scheduled unchanged.
	 */
	public function test_ics_status_property_and_summary_flags(): void {
		$soon  = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS ) );
		update_post_meta( $soon, 'eh_end', gmdate( 'Y-m-d H:i:s', time() + 26 * HOUR_IN_SECONDS ) );

		$exporter = new Ics_Exporter();

		// Sanity: scheduled has no STATUS line.
		$plain = $exporter->build( array( $soon ) );
		$this->assertStringNotContainsString( 'STATUS:', $plain );

		update_post_meta( $soon, Event_Status::META, 'cancelled' );

		$cancelled = $exporter->build( array( $soon ) );

		$this->assertStringContainsString( 'STATUS:CANCELLED', $cancelled );
		$this->assertStringContainsString( 'SUMMARY:CANCELLED:', $cancelled );

		update_post_meta( $soon, Event_Status::META, 'postponed' );

		$postponed = $exporter->build( array( $soon ) );

		$this->assertStringNotContainsString( 'STATUS:', $postponed );
		$this->assertStringContainsString( 'POSTPONED', $postponed );
	}

	/**
	 * Cancelled events stay OUT of the reminder sweep's target list even
	 * when other events are in-window (defence in depth under the same
	 * get_posts shape).
	 */
	public function test_events_in_window_excludes_cancelled(): void {
		$engine = new Reminders();
		$method = new \ReflectionMethod( $engine, 'events_in_window' );
		$method->setAccessible( true );

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '24' );

		$this->assertContains( $event, $method->invoke( $engine ), 'sanity: scheduled + in-window present' );

		update_post_meta( $event, Event_Status::META, 'cancelled' );

		$this->assertNotContains( $event, $method->invoke( $engine ) );
	}
}
