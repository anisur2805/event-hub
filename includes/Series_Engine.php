<?php
/**
 * Recurring events (ROADMAP §3.2): additive materialization of occurrences.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use DateTimeImmutable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Series engine. A series seed is any published eh_event whose
 * `eh_series_freq` meta is non-empty; occurrences are real published
 * eh_event posts with `post_parent = seed ID`, spawned ADDITIVELY (the
 * engine never edits or deletes existing events — each occurrence owns its
 * RSVP rows). Config lives in four flat, pattern-checked meta keys
 * (BUG 5 discipline, registered in Plugin::register_meta()):
 * eh_series_freq ('' | daily | weekly | monthly; '' = not a series),
 * eh_series_interval ('' | digits; '' = 1), eh_series_byweekday ('' |
 * comma-separated 0-6 list; '' = seed weekday), eh_series_until ('' | Y-m-d;
 * '' = horizon only).
 *
 * Triggers: meta writes on eh_series_freq (the block editor panel updates
 * meta via REST, which never fires save_post), save_post_eh_event (classic
 * saves), and a twice-daily cron that rolls the generation horizon
 * (HORIZON_MONTHS ahead of the last spawned date) forward.
 *
 * Read surfaces need NO changes: seeds and children are ordinary published
 * events, so REST list, ICS, JSON-LD, CSV, calendar, dashboard and the
 * admin picker all see them unchanged (docs/DECISIONS.md #44).
 */
final class Series_Engine {

	/**
	 * Time-of-day copied from the seed: parsing anchor used for date math
	 * (noons dodge DST edges and month overflow; the SEED's own wall-clock
	 * time is re-attached afterwards, see spawn()).
	 */
	private const ANCHOR_TIME = '12:00:00';

	/**
	 * Rolling generation horizon: occurrences are materialized this many
	 * months past the later of (last existing child, today). Open-ended
	 * series therefore always have ~HORIZON_MONTHS of bookable future.
	 */
	public const HORIZON_MONTHS = 6;

	/**
	 * Hard per-run spawn cap: a pathological rule (interval 1, daily, no
	 * until) never floods a single request or cron tick with thousands of
	 * inserts. The next horizon roll tops up.
	 */
	public const MAX_SPAWN_PER_RUN = 200;

	/**
	 * Cron hook name.
	 */
	public const CRON_HOOK = 'event_hub_series_tick';

	/**
	 * Registers hooks + schedules the horizon cron. Called from
	 * Plugin::boot().
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'save_post_eh_event', array( $this, 'maybe_materialize_on_save' ), 10, 3 );

		// Meta writes are DEFERRED to shutdown: the editor panel PUTs all
		// four series keys in one REST update and core applies them one
		// update_post_meta() at a time — materializing on the freq key
		// itself would read the other three mid-write (observed: until
		// parsed NULL, full horizon spawned with a stale rule). shutdown
		// fires after every key in the request has landed.
		add_action( 'added_post_meta', array( $this, 'queue_meta_materialize' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'queue_meta_materialize' ), 10, 4 );

		add_action( self::CRON_HOOK, array( $this, 'run_tick' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Queues a post ID for materialization on shutdown (meta-write path).
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function queue_meta_materialize( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );

		if ( 'eh_series_freq' !== $meta_key ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		static $queued = array();

		if ( isset( $queued[ $post_id ] ) ) {
			return;
		}

		$queued[ $post_id ] = true;

		add_action(
			'shutdown',
			static function () use ( $post_id ): void {
				$post = get_post( $post_id );

				if ( ! $post instanceof WP_Post || 'eh_event' !== $post->post_type || 'publish' !== $post->post_status ) {
					return;
				}

				( new Series_Engine() )->materialize( $post_id );
			}
		);
	}

	/**
	 * Materializes on save_post_eh_event when a PUBLISHED seed is saved
	 * through wp-admin (classic editor, quick edit, WP-CLI). Revisions
	 * and autosaves are skipped; the meta hooks cover the REST editor path.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  True when updating an existing post.
	 * @return void
	 */
	public function maybe_materialize_on_save( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$this->materialize( $post_id );
	}

