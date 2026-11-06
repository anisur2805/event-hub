<?php
/**
 * ROADMAP 5.2 regressions: reminder cron sweep, windows, stamping, and the
 * notification action surfaces (created / cancelled).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP) for Reminders + notification wiring.
 */
class Reminders_Test extends WP_UnitTestCase {

	/**
	 * Captured cancelled-action payloads.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private static array $cancelled = array();

	/**
	 * Captured created-action payloads.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private static array $created = array();

	/**
	 * Resets captured actions and the notifications toggle before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		self::$cancelled = array();
		self::$created   = array();

		update_option( Reminders::OPTION, false );
		delete_post_meta( 0, 'eh_reminder_hours' ); // no-op shape guard.
	}

	/**
	 * Creates an event with start meta.
	 *
	 * @param string $start 'Y-m-d H:i:s' site time ('' = none).
	 * @return int Post ID.
	 */
	private static function make_event( string $start ): int {
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
	 * Creates an RSVP row directly through the repository (bypasses REST).
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $created  Row created_at override ('' = now).
	 * @param string $status   Row status.
	 * @return int Row ID.
	 */
	private function make_rsvp( int $event_id, string $created = '', string $status = 'going' ): int {
		$result = Plugin::instance()->repository()->create( 'Attendee', "a-{$event_id}-" . wp_rand( 1, PHP_INT_MAX ) . '@example.org', $status, $event_id );

		if ( is_wp_error( $result ) ) {
			self::fail( 'RSVP create failed: ' . $result->get_error_message() );
		}

		$row_id = (int) $result['rsvp']['id'];

		if ( '' !== $created ) {
			global $wpdb;

			$wpdb->update(
				$wpdb->prefix . 'eh_rsvps',
				array( 'created_at' => $created ),
				array( 'id' => $row_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $row_id;
	}

	/**
	 * Window parsing: default 24, digits; '0' disables; junk falls back to
	 * the default (the read-side twin of the save-time pattern).
	 */
	public function test_window_hours_parsing(): void {
		$engine  = new Reminders();
		$event   = self::make_event( '' );
		$reflect = new \ReflectionMethod( $engine, 'window_hours' );
		$reflect->setAccessible( true );

		$this->assertSame( 24, $reflect->invoke( $engine, $event ), 'absent meta = default' );

		update_post_meta( $event, Reminders::HOURS_META, '48' );
		$this->assertSame( 48, $reflect->invoke( $engine, $event ) );

		update_post_meta( $event, Reminders::HOURS_META, '0' );
		$this->assertSame( 0, $reflect->invoke( $engine, $event ), "'0' = reminders off" );

		update_post_meta( $event, Reminders::HOURS_META, 'abc' );
		$this->assertSame( 24, $reflect->invoke( $engine, $event ), 'junk = default' );

		update_post_meta( $event, Reminders::HOURS_META, '999' );
		$this->assertSame( 168, $reflect->invoke( $engine, $event ), 'above 168 clamps' );

		update_post_meta( $event, Reminders::HOURS_META, '-5' );
		$this->assertSame( 24, $reflect->invoke( $engine, $event ), 'negative = junk' );
	}

	/**
	 * Core sweep: a going row older than the window is reminded exactly once
	 * (re-run is a no-op) and the stamp survives; mail is disabled in the
	 * test environment (wp_mail returns void/false path) so the row must NOT
	 * stamp — proving stamp-only-on-accept.
	 */
	public function test_tick_never_stamps_when_mail_not_accepted(): void {
		// wp_mail is a pluggable no-op (null) in core's test bootstrap unless
		// overridden — treat "not an explicit true" as not-accepted.
		add_filter(
			'pre_wp_mail',
			static fn () => false
		);

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '24' ); // window opens now - 0s … event in +2h.
		self::make_rsvp( $event );

		$engine = new Reminders();
		$stats  = $engine->run_tick();

		$this->assertSame( 0, $stats['notified'], 'unaccepted mail must not count as notified' );

		global $wpdb;

		$unstamped = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eh_rsvps WHERE event_id = %d AND reminded_at IS NULL", $event )
		);

		$this->assertSame( 1, $unstamped, 'row must remain unstamped for the next tick to retry' );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Full success path: accepted mail stamps the row; a second tick sends
	 * nothing (one-shot). Fresh rows (created after window open) are skipped
	 * and stamped.
	 */
	public function test_tick_stamps_on_accept_and_skips_fresh_rows(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => true
		);

		update_option( Reminders::OPTION, true );

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '24' );

		// Created 30h ago = before the window opened (start +2h, window 24h
		// → opens at −22h): a genuine reminder target.
		$old_row = self::make_rsvp( $event, gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) );

		// Created "1 minute ago" — INSIDE the reminder window: skipped and
		// stamped, never emailed (its confirmation already said when).
		$fresh_row = self::make_rsvp( $event, gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );

		$engine = new Reminders();
		$stats  = $engine->run_tick();

		$this->assertSame( 1, $stats['notified'], 'only the pre-window row is reminded' );

		global $wpdb;

		$stamp_old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reminded_at FROM {$wpdb->prefix}eh_rsvps WHERE id = %d", $old_row ) );
		$stamp_new = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reminded_at FROM {$wpdb->prefix}eh_rsvps WHERE id = %d", $fresh_row ) );

		$this->assertNotSame( '', $stamp_old );
		$this->assertNotSame( '', $stamp_new );

		// One-shot: nothing left to do.
		$this->assertSame( 0, $engine->run_tick()['notified'] );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Fresh-signup skip: a row created INSIDE the reminder window (after the
	 * window opened) is never emailed — its confirmation already told it
	 * when the event starts. It is stamped so future ticks ignore it.
	 */
	public function test_fresh_signup_inside_window_is_skipped_and_stamped(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => true
		);

		update_option( Reminders::OPTION, true );

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '1' ); // window opens -30min.

