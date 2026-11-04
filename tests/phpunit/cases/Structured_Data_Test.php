<?php
/**
 * ROADMAP 1.1 regressions: schema.org Event JSON-LD on single-event pages.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP) for Structured_Data output.
 */
class Structured_Data_Test extends WP_UnitTestCase {

	/**
	 * Shared now-reference so upcoming/past fixtures stay relative even if
	 * the suite runs slowly.
	 *
	 * @var int
	 */
	private static int $now = 0;

	/**
	 * Creates a published event with start/end/capacity meta and seeded
	 * RSVP counts, mirroring the Phase 0 fixture shape.
	 *
	 * @param string                          $start    Start datetime (site format).
	 * @param string                          $end      End datetime ('' = none).
	 * @param string                          $capacity Capacity meta ('' = unlimited).
	 * @param array{going?:int,waitlist?:int} $counts   RSVP counts to seed.
	 * @return int Post ID.
	 */
	private static function make_event( string $start, string $end = '', string $capacity = '', array $counts = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'eh_start', $start );

		if ( '' !== $end ) {
			update_post_meta( $post_id, 'eh_end', $end );
		}

		if ( '' !== $capacity ) {
			update_post_meta( $post_id, 'eh_capacity', $capacity );
		}

		$going    = $counts['going'] ?? 0;
		$waitlist = $counts['waitlist'] ?? 0;

		for ( $i = 0; $i < $going; $i++ ) {
			Plugin::instance()->repository()->create( "Going {$i}", "going-{$i}@example.org", 'going', $post_id );
		}

		for ( $i = 0; $i < $waitlist; $i++ ) {
			Plugin::instance()->repository()->create( "Wait {$i}", "wait-{$i}@example.org", 'waitlist', $post_id );
		}