	/**
	 * Cron tick: materialize every published seed. The meta/save hooks keep
	 * individual series current on edit; the tick is the horizon roll that
	 * keeps open-ended series booked ~HORIZON_MONTHS into the future as time
	 * passes, and it heals any spawn missed while the site was down.
	 *
	 * @return void
	 */
	public function run_tick(): void {
		$seeds = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				// Do not even list children as seed candidates (an occurrence
				// with forged freq meta); do_materialize gates this too.
				'post_parent'    => 0,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- series rule IS the identity; bounded CPT.
				'meta_query'     => array(
					array(
						'key'     => 'eh_series_freq',
						'value'   => array( 'daily', 'weekly', 'monthly' ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $seeds as $seed_id ) {
			$this->materialize( (int) $seed_id );
		}
	}

	/**
	 * Parses the four series meta values on a post into a validated rule.
	 * All inputs are defensive: junk meta (set directly in DB or via WP-CLI,
	 * bypassing the REST schema) degrades to the documented defaults, never
	 * throws. The meta REST schema pattern guards REST writes; this guards
	 * the read side.
	 *
	 * @param int $seed_id Seed post ID.
	 * @return array{freq:string,interval:int,byweekday:int[],until:DateTimeImmutable|null,start:DateTimeImmutable,time:string,duration:int}|null null when the post is not a materializable series (no freq, no parseable seed start).
	 */
	public function parse_rules( int $seed_id ): ?array {
		$freq = strtolower( trim( (string) get_post_meta( $seed_id, 'eh_series_freq', true ) ) );

		if ( ! in_array( $freq, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return null;
		}

		$start_raw = (string) get_post_meta( $seed_id, 'eh_start', true );

		if ( '' === trim( $start_raw ) ) {
			return null;
		}

		// Seed wall-clock strings are site-local; parse in the site zone. The
		// ISO-8601 variants summary() accepts are tolerated here too.
		try {
			$start = new \DateTimeImmutable( $start_raw, wp_timezone() );
		} catch ( \Exception $e ) {
			$start = date_create_immutable_from_format( 'Y-m-d H:i:s', $start_raw, wp_timezone() );
		}

		if ( false === $start ) {
			return null;
		}

		// Interval: 1 is both the '' default and the floor for junk.
		$interval_raw = (string) get_post_meta( $seed_id, 'eh_series_interval', true );
		$interval     = 1;

		if ( preg_match( '/^[0-9]+$/', $interval_raw ) ) {
			$interval = max( 1, min( 366, (int) $interval_raw ) );
		}

		// By-weekday (weekly only): 0 = Sunday .. 6 = Saturday. Junk items and
		// duplicates are dropped; '' falls back to the seed's own weekday.
		$byweekday = array();
		$by_raw    = (string) get_post_meta( $seed_id, 'eh_series_byweekday', true );

		if ( '' !== trim( $by_raw ) ) {
			foreach ( explode( ',', $by_raw ) as $piece ) {
				$piece = trim( $piece );

				if ( preg_match( '/^[0-6]$/', $piece ) ) {
					$byweekday[ (int) $piece ] = (int) $piece;
				}
			}

			$byweekday = array_values( $byweekday );
			sort( $byweekday );
		}

		if ( 'weekly' === $freq && array() === $byweekday ) {
			$byweekday = array( (int) $start->format( 'w' ) );
		}

		/*
		 * Until: a Y-m-d cap in the site zone (inclusive — events ON the
		 * until date spawn). date_create_immutable_from_format('Y-m-d')
		 * inherits the CURRENT clock time for the absent H:i:s fields (and
		 * getenv-dependent errors without an explicit getLastErrors()), so
		 * strictness comes from a round-trip check instead: re-format the
		 * parsed value and require byte-equality with the input. 2030-2-28
		 * or 2030-02-31 fail it; 2030-02-28 passes.
		 */
		$until     = null;
		$until_raw = trim( (string) get_post_meta( $seed_id, 'eh_series_until', true ) );

		if ( '' !== $until_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until_raw ) ) {
			$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $until_raw, wp_timezone() );

			if ( false !== $parsed && $parsed->format( 'Y-m-d' ) === $until_raw ) {
				$until = $parsed->setTime( 23, 59, 59 );
			}
		}

		// Seed duration (end - start) is carried to each spawn; an absent or
		// junk end degrades to zero duration (child end = child start).
		$duration = 0;

		$end_raw = (string) get_post_meta( $seed_id, 'eh_end', true );

		if ( '' !== trim( $end_raw ) ) {
			try {
				$end = new \DateTimeImmutable( $end_raw, wp_timezone() );
			} catch ( \Exception $e ) {
				$end = date_create_immutable_from_format( 'Y-m-d H:i:s', $end_raw, wp_timezone() );
			}

			if ( false !== $end ) {
				$duration = max( 0, $end->getTimestamp() - $start->getTimestamp() );
			}
		}

		return array(
			'freq'      => $freq,
			'interval'  => $interval,
			'byweekday' => $byweekday,
			'until'     => $until,
			'start'     => $start,
			'time'      => $start->format( 'H:i:s' ),
			'duration'  => $duration,
		);
	}

	/**
	 * Materializes missing occurrences for one seed: additive-only, bounded
	 * by the 6-month rolling horizon (from the later of last child or today)
	 * and an optional eh_series_until date. Runs are idempotent.
	 *
	 * @param int $seed_id Seed post ID.
	 * @return int Number of events spawned (0 when rule invalid/nothing due).
	 */
	public function materialize( int $seed_id ): int {
		static $in_run = array();

		if ( isset( $in_run[ $seed_id ] ) ) {
			return 0; // Re-entrant call in the same request (spawn -> save_post hook).
		}

		$in_run[ $seed_id ] = true;

		try {
			return $this->do_materialize( $seed_id );
		} finally {
			unset( $in_run[ $seed_id ] );
		}
	}

	/**
	 * Materialize body, separated so the re-entrancy guard stays readable.
	 *
	 * @param int $seed_id Seed post ID.
	 * @return int
	 */
	private function do_materialize( int $seed_id ): int {
		// Publish gate lives HERE (not only on the hooks): every entry path —
		// save hook, deferred meta queue, cron tick, direct call — inherits
		// it. Draft/pending seeds never spawn. Children never seed either:
		// an occurrence carrying eh_series_freq (forged DB/CLI write, or an
		// organizer setting "Repeats" on a copy) must not fork a sub-series
		// with its own spawn tree.
		$seed = get_post( $seed_id );

		if ( ! $seed instanceof WP_Post || 'eh_event' !== $seed->post_type || 'publish' !== $seed->post_status || $seed->post_parent > 0 ) {
			return 0;
		}

		$rule = $this->parse_rules( $seed_id );

		if ( null === $rule ) {
			return 0;
		}

		// Window: strictly AFTER the seed start, up to the later of (last
		// existing child start, today) + HORIZON_MONTHS, capped by until.
		$horizon_base = $this->last_child_start( $seed_id, $rule['start'] );
		$horizon      = $horizon_base->modify( '+' . self::HORIZON_MONTHS . ' months' );

		if ( null !== $rule['until'] && $rule['until'] < $horizon ) {
			$horizon = $rule['until'];
		}

		$existing = $this->existing_dates( $seed_id );

		$dates = $this->occurrence_dates( $rule, $horizon );

		$spawned = 0;

		foreach ( $dates as $date ) {
			if ( $spawned >= self::MAX_SPAWN_PER_RUN ) {
				break;
			}

			if ( isset( $existing[ $date->format( 'Y-m-d' ) ] ) ) {
				continue;
			}

			if ( ! $this->spawn( $seed_id, $rule, $date ) ) {
				continue;
			}

			++$spawned;
		}

		return $spawned;
	}

	/**
	 * Collects the Y-m-d start dates of the seed's existing children
	 * (post_parent = seed), used as the idempotency key: a date with an
	 * existing child never spawns a second one.
	 *
	 * @param int $seed_id Seed post ID.
	 * @return array<string,true> Y-m-d => true.
	 */
	private function existing_dates( int $seed_id ): array {
		$children = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'any',
				'post_parent'    => $seed_id,
				// A 6-month daily series is ~180 children; 500 covers the
				// horizon plus every legacy child on one page load.
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- idempotency scan over one series' children.
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		$dates = array();

		foreach ( $children as $child_id ) {
			$child_id = (int) $child_id;

			$start_raw = (string) get_post_meta( $child_id, 'eh_start', true );

			if ( '' === trim( $start_raw ) ) {
				continue;
			}

			try {
				$child_start = new \DateTimeImmutable( $start_raw, wp_timezone() );
			} catch ( \Exception $e ) {
				continue;
			}

			$dates[ $child_start->format( 'Y-m-d' ) ] = true;
		}

		return $dates;
	}

	/**
	 * Start of the horizon window: the later of the last child's start and
	 * today (site time). Noon anchor keeps month arithmetic overflow-free.
	 *
	 * @param int                $seed_id Seed post ID.
	 * @param \DateTimeImmutable $fallback Seed start (when no children exist).
	 * @return \DateTimeImmutable
	 */
	private function last_child_start( int $seed_id, \DateTimeImmutable $fallback ): \DateTimeImmutable {
		$children = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'any',
				'post_parent'    => $seed_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology on a bounded per-parent set.
				'meta_key'       => 'eh_start',
				'order'          => 'DESC',
			)
		);

