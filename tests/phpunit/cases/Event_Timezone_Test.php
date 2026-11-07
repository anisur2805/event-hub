<?php
/**
 * Per-event timezone regressions: the event-zone wall-clock contract. A
 * stored eh_start / eh_end is interpreted in the event's OWN IANA zone
 * (eh_timezone; '' = site zone — no migration); every consumer parses in
 * that frame, and the SQL windows are widened supersets re-filtered
 * exactly in PHP.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Per-event timezone tests.
 */
class Event_Timezone_Test extends WP_UnitTestCase {

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	private int $event_id = 0;

	/**
	 * Captured wp_mail payloads (pre_wp_mail captor).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $captured = array();

	/**
	 * Creates the event; the site zone is pinned to UTC for determinism.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'timezone_string', 'UTC' );
		update_option( 'gmt_offset', 0 );

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => 'Tz Test Event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->event_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $this->event_id, 'eh_end', '2099-05-01 20:00:00' );
	}

	/**
	 * Restores site zone, options and hooks.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );
		update_option( Reminders::OPTION, false );
		delete_post_meta( $this->event_id, 'eh_rsvp_close_hours' );
		remove_all_filters( 'pre_wp_mail' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Installs the accepting mail captor (prior hooks removed first). The
	 * filter appends to the self::$captured static property — any returned
	 * copy would be stale-empty, so callers assert against the property.
	 *
	 * @return void
	 */
	private static function capture_mail(): void {
		remove_all_filters( 'pre_wp_mail' );

		self::$captured = array();

		add_filter(
			'pre_wp_mail',
			static function ( $pre, array $atts ) {
				self::$captured[] = $atts;

				return true; // Accepted send: stamps may land.
			},
			10,
			2
		);
	}

