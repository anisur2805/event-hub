<?php
/**
 * Tests for the §3.2 recurring-events series engine: rule parsing,
 * additive materialization (dates, copies, idempotency), cron wiring, and
 * the surfaces contract (occurrences behave as ordinary events).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Series engine test case.
 */
class Series_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with meta. The default start is a fixed
	 * future Wednesday noon so weekly by-weekday tests are deterministic.
	 *
	 * @param array<string,mixed> $args Post args.
	 * @param array<string,string> $meta Meta key/value pairs.
	 * @return int
	 */
	private function make_event( array $args = array(), array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'eh_event',
					'post_status' => 'publish',
					'post_title'  => 'Series Seed',
				),
				$args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * A fixed published event: Wednesday 2030-01-09 18:00:00 site time, ends
	 * 20:00:00 (7200s duration), capacity 40, venue copied on spawn.
	 *
	 * @param array<string,string> $extra_meta Additional meta on the seed.
	 * @return int
	 */
	private function make_seed( array $extra_meta = array() ): int {
		return $this->make_event(
			array(),
			array_merge(
				array(
					'eh_start'         => '2030-01-09 18:00:00',
					'eh_end'           => '2030-01-09 20:00:00',
					'eh_venue'         => 'Hall B',
					'eh_capacity'      => '40',
					'eh_series_freq'   => 'weekly',
				),
				$extra_meta
			)
		);
	}

	/**
	 * Child start dates (Y-m-d H:i:s strings) for a seed, ASC.
	 *
	 * @param int $seed_id Seed post ID.
	 * @return string[]
	 */
	private function child_starts( int $seed_id ): array {
		$children = get_posts(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'any',
				'post_parent' => $seed_id,
				'posts_per_page' => 500,
				'orderby'     => 'meta_value',
				'meta_key'    => 'eh_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test-side chronology.
				'order'       => 'ASC',
				'no_found_rows' => true,
			)
		);

		$starts = array();

		foreach ( $children as $child ) {
			$starts[] = (string) get_post_meta( (int) $child->ID, 'eh_start', true );
		}

		return $starts;
	}

	/**
	 * Junction helper: grab the first child's ID.
	 *
	 * @param int $seed_id Seed post ID.
	 * @return int 0 when no child exists.
	 */
	private function first_child_id( int $seed_id ): int {
		$children = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'any',
				'post_parent'    => $seed_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array() === $children ? 0 : (int) $children[0];
	}

	/**
	 * Rule parsing: junk meta degrades, valid meta parses, missing start
	 * blocks materialization.
	 *
	 * @return void
	 */
	public function test_parse_rules_validation(): void {
		$engine = new Series_Engine();

		// No freq = not a series.
		$plain = $this->make_event( array(), array( 'eh_start' => '2030-01-09 18:00:00' ) );
		$this->assertNull( $engine->parse_rules( $plain ) );

		// Junk freq (DB/CLI write bypassing the REST schema) = not a series.
		$junk = $this->make_event(
			array(),
			array( 'eh_start' => '2030-01-09 18:00:00', 'eh_series_freq' => 'annually' )
		);
		$this->assertNull( $engine->parse_rules( $junk ) );

		// Freq without start = not materializable.
		$no_start = $this->make_event( array(), array( 'eh_series_freq' => 'daily' ) );
		$this->assertNull( $engine->parse_rules( $no_start ) );

		// Valid weekly rule; empty interval/byweekday default to 1 and the
		// seed's weekday (Wednesday = 3).
		$seed = $this->make_seed();
		$rule = $engine->parse_rules( $seed );

		$this->assertNotNull( $rule );
		$this->assertSame( 'weekly', $rule['freq'] );
		$this->assertSame( 1, $rule['interval'] );
		$this->assertSame( array( 3 ), $rule['byweekday'] );
		$this->assertSame( '18:00:00', $rule['time'] );
		$this->assertSame( 7200, $rule['duration'] );
		$this->assertNull( $rule['until'] );
	}

	/**
	 * Weekly materialization: children one week apart carrying the seed's
	 * time of day and duration, all published with post_parent set.
	 *
	 * @return void
	 */
	public function test_weekly_spawn_dates_and_copies(): void {
		$seed = $this->make_seed( array( 'eh_series_until' => '2030-02-28' ) );

		$engine    = new Series_Engine();
		$spawned   = $engine->materialize( $seed );
		$starts    = $this->child_starts( $seed );

		// Jan 9 + horizon to Feb 28 inclusive: Jan 16, 23, 30; Feb 6, 13, 20, 27.
		$this->assertSame( 7, $spawned );
		$this->assertSame( '2030-01-16 18:00:00', $starts[0] );
		$this->assertSame( '2030-02-27 18:00:00', $starts[ count( $starts ) - 1 ] );

		// Copies carried to the first child.
		$child_id = $this->first_child_id( $seed );
		$this->assertGreaterThan( 0, $child_id );

		$child = get_post( $child_id );
		$this->assertSame( 'publish', $child->post_status );
		$this->assertSame( $seed, $child->post_parent );
		$this->assertSame( 'Series Seed', $child->post_title );
		$this->assertSame( 'Hall B', get_post_meta( $child_id, 'eh_venue', true ) );
		$this->assertSame( '40', get_post_meta( $child_id, 'eh_capacity', true ) );
		$this->assertSame( '2030-01-16 20:00:00', get_post_meta( $child_id, 'eh_end', true ) );

		// Series meta is NOT copied (a child with freq would be its own seed).
		$this->assertSame( '', get_post_meta( $child_id, 'eh_series_freq', true ) );
	}

	/**
	 * Materialize is idempotent: a second run spawns nothing.
	 *
	 * @return void
	 */
	public function test_materialize_is_idempotent(): void {
		$seed = $this->make_seed( array( 'eh_series_until' => '2030-02-28' ) );

		$engine = new Series_Engine();
		$first  = $engine->materialize( $seed );
		$second = $engine->materialize( $seed );

		$this->assertSame( 7, $first );
		$this->assertSame( 0, $second );
		$this->assertCount( 7, $this->child_starts( $seed ) );
	}

	/**
	 * Interval 2 weekly = fortnightly occurrences.
	 *
	 * @return void
	 */
	public function test_fortnightly_interval(): void {
		$seed = $this->make_seed(
			array(
				'eh_series_interval' => '2',
				'eh_series_until'    => '2030-03-15',
			)
		);

		$engine = new Series_Engine();
		$engine->materialize( $seed );
		$starts = $this->child_starts( $seed );

		// Every 2nd Wednesday from Jan 9 through Mar 15: Jan 23, Feb 6, 20, Mar 6.
		$this->assertSame( '2030-01-23 18:00:00', $starts[0] );
		$this->assertSame( '2030-03-06 18:00:00', $starts[ count( $starts ) - 1 ] );
		$this->assertCount( 4, $starts );
	}

	/**
	 * Multi-weekday selection: Mon + Fri weeks.
	 *
	 * @return void
	 */
	public function test_multi_weekday(): void {
		// Seed Friday 2030-01-11; rule selects Monday(1) + Friday(5).
		$seed = $this->make_event(
			array(),
			array(
				'eh_start'            => '2030-01-11 09:00:00',
				'eh_end'              => '2030-01-11 11:00:00',
				'eh_series_freq'      => 'weekly',
				'eh_series_byweekday' => '1,5',
				'eh_series_until'     => '2030-01-21',
			)
		);

		$engine = new Series_Engine();
		$engine->materialize( $seed );
		$starts = $this->child_starts( $seed );

		// After Fri Jan 11, through Jan 21 inclusive: Mon 14, Fri 18, Mon 21.
		$this->assertSame(
			array( '2030-01-14 09:00:00', '2030-01-18 09:00:00', '2030-01-21 09:00:00' ),
			$starts
		);
	}

	/**
	 * Monthly occurrence day clamps into shorter months (Jan 31 seed).
	 *
	 * @return void
	 */
	public function test_monthly_day_clamp(): void {
		$seed = $this->make_event(
			array(),
			array(
				'eh_start'       => '2031-01-31 10:00:00',
				'eh_end'         => '2031-01-31 12:00:00',
				'eh_series_freq' => 'monthly',
				// Through April covers the Feb clamp (28, 2031 not a leap year).
			)
		);

		update_post_meta( $seed, 'eh_series_until', '2031-05-01' );

		$engine = new Series_Engine();
		$engine->materialize( $seed );
		$starts = $this->child_starts( $seed );

		// Feb 28 (clamped), Mar 31, Apr 30 (clamped).
		$this->assertContains( '2031-02-28 10:00:00', $starts );
		$this->assertContains( '2031-03-31 10:00:00', $starts );
		$this->assertContains( '2031-04-30 10:00:00', $starts );
		$this->assertCount( 3, $starts );
	}

	/**
	 * Daily frequency with a custom interval.
	 *
	 * @return void
	 */
	public function test_daily_every_n_days(): void {
		$seed = $this->make_event(
			array(),
			array(
				'eh_start'            => '2030-06-01 08:30:00',
				'eh_end'              => '2030-06-01 09:30:00',
				'eh_series_freq'      => 'daily',
				'eh_series_interval'  => '3',
				'eh_series_until'     => '2030-06-13',
			)
		);

		$engine = new Series_Engine();
		$engine->materialize( $seed );
		$starts = $this->child_starts( $seed );

		// Jun 4, 7, 10 (stop before 13 — until is INCLUSIVE of the 13th but
		// the 13th is exactly the until... no: until 2030-06-13 23:59:59,
		// so Jun 13 08:30 < horizon. Expect Jun 4, 7, 10, 13.
		$this->assertSame(
			array( '2030-06-04 08:30:00', '2030-06-07 08:30:00', '2030-06-10 08:30:00', '2030-06-13 08:30:00' ),
			$starts
		);
	}

	/**
	 * Rule changes are additive: existing children keep their dates; new
	 * candidates spawn after.
	 *
	 * @return void
	 */
	public function test_rule_change_is_additive(): void {
		$seed = $this->make_seed( array( 'eh_series_until' => '2030-01-31' ) );

		$engine = new Series_Engine();
		$engine->materialize( $seed );

		$before = $this->child_starts( $seed );

		// Extend reach: existing Jan children survive identically...
		update_post_meta( $seed, 'eh_series_until', '2030-03-31' );
		$engine->materialize( $seed );

		$after = $this->child_starts( $seed );

		foreach ( $before as $index => $start ) {
			$this->assertSame( $start, $after[ $index ] );
		}

		$this->assertCount( 11, $after ); // Jan 16/23/30 + 4 Feb + 4 Mar (Wednesdays).
	}

	/**
	 * Draft seeds spawn nothing.
	 *
	 * @return void
	 */
	public function test_draft_seed_spawns_nothing(): void {
		$seed = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'draft',
				'post_title'  => 'Draft Seed',
			)
		);

		update_post_meta( $seed, 'eh_start', '2030-01-09 18:00:00' );
		update_post_meta( $seed, 'eh_series_freq', 'weekly' );

		$engine = new Series_Engine();

		// Direct call (the engine body, no hook) still refuses non-published.
		$this->assertSame( 0, $engine->materialize( $seed ) );
		$this->assertCount( 0, $this->child_starts( $seed ) );
	}

	/**
	 * The save hook materializes a published seed and skips revisions.
	 *
	 * @return void
	 */
	public function test_save_hook_materializes_published_seed(): void {
		$seed = $this->make_seed( array( 'eh_series_until' => '2030-01-30' ) );

		// Simulate a classic wp-admin save (published event).
		$seed_post = get_post( $seed );
		$engine    = new Series_Engine();
		$engine->maybe_materialize_on_save( $seed, $seed_post, true );

		$this->assertCount( 3, $this->child_starts( $seed ) ); // Jan 16, 23, 30.
	}

	/**
	 * Fires the shutdown action without core's wp_ob_end_flush_all handler
	 * (priority 1 on shutdown), which would close PHPUnit's own output
	 * buffers and flag the test risky.
	 *
	 * @return void
	 */
	private function fire_shutdown(): void {
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		do_action( 'shutdown' );

		add_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
	}

	/**
	 * The meta-write path materializes DEFERRED (shutdown), after all four
	 * series keys of a REST meta update have landed — the mid-write bug this
	 * contract exists for (freq landing first read the other keys missing).
	 *
	 * @return void
	 */
	public function test_meta_hook_materializes_deferred_to_shutdown(): void {
		$seed = $this->make_event(
			array(),
			array(
				'eh_start' => '2030-01-09 18:00:00',
				'eh_end'   => '2030-01-09 20:00:00',
			)
		);

		$engine = new Series_Engine();
		$engine->boot();

		// The REST-editor sequence: the other three keys land, THEN freq.
		// boot()'s hooks queue automatically on the freq write — the same
		// request the editor panel produces. Nothing spawns synchronously
		// (the old mid-write bug spawned the full horizon here).
		update_post_meta( $seed, 'eh_series_interval', '' );
		update_post_meta( $seed, 'eh_series_until', '2030-01-30' );
		update_post_meta( $seed, 'eh_series_byweekday', '' );
		update_post_meta( $seed, 'eh_series_freq', 'weekly' );

		$this->assertCount( 0, $this->child_starts( $seed ) );

		// Shutdown resolves the queue; the until cap then applies.
		$this->fire_shutdown();

		$this->assertSame(
			array( '2030-01-16 18:00:00', '2030-01-23 18:00:00', '2030-01-30 18:00:00' ),
			$this->child_starts( $seed )
		);
	}

	/**
	 * The deferred queue ignores non-published posts at shutdown time.
	 *
	 * @return void
	 */
	public function test_meta_hook_queue_skips_drafts(): void {
		$seed = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'draft',
				'post_title'  => 'Draft Seed',
			)
		);

		update_post_meta( $seed, 'eh_start', '2030-01-09 18:00:00' );

		( new Series_Engine() )->boot();

		// The freq write on a draft queues nothing (queue-time gate); the
		// shutdown closure's publish re-check would refuse it anyway.
		update_post_meta( $seed, 'eh_series_freq', 'weekly' );

		$this->fire_shutdown();

		// Belt-and-braces: a direct materialize also refuses (run-time gate).
		$this->assertCount( 0, $this->child_starts( $seed ) );
		$this->assertSame( 0, ( new Series_Engine() )->materialize( $seed ) );
	}

	/**
	 * Occurrences are ordinary events: they surface through the SAME list
	 * query contract (published + eh_start) the REST route and calendar use.
	 *
	 * @return void
	 */
	public function test_children_surface_in_list_queries(): void {
		$seed = $this->make_seed( array( 'eh_series_until' => '2030-02-28' ) );

		$engine = new Series_Engine();
		$engine->materialize( $seed );

		$calendar = Render_Blocks::calendar_month_events( array( 'year' => 2030, 'month' => 1 ) );

		// Seed (Jan 9) + 3 January children.
		$this->assertSame( 4, count( $calendar ) );
	}

	/**
	 * Cron wiring: the tick fires materialization for every published seed.
	 *
	 * @return void
	 */
	public function test_cron_tick_materializes_all_seeds(): void {
		$seed_a = $this->make_seed( array( 'eh_series_until' => '2030-01-30' ) );
		$seed_b = $this->make_event(
			array(),
			array(
				'eh_start'        => '2030-02-01 10:00:00',
				'eh_end'          => '2030-02-01 12:00:00',
				'eh_series_freq'  => 'daily',
				'eh_series_until' => '2030-02-03',
			)
		);

		$engine = new Series_Engine();
		$engine->run_tick();

		// Both seeds spawned into their horizon windows: A = Jan 16/23/30,
		// B = Feb 2 + Feb 3 (Feb 1 is the seed itself).
		$this->assertSame(
			array( '2030-01-16 18:00:00', '2030-01-23 18:00:00', '2030-01-30 18:00:00' ),
			$this->child_starts( $seed_a )
		);
		$this->assertSame(
			array( '2030-02-02 10:00:00', '2030-02-03 10:00:00' ),
			$this->child_starts( $seed_b )
		);
	}
}
