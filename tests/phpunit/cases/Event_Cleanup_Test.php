<?php
/**
 * Event-deletion cascade tests: hard-deleting an eh_event post removes its
 * RSVP rows; trash and non-event deletes leave them alone; missing-table
 * installs degrade gracefully.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests for Plugin::maybe_delete_event_rsvps().
 */
class Event_Cleanup_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with meta.
	 *
	 * @return int Post ID.
	 */
	private static function make_event(): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $post_id, 'eh_end', '2099-05-01 20:00:00' );

		return $post_id;
	}

	/**
	 * Seeds rows through the public create path.
	 *
	 * @param int $event_id Event post ID.
	 * @return void
	 */
	private static function seed( int $event_id ): void {
		$repo = Plugin::instance()->repository();

		$repo->create( 'Going One', 'one@example.com', 'going', $event_id );
		$repo->create( 'Going Two', 'two@example.com', 'going', $event_id );
		$repo->create( 'Wait One', 'wait@example.com', 'waitlist', $event_id );
	}

	/**
	 * Permanent delete removes every row for that event — and only that
	 * event's rows.
	 */
	public function test_hard_delete_removes_event_rows(): void {
		$a = self::make_event();
		$b = self::make_event();

		self::seed( $a );
		self::seed( $b );

		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 1,
			),
			Plugin::instance()->repository()->counts( $a )
		);

		wp_delete_post( $a, true );

		$this->assertSame(
			array(
				'going'    => 0,
				'waitlist' => 0,
			),
			Plugin::instance()->repository()->counts( $a ),
			'Deleted event must leave zero rows.'
		);
		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 1,
			),
			Plugin::instance()->repository()->counts( $b ),
			'Other events keep their rows.'
		);
	}

	/**
	 * Trashing does NOT remove rows (restore brings the list back).
	 */
	public function test_trash_keeps_rows(): void {
		$event_id = self::make_event();
		self::seed( $event_id );

		wp_trash_post( $event_id );

		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 1,
			),
			Plugin::instance()->repository()->counts( $event_id ),
			'Trash must keep the RSVP rows.'
		);

		wp_untrash_post( $event_id );

		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 1,
			),
			Plugin::instance()->repository()->counts( $event_id ),
			'Untrash keeps rows too.'
		);
	}

	/**
	 * Deleting other post types (including the plain-post edge where the
	 * global post may not exist) never touches RSVP data.
	 */
	public function test_non_event_delete_untouched(): void {
		$event_id = self::make_event();
		self::seed( $event_id );

		$plain = self::factory()->post->create();
		wp_delete_post( $plain, true );

		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 1,
			),
			Plugin::instance()->repository()->counts( $event_id ),
			'Non-event deletes must not touch RSVP rows.'
		);

		// Absent ID must be a no-op as well.
		Plugin::instance()->maybe_delete_event_rsvps( 0 );
		Plugin::instance()->maybe_delete_event_rsvps( '999999' );

		$this->assertSame(
			array(
				'going'    => 2,
				'waitlist' => 1,
			),
			Plugin::instance()->repository()->counts( $event_id )
		);
	}

	/**
	 * delete_all_for_event() removes exactly its own event's rows and
	 * reports the count.
	 */
	public function test_delete_all_for_event_returns_row_count(): void {
		$a = self::make_event();
		$b = self::make_event();

		self::seed( $a );
		self::seed( $b );

		$repo = Plugin::instance()->repository();

		$this->assertSame( 3, $repo->delete_all_for_event( $a ) );
		$this->assertSame(
			array(
				'going'    => 0,
				'waitlist' => 0,
			),
			$repo->counts( $a )
		);
		$this->assertSame( 0, $repo->delete_all_for_event( $a ), 'Second sweep has nothing left.' );
		$this->assertSame( 3, $repo->delete_all_for_event( $b ) );
	}
}
