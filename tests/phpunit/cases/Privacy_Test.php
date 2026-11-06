<?php
/**
 * ROADMAP 5.4 regressions: privacy exporter + eraser over eh_rsvps, the
 * anonymize-in-place contract, and the sweeps' placeholder-email guard.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP) for the 5.4 privacy slice.
 */
class Privacy_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with start meta.
	 *
	 * @param string $start 'Y-m-d H:i:s' site time ('' = none).
	 * @return int Post ID.
	 */
	private static function make_event( string $start = '' ): int {
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
	 * Exporter: rows for the requested email arrive as one grouped item
	 * with every PII field; other emails' rows never appear.
	 */
	public function test_exporter_groups_rows_and_carries_pii(): void {
		$event = self::make_event();

		Plugin::instance()->repository()->create( 'Export Probe', 'export-probe@example.org', 'going', $event );
		Plugin::instance()->repository()->create( 'Export Probe', 'export-probe@example.org', 'waitlist', self::make_event() );
		Plugin::instance()->repository()->create( 'Other Person', 'other@example.org', 'going', $event );

		$result = Privacy::export_rows( 'export-probe@example.org' );

		$this->assertSame( 'event-hub', $result['data'][0]['group_id'] );
		$this->assertCount( 14, $result['data'][0]['data'], 'two rows x 7 fields' );

		$values = wp_list_pluck( $result['data'][0]['data'], 'value' );

		$this->assertContains( 'Export Probe', $values, 'name exported (the PII the requester owns)' );
		$this->assertNotContains( 'Other Person', $values, "other people's rows never appear" );
	}

	/**
	 * Exporter: no match / junk / page > 1 yields the empty contract.
	 */
	public function test_exporter_empty_contracts(): void {
		$this->assertSame( array( 'data' => array() ), Privacy::export_rows( 'nobody@example.org' ) );
		$this->assertSame( array( 'data' => array() ), Privacy::export_rows( 'not-an-email' ) );
		$this->assertSame( array( 'data' => array() ), Privacy::export_rows( 'export-probe@example.org', 2 ) );
	}

	/**
	 * Eraser: rows anonymize in place — placeholder email + redacted name +
	 * cleared token + swept stamps; counts stay truthful; nothing else
	 * changes.
	 */
	public function test_eraser_anonymizes_in_place(): void {
		$event  = self::make_event( gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		$before = Plugin::instance()->repository()->counts( $event );

		Plugin::instance()->repository()->create( 'Erase Probe', 'erase-probe@example.org', 'going', $event );

		$result = Privacy::erase_rows( 'erase-probe@example.org' );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( 0, $result['items_retained'] );
		$this->assertTrue( $result['done'] );

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eh_rsvps WHERE email LIKE %s", 'erased-%' ),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'Erased', $row['name'] );
		$this->assertStringStartsWith( 'erased-', $row['email'] );
		$this->assertStringNotContainsString( '@', $row['email'], 'placeholder must not be a deliverable address' );
		$this->assertSame( '', (string) $row['manage_token'], 'manage token cleared' );
		$this->assertNotSame( '', (string) $row['reminded_at'], 'reminder stamp set' );
		$this->assertGreaterThan( 0, (int) $row['status_notified_gen'], 'status stamp set' );

		// Capacity truth: counts unchanged by the erase.
		$this->assertSame( $before['going'] + 1, Plugin::instance()->repository()->counts( $event )['going'] );
	}

	/**
	 * Eraser: unknown email / junk / page > 1 = nothing-removed contract;
	 * erasing twice reports the second pass as a no-op (placeholders no
	 * longer match the request).
	 */
	public function test_eraser_empty_contracts_and_idempotence(): void {
		$event = self::make_event();
		Plugin::instance()->repository()->create( 'Twice Probe', 'twice-probe@example.org', 'going', $event );

		$first = Privacy::erase_rows( 'twice-probe@example.org' );
		$this->assertSame( 1, $first['items_removed'] );

		$second = Privacy::erase_rows( 'twice-probe@example.org' );
		$this->assertSame( 0, $second['items_removed'], 'second pass finds nothing (placeholders do not match)' );

		$this->assertSame( 0, Privacy::erase_rows( 'nobody@example.org' )['items_removed'] );
		$this->assertSame( 0, Privacy::erase_rows( 'x' )['items_removed'] );
		$this->assertSame( 0, Privacy::erase_rows( 'twice-probe@example.org', 3 )['items_removed'] );
	}

	/**
	 * The filters are registered under the core contract keys.
	 */
	public function test_filters_registered(): void {
		$privacy = new Privacy();
		$privacy->boot();

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'event-hub', $exporters );
		$this->assertArrayHasKey( 'event-hub', $erasers );
		$this->assertSame( 'Event Hub bookings', $exporters['event-hub']['exporter_friendly_name'] );
	}

	/**
	 * The sweeps self-heal placeholder rows: reminder stage stamps+skips
	 * them (never an endless retry), and send_status_change refuses them.
	 */
	public function test_sweeps_skip_undeliverable_rows(): void {
		add_filter(
			'pre_wp_mail',
			static fn () => true
		);

		update_option( Reminders::OPTION, true );

		// An in-window reminder target whose email is undeliverable.
		$event = self::make_event( gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $event, Reminders::HOURS_META, '24' );

		Plugin::instance()->repository()->create( 'Dead Row', 'dead-row-undeliverable', 'going', $event );

		global $wpdb;

		$row_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}eh_rsvps WHERE name = %s", 'Dead Row' )
		);

		$wpdb->update(
			$wpdb->prefix . 'eh_rsvps',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);

		$stats = ( new Reminders() )->run_tick();

		$this->assertSame( 0, $stats['notified'], 'undeliverable rows are never "notified"' );

		$reminded = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT reminded_at FROM {$wpdb->prefix}eh_rsvps WHERE id = %d", $row_id )
		);

		$this->assertNotSame( '', $reminded, 'the row is stamped so no tick re-examines it' );

		// Second tick: nothing left to do (no livelock).
		$again = ( new Reminders() )->run_tick();

		$this->assertSame( 0, $again['notified'] );

		remove_all_filters( 'pre_wp_mail' );
	}

	/**
	 * send_status_change refuses placeholder emails outright (defence in
	 * depth alongside the sweep guard).
	 */
	public function test_send_status_change_refuses_placeholder(): void {
		$event = self::make_event();

		$this->assertFalse(
			Notifier::send_status_change(
				array(
					'name'  => 'X',
					'email' => 'erased-42',
				),
				$event
			)
		);
	}
}
