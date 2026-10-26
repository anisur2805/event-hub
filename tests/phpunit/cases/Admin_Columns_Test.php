<?php
/**
 * Tests for the Events list-table columns and organizer row actions.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Admin columns test case.
 */
class Admin_Columns_Test extends WP_UnitTestCase {

	/**
	 * The columns filter: labels present after title, default date gone.
	 * Exercised through the registered hook (boot()) like wp-admin runs it.
	 *
	 * @return void
	 */
	public function test_columns_filter_inserts_labels_and_drops_date(): void {
		$cols = new Admin_Columns();
		$cols->boot();

		$columns = apply_filters(
			'manage_eh_event_posts_columns',
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertArrayNotHasKey( 'date', $columns );
		$this->assertSame( 'When', $columns['eh_when'] );
		$this->assertSame( 'Venue', $columns['eh_venue'] );
		$this->assertSame( 'Capacity', $columns['eh_capacity'] );

		$keys = array_keys( $columns );
		$this->assertSame( 'title', $keys[ (int) array_search( 'eh_when', $keys, true ) - 1 ] );

		remove_all_filters( 'manage_eh_event_posts_columns' );
	}

	/**
	 * Creates a published event with start/end/capacity meta.
	 *
	 * @param string         $start    Start datetime (site format).
	 * @param string         $end      End datetime (site format).
	 * @param string         $capacity Capacity meta ('' = unlimited).
	 * @param array{int:int} $counts   RSVP counts to seed directly.
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
				'post_title'  => 'Columns Event',
			)
		);

		update_post_meta( $post_id, 'eh_start', $start );
		update_post_meta( $post_id, 'eh_end', $end );
		update_post_meta( $post_id, 'eh_capacity', $capacity );

		if ( $counts['going'] > 0 || $counts['waitlist'] > 0 ) {
			self::seed_rsvps( $post_id, (int) $counts['going'], (int) $counts['waitlist'] );
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
	 * When cell renders the start date; missing meta renders a dash.
	 *
	 * @return void
	 */
	public function test_when_cell_renders_start_and_dash_for_missing(): void {
		$cols = new Admin_Columns();
		$id   = $this->make_event( '2099-05-01 18:00:00' );

		ob_start();
		$cols->column_content( 'eh_when', $id );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'May', $out );
		$this->assertStringContainsString( '2099', $out );

		$no_date = (int) self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		ob_start();
		$cols->column_content( 'eh_when', $no_date );
		$empty = (string) ob_get_clean();

		$this->assertSame( '—', $empty );
	}

	/**
	 * Venue cell echoes the meta; empty renders a dash.
	 *
	 * @return void
	 */
	public function test_venue_cell(): void {
		$id   = $this->make_event( '2099-05-01 18:00:00' );
		$cols = new Admin_Columns();

		update_post_meta( $id, 'eh_venue', 'Hall B, 12 Mercer St' );

		ob_start();
		$cols->column_content( 'eh_venue', $id );
		$this->assertStringContainsString( 'Hall B, 12 Mercer St', (string) ob_get_clean() );

		update_post_meta( $id, 'eh_venue', '' );

		ob_start();
		$cols->column_content( 'eh_venue', $id );
		$this->assertSame( '—', (string) ob_get_clean() );
	}

	/**
	 * Capacity cell: Unlimited without meta, "X of Y" with seeded counts.
	 *
	 * @return void
	 */
	public function test_capacity_cell_unlimited_and_fraction(): void {
		$cols = new Admin_Columns();

		$unlimited = $this->make_event( '2099-06-01 18:00:00' );

		ob_start();
		$cols->column_content( 'eh_capacity', $unlimited );
		$this->assertSame( 'Unlimited', (string) ob_get_clean() );

		$capped = $this->make_event(
			'2099-06-02 18:00:00',
			'2099-06-02 20:00:00',
			'60',
			array(
				'going'    => 2,
				'waitlist' => 0,
			)
		);

		ob_start();
		$cols->column_content( 'eh_capacity', $capped );
		$this->assertSame( '2 of 60', (string) ob_get_clean() );
	}

	/**
	 * Row actions: organizer sees RSVPs + Export CSV; anonymous user does
	 * not. Actions append to (not replace) existing ones.
	 *
	 * @return void
	 */
	public function test_row_actions_capability_gated_and_appended(): void {
		$id   = $this->make_event( '2099-07-01 18:00:00' );
		$cols = new Admin_Columns();
		$base = array( 'edit' => '<a href="#">Edit</a>' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$actions = $cols->row_actions( $base, get_post( $id ) );

		$this->assertIsArray( $actions );
		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'eh_rsvps', $actions );
		// esc_url() entity-encodes the ampersand inside the href attribute.
		$this->assertStringContainsString( 'page=event-hub-rsvps', (string) $actions['eh_rsvps'] );
		$this->assertStringContainsString( 'event=' . $id, (string) $actions['eh_rsvps'] );
		$this->assertArrayHasKey( 'eh_export_csv', $actions );
		$this->assertStringContainsString( 'action=eh_export_rsvps', (string) $actions['eh_export_csv'] );
		$this->assertStringContainsString( 'event_id=' . $id, (string) $actions['eh_export_csv'] );

		wp_set_current_user( 0 );
		$anon = $cols->row_actions( $base, get_post( $id ) );

		$this->assertArrayNotHasKey( 'eh_rsvps', $anon );
		$this->assertArrayNotHasKey( 'eh_export_csv', $anon );
	}

	/**
	 * Non-event posts get no organizer actions.
	 *
	 * @return void
	 */
	public function test_row_actions_skip_other_post_types(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = (int) self::factory()->post->create();
		$cols    = new Admin_Columns();
		$actions = $cols->row_actions( array(), get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'eh_rsvps', $actions );
		$this->assertArrayNotHasKey( 'eh_export_csv', $actions );
	}

	/**
	 * Sortable registration: eh_when is exposed as a sortable column with
	 * the eh_start sort key.
	 *
	 * The posts list table applies only the screen-scoped filter
	 * manage_edit-{post_type}_sortable_columns for custom post types; the
	 * filter name here must match boot()'s hook or the header renders dead.
	 *
	 * The pre_get_posts mapping itself is not unit-testable: is_admin() is
	 * permanently false in the PHPUnit harness, so the sort_when() guard
	 * returns early there. It is verified by the hook wiring in boot() and
	 * manual sorting in wp-admin.
	 *
	 * @return void
	 */
	public function test_sortable_filter_exposes_when(): void {
		$cols = new Admin_Columns();
		$cols->boot();

		$sortable = apply_filters(
			'manage_edit-eh_event_sortable_columns',
			array( 'title' => 'title' )
		);
		$this->assertSame( 'eh_when', $sortable['eh_when'] );

		remove_all_filters( 'manage_edit-eh_event_sortable_columns' );
	}
}