		return $post_id;
	}

	/**
	 * Resolves a real singular query for the event, then captures what
	 * print_json_ld() prints. Returns null when nothing was printed.
	 *
	 * @param int $post_id Event post ID.
	 * @return string|null
	 */
	private function capture_json_ld( int $post_id ): ?string {
		$this->go_to( (string) get_permalink( $post_id ) );

		$instance = new Structured_Data();

		ob_start();
		$instance->print_json_ld();
		$printed = (string) ob_get_clean();

		return '' === $printed ? null : $printed;
	}

	/**
	 * Decodes the JSON-LD body from a captured script tag.
	 *
	 * @param string $html Captured script tag.
	 * @return array<string,mixed>
	 */
	private static function json_of( string $html ): array {
		self::assertSame( 1, preg_match( '/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m ) );
		$decoded = json_decode( trim( $m[1] ), true );

		self::assertIsArray( $decoded );

		return (array) $decoded;
	}

	/**
	 * Seeds the shared time reference.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$now = time();
	}

	/**
	 * Upcoming event with capacity: full Event shape, InStock, both capacity
	 * numbers.
	 *
	 * @return void
	 */
	public function test_upcoming_event_emits_event_with_instock_offer(): void {
		$post_id = self::make_event(
			gmdate( 'Y-m-d H:i:s', self::$now + 86400 ),
			gmdate( 'Y-m-d H:i:s', self::$now + 90000 ),
			'10',
			array( 'going' => 4 )
		);

		$json = self::json_of( (string) $this->capture_json_ld( $post_id ) );

		$this->assertSame( 'https://schema.org', $json['@context'] );
		$this->assertSame( 'Event', $json['@type'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $json['eventStatus'] );
		$this->assertSame( 'https://schema.org/InStock', $json['offers']['availability'] );
		$this->assertSame( 10, $json['offers']['maximumAttendeeCapacity'] );
		$this->assertSame( 6, $json['offers']['remainingAttendeeCapacity'] );
		$this->assertSame( get_permalink( $post_id ), $json['offers']['url'] );
	}

	/**
	 * Full event: SoldOut with remaining 0.
	 *
	 * @return void
	 */
	public function test_full_event_is_soldout(): void {
		$post_id = self::make_event(
			gmdate( 'Y-m-d H:i:s', self::$now + 86400 ),
			'',
			'2',
			array(
				'going'    => 2,
				'waitlist' => 1,
			)
		);

		$json = self::json_of( (string) $this->capture_json_ld( $post_id ) );

		$this->assertSame( 'https://schema.org/SoldOut', $json['offers']['availability'] );
		$this->assertSame( 2, $json['offers']['maximumAttendeeCapacity'] );
		$this->assertSame( 0, $json['offers']['remainingAttendeeCapacity'] );
	}

	/**
	 * Past event: still EventScheduled but offers removed (no phantom
	 * availability for ended events).
	 *
	 * @return void
	 */
	public function test_past_event_has_no_offers(): void {
		$post_id = self::make_event(
			gmdate( 'Y-m-d H:i:s', self::$now - 172800 ),
			gmdate( 'Y-m-d H:i:s', self::$now - 169200 )
		);

		$json = self::json_of( (string) $this->capture_json_ld( $post_id ) );

		$this->assertSame( 'https://schema.org/EventScheduled', $json['eventStatus'] );
		$this->assertArrayNotHasKey( 'offers', $json );
	}

	/**
	 * Unlimited capacity: InStock without any capacity fields.
	 *
	 * @return void
	 */
	public function test_unlimited_capacity_omits_capacity_fields(): void {
		$post_id = self::make_event( gmdate( 'Y-m-d H:i:s', self::$now + 86400 ) );

		$json = self::json_of( (string) $this->capture_json_ld( $post_id ) );

		$this->assertSame( 'https://schema.org/InStock', $json['offers']['availability'] );
		$this->assertArrayNotHasKey( 'maximumAttendeeCapacity', $json['offers'] );
		$this->assertArrayNotHasKey( 'remainingAttendeeCapacity', $json['offers'] );
	}

	/**
	 * Venue, featured image, and excerpt flow in as Place/image/description;
	 * a start-less event and a non-singular context print nothing.
	 *
	 * @return void
	 */
	public function test_venue_image_description_included_when_present(): void {
		$post_id = self::make_event( gmdate( 'Y-m-d H:i:s', self::$now + 86400 ) );

		update_post_meta( $post_id, 'eh_venue', 'Community Hall' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => 'A test event excerpt.',
			)
		);

		$tests_dir = (string) getenv( 'WP_TESTS_DIR' );

		if ( '' === $tests_dir || ! file_exists( $tests_dir . '/phpunit/data/images/blank.gif' ) ) {
			$tests_dir = '/private/tmp/eh-wp-tests/tests';
		}

		$attachment_id = self::factory()->attachment->create_object(
			$tests_dir . '/phpunit/data/images/blank.gif',
			$post_id,
			array(
				'post_mime_type' => 'image/gif',
				'post_type'      => 'attachment',
			)
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$json = self::json_of( (string) $this->capture_json_ld( $post_id ) );

		$this->assertSame( 'Place', $json['location']['@type'] );
		$this->assertSame( 'Community Hall', $json['location']['name'] );
		$this->assertSame( 'A test event excerpt.', $json['description'] );
		$this->assertIsString( $json['image'] );
		$this->assertNotSame( '', $json['image'] );
	}

	/**
	 * Event without a start date prints nothing — startDate is the one fact
	 * a valid Event requires.
	 *
	 * @return void
	 */
	public function test_missing_start_prints_nothing(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		$this->assertNull( $this->capture_json_ld( $post_id ) );
	}

	/**
	 * Non-singular context (the home query): nothing is printed even when an
	 * event exists.
	 *
	 * @return void
	 */
	public function test_non_singular_context_prints_nothing(): void {
		self::make_event( gmdate( 'Y-m-d H:i:s', self::$now + 86400 ) );

		$this->go_to( home_url( '/' ) );

		$instance = new Structured_Data();

		ob_start();
		$instance->print_json_ld();
		$printed = (string) ob_get_clean();

		$this->assertSame( '', $printed );
	}
}