		// Created 10 minutes ago = inside the window.
		self::make_rsvp( $event, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) );

		$stats = ( new Reminders() )->run_tick();

		global $wpdb;

		$this->assertSame( 0, $stats['notified'], 'inside-window signup must not be emailed' );

		$unstamped = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eh_rsvps WHERE event_id = %d AND reminded_at IS NULL", $event )
		);

		$this->assertSame( 0, $unstamped, 'skipped row is stamped so ticks never re-examine it' );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * Disabled event ('0') and toggle-off produce zero work.
	 */
	public function test_toggle_and_zero_window_disable_the_sweep(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => true
		);

		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '24' );
		self::make_rsvp( $event, gmdate( 'Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS ) );

		// Toggle off (default from setUp) → no work even with a due row.
		$this->assertSame( 0, ( new Reminders() )->run_tick()['notified'] );

		// Toggle on but event opts out ('0').
		update_option( Reminders::OPTION, true );
		update_post_meta( $event, Reminders::HOURS_META, '0' );
		$this->assertSame( 0, ( new Reminders() )->run_tick()['notified'] );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * The created action fires on the REST path (organizer notice rides it),
	 * carrying the row + event ID.
	 */
	public function test_created_action_fires_on_rest_post(): void {
		add_action(
			'event_hub_rsvp_created',
			static function ( array $rsvp, int $event_id ): void {
				self::$created[] = array( $rsvp, $event_id );
			},
			10,
			2
		);

		$event_id = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );

		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'eventId'   => $event_id,
					'name'      => 'Action Probe',
					'email'     => 'action-probe@example.org',
					'status'    => 'going',
					'formToken' => Form_Guard::issue_token( $event_id, time() - 10 ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, self::$created );
		$this->assertSame( 'Action Probe', self::$created[0][0]['name'] );
	}

	/**
	 * The cancelled action fires from delete_and_promote (the cancel-notice
	 * ride-along), carrying the removed row.
	 */
	public function test_cancelled_action_fires_on_delete(): void {
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS ) );
		$row   = self::make_rsvp( $event );

		add_action(
			'event_hub_rsvp_cancelled',
			static function ( array $deleted, int $event_id ): void {
				self::$cancelled[] = array( $deleted, $event_id );
			},
			10,
			2
		);

		Plugin::instance()->repository()->delete_and_promote( $row );

		$this->assertCount( 1, self::$cancelled );
		$this->assertSame( $row, (int) self::$cancelled[0][0]['id'] );
		$this->assertSame( $event, self::$cancelled[0][1] );
	}
}