		if ( array() === $children ) {
			return $fallback;
		}

		$child = $children[0];

		$start_raw = (string) get_post_meta( (int) $child->ID, 'eh_start', true );

		if ( '' === trim( $start_raw ) ) {
			return $fallback;
		}

		try {
			$parsed = new \DateTimeImmutable( $start_raw, wp_timezone() );
		} catch ( \Exception $e ) {
			return $fallback;
		}

		$today = new \DateTimeImmutable( self::ANCHOR_TIME, wp_timezone() );

		return $parsed > $today ? $parsed : $today;
	}

	/**
	 * Computes occurrence DATES inside the horizon window. Date math runs on
	 * noon anchors in the site zone (DST edges and month overflow never
	 * shift the day); the seed's wall-clock time is attached in spawn().
	 *
	 * @param array<string,mixed> $rule    Parsed rule.
	 * @param \DateTimeImmutable  $horizon Exclusive upper bound (local date).
	 * @return \DateTimeImmutable[] Noon-anchored dates, ascending.
	 */
	private function occurrence_dates( array $rule, \DateTimeImmutable $horizon ): array {
		$dates = array();

		/*
		 * Generous date cap (= 2x the spawn cap): the date generator is the
		 * inner safety bound — a pathological no-until rule enumerates at
		 * most this many candidate dates per run, and the spawn loop applies
		 * its own MAX_SPAWN_PER_RUN on top. count() lives in a variable
		 * (Squiz.PHP.DisallowSizeFunctionsInLoops).
		 */
		$date_cap = self::MAX_SPAWN_PER_RUN * 2;

		// Cursor starts one step past the seed so the seed never spawns a
		// duplicate of itself.
		$cursor = $rule['start'];

		switch ( $rule['freq'] ) {
			case 'daily':
				$daily_count = 0;

				do {
					$cursor = $cursor->modify( '+' . $rule['interval'] . ' days' );

					if ( $cursor >= $horizon ) {
						break;
					}

					$dates[] = $cursor;
					++$daily_count;
				} while ( $daily_count < $date_cap );

				break;

			case 'weekly':
				// Candidate grid: every interval-th week from the seed's week,
				// on each selected weekday. Week identity is anchored on the
				// seed's own week: days are offsets from the seed's week start.
				$week_anchor  = $rule['start']->modify( 'monday this week' );
				$weekly_count = 0;

				do {
					foreach ( $rule['byweekday'] as $dow ) {
						// Monday-anchored offset: 0=Mon .. 6=Sun of that week.
						$offset    = ( ( $dow + 6 ) % 7 );
						$candidate = $week_anchor->modify( '+' . $offset . ' days' );

						if ( $candidate <= $rule['start'] ) {
							continue; // Seed's own date/time never spawns.
						}

						if ( $candidate >= $horizon ) {
							continue;
						}

						$dates[] = $candidate;
						++$weekly_count;
					}

					$week_anchor = $week_anchor->modify( '+' . $rule['interval'] . ' weeks' );
				} while ( $week_anchor < $horizon && $weekly_count < $date_cap );

				// Grid order is per-week; restore chronological order.
				usort(
					$dates,
					static fn ( \DateTimeImmutable $a, \DateTimeImmutable $b ): int => $a->getTimestamp() <=> $b->getTimestamp()
				);

				break;

			case 'monthly':
				$day           = (int) $rule['start']->format( 'j' );
				$monthly_count = 0;

				/*
				 * First-of-month ANCHOR stepping: advancing Jan 31 by +1 month
				 * in DateTimeImmutable overflows to Mar 3 (Feb has no 31st),
				 * silently skipping February. Stepping the 1st of the month
				 * never overflows; the seed day is clamped AFTER each step.
				 */
				$month_anchor = $rule['start']->setDate(
					(int) $rule['start']->format( 'Y' ),
					(int) $rule['start']->format( 'n' ),
					1
				);

				do {
					$month_anchor = $month_anchor->modify( '+' . $rule['interval'] . ' months' );

					// Clamp 29/30/31 into shorter months (Jan 31 -> Feb 28).
					$clamped = min( $day, (int) $month_anchor->format( 't' ) );

					$candidate = $month_anchor->setDate(
						(int) $month_anchor->format( 'Y' ),
						(int) $month_anchor->format( 'n' ),
						$clamped
					);

					if ( $candidate >= $horizon ) {
						break;
					}

					$dates[] = $candidate;
					++$monthly_count;
				} while ( $monthly_count < $date_cap );

				break;
		}

		return $dates;
	}

	/**
	 * Creates one occurrence: a published eh_event child carrying the seed's
	 * content, terms, and event meta; eh_start/eh_end carry the occurrence
	 * date + the seed's wall-clock time (end = start + seed duration).
	 *
	 * @param int                 $seed_id Seed post ID.
	 * @param array<string,mixed> $rule    Parsed rule.
	 * @param \DateTimeImmutable  $date    Noon-anchored occurrence date (site zone).
	 * @return bool True when a child was created.
	 */
	private function spawn( int $seed_id, array $rule, \DateTimeImmutable $date ): bool {
		$seed = get_post( $seed_id );

		if ( ! $seed instanceof WP_Post ) {
			return false;
		}

		// Re-attach the seed's wall-clock time to the occurrence date. The
		// time string is engine-produced (H:i:s from the parsed seed start),
		// but sscanf keeps it honest even if that invariant ever drifts.
		$time_parts = sscanf( (string) $rule['time'], '%02d:%02d:%02d' );
		$h          = is_array( $time_parts ) && null !== $time_parts[0] ? (int) $time_parts[0] : 0;
		$i          = is_array( $time_parts ) && null !== $time_parts[1] ? (int) $time_parts[1] : 0;
		$s          = is_array( $time_parts ) && null !== $time_parts[2] ? (int) $time_parts[2] : 0;

		$child_start = $date->setTime( $h, $i, $s );
		$child_end   = $child_start->modify( '+' . $rule['duration'] . ' seconds' );

		$child_id = wp_insert_post(
			array(
				'post_type'    => 'eh_event',
				'post_status'  => 'publish',
				'post_author'  => (int) $seed->post_author,
				'post_title'   => (string) $seed->post_title,
				'post_content' => (string) $seed->post_content,
				'post_excerpt' => (string) $seed->post_excerpt,
				'post_parent'  => $seed_id,
			),
			true
		);

		if ( is_wp_error( $child_id ) || 0 === (int) $child_id ) {
			return false;
		}

		$child_id = (int) $child_id;

		// Event meta: occurrence dates + copies of venue/capacity/cutoffs.
		// Series meta is deliberately NOT copied: a child with a non-empty
		// eh_series_freq would become its own seed (documented semantics).
		$meta_copy = array(
			'eh_start'            => $child_start->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
			'eh_end'              => $child_end->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
			'eh_venue'            => (string) get_post_meta( $seed_id, 'eh_venue', true ),
			'eh_venue_id'         => (string) get_post_meta( $seed_id, 'eh_venue_id', true ),
			'eh_organizer_id'     => (string) get_post_meta( $seed_id, 'eh_organizer_id', true ),
			'eh_capacity'         => (string) get_post_meta( $seed_id, 'eh_capacity', true ),
			'eh_rsvp_open_hours'  => (string) get_post_meta( $seed_id, 'eh_rsvp_open_hours', true ),
			'eh_rsvp_close_hours' => (string) get_post_meta( $seed_id, 'eh_rsvp_close_hours', true ),
		);

		foreach ( $meta_copy as $key => $value ) {
			update_post_meta( $child_id, $key, $value );
		}

		// Terms: copy the seed's categories so lists/filters see occurrences.
		$term_ids = wp_get_object_terms( $seed_id, 'eh_event_category', array( 'fields' => 'ids' ) );

		if ( is_array( $term_ids ) && array() !== $term_ids ) {
			wp_set_object_terms( $child_id, array_map( 'intval', $term_ids ), 'eh_event_category' );
		}

		// Featured image rides along (calendar/list cards may show it).
		$thumb_id = (int) get_post_meta( $seed_id, '_thumbnail_id', true );

		if ( $thumb_id > 0 ) {
			set_post_thumbnail( $child_id, $thumb_id );
		}

		return true;
	}

	/**
	 * Removes the cron event on plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
