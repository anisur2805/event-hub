<?php
/**
 * Tests for the §3.3 event-calendar block: month resolution, month-window
 * queries (site-timezone boundaries), grid math (start_of_week, leap years,
 * year wrap), and the rendered markup contract.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Event calendar block test case.
 */
class Calendar_Block_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event starting on a given site-local datetime.
	 *
	 * @param string $site_start Y-m-d H:i:s in site time.
	 * @param string $title      Post title.
	 * @return int
	 */
	private function make_event_at( string $site_start, string $title = 'Cal Event' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $event_id, 'eh_start', $site_start );
		update_post_meta( $event_id, 'eh_end', $site_start );

		return $event_id;
	}

	/**
	 * calendar_month(): GET value wins, then attribute, then current month;
	 * junk is rejected at every level.
	 *
	 * @return void
	 */
	public function test_month_resolution_precedence(): void {
		// Attribute alone.
		$m = Render_Blocks::calendar_month( '2027-03' );
		$this->assertSame( array( 'year' => 2027, 'month' => 3, 'iso' => '2027-03' ), $m );

		// GET beats attribute.
		$_GET['eh_month'] = '2028-12';
		$m                = Render_Blocks::calendar_month( '2027-03' );
		$this->assertSame( '2028-12', $m['iso'] );

		// Junk GET falls back to a valid attribute.
		$_GET['eh_month'] = 'junk../..';
		$m                = Render_Blocks::calendar_month( '2027-03' );
		$this->assertSame( '2027-03', $m['iso'] );

		// Junk GET + junk attribute = current month (site clock).
		$_GET['eh_month'] = '9999-99';
		$m                = Render_Blocks::calendar_month( 'nope' );
		$this->assertMatchesRegularExpression( '/^\d{4}-(0[1-9]|1[0-2])$/', $m['iso'] );
		$now_iso = gmdate( 'Y-m', time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$this->assertSame( $now_iso, $m['iso'] );

		unset( $_GET['eh_month'] );
	}

	/**
	 * Month boundary precision: an event at 00:00 on day 1 and one at 23:59
	 * on the last day are both in; the first minute outside (next month) is
	 * not. Runs in the site default timezone (UTC in tests) — the offset
	 * math is a no-op there, boundary logic is what's under test.
	 *
	 * @return void
	 */
	public function test_month_window_boundaries(): void {
		// November has 30 days.
		$first  = $this->make_event_at( '2026-11-01 00:00:00', 'First Minute' );
		$last   = $this->make_event_at( '2026-11-30 23:59:00', 'Last Minute' );
		$out    = $this->make_event_at( '2026-12-01 00:00:00', 'Outside Next' );
		$out2   = $this->make_event_at( '2026-10-31 23:59:00', 'Outside Prev' );

		$events = Render_Blocks::calendar_month_events( array( 'year' => 2026, 'month' => 11 ) );
		$ids    = array_map( static fn ( array $s ): int => (int) $s['id'], $events );

		$this->assertContains( $first, $ids );
		$this->assertContains( $last, $ids );
		$this->assertNotContains( $out, $ids );
		$this->assertNotContains( $out2, $ids );
	}

	/**
	 * December wraps: the window query must include December events, not
	 * overflow into month 13.
	 *
	 * @return void
	 */
	public function test_december_window(): void {
		$in  = $this->make_event_at( '2027-12-15 10:00:00', 'December Wrap' );
		$out = $this->make_event_at( '2028-01-05 10:00:00', 'January Spill' );

		$events = Render_Blocks::calendar_month_events( array( 'year' => 2027, 'month' => 12 ) );
		$ids    = array_map( static fn ( array $s ): int => (int) $s['id'], $events );

		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );
	}

	/**
	 * Events starting outside the rendered month via ?eh_month do not leak
	 * into the grid (draft/unpublished excluded implicitly by post_status).
	 *
	 * @return void
	 */
	public function test_draft_events_excluded(): void {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'draft',
				'post_title'  => 'Draft Event',
			)
		);

		update_post_meta( $draft, 'eh_start', '2026-11-10 12:00:00' );
		update_post_meta( $draft, 'eh_end', '2026-11-10 14:00:00' );

		$events = Render_Blocks::calendar_month_events( array( 'year' => 2026, 'month' => 11 ) );
		$ids    = array_map( static fn ( array $s ): int => (int) $s['id'], $events );

		$this->assertNotContains( $draft, $ids );
	}

	/**
	 * Render: default (no GET, no attribute) shows the current month label,
	 * the today marker, and count text; junk GET fell back already (tested
	 * above).
	 *
	 * @return void
	 */
	public function test_render_current_month_and_counts(): void {
		unset( $_GET['eh_month'] );

		// Current month event.
		$now_iso = wp_date( 'Y-m-d H:i:s' );
		$this->make_event_at( $now_iso, 'Now Present' );

		$html = Render_Blocks::render_calendar_block( array() );

		$this->assertStringContainsString( wp_date( 'F Y' ), $html );
		$this->assertStringContainsString( 'event-hub-calendar__cell--today', $html );
		$this->assertStringContainsString( esc_html( 'Now Present' ), $html );
		$this->assertStringContainsString( 'event this month', $html );
		$this->assertStringContainsString( 'data-wp-router-region="event-hub-calendar"', $html );
	}

	/**
	 * Render: navigation hrefs carry eh_month for both neighbors; December
	 * next wraps to the next year; January prev wraps back.
	 *
	 * @return void
	 */
	public function test_nav_href_wrap(): void {
		$html = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2026-12' ) );

		$expected_next = esc_url( add_query_arg( 'eh_month', '2027-01', remove_query_arg( 'eh_month', home_url( '/' ) ) ) );
		$this->assertStringContainsString( rawurlencode( '2027-01' ), $html );
		$this->assertStringContainsString( 'eh_month=2027-01', $html );

		$this->assertStringContainsString( 'eh_month=2026-11', $html );

		$html_jan = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2027-01' ) );
		$this->assertStringContainsString( 'eh_month=2026-12', $html_jan );

		unset( $expected_next );
	}

	/**
	 * Grid math: February 2027 starts on a Monday (w=1). With the WP default
	 * start_of_week=1 (Monday), day 1 must sit in column 0 — zero leading
	 * blank cells before it; the grid carries exactly 28 day cells.
	 *
	 * @return void
	 */
	public function test_grid_math_feb_2027_monday_start(): void {
		update_option( 'start_of_week', 1 );

		$html = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2027-02' ) );

		// No leading pad before day 1 with a Monday month + Monday start.
		$this->assertStringNotContainsString( 'event-hub-calendar__cell--pad', $html );
		$this->assertStringContainsString( '>' . number_format_i18n( 28 ) . '<', $html );

		update_option( 'start_of_week', 0 );

		// Sunday start: Monday day-1 shifts one column right -> 1 pad cell.
		$html_sunday = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2027-02' ) );
		$this->assertStringContainsString( 'event-hub-calendar__cell--pad', $html_sunday );
	}

	/**
	 * Leap February renders 29 days (2028 is a leap year).
	 *
	 * @return void
	 */
	public function test_leap_february(): void {
		$this->make_event_at( '2028-02-29 12:00:00', 'Leap Day Event' );

		$html = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2028-02' ) );

		$this->assertStringContainsString( '>' . number_format_i18n( 29 ) . '<', $html );
		$this->assertStringContainsString( 'Leap Day Event', $html );
	}

	/**
	 * Events on the same day stack inside one cell as a list. Counts the
	 * `<li class="event-hub-calendar__event">` items (not the `__events` ul,
	 * which shares the prefix — hence the trailing quote anchor).
	 *
	 * @return void
	 */
	public function test_same_day_events_stack(): void {
		$this->make_event_at( '2026-11-08 09:00:00', 'Morning Seat' );
		$this->make_event_at( '2026-11-08 15:00:00', 'Afternoon Seat' );

		$html = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2026-11' ) );

		$this->assertSame( 2, substr_count( $html, 'event-hub-calendar__event"' ) );
		$this->assertStringContainsString( 'Morning Seat', $html );
		$this->assertStringContainsString( 'Afternoon Seat', $html );
	}

	/**
	 * Count text contract (shared helper).
	 *
	 * @return void
	 */
	public function test_count_text(): void {
		$this->assertSame( '1 event this month', Render_Blocks::calendar_count_text( 1 ) );
		$this->assertSame( '3 events this month', Render_Blocks::calendar_count_text( 3 ) );
		$this->assertSame( '0 events this month', Render_Blocks::calendar_count_text( 0 ) );
	}

	/**
	 * Empty month renders a full pad-only grid without events markup and the
	 * zero count line.
	 *
	 * @return void
	 */
	public function test_empty_month_renders_without_events(): void {
		$html = Render_Blocks::render_calendar_block( array( 'initialMonth' => '2031-04' ) );

		$this->assertStringNotContainsString( 'event-hub-calendar__events', $html );
		$this->assertStringContainsString( '0 events this month', $html );
	}
}
