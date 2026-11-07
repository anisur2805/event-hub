<?php
/**
 * ROADMAP 5.5 regressions: event-list search / month / venue filters, the
 * shared upcoming_query_args() builder (block and REST must agree), and the
 * no-JS GET filterform integration.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * 5.5 filter integration tests (real WP_Query, real REST, real block HTML).
 */
class List_Search_Filter_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with start/end title meta.
	 *
	 * @param string $title Event title.
	 * @param string $start Start datetime (site format, '' = none).
	 * @param string $end   End datetime (site format, '' = none).
	 * @return int Post ID.
	 */
	private static function make_event( string $title, string $start = '', string $end = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( '' !== $start ) {
			update_post_meta( $post_id, 'eh_start', $start );
		}

		if ( '' !== $end ) {
			update_post_meta( $post_id, 'eh_end', $end );
		}

		return $post_id;
	}

	/**
	 * Creates a published venue post.
	 *
	 * @param string $title Venue title.
	 * @return int Post ID.
	 */
	private static function make_venue( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'eh_venue',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Shared builder: each filter solo.
	 * ------------------------------------------------------------------- */

	/**
	 * Keyword search matches the title (WP_Query 's'); non-matching and
	 * keywordless events drop out; no keyword = upcoming as before.
	 *
	 * @return void
	 */
	public function test_search_filter_matches_title(): void {
		self::make_event( 'Jazz Night Summer', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		self::make_event( 'Choir Gala', '2099-03-20 18:00:00', '2099-03-20 21:00:00' );

		// Keyword 'jazz' (case-insensitive SQL LIKE): only event A.
		$args   = Render_Blocks::upcoming_query_args( 10, array(), array( 'search' => 'jazz' ) );
		$result = Render_Blocks::upcoming_events( 10, array(), array( 'search' => 'jazz' ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Jazz Night Summer', $result[0]['title'] );
		$this->assertArrayHasKey( 's', $args );

		// No filter: both return.
		$this->assertCount( 2, Render_Blocks::upcoming_events( 10 ) );

		// Empty-string filter behaves like no filter.
		$this->assertCount( 2, Render_Blocks::upcoming_events( 10, array(), array( 'search' => '' ) ) );
	}

	/**
	 * Month filter: events whose START falls in the month (the same
	 * "belongs to its start month" contract as the calendar grid).
	 *
	 * @return void
	 */
	public function test_month_filter_is_a_start_window(): void {
		self::make_event( 'In Month', '2099-03-05 10:00:00', '2099-03-05 12:00:00' );
		self::make_event( 'Starts Before Ends Inside', '2099-02-27 10:00:00', '2099-03-30 23:00:00' );
		self::make_event( 'Next Month', '2099-04-01 10:00:00', '2099-04-01 12:00:00' );
		// No-end event starting in March: passes the upcoming window via the
		// NOT EXISTS branch and must survive the BETWEEN intersection.
		self::make_event( 'No End In Month', '2099-03-15 09:00:00' );

		$result = Render_Blocks::upcoming_events( 10, array(), array( 'month' => '2099-03' ) );

		$titles = wp_list_pluck( $result, 'title' );
		sort( $titles );

		$this->assertSame(
			array( 'In Month', 'No End In Month' ),
			$titles,
			'Only events STARTING in March count; a Feb-start event ending inside March belongs to February, like the calendar grid.'
		);
	}

	/**
	 * Invalid months degrade to "no month filter" (same tolerance as the
	 * GET path): junk, wrong format, month 13 and a bare year all ignore.
	 *
	 * @return void
	 */
	public function test_invalid_month_ignores_the_filter(): void {
		self::make_event( 'March', '2099-03-05 10:00:00', '2099-03-05 12:00:00' );
		self::make_event( 'April', '2099-04-05 10:00:00', '2099-04-05 12:00:00' );

		foreach ( array( 'bogus', '2099-13', '2099', '03-2099', '2099-00' ) as $junk ) {
			$this->assertCount(
				2,
				Render_Blocks::upcoming_events( 10, array(), array( 'month' => $junk ) ),
				"'{$junk}' must not filter"
			);
		}
	}

	/**
	 * Digits select the LINKED venue (eh_venue_id); the legacy free-text
	 * meta on other events never leaks into an ID query.
	 *
	 * @return void
	 */
	public function test_venue_filter_by_linked_id(): void {
		$venue = self::make_venue( 'Linked Hall' );

		$linked = self::make_event( 'Linked Event', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $linked, 'eh_venue_id', (string) $venue );
		update_post_meta( $linked, 'eh_venue', (string) $venue ); // Free text collides with the digits — must NOT match.

		$textual = self::make_event( 'Text Venue Event', '2099-03-12 18:00:00', '2099-03-12 20:00:00' );
		update_post_meta( $textual, 'eh_venue', (string) $venue );

		$result = Render_Blocks::upcoming_events( 10, array(), array( 'venue' => (string) $venue ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Linked Event', $result[0]['title'] );
	}

	/**
	 * Non-digit venue values filter on the legacy free-text meta with an
	 * exact-substring LIKE.
	 *
	 * @return void
	 */
	public function test_venue_filter_by_free_text(): void {
		$venue = self::make_venue( 'Linked Hall' );

		$linked = self::make_event( 'Linked Event', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $linked, 'eh_venue_id', (string) $venue );

		$textual = self::make_event( 'Text Venue Event', '2099-03-12 18:00:00', '2099-03-12 20:00:00' );
		update_post_meta( $textual, 'eh_venue', 'Riverside Barn' );

		$result = Render_Blocks::upcoming_events( 10, array(), array( 'venue' => 'Riverside' ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Text Venue Event', $result[0]['title'] );

		// Full title also matches (LIKE, not equality).
		$this->assertCount( 1, Render_Blocks::upcoming_events( 10, array(), array( 'venue' => 'Riverside Barn' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Shared builder: combined filters intersect.
	 * ------------------------------------------------------------------- */

	/**
	 * Search + month + venue AND together: each alone matches more.
	 *
	 * @return void
	 */
	public function test_combined_filters_intersect(): void {
		$venue = self::make_venue( 'Target Hall' );
		$other = self::make_venue( 'Other Hall' );

		$hit = self::make_event( 'Battle Rap Finale', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $hit, 'eh_venue_id', (string) $venue );

		$wrong_month = self::make_event( 'Battle Rap Warmup', '2099-04-10 18:00:00', '2099-04-10 20:00:00' );
		update_post_meta( $wrong_month, 'eh_venue_id', (string) $venue );

		$wrong_venue = self::make_event( 'Battle Rap Replay', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $wrong_venue, 'eh_venue_id', (string) $other );

		$wrong_words = self::make_event( 'Chess Open', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $wrong_words, 'eh_venue_id', (string) $venue );

		$filters = array(
			'search' => 'battle',
			'month'  => '2099-03',
			'venue'  => (string) $venue,
		);

		// The crucial past-event guard: alone, a venue filter must NEVER
		// surface the window (a past event's venue still matches — the
		// rising event ANDs against the upcoming window).
		$past = self::make_event( 'Battle Rap Past', '2019-03-10 18:00:00', '2019-03-10 20:00:00' );
		update_post_meta( $past, 'eh_venue_id', (string) $venue );

		$result = Render_Blocks::upcoming_events( 10, array(), $filters );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Battle Rap Finale', $result[0]['title'] );
	}

	/* ---------------------------------------------------------------------
	 * Back-compat and freshness of the two-arg signature.
	 * ------------------------------------------------------------------- */

	/**
	 * Existing two-arg callers (widgets, older integrations) behave
	 * identically: no filters = the pre-5.5 query.
	 *
	 * @return void
	 */
	public function test_two_arg_signature_unchanged(): void {
		self::make_event( 'Plus A', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		self::make_event( 'Plus B', '2020-01-01 18:00:00', '2020-01-01 20:00:00' );

		$result = Render_Blocks::upcoming_events( 10, array() );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Plus A', $result[0]['title'] );
	}

	/* ---------------------------------------------------------------------
	 * REST endpoint: the same builder serves /event-hub/v1/events.
	 * ------------------------------------------------------------------- */

	/**
	 * GET /events accepts search/month/venue and returns the same events
	 * the block order does (ONE builder means no drift).
	 *
	 * @return void
	 */
	public function test_rest_events_search_month_venue(): void {
		$venue = self::make_venue( 'Apex Hall' );

		$hit = self::make_event( 'Neon Retro', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $hit, 'eh_venue_id', (string) $venue );

		self::make_event( 'Neon Wrong Hall', '2099-03-11 18:00:00', '2099-03-11 20:00:00' );
		self::make_event( 'Neon Wrong Month', '2099-04-11 18:00:00', '2099-04-11 20:00:00' );

		$request = new \WP_REST_Request( 'GET', '/event-hub/v1/events' );
		$request->set_query_params(
			array(
				'search' => 'neon',
				'month'  => '2099-03',
				'venue'  => (string) $venue,
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Neon Retro', $data[0]['title'] );

		// Venue-only REST request takes the linked-ID path too (only
		// Neon Retro is linked to this venue).
		$request2 = new \WP_REST_Request( 'GET', '/event-hub/v1/events' );
		$request2->set_query_params( array( 'venue' => (string) $venue ) );
		$data2 = rest_get_server()->dispatch( $request2 )->get_data();

		$this->assertCount( 1, $data2 );
		$this->assertSame( 'Neon Retro', $data2[0]['title'] );

		// Invalid month arrives through REST: degrades, returns everything.
		$request3 = new \WP_REST_Request( 'GET', '/event-hub/v1/events' );
		$request3->set_query_params( array( 'month' => '20zz-99' ) );
		$data3 = rest_get_server()->dispatch( $request3 )->get_data();

		$this->assertCount( 3, $data3 );
	}

	/* ---------------------------------------------------------------------
	 * Block form: no-JS GET integration and markup.
	 * ------------------------------------------------------------------- */

	/**
	 * No-JS apply filters server-side: GET vars actually change the SSR
	 * grid (the same builder serves the page load).
	 *
	 * @return void
	 */
	public function test_get_form_filters_apply_server_side(): void {
		$venue = self::make_venue( 'Form Hall' );

		$hit  = self::make_event( 'Form Hit', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		self::make_event( 'Form Miss', '2099-04-10 18:00:00', '2099-04-10 20:00:00' );
		update_post_meta( $hit, 'eh_venue_id', (string) $venue );

		$_GET['eh_q']          = 'Form';
		$_GET['eh_list_month'] = '2099-03';
		$_GET['eh_venue']      = (string) $venue;

		$html = Render_Blocks::render_event_list_block( array() );

		unset( $_GET['eh_q'], $_GET['eh_list_month'], $_GET['eh_venue'] );

		$this->assertStringContainsString( 'Form Hit', $html );
		$this->assertStringNotContainsString( 'Form Miss', $html );
		$this->assertStringContainsString( '1 event found', $html );
	}

	/**
	 * Filter controls render: keyword + month always (with showFilters),
	 * selects only when options exist; every control keeps its GET name.
	 *
	 * @return void
	 */
	public function test_filter_form_markup(): void {
		$venue = self::make_venue( 'Markup Hall' );
		$event = self::make_event( 'Markup Event', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $event, 'eh_venue_id', (string) $venue );

		// hide_empty=true on the PHP side: the term must be ASSIGNED for the
		// category select to render (existing 3.x behavior, kept).
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'eh_event_category',
				'name'     => 'Markups',
				'slug'     => 'markups',
			)
		);
		wp_set_object_terms( $event, array( $term_id ), 'eh_event_category' );

		$html = Render_Blocks::render_event_list_block( array() );

		// Every control + name.
		$this->assertStringContainsString( 'name="eh_category"', $html );
		$this->assertStringContainsString( 'name="eh_venue"', $html );
		$this->assertStringContainsString( 'name="eh_q"', $html );
		$this->assertStringContainsString( 'name="eh_list_month"', $html );

		// Full venue option with the linked venue (title, ID value).
		$this->assertStringContainsString( 'value="' . (string) $venue . '"' , $html );
		$this->assertStringContainsString( 'Markup Hall', $html );

		// GET var state prefills the controls.
		$_GET['eh_q']          = 'keyword hunt';
		$_GET['eh_list_month'] = '2099-03';
		$_GET['eh_venue']      = (string) $venue;

		$html2 = Render_Blocks::render_event_list_block( array() );

		unset( $_GET['eh_q'], $_GET['eh_list_month'], $_GET['eh_venue'] );

		$this->assertStringContainsString( 'value="keyword hunt"', $html2 );
		$this->assertStringContainsString( 'value="2099-03"', $html2 );
		$this->assertStringContainsString( '<option value="' . (string) $venue . '"  selected=\'selected\'>', $html2 );
	}

	/**
	 * showFilters=false keeps today's behavior: no form at all, and no
	 * venue-option re-query cost either.
	 *
	 * @return void
	 */
	public function test_filterless_block_renders_no_form(): void {
		$event = self::make_event( 'Plain Event', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		update_post_meta( $event, 'eh_venue_id', (string) self::make_venue( 'Unused Hall' ) );

		$html = Render_Blocks::render_event_list_block( array( 'showFilters' => false ) );

		$this->assertStringNotContainsString( 'filterform', $html );
		$this->assertStringNotContainsString( 'name="eh_q"', $html );
		$this->assertStringNotContainsString( 'data-eh-card-tpl', $html );
		$this->assertStringContainsString( 'Plain Event', $html );
	}

	/**
	 * Search works term-free: with zero categories and zero linked venues
	 * the form still shows (search needs no terms), and the card template
	 * exists for the client swap.
	 *
	 * @return void
	 */
	public function test_search_only_form_without_options(): void {
		self::make_event( 'Solo Event', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );

		$html = Render_Blocks::render_event_list_block( array() );

		$this->assertStringContainsString( 'name="eh_q"', $html );
		$this->assertStringContainsString( 'data-eh-card-tpl', $html );
		$this->assertStringNotContainsString( 'name="eh_venue"', $html );
	}

	/**
	 * Calendar isolation: the list block must ignore the calendar's
	 * ?eh_month nav var (a calendar elsewhere on the page navigates with
	 * it; sharing would silently month-filter the list).
	 *
	 * @return void
	 */
	public function test_list_ignores_calendar_month_var(): void {
		self::make_event( 'March Cal', '2099-03-10 18:00:00', '2099-03-10 20:00:00' );
		self::make_event( 'June Cal', '2099-06-10 18:00:00', '2099-06-10 20:00:00' );

		$_GET['eh_month'] = '2099-03';

		$html = Render_Blocks::render_event_list_block( array() );

		unset( $_GET['eh_month'] );

		$this->assertStringContainsString( 'March Cal', $html );
		$this->assertStringContainsString( 'June Cal', $html );
		$this->assertStringContainsString( '2 events found', $html );
	}
}