	/**
	 * Creates a going RSVP on the test event, backdated behind any window.
	 *
	 * @return int Row ID.
	 */
	private function make_backdated_rsvp(): int {
		$row = Plugin::instance()->repository()->create( 'Zone Leg', 'tzleg+' . wp_rand( 1, PHP_INT_MAX ) . '@example.org', 'going', $this->event_id );

		if ( is_wp_error( $row ) ) {
			self::fail( 'RSVP create failed: ' . $row->get_error_message() );
		}

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture backdate.
			$wpdb->prefix . 'eh_rsvps',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ),
			array( 'id' => (int) $row['rsvp']['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		return (int) $row['rsvp']['id'];
	}

	/**
	 * Resolution matrix: missing / empty / junk / whitespace fall back to
	 * the site zone; a valid identifier resolves; read-side leniency never
	 * throws.
	 *
	 * @return void
	 */
	public function test_for_event_resolution_matrix(): void {
		// No meta: site zone (UTC).
		$this->assertSame( 'UTC', Event_Timezone::for_event( $this->event_id )->getName() );

		// Empty meta: site zone.
		update_post_meta( $this->event_id, Event_Timezone::META, '' );
		$this->assertSame( 'UTC', Event_Timezone::for_event( $this->event_id )->getName() );

		// Valid IANA identifier (plus whitespace tolerance).
		update_post_meta( $this->event_id, Event_Timezone::META, 'Asia/Dhaka' );
		$this->assertSame( 'Asia/Dhaka', Event_Timezone::for_event( $this->event_id )->getName() );

		update_post_meta( $this->event_id, Event_Timezone::META, '  Asia/Tokyo  ' );
		$this->assertSame( 'Asia/Tokyo', Event_Timezone::for_event( $this->event_id )->getName() );

		// Unknown identifier: no exception; site-zone fallback.
		update_post_meta( $this->event_id, Event_Timezone::META, 'Mars/Olympus_Mons' );
		$this->assertSame( 'UTC', Event_Timezone::for_event( $this->event_id )->getName() );
	}

	/**
	 * The offset-ISO parser: event-zone wall clock becomes offset-bearing
	 * ISO; DST zones resolve per-date offsets; both storage shapes
	 * accepted; junk wall clocks degrade to ''.
	 *
	 * @return void
	 */
	public function test_to_offset_iso_event_zone(): void {
		$dhaka = new \DateTimeZone( 'Asia/Dhaka' ); // +06:00, no DST.

		// Site format (Y-m-d H:i:s).
		$this->assertSame(
			'2099-05-01T18:00:00+06:00',
			Event_Timezone::to_offset_iso( '2099-05-01 18:00:00', $dhaka )
		);

		// Editor format (Y-m-d\TH:i:s) — the meta_iso consumer contract.
		$this->assertSame(
			'2099-05-01T18:00:00+06:00',
			Event_Timezone::to_offset_iso( '2099-05-01T18:00:00', $dhaka )
		);

		// Site-zone (UTC) events keep the +00:00 shape the old code's
		// format('c') produced — pre-slice rows render byte-identical.
		$this->assertSame(
			'2099-05-01T18:00:00+00:00',
			Event_Timezone::to_offset_iso( '2099-05-01 18:00:00', new \DateTimeZone( 'UTC' ) )
		);

		// DST-real zone: July is CEST (+02:00), January CET (+01:00).
		$berlin = new \DateTimeZone( 'Europe/Berlin' );

		$this->assertSame(
			'2099-07-01T18:00:00+02:00',
			Event_Timezone::to_offset_iso( '2099-07-01 18:00:00', $berlin )
		);
		$this->assertSame(
			'2099-01-01T18:00:00+01:00',
			Event_Timezone::to_offset_iso( '2099-01-01 18:00:00', $berlin )
		);

		// Blank and junk wall clocks degrade to ''.
		$this->assertSame( '', Event_Timezone::to_offset_iso( '   ', $dhaka ) );
		$this->assertSame( '', Event_Timezone::to_offset_iso( 'not a date', $dhaka ) );

		// Out-of-range DIGITS, though, roll over into a valid date
		// (2099-13-40 99:00 → 2100-02-13 03:00) — core
		// date_create_immutable_from_format behavior shared by every
		// parse site; pinned like #58's esc_url_raw repair finding.
		$this->assertSame( '2100-02-13T03:00:00+06:00', Event_Timezone::to_offset_iso( '2099-13-40 99:00:00', $dhaka ) );
	}

	/**
	 * Save-side normalizer: '' passes; valid identifiers pass (trimmed);
	 * junk degrades to '' — never stored as garbage.
	 *
	 * @return void
	 */
	public function test_sanitize_zone(): void {
		$this->assertSame( '', Event_Timezone::sanitize_zone( '' ) );
		$this->assertSame( 'Asia/Dhaka', Event_Timezone::sanitize_zone( 'Asia/Dhaka' ) );
		$this->assertSame( 'Asia/Dhaka', Event_Timezone::sanitize_zone( '  Asia/Dhaka ' ) );
		$this->assertSame( '', Event_Timezone::sanitize_zone( 'Mars/Olympus' ) );
		$this->assertSame( '', Event_Timezone::sanitize_zone( 'javascript:alert(1)' ) );
	}

	/**
	 * The /wp/v2 meta write path normalizes through the registered
	 * sanitize callback: a valid zone persists and reads back; junk
	 * degrades to '' with no 400 (5A.4 twin discipline).
	 *
	 * @return void
	 */
	public function test_rest_meta_round_trip(): void {
		Plugin::instance()->register_meta();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$ok = new \WP_REST_Request( 'POST', "/wp/v2/events-cpt/{$this->event_id}" );
		$ok->set_body_params( array( 'meta' => array( Event_Timezone::META => 'Asia/Dhaka' ) ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $ok )->get_status() );
		$this->assertSame( 'Asia/Dhaka', get_post_meta( $this->event_id, Event_Timezone::META, true ) );

		$read = new \WP_REST_Request( 'GET', "/wp/v2/events-cpt/{$this->event_id}" );
		$read->set_param( 'context', 'edit' );
		$data = rest_get_server()->dispatch( $read )->get_data();

		// The zone is public fact (where/when), carried in meta.
		$this->assertSame( 'Asia/Dhaka', $data['meta'][ Event_Timezone::META ] ?? 'missing-key' );

		$junk = new \WP_REST_Request( 'POST', "/wp/v2/events-cpt/{$this->event_id}" );
		$junk->set_body_params( array( 'meta' => array( Event_Timezone::META => 'Not/AZone' ) ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $junk )->get_status() );
		$this->assertSame( '', get_post_meta( $this->event_id, Event_Timezone::META, true ) );

		// And the published read degrades with it — never stored as junk.
		$data2 = rest_get_server()->dispatch( $read )->get_data();

		$this->assertSame( '', $data2['meta'][ Event_Timezone::META ] ?? 'missing-key' );
	}

	/**
	 * The summary start ISO carries the event-zone offset, and the offset
	 * rides into JSON-LD unchanged (no re-interpretation downstream).
	 *
	 * @return void
	 */
	public function test_summary_start_carries_event_offset(): void {
		update_post_meta( $this->event_id, Event_Timezone::META, 'Asia/Dhaka' );

		$summary = Render_Blocks::summary( $this->event_id );

		$this->assertSame( '2099-05-01T18:00:00+06:00', $summary['start'] );

		// The offset rides into JSON-LD unchanged (no re-interpretation).
		$graph = ( new Structured_Data() )->graph( $summary );

		$this->assertNotNull( $graph );
		$this->assertSame( '2099-05-01T18:00:00+06:00', $graph['startDate'] );
	}

	/**
	 * Display self-containment: a DateTimeImmutable built from the offset
	 * ISO renders the exact wall clock the event zone names (the single
	 * human_range / card display mechanism).
	 *
	 * @return void
	 */
	public function test_offset_iso_renders_event_wall_clock(): void {
		$iso = Event_Timezone::to_offset_iso(
			'2099-05-01 18:00:00',
			new \DateTimeZone( 'Asia/Dhaka' )
		);

		$dt = new \DateTimeImmutable( $iso );

		$this->assertSame( '18:00', $dt->format( 'H:i' ) );
		// 18:00 +06:00 is 12:00 UTC (UTC = Dhaka - 6h).
		$this->assertSame( '12:00', $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'H:i' ) );
	}

