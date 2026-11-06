<?php
/**
 * ROADMAP §4 uninstall-audit regressions: uninstall.php removes every piece
 * of plugin state — options, plugin posts, every registered eh_* meta key,
 * the eh_event_category terms, all three cron schedules and the rate-limit
 * transients.
 *
 * uninstall.php exits early without WP_UNINSTALL_PLUGIN and is written to
 * run standalone (no plugin bootstrap): requiring it here executes its whole
 * sweep and proves it never fatals (the require path is the single place
 * the \EventHub\Migrations FQN could rot silently — it did once).
 *
 * HARNESS LIMIT: the WP core testcase filters every query through
 * _drop_temporary_tables() (DROP TABLE → DROP TEMPORARY TABLE) and wraps
 * tests in a transaction, so uninstall.php's DDL (the eh_rsvps DROP) cannot
 * be observed mid-test — a permanent table silently survives. Every OTHER
 * sweep writes rows/options inside the same transaction and IS asserted
 * here; the DDL itself stays inspection-verified (3 literal lines).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Uninstall-contract tests (state sweep completeness).
 */
class Uninstall_Test extends WP_UnitTestCase {

	/**
	 * Seeds a maximal install: events (+child), venue, organizer, RSVP,
	 * category terms, meta keys, options, cron schedules, rate-limit
	 * transient.
	 *
	 * @return void
	 */
	private static function seed_everything(): void {
		$event = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_parent' => $event,
			)
		);

		self::factory()->post->create( array( 'post_type' => 'eh_venue' ) );
		self::factory()->post->create( array( 'post_type' => 'eh_organizer' ) );

		$festival_event = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $event, 'Concerts', 'eh_event_category' );
		wp_set_object_terms( $festival_event, 'Festivals', 'eh_event_category' );

		Plugin::instance()->repository()->create( 'Uninstall Probe', 'uninstall-probe@example.org', 'going', $event );

		foreach ( array( 'eh_start', 'eh_end', 'eh_capacity', 'eh_status', 'eh_status_generation' ) as $meta_key ) {
			update_post_meta( $event, $meta_key, '1' );
		}

		update_option( 'event_hub_db_version', '1.6.0' );
		update_option( 'event_hub_confirmations', '1' );
		update_option( 'event_hub_notifications', '1' );

		wp_schedule_event( time() + 100, 'hourly', Migrations::CRON_HOOK );
		wp_schedule_event( time() + 100, 'hourly', Series_Engine::CRON_HOOK );
		wp_schedule_event( time() + 100, 'hourly', Reminders::CRON_HOOK );

		set_transient( 'eh_rl_deadbeef', 3, 600 );
	}

	/**
	 * The uninstall sweep removes every (non-DDL) piece of plugin state and
	 * runs without fatals.
	 */
	public function test_uninstall_removes_all_state(): void {
		global $wpdb;

		self::seed_everything();

		// Pre-state: everything exists (DDL observations are out of scope —
		// see the harness note in the file docblock).
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eh_rsvps" ) );
		$this->assertNotFalse( get_option( 'event_hub_db_version' ) );
		$this->assertNotFalse( get_option( 'event_hub_confirmations' ) );
		$this->assertNotFalse( get_option( 'event_hub_notifications' ) );
		$this->assertSame( 2, (int) wp_count_terms( array( 'taxonomy' => 'eh_event_category', 'hide_empty' => false ) ) );
		$this->assertNotFalse( wp_next_scheduled( Migrations::CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Series_Engine::CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Reminders::CRON_HOOK ) );
		$this->assertNotFalse( get_transient( 'eh_rl_deadbeef' ) );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = '_transient_eh_rl_deadbeef'"
			)
		);

		$plugin_post_ids = get_posts(
			array(
				'post_type'      => array( 'eh_event', 'eh_venue', 'eh_organizer' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 5, $plugin_post_ids, '4 events (incl. 1 child) + venue + organizer' );

		// Run the whole standalone sweep — no-fatal IS an assertion here.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require EVENT_HUB_DIR . 'uninstall.php';

		// Options gone (delete_option is row-level, observable in-transaction).
		$this->assertFalse( get_option( 'event_hub_db_version', false ) );
		$this->assertFalse( get_option( 'event_hub_confirmations', false ) );
		$this->assertFalse( get_option( 'event_hub_notifications', false ) );

		// All plugin posts deleted.
		$this->assertSame(
			0,
			(int) count(
				get_posts(
					array(
						'post_type'      => array( 'eh_event', 'eh_venue', 'eh_organizer' ),
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => -1,
					)
				)
			)
		);

		// Meta sweep: every registered eh_* key gone.
		$remaining_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
				WHERE meta_key IN ( 'eh_start', 'eh_end', 'eh_venue', 'eh_capacity',
				'eh_rsvp_open_hours', 'eh_rsvp_close_hours', 'eh_series_freq',
				'eh_series_interval', 'eh_series_byweekday', 'eh_series_until',
				'eh_venue_id', 'eh_organizer_id', 'eh_venue_address',
				'eh_reminder_hours', 'eh_status', 'eh_status_generation' )"
		);
		$this->assertSame( 0, $remaining_meta );

		// Category terms removed (the §4 audit gap: wp_delete_post detaches
		// terms but leaves the term rows).
		$this->assertSame( 0, (int) wp_count_terms( array( 'taxonomy' => 'eh_event_category', 'hide_empty' => false ) ) );

		// All three cron schedules cleared (the §4 audit gap: the migration
		// tick was never cleared before).
		$this->assertFalse( wp_next_scheduled( Migrations::CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Series_Engine::CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Reminders::CRON_HOOK ) );

		// Rate-limit transients gone. Asserted at the ROW level: uninstall
		// deletes via a raw $wpdb query (uninstall.php loads no plugin
		// classes), which bypasses the alloptions cache — get_transient()
		// here would read the STALE cached value, not the DB.
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = '_transient_eh_rl_deadbeef'"
			)
		);
	}
}
