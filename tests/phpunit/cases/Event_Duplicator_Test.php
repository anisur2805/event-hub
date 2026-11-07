<?php
/**
 * ROADMAP §5B regressions: duplicate-event row action — the organizer's
 * "Copy" verb. A copy is a DRAFT carrying the source's content and event
 * setup, never its RSVPs, never its series rule, never its attendee
 * status; the organizer edits and publishes it themselves.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Duplicate-event tests.
 */
class Event_Duplicator_Test extends WP_UnitTestCase {

	/**
	 * Creates a published source event with the full event setup
	 * (including series + status keys the copy must SKIP).
	 *
	 * @return int Post ID.
	 */
	private static function make_source(): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Duplicator Source',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);

		update_post_meta( $post_id, 'eh_start', '2099-06-01 18:00:00' );
		update_post_meta( $post_id, 'eh_end', '2099-06-01 20:00:00' );
		update_post_meta( $post_id, 'eh_capacity', '40' );
		update_post_meta( $post_id, 'eh_venue', 'Main Hall' );
		update_post_meta( $post_id, 'eh_venue_id', '123' );
		update_post_meta( $post_id, 'eh_organizer_id', '456' );
		update_post_meta( $post_id, 'eh_rsvp_open_hours', '48' );
		update_post_meta( $post_id, 'eh_rsvp_close_hours', '2' );
		update_post_meta( $post_id, Reminders::HOURS_META, '12' );
		update_post_meta( $post_id, 'eh_external_url', 'https://example.org/register' );
		update_post_meta( $post_id, '_eh_online_url', 'https://meet.example.org/room' );
		update_post_meta( $post_id, Event_Timezone::META, 'Asia/Dhaka' );
		update_post_meta( $post_id, Event_Status::META, 'cancelled' );
		update_post_meta( $post_id, 'eh_series_freq', 'weekly' );
		update_post_meta( $post_id, 'eh_series_interval', '2' );
		update_post_meta( $post_id, 'eh_series_byweekday', '1,3' );
		update_post_meta( $post_id, 'eh_series_until', '2099-12-31' );