	/**
	 * Cutoffs parse the event-zone frame. The stored string reads as a UTC
	 * wall clock (site = UTC) but the meta names Asia/Dhaka (+6): the
	 * event-zone instant is now+5m while the site-frame read is now+6h05m.
	 * A 3h pre-start close therefore SHUTS the event-zone reading and
	 * stays OPEN for the site-frame reading — same bytes, zone decides.
	 *
	 * @return void
	 */
	public function test_cutoffs_use_event_zone(): void {
		update_post_meta( $this->event_id, Event_Timezone::META, 'Asia/Dhaka' );
		update_post_meta( $this->event_id, 'eh_start', gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS ) );

		$repo = new Rsvp_Repository();

		// Zone frame: start instant = now+5m => start - 3h already passed.
		update_post_meta( $this->event_id, 'eh_rsvp_close_hours', '3' );
		$this->assertTrue( $repo->rsvp_cutoffs( $this->event_id )['closed'] );

		// Same stored string WITHOUT the zone meta (site frame): start
		// instant = now+6h05m => start - 3h is ahead. Proves the ZONE —
		// not the string — moved the verdict.
		delete_post_meta( $this->event_id, Event_Timezone::META );
		$this->assertFalse( $repo->rsvp_cutoffs( $this->event_id )['closed'] );
	}

	/**
	 * Upcoming window: an end that is PAST on the site wall (site = UTC)
	 * but FUTURE in the event's zone keeps its listing. America/Phoenix is
	 * UTC-7 year-round, so a UTC wall string of "now-30m" is the instant
	 * now+6h30m in Phoenix — still running. A tight site-frame comparison
	 * (the pre-slice behavior) would have dropped it; the widened superset
	 * + event-zone exact verdict keep it.
	 *
	 * @return void
	 */
	public function test_upcoming_window_superset_keeps_zone_future_end(): void {
		update_post_meta( $this->event_id, Event_Timezone::META, 'America/Phoenix' );
		update_post_meta( $this->event_id, 'eh_start', gmdate( 'Y-m-d H:i:s', time() - 90 * MINUTE_IN_SECONDS ) );
		update_post_meta( $this->event_id, 'eh_end', gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) );

		$ids = wp_list_pluck( Render_Blocks::upcoming_events( 200 ), 'id' );

		$this->assertContains( $this->event_id, $ids );
	}

	/**
	 * Month membership follows the event zone. Asia/Tokyo start
	 * 2099-06-01 00:30 is June in Tokyo but May 31 15:30 in UTC: it must
	 * appear in June's calendar and June's list filter, and NOT in May's —
	 * despite the widened SQL windows catching both edges.
	 *
	 * @return void
	 */
	public function test_month_filters_judge_event_zone(): void {
		update_post_meta( $this->event_id, Event_Timezone::META, 'Asia/Tokyo' );
		update_post_meta( $this->event_id, 'eh_start', '2099-06-01 00:30:00' );
		update_post_meta( $this->event_id, 'eh_end', '2099-06-01 02:30:00' );

		$june    = Render_Blocks::calendar_month_events(
			array(
				'year'  => 2099,
				'month' => 6,
			)
		);
		$ids     = wp_list_pluck( $june, 'id' );
		$may     = Render_Blocks::calendar_month_events(
			array(
				'year'  => 2099,
				'month' => 5,
			)
		);
		$ids_may = wp_list_pluck( $may, 'id' );

		$this->assertContains( $this->event_id, $ids );
		$this->assertNotContains( $this->event_id, $ids_may );

		// The REST list path agrees via the same exact pass.
		$ids_list = $this->rest_month_ids( '2099-06' );
		$this->assertContains( $this->event_id, $ids_list );

		$ids_list_may = $this->rest_month_ids( '2099-05' );
		$this->assertNotContains( $this->event_id, $ids_list_may );
	}

	/**
	 * Dispatches GET /event-hub/v1/events?month=… and plucks event IDs.
	 *
	 * @param string $month YYYY-MM filter.
	 * @return array<int,int>
	 */
	private function rest_month_ids( string $month ): array {
		$request = new \WP_REST_Request( 'GET', '/event-hub/v1/events' );
		$request->set_param( 'month', $month );

		$data = rest_get_server()->dispatch( $request )->get_data();

		return wp_list_pluck( is_array( $data ) ? $data : array(), 'id' );
	}

	/**
	 * Reminder sweep reads the event-zone start. The stored string is
	 * "now + 30 minutes" on the Dhaka wall clock: parsed in Dhaka the
	 * start instant is now+30m — INSIDE a 1h reminder window; the same
	 * bytes parsed in the site frame (UTC) are now-5h30m — long gone.
	 * Zone decides who gets reminded; the backdated row makes the window
	 * verdict observable through run_tick()'s notified stat.
	 *
	 * @return void
	 */
	public function test_reminder_sweep_reads_event_zone(): void {
		$dhaka = new \DateTimeZone( 'Asia/Dhaka' );

		// The wall string whose DHAKA reading is now+30m.
		$wall = ( new \DateTimeImmutable( '@' . ( time() + 30 * MINUTE_IN_SECONDS ) ) )
			->setTimezone( $dhaka )
			->format( 'Y-m-d H:i:s' );

		update_post_meta( $this->event_id, Event_Timezone::META, 'Asia/Dhaka' );
		update_post_meta( $this->event_id, 'eh_start', $wall );
		update_post_meta( $this->event_id, Reminders::HOURS_META, '1' );

		update_option( Reminders::OPTION, true );

		self::make_backdated_rsvp();

		// Dhaka frame: start now+30m, window opened now-30m. The backdated
		// row (created -2h) qualifies and the mail is accepted.
		self::capture_mail();
		$stats = ( new Reminders() )->run_tick();

		$this->assertSame( 1, $stats['notified'], 'Dhaka-frame start (now+30m) sits inside the 1h window' );
		$this->assertCount( 1, self::$captured );
		$this->assertSame( __( 'Reminder: Tz Test Event is coming up', 'event-hub' ), self::$captured[0]['subject'] ?? '' );

		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture cleanup.
			$wpdb->prefix . 'eh_rsvps',
			array( 'event_id' => $this->event_id ),
			array( '%d' )
		);

		// Site-frame control: remove the zone so the identical string now
		// reads "a UTC wall clock" — the instant shifts 6 hours back and
		// the event drops out of the window. A fresh backdated row must
		// NOT be reminded. The zone, not the string, filled the window.
		delete_post_meta( $this->event_id, Event_Timezone::META );

		self::make_backdated_rsvp();

		self::capture_mail();
		$stats2 = ( new Reminders() )->run_tick();

		$this->assertSame( 0, $stats2['notified'], 'site-frame start (now-5h30m) is outside the 1h window' );
		$this->assertCount( 0, self::$captured );

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture cleanup.
			$wpdb->prefix . 'eh_rsvps',
			array( 'event_id' => $this->event_id ),
			array( '%d' )
		);
	}

	/**
	 * Series: children inherit eh_timezone via meta copy and keep the
	 * seed's wall time in that zone (weekly at 18:00 stays 18:00 Dhaka).
	 *
	 * @return void
	 */
	public function test_series_children_inherit_zone(): void {
		$seed = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Tz Series Seed',
			)
		);

		update_post_meta( $seed, 'eh_start', '2030-01-02 18:00:00' );
		update_post_meta( $seed, 'eh_end', '2030-01-02 20:00:00' );
		update_post_meta( $seed, 'eh_series_freq', 'weekly' );
		update_post_meta( $seed, 'eh_series_until', '2030-02-28' );
		update_post_meta( $seed, Event_Timezone::META, 'Asia/Dhaka' );

		$count = ( new Series_Engine() )->materialize( $seed );

		$this->assertGreaterThan( 0, $count );

		$children = get_posts(
			array(
				'post_type'   => 'eh_event',
				'post_parent' => $seed,
				'numberposts' => 2,
				'orderby'     => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology sort on a bounded CPT.
				'meta_key'    => 'eh_start',
				'order'       => 'ASC',
			)
		);

		$this->assertNotEmpty( $children );

		$this->assertSame( 'Asia/Dhaka', get_post_meta( (int) $children[0]->ID, Event_Timezone::META, true ) );

		foreach ( $children as $child ) {
			// The child's wall clock stays 18:00 and the summary start
			// now carries the +06:00 offset — the Dhaka frame kept.
			$summary = Render_Blocks::summary( (int) $child->ID );

			$this->assertStringContainsString( 'T18:00:00+06:00', (string) $summary['start'] );
		}
	}
}
