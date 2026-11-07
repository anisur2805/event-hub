<?php
/**
 * Tests for the PROBLEM-3 redesigned card: date block, status pill
 * precedence, capacity bar ARIA values, and past-event state.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Card render test case.
 */
class Render_Blocks_Card_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with start/end/capacity meta.
	 *
	 * @param string        $start    Start datetime (site format).
	 * @param string        $end      End datetime (site format).
	 * @param string        $capacity Capacity meta ('' = unlimited).
	 * @param array{int:int} $counts  RSVP counts to seed directly.
	 * @return int Post ID.
	 */
	private function make_event(
		string $start,
		string $end = '',
		string $capacity = '',
		array $counts = array(
			'going'    => 0,
			'waitlist' => 0,
		)
	): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Card Event',
			)
		);

		update_post_meta( $post_id, 'eh_start', $start );
		update_post_meta( $post_id, 'eh_end', $end );
		update_post_meta( $post_id, 'eh_capacity', $capacity );

		if ( $counts['going'] > 0 || $counts['waitlist'] > 0 ) {
			self::seed_rsvps( $post_id, $counts['going'], $counts['waitlist'] );
		}

		return $post_id;
	}

	/**
	 * Inserts going/waitlist RSVP rows through the repository.
	 *
	 * @param int $post_id  Event ID.
	 * @param int $going    Going rows.
	 * @param int $waitlist Waitlist rows.
	 * @return void
	 */
	private static function seed_rsvps( int $post_id, int $going, int $waitlist ): void {
		$repo = Plugin::instance()->repository();

		for ( $i = 0; $i < $going; $i++ ) {
			$repo->create(
				sprintf( 'Going %d', $i ),
				sprintf( 'going%d@example.com', $i ),
				'going',
				$post_id
			);
		}

		for ( $i = 0; $i < $waitlist; $i++ ) {
			$repo->create(
				sprintf( 'Wait %d', $i ),
				sprintf( 'wait%d@example.com', $i ),
				'waitlist',
				$post_id
			);
		}
	}

	/**
	 * Open pill on a fresh event with capacity far from full.
	 *
	 * @return void
	 */
	public function test_open_pill_when_plenty_of_spots(): void {
		$id  = $this->make_event(
			'2099-05-01 18:00:00',
			'2099-05-01 20:00:00',
			'100',
			array(
				'going'    => 10,
				'waitlist' => 0,
			)
		);
		$out = Render_Blocks::render_card( Render_Blocks::summary( $id ) );

		$this->assertStringContainsString( 'event-hub-card__pill--open', $out );
		$this->assertStringContainsString( '>Open</p>', $out );
		$this->assertStringNotContainsString( 'event-hub-card--past', $out );
	}

	/**
	 * Few spots left at >= 80% fill; sold out at 100% with no waitlist.
	 *
	 * @return void
	 */
	public function test_few_and_soldout_pills(): void {
		$few = $this->make_event(
			'2099-06-01 18:00:00',
			'2099-06-01 20:00:00',
			'10',
			array(
				'going'    => 8,
				'waitlist' => 0,
			)
		);
		$out = Render_Blocks::render_card( Render_Blocks::summary( $few ) );
		$this->assertStringContainsString( 'event-hub-card__pill--few', $out );

		$full = $this->make_event(
			'2099-07-01 18:00:00',
			'2099-07-01 20:00:00',
			'5',
			array(
				'going'    => 5,
				'waitlist' => 0,
			)
		);
		$out = Render_Blocks::render_card( Render_Blocks::summary( $full ) );
		$this->assertStringContainsString( 'event-hub-card__pill--soldout', $out );
	}

	/**
	 * Full event with waitlist takers shows the Waitlist pill.
	 *
	 * @return void
	 */
	public function test_waitlist_pill_when_full_with_takers(): void {
		$id  = $this->make_event(
			'2099-08-01 18:00:00',
			'2099-08-01 20:00:00',
			'2',
			array(
				'going'    => 2,
				'waitlist' => 3,
			)
		);
		$out = Render_Blocks::render_card( Render_Blocks::summary( $id ) );

		$this->assertStringContainsString( 'event-hub-card__pill--waitlist', $out );
		$this->assertStringContainsString( 'Join waitlist', $out );
	}

	/**
	 * Past events: Past pill, card modifier class, no RSVP button, closed
	 * notice instead.
	 *
	 * @return void
	 */
	public function test_past_event_pill_and_closed_notice(): void {
		$id  = $this->make_event(
			'2020-01-01 18:00:00',
			'2020-01-01 20:00:00',
			'10',
			array(
				'going'    => 1,
				'waitlist' => 0,
			)
		);
		$out = Render_Blocks::render_card( Render_Blocks::summary( $id ) );

		$this->assertStringContainsString( 'event-hub-card__pill--past', $out );
		$this->assertStringContainsString( 'event-hub-card--past', $out );
		$this->assertStringContainsString( 'has already taken place', $out );
		$this->assertStringNotContainsString( 'data-wp-on--click', $out );
	}

	/**
	 * Capacity bar: correct ARIA values and fill width, capped at 100.
	 *
	 * @return void
	 */
	public function test_capacity_bar_aria_and_width(): void {
		$id  = $this->make_event(
			'2099-09-01 18:00:00',
			'2099-09-01 20:00:00',
			'4',
			array(
				'going'    => 6,
				'waitlist' => 0,
			)
		);
		$out = Render_Blocks::render_card( Render_Blocks::summary( $id ) );

		$this->assertStringContainsString( 'role="progressbar"', $out );
		$this->assertStringContainsString( 'aria-valuemax="4"', $out );
		// Repository capacity enforcement: only 4 of the 6 seeded going RSVPs
		// are accepted, so the rendered values sit exactly at 100%.
		$this->assertStringContainsString( 'aria-valuenow="4"', $out );
		$this->assertStringContainsString( 'width:100%', $out );
		$this->assertStringContainsString( 'aria-valuetext="4 of 4 spots filled"', $out );
	}

	/**
	 * No capacity meta: no progressbar markup at all.
	 *
	 * @return void
	 */
	public function test_no_bar_without_capacity(): void {
		$id  = $this->make_event( '2099-10-01 18:00:00', '2099-10-01 20:00:00' );
		$out = Render_Blocks::render_card( Render_Blocks::summary( $id ) );

		$this->assertStringNotContainsString( 'role="progressbar"', $out );
		$this->assertStringContainsString( 'event-hub-card__pill--open', $out );
	}

	/**
	 * Date block renders day and month spans from the start meta.
	 *
	 * @return void
	 */
	public function test_date_block_day_month(): void {
		$id  = $this->make_event( '2099-03-15 18:00:00', '2099-03-15 20:00:00' );
		$out = Render_Blocks::render_card( Render_Blocks::summary( $id ) );

		$this->assertStringContainsString( 'event-hub-card__day">15', $out );
		$this->assertStringContainsString( 'event-hub-card__month">', $out );
		$this->assertStringContainsString( 'aria-hidden="true"', $out );
	}

	/**
	 * 5A.1 success panel SSR contract: the region renders hidden with an
	 * empty manageUrl server default, the panel directives are present,
	 * and NO token ever appears in server markup (the 201 body is the only
	 * token channel; the store builds the client-side URL).
	 *
	 * @return void
	 */
	public function test_success_panel_hidden_and_tokenless(): void {
		$id  = $this->make_event( '2099-03-15 18:00:00', '2099-03-15 20:00:00' );
		$out = Render_Blocks::render_rsvp_button( $id );

		// Panel region + directives are in the markup.
		$this->assertStringContainsString( 'event-hub-rsvp__success', $out );
		$this->assertStringContainsString( 'data-wp-bind--hidden', $out );
		$this->assertStringContainsString( 'actions.copyManageLink', $out );

		// Server default keeps it hidden and tokenless.
		$this->assertStringContainsString( '"manageUrl":""', $out );
		$this->assertStringContainsString( '"successTitle":""', $out );
		$this->assertStringContainsString( 'context.successTitle', $out );
		$this->assertStringNotContainsString( 'eh_manage', $out );

		// The seeded client strings are present in the state.
		$this->assertStringContainsString( 'Keep this link', $out );
	}

	/* ---------------------------------------------------------------------
	 * 5A.3: add-to-calendar links on the single-event view.
	 * ------------------------------------------------------------------- */

	/**
	 * An upcoming event with start + venue renders both provider links with
	 * the event's data encoded (rawurlencode per value; datetimes in each
	 * provider's documented UTC form).
	 *
	 * @return void
	 */
	public function test_calendar_links_render_for_upcoming_event(): void {
		$id  = $this->make_event( '2099-05-01 18:00:00', '2099-05-01 20:00:00' );
		self::factory()->post->update_object(
			$id,
			array( 'post_title' => 'Launch Night & Signals' )
		);
		update_post_meta( $id, 'eh_venue', 'Main Hall' );

		$out = Render_Blocks::calendar_links( Render_Blocks::summary( $id ) );

		$this->assertStringContainsString( 'https://calendar.google.com/calendar/render?', $out );
		$this->assertStringContainsString( 'action=TEMPLATE', $out );
		$this->assertStringContainsString( 'https://outlook.live.com/calendar/0/deeplink/compose?', $out );
		$this->assertStringContainsString( 'rru=addevent', $out );
		$this->assertStringContainsString( 'Add to Google Calendar', $out );
		$this->assertStringContainsString( 'Add to Outlook', $out );

		// Title encoded per value ('&' -> %26); venue too.
		$this->assertStringContainsString( 'Launch%20Night%20%26%20Signals', $out );
		$this->assertStringContainsString( 'Main%20Hall', $out );

		// Google gets compact ICS UTC datetimes (as the ICS feed), Outlook
		// gets dashed ISO 8601; both in UTC (site tz +00:00 in tests).
		$this->assertStringContainsString( 'dates=20990501T180000Z%2F20990501T200000Z', $out );
		$this->assertStringContainsString( 'startdt=2099-05-01T18%3A00%3A00Z', $out );
		$this->assertStringContainsString( 'enddt=2099-05-01T20%3A00%3A00Z', $out );

		// Title '&' became %26, not a second param.
		$this->assertStringNotContainsString( 'text=Launch%20Night%20&', $out );

		// Both links open safely in a new tab.
		$this->assertSame( 2, substr_count( $out, 'rel="noopener noreferrer"' ) );
	}

	/**
	 * No start meta => no links at all.
	 *
	 * @return void
	 */
	public function test_calendar_links_empty_without_start(): void {
		$id  = $this->make_event( '' );
		$out = Render_Blocks::calendar_links( Render_Blocks::summary( $id ) );

		$this->assertSame( '', $out );
	}

	/**
	 * Past events render no links (same honesty rule as the ICS feed).
	 *
	 * @return void
	 */
	public function test_calendar_links_absent_on_past_event(): void {
		$id  = $this->make_event( '2020-01-01 18:00:00', '2020-01-01 20:00:00' );
		$out = Render_Blocks::calendar_links( Render_Blocks::summary( $id ) );

		$this->assertSame( '', $out );
	}

	/**
	 * The single-event view embeds the links for an upcoming event, and the
	 * description carries the event permalink so the calendar entry links
	 * back to the page.
	 *
	 * @return void
	 */
	public function test_single_event_embeds_calendar_links_with_permalink(): void {
		$id   = $this->make_event( '2099-06-01 18:00:00', '2099-06-01 20:00:00' );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$out = Render_Blocks::render_single_event( $post );

		$this->assertStringContainsString( 'event-hub-single__cal', $out );
		$this->assertStringContainsString( 'calendar.google.com', $out );

		// The permalink rides the details param, rawurlencode'd: scheme colon
		// and path slashes come out percent-encoded.
		$permalink = (string) get_permalink( $id );
		$this->assertStringContainsString( rawurlencode( $permalink ), $out );
	}
}