		return $post_id;
	}

	/**
	 * Clears the fixture's cancelled status (RSVP create() refuses
	 * cancelled events — tests that seed rows must reset it first).
	 *
	 * @param int $post_id Event post ID.
	 * @return void
	 */
	private static function clear_status( int $post_id ): void {
		update_post_meta( $post_id, Event_Status::META, '' );
	}

	/**
	 * A draft copy carries the source's content and event setup (the whole
	 * copy list), its terms and its featured image; the title gains the
	 * "Copy of" prefix; the author is the duplicating user.
	 *
	 * @return void
	 */
	public function test_copy_carries_setup_and_content(): void {
		$source_id = self::make_source();

		$term_id = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'eh_event_category',
				'name'     => 'Dup Term',
			)
		);
		wp_set_object_terms( $source_id, array( $term_id ), 'eh_event_category' );

		$actor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Featured image: real image file (the Structured_Data_Test
		// pattern — a bare attachment row carries no attachable file).
		$tests_dir = (string) getenv( 'WP_TESTS_DIR' );

		if ( '' === $tests_dir || ! file_exists( $tests_dir . '/phpunit/data/images/blank.gif' ) ) {
			$tests_dir = '/private/tmp/eh-wp-tests/tests';
		}

		$thumb = (int) self::factory()->attachment->create_object(
			$tests_dir . '/phpunit/data/images/blank.gif',
			$source_id,
			array(
				'post_mime_type' => 'image/gif',
				'post_type'      => 'attachment',
			)
		);
		set_post_thumbnail( $source_id, $thumb );

		$duplicator = new Event_Duplicator();
		$new_id     = $duplicator->duplicate( $source_id, (int) $actor );

		$this->assertIsInt( $new_id );
		$this->assertNotWPError( $new_id );

		$copy = get_post( $new_id );

		$this->assertInstanceOf( \WP_Post::class, $copy );
		$this->assertSame( 'draft', $copy->post_status );
		$this->assertSame( 'eh_event', $copy->post_type );
		$this->assertSame( (int) $actor, (int) $copy->post_author );
		$this->assertSame( 0, (int) $copy->post_parent );
		$this->assertSame( 'Copy of Duplicator Source', $copy->post_title );
		$this->assertSame( get_post( $source_id )->post_content, $copy->post_content );

		// The copied setup: every copy-list key, value-for-value.
		$expected_meta = array(
			'eh_start'            => '2099-06-01 18:00:00',
			'eh_end'              => '2099-06-01 20:00:00',
			Event_Timezone::META  => 'Asia/Dhaka',
			'eh_venue'            => 'Main Hall',
			'eh_venue_id'         => '123',
			'eh_organizer_id'     => '456',
			'eh_capacity'         => '40',
			'eh_rsvp_open_hours'  => '48',
			'eh_rsvp_close_hours' => '2',
			Reminders::HOURS_META => '12',
			'eh_external_url'     => 'https://example.org/register',
			'_eh_online_url'      => 'https://meet.example.org/room',
		);

		foreach ( $expected_meta as $key => $expected ) {
			$this->assertSame(
				$expected,
				(string) get_post_meta( $new_id, $key, true ),
				sprintf( 'Meta key %s must copy value-for-value.', $key )
			);
		}

		$this->assertSame(
			array( $term_id ),
			wp_get_object_terms( $new_id, 'eh_event_category', array( 'fields' => 'ids' ) )
		);
		$this->assertSame( $thumb, (int) get_post_meta( $new_id, '_thumbnail_id', true ) );
	}

	/**
	 * The skip set: series keys and attendee status never ride along —
	 * publishing the copy must not fork the source's spawn tree and must
	 * not announce a cancelled/postponed state. The source keeps its own
	 * rule untouched.
	 *
	 * @return void
	 */
	public function test_copy_skips_series_and_status_keys(): void {
		$source_id = self::make_source();

		$duplicator = new Event_Duplicator();
		$new_id     = $duplicator->duplicate( $source_id, 1 );

		$this->assertIsInt( $new_id );
		$this->assertNotWPError( $new_id );

		$skipped = array(
			'eh_series_freq',
			'eh_series_interval',
			'eh_series_byweekday',
			'eh_series_until',
			Event_Status::META,
		);

		foreach ( $skipped as $key ) {
			$this->assertSame(
				'',
				(string) get_post_meta( $new_id, $key, true ),
				sprintf( 'Key %s must NOT copy.', $key )
			);
		}

		$this->assertSame( 'weekly', (string) get_post_meta( $source_id, 'eh_series_freq', true ) );
	}

	/**
	 * RSVP rows never copy: the source's registrations stay its own; the
	 * copy starts at zero so capacity math begins clean.
	 *
	 * @return void
	 */
	public function test_copy_has_no_rsvp_rows(): void {
		$source_id = self::make_source();
		self::clear_status( $source_id );
		$repo = Plugin::instance()->repository();

		// A RUNNING, open event in the SITE frame (zone meta cleared — the
		// fixture's Asia/Dhaka would re-interpret the wall clock; a +6 zone
		// makes a site-future end zone-past, the #59 sign lesson).
		$now_wall = ( new \DateTimeImmutable( 'now', wp_timezone() ) )
			->modify( '+1 hour' );

		update_post_meta( $source_id, 'eh_start', $now_wall->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $source_id, 'eh_end', $now_wall->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $source_id, 'eh_rsvp_close_hours', '' );
		update_post_meta( $source_id, Event_Timezone::META, '' );

		$seeded     = $repo->create( 'Dup Goer', 'dup-goer@example.com', 'going', $source_id );
		$waitlisted = $repo->create( 'Dup Waiter', 'dup-waiter@example.com', 'waitlist', $source_id );

		$this->assertIsArray( $seeded );
		$this->assertIsArray( $waitlisted );

		$duplicator = new Event_Duplicator();
		$new_id     = $duplicator->duplicate( $source_id, 1 );

		$this->assertIsInt( $new_id );
		$this->assertNotWPError( $new_id );

		$this->assertSame(
			array(
				'going'     => 0,
				'waitlist'  => 0,
				'checkedIn' => 0,
			),
			$repo->counts( $new_id )
		);
		$this->assertSame( 1, (int) $repo->counts( $source_id )['going'] );
	}

	/**
	 * Unknown or non-event source: WP_Error eh_not_found. Copying a plain
	 * post must not produce a bogus event shell.
	 *
	 * @return void
	 */
	public function test_duplicate_unknown_source_errors(): void {
		$duplicator = new Event_Duplicator();

		$result = $duplicator->duplicate( 999999, 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'eh_not_found', $result->get_error_code() );

		$plain_post = self::factory()->post->create();
		$result2    = $duplicator->duplicate( (int) $plain_post, 1 );

		$this->assertWPError( $result2 );
		$this->assertSame( 'eh_not_found', $result2->get_error_code() );
	}

	/**
	 * The row-action link is a nonced admin-post URL, and the handler
	 * refuses a user without the capability (subscriber → 403 wp_die),
	 * the Csv_Exporter gate pattern via handle_request().
	 *
	 * @return void
	 */
	public function test_handler_gates(): void {
		$source_id = self::make_source();

		$url = Event_Duplicator::duplicate_url( $source_id );

		$this->assertStringContainsString( 'action=eh_duplicate_event', $url );
		$this->assertStringContainsString( 'event_id=' . $source_id, $url );
		$this->assertStringContainsString( '_wpnonce=', $url );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$duplicator = new Event_Duplicator();

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'You are not allowed to duplicate events.' );

		$duplicator->handle_request();
	}

	/**
	 * Handler happy path: nonce + capability + valid event → exactly one
	 * draft copy exists afterwards (the redirect's WPDieException inside
	 * the test process is expected and swallowed; the copy is observable).
	 *
	 * @return void
	 */
	public function test_handler_copies(): void {
		$source_id = self::make_source();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$url   = Event_Duplicator::duplicate_url( $source_id );
		$query = array();

		// wp_nonce_url() HTML-encodes separators (&amp;): decode before
		// splitting (the Admin_Page nonce-URL lesson).
		parse_str( html_entity_decode( (string) wp_parse_url( $url, PHP_URL_QUERY ) ), $query );

		$_GET['action']      = 'eh_duplicate_event';
		$_GET['event_id']     = (string) $source_id;
		$_REQUEST['_wpnonce'] = (string) ( $query['_wpnonce'] ?? '' );
		$_GET['_wpnonce']     = (string) ( $query['_wpnonce'] ?? '' );

		$duplicator = new Event_Duplicator();

		ob_start();

		try {
			$redirect = $duplicator->handle_request();
		} catch ( \WPDieException $e ) {
			// Surface the actual wp_die reason instead of an empty assert.
			ob_end_clean();
			$this->fail( 'handle_request() died unexpectedly: ' . $e->getMessage() );
		}

		ob_end_clean();

		$this->assertStringContainsString( 'eh_copied=', $redirect );
		$this->assertStringContainsString( 'post_type=eh_event', $redirect );

		$copies = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'draft',
				'posts_per_page' => 5,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$this->assertCount( 1, $copies, 'Exactly one draft copy exists.' );
		$this->assertSame( 'Copy of Duplicator Source', (string) $copies[0]->post_title );
	}
}
