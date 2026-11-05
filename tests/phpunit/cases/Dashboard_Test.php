<?php
/**
 * Tests for the §3.1 organizer dashboard surfaces: Rsvp_Repository::recent(),
 * Dashboard_Widget rendering + labels, and the Admin_Page summary tab.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Organizer dashboard test case.
 */
class Dashboard_Test extends WP_UnitTestCase {

	/**
	 * Admin user ID (edit_others_posts).
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID (no caps).
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Sets up users.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Creates a published upcoming event with start/end/capacity meta.
	 *
	 * @param array<string,mixed> $meta Extra meta (eh_capacity etc.).
	 * @param string              $title Post title.
	 * @return int
	 */
	private function make_event( array $meta = array(), string $title = 'Dashboard Event' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$defaults = array(
			'eh_start' => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'eh_end'   => wp_date( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ),
		);

		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			update_post_meta( $event_id, $key, (string) $value );
		}

		return $event_id;
	}

	/**
	 * Inserts an RSVP row bypassing repository validation (dates etc.).
	 *
	 * @param int    $event_id Event ID.
	 * @param string $name     Name.
	 * @param string $email    Email.
	 * @param string $status   Status.
	 * @param int    $guests   Seats.
	 * @param string $when     Site-time Y-m-d H:i:s for created_at.
	 * @return int Row ID.
	 */
	private function insert_rsvp(
		int $event_id,
		string $name,
		string $email,
		string $status = 'going',
		int $guests = 1,
		string $when = ''
	): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture, mirrors Seats_Test.
			$wpdb->prefix . 'eh_rsvps',
			array(
				'event_id'   => $event_id,
				'name'       => $name,
				'email'      => $email,
				'status'     => $status,
				'guests'     => $guests,
				'created_at' => '' !== $when ? $when : current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	/**
	 * Repository::recent() returns newest-first across all events, capped.
	 *
	 * @return void
	 */
	public function test_recent_orders_desc_and_limits(): void {
		$a = $this->make_event( array(), 'Event A' );
		$b = $this->make_event( array(), 'Event B' );

		$first  = $this->insert_rsvp( $a, 'Old Row', 'old@example.com', 'going', 1, '-2 hours' );
		$second = $this->insert_rsvp( $b, 'Mid Row', 'mid@example.com', 'waitlist', 2, '-1 hour' );
		$third  = $this->insert_rsvp( $a, 'New Row', 'new@example.com', 'going', 3, '-1 minute' );

		$rows = ( new Rsvp_Repository() )->recent( 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( $third, $rows[0]['id'] );
		$this->assertSame( $second, $rows[1]['id'] );

		$all = ( new Rsvp_Repository() )->recent( 10 );

		$this->assertCount( 3, $all );
		$this->assertSame( array( $third, $second, $first ), array_column( $all, 'id' ) );
	}

	/**
	 * Recent() keeps the full internal row shape (email included — the method
	 * is admin-context only; the public serializers never call it).
	 *
	 * @return void
	 */
	public function test_recent_returns_internal_shape(): void {
		$event_id = $this->make_event();
		$this->insert_rsvp( $event_id, 'Shape Check', 'shape@example.com', 'going', 4 );

		$rows = ( new Rsvp_Repository() )->recent( 5 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'shape@example.com', $rows[0]['email'] );
		$this->assertSame( 4, $rows[0]['guests'] );
		$this->assertSame( $event_id, $rows[0]['event_id'] );
		$this->assertArrayNotHasKey( 'manage_token', $rows[0] );
	}

	/**
	 * Widget hides entirely for users without edit_others_posts.
	 *
	 * @return void
	 */
	public function test_widget_not_registered_without_capability(): void {
		wp_set_current_user( $this->subscriber_id );

		// Order-independence: a prior test in this process may have registered
		// the box (the $wp_meta_boxes global persists across tests).
		unset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']['event-hub-dashboard'] );

		( new Dashboard_Widget() )->register();

		$this->assertArrayNotHasKey( 'event-hub-dashboard', (array) ( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] ?? array() ) );
	}

	/**
	 * Widget registration is safe when wp_add_dashboard_widget() is absent
	 * (REST/CLI context — its home file wp-admin/includes/dashboard.php is
	 * not loaded there). Capability gate still evaluated first; registration
	 * itself degrades to a silent no-op instead of a fatal.
	 *
	 * @return void
	 */
	public function test_widget_register_noops_without_dashboard_context(): void {
		wp_set_current_user( $this->admin_id );

		$this->assertFalse( function_exists( 'wp_add_dashboard_widget' ) );

		$widget = new Dashboard_Widget();

		// Must not throw.
		$widget->register();

		$this->assertArrayNotHasKey( 'event-hub-dashboard', (array) ( $GLOBALS['wp_meta_boxes'] ?? array() ) );
	}

	/**
	 * Fill-parts label: capped events show booked/capacity with a clamped
	 * percentage; 0 capacity stays 100%.
	 *
	 * @return void
	 */
	public function test_fill_parts_capped(): void {
		$exact = Dashboard_Widget::fill_parts(
			array(
				'capacity' => 40,
				'counts'   => array( 'going' => 12, 'waitlist' => 3 ),
			)
		);

		$this->assertSame( '12 / 40 seats (30%)', $exact['fill'] );
		$this->assertSame( 'Waitlist: 3', $exact['waitlist'] );

		$over = Dashboard_Widget::fill_parts(
			array(
				'capacity' => 40,
				'counts'   => array( 'going' => 45, 'waitlist' => 0 ),
			)
		);

		$this->assertSame( '45 / 40 seats (100%)', $over['fill'] );

		$zero = Dashboard_Widget::fill_parts(
			array(
				'capacity' => 0,
				'counts'   => array( 'going' => 0, 'waitlist' => 2 ),
			)
		);

		$this->assertSame( '0 / 0 seats (100%)', $zero['fill'] );
	}

	/**
	 * Fill-parts label: no capacity is "unlimited" — no percentage.
	 *
	 * @return void
	 */
	public function test_fill_parts_unlimited(): void {
		$parts = Dashboard_Widget::fill_parts(
			array(
				'capacity' => null,
				'counts'   => array( 'going' => 7, 'waitlist' => 1 ),
			)
		);

		$this->assertSame( '7 seats (unlimited)', $parts['fill'] );
		$this->assertSame( 'Waitlist: 1', $parts['waitlist'] );
	}

	/**
	 * Status label: single seat omits the suffix; multi-seat appends it.
	 *
	 * @return void
	 */
	public function test_status_label_seat_suffix(): void {
		$this->assertSame( 'Going', Dashboard_Widget::status_label( 'going', 1 ) );
		$this->assertSame( 'Waitlist', Dashboard_Widget::status_label( 'waitlist', 1 ) );
		$this->assertSame( 'Going · 4 seats', Dashboard_Widget::status_label( 'going', 4 ) );
	}

	/**
	 * Date label: empty and unparseable inputs fall back to the em dash.
	 *
	 * @return void
	 */
	public function test_date_label_fallbacks(): void {
		$this->assertSame( '—', Dashboard_Widget::date_label( '' ) );
		$this->assertSame( '—', Dashboard_Widget::date_label( 'not-a-date' ) );
	}

	/**
	 * Time-ago label: inside a day reads as human diff, outside falls back to
	 * the date; unparseable input is the em dash.
	 *
	 * @return void
	 */
	public function test_time_ago_label_windows(): void {
		$recent = wp_date( 'Y-m-d H:i:s', time() - 300 );

		$this->assertStringContainsString( 'ago', Dashboard_Widget::time_ago_label( $recent ) );
		// "Now" itself (site time) — boundary sanity, still inside the window.
		$this->assertStringContainsString( 'ago', Dashboard_Widget::time_ago_label( current_time( 'mysql' ) ) );

		$old = wp_date( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );

		$this->assertStringNotContainsString( 'ago', Dashboard_Widget::time_ago_label( $old ) );

		$this->assertSame( '—', Dashboard_Widget::time_ago_label( 'garbage' ) );
	}

	/**
	 * Widget body renders rows with emails (admin surface) and the summary
	 * link.
	 *
	 * @return void
	 */
	public function test_widget_renders_rows_and_emails(): void {
		wp_set_current_user( $this->admin_id );

		$event_id = $this->make_event( array( 'eh_capacity' => '50' ), 'Widget Event' );
		$this->insert_rsvp( $event_id, 'Ada Lovelace', 'ada@example.com', 'going', 2 );
		$this->insert_rsvp( $event_id, 'Grace Hopper', 'grace@example.com', 'waitlist', 1 );

		$widget = new Dashboard_Widget();

		ob_start();
		$widget->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ada@example.com', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'Grace Hopper', $html );

		// Fill uses SEATS (2 on the Ada row) and the waitlist is labeled in
		// seats per the §2.3 counts contract.
		$this->assertStringContainsString( '2 / 50 seats', $html );
		$this->assertStringContainsString( 'Waitlist: 1', $html );
		$this->assertStringContainsString( 'Going · 2 seats', $html );
		$this->assertStringContainsString( 'tab=summary', $html );
	}

	/**
	 * Admin page without a tab param keeps the RSVPs view (React mount +
	 * settings form), not the summary.
	 *
	 * @return void
	 */
	public function test_admin_page_default_tab_is_rsvps(): void {
		wp_set_current_user( $this->admin_id );

		unset( $_GET['tab'] );

		// get_admin_page_title() is empty outside real menu registration; the
		// assertions below target structure, not the title.
		$page = new Admin_Page();

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertStringContainsString( 'event-hub-rsvps-root', $html );
		$this->assertStringNotContainsString( 'Recent RSVPs', $html );
	}

	/**
	 * ?tab=summary renders the server-side summary tables with emails and
	 * per-event fill — no React mount.
	 *
	 * @return void
	 */
	public function test_admin_page_summary_tab_renders(): void {
		wp_set_current_user( $this->admin_id );

		$event_id = $this->make_event( array( 'eh_capacity' => '25' ), 'Summary Tab Event' );
		$this->insert_rsvp( $event_id, 'Summary Person', 'summary@example.com', 'going', 3 );

		$_GET['tab'] = 'summary';

		$page = new Admin_Page();

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertStringContainsString( 'Summary Tab Event', $html );
		$this->assertStringContainsString( 'summary@example.com', $html );
		$this->assertStringContainsString( '3 / 25 seats', $html );
		$this->assertStringNotContainsString( 'event-hub-rsvps-root', $html );
	}
}
