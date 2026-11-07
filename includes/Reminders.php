<?php
/**
 * Pre-event attendee reminders (ROADMAP 5.2).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hourly cron sweep: finds upcoming events whose start sits inside their
 * reminder window (eh_reminder_hours meta, default 24h) and emails every
 * not-yet-reminded RSVP row. One-shot semantics ride the reminded_at column
 * (DB 1.5.0): a row is stamped only after wp_mail accepts, so a mail outage
 * retries next tick and a sent reminder never repeats.
 *
 * Rows created AFTER the window opened are skipped on purpose: someone who
 * signed up an hour before the event must not immediately receive a
 * "coming up" email (their confirmation already said when it starts).
 */
final class Reminders {

	/**
	 * Option name driving the Admin_Page notifications toggle.
	 *
	 * @var string
	 */
	public const OPTION = 'event_hub_notifications';

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'event_hub_reminders_tick';

	/**
	 * Meta key holding the per-event reminder window (hours before start).
	 *
	 * @var string
	 */
	public const HOURS_META = 'eh_reminder_hours';

	/**
	 * Default window when the meta is absent or empty: 24 hours.
	 *
	 * @var int
	 */
	public const DEFAULT_HOURS = 24;

	/**
	 * Maximum rows emailed per tick (bounded runtime; the next hourly tick
	 * continues the backlog).
	 *
	 * @var int
	 */
	public const BATCH_LIMIT = 100;

	/**
	 * Whether reminder/notification emails are enabled (Admin_Page toggle,
	 * default OFF: emails are opt-in exactly like confirmations).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Registers hooks and schedules the tick. Mirrors Series_Engine::boot().
	 * The cron stays scheduled even when disabled — one cached option read
	 * makes a disabled tick nearly free, and enabling needs no re-schedule.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action(
			self::CRON_HOOK,
			static function (): void {
				Plugin::instance()->reminders()->run_tick();
			}
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Un-schedules the tick (deactivation hook).
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * The event's reminder window in hours: digits-only meta (read-side twin
	 * of the save-time schema) clamped to 1..168; absent/empty/junk = the 24h
	 * default. 0 disables reminders for the event — WHICH requires
	 * distinguishing junk from a real 0, so the pattern check doubles as the
	 * disable switch ('0' IS digits, junk is not).
	 *
	 * @param int $event_id Event post ID.
	 * @return int Window hours (0 = reminders off for this event).
	 */
	public function window_hours( int $event_id ): int {
		$raw = trim( (string) get_post_meta( $event_id, self::HOURS_META, true ) );

		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			return self::DEFAULT_HOURS;
		}

		$hours = (int) $raw;

		if ( 0 === $hours ) {
			return 0;
		}

		return min( 168, max( 1, $hours ) );
	}

	/**
	 * One cron pass. Visible for tests (they call it directly and read the
	 * return); the registered cron callback ignores it per WP cron contract.
	 * No state is kept between runs.
	 *
	 * @return array{notified:int,skipped:int,status:array{sent:int,skipped:int}}
	 */
	public function run_tick(): array {
		$stats = array(
			'notified' => 0,
			'skipped'  => 0,
			'status'   => array(
				'sent'    => 0,
				'skipped' => 0,
			),
		);

		if ( ! self::enabled() ) {
			return $stats;
		}

		$repository = Plugin::instance()->repository();
		$event_ids  = $this->events_in_window();

		if ( array() !== $event_ids ) {
			$rows = $repository->list_for_reminder( $event_ids, self::BATCH_LIMIT );

			foreach ( $rows as $row ) {
				$event_id = (int) $row['event_id'];

				// Anonymized/legacy rows (email without @, ROADMAP 5.4) can
				// never receive mail: stamp them so no future tick re-examines
				// (returning false alone would retry forever).
				if ( false === is_email( (string) ( $row['email'] ?? '' ) ) ) {
					$repository->mark_reminded( (int) $row['id'] );
					++$stats['skipped'];

					continue;
				}

				// A row created after its event's window opened is skipped and
				// STAMPED: it never qualified for a reminder, and leaving it
				// unstamped would make every future tick re-examine it.
				$row_created = $this->to_timestamp( (string) ( $row['created_at'] ?? '' ) );
				$window_open = $this->window_opened_at( $event_id );

				if ( null === $row_created || null === $window_open || $row_created >= $window_open ) {
					if ( null !== $row_created ) {
						$repository->mark_reminded( (int) $row['id'] );
						++$stats['skipped'];
					}

					continue;
				}

				$sent = Notifier::send_reminder( $row, $event_id );

				// Stamp only on accepted send: a mail outage retries next tick.
				if ( ! $sent ) {
					++$stats['skipped'];

					continue;
				}

				if ( $repository->mark_reminded( (int) $row['id'] ) ) {
					++$stats['notified'];
				} else {
					// The stamp raced away (row deleted mid-tick) — the email is
					// harmless (a genuine attendee got it) but not countable.
					++$stats['skipped'];
				}
			}
		}

		$stats['status'] = $this->sweep_status_notices( $repository );

		return $stats;
	}

	/**
	 * Upcoming event IDs whose start lies inside their reminder window:
	 * window_open <= now < start. Uses site-time semantics identical to the
	 * cutoff/cron code (meta is site-local wall clock).
	 *
	 * @return array<int,int>
	 */
	private function events_in_window(): array {
		// "Now" in the SITE frame (stored meta is site-local wall clock):
		// UTC time + offset. Never current_time('timestamp') — it returns the
		// local-clock integer under a UTC name (the #43 calendar pattern).
		$now       = time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$in_window = array();

		$events = get_posts(
			array(
				'post_type'        => 'eh_event',
				'post_status'      => 'publish',
				'posts_per_page'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded cron sweep, hourly continuation covers the rest.
				'orderby'          => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology window, bounded CPT (the When-sort pattern).
				'meta_key'         => 'eh_start',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		foreach ( $events as $event ) {
			$event_id = (int) $event->ID;

			// Cancelled/postponed events (5.3) never mail "coming up".
			if ( '' !== Event_Status::get( $event_id ) ) {
				continue;
			}

			$hours = $this->window_hours( $event_id );

			if ( $hours < 1 ) {
				continue; // Reminders disabled for this event.
			}

			// Per-event timezone: eh_start is the event-zone wall clock.
			$start = $this->event_start_ts( $event_id );

			if ( null === $start ) {
				continue;
			}

			$window_open = $start - ( $hours * HOUR_IN_SECONDS );

			if ( $now >= $window_open && $now < $start ) {
				$in_window[] = $event_id;
			}
		}

		return $in_window;
	}

	/**
	 * When the event's reminder window opened (start minus N hours), or null
	 * without a parseable start.
	 *
	 * @param int $event_id Event post ID.
	 * @return int|null Unix timestamp.
	 */
	public function window_opened_at( int $event_id ): ?int {
		$hours = $this->window_hours( $event_id );

		if ( $hours < 1 ) {
			return null;
		}

		$start = $this->event_start_ts( $event_id );

		return null === $start ? null : $start - ( $hours * HOUR_IN_SECONDS );
	}

	/**
	 * Parses the event's eh_start wall clock IN THE EVENT'S ZONE (per-event
	 * timezone) to a timestamp.
	 *
	 * @param int $event_id Event post ID.
	 * @return int|null Unix timestamp, null when unset/unparseable.
	 */
	private function event_start_ts( int $event_id ): ?int {
		return self::to_timestamp( (string) get_post_meta( $event_id, 'eh_start', true ), Event_Timezone::for_event( $event_id ) );
	}

	/**
	 * Parses 'Y-m-d H:i:s' in the given zone (site zone when null — the
	 * stored created_at format is site-local) to a timestamp.
	 *
	 * @param string             $value Stored datetime.
	 * @param \DateTimeZone|null $zone  Interpretation zone (null = site).
	 * @return int|null
	 */
	private static function to_timestamp( string $value, ?\DateTimeZone $zone = null ): ?int {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $value, null !== $zone ? $zone : wp_timezone() );

		return false !== $datetime ? $datetime->getTimestamp() : null;
	}

	/**
	 * Status-notice sweep (ROADMAP 5.3): for every published event whose
	 * notice generation is 1+ (i.e. it transitioned to cancelled/postponed
	 * at least once), email the RSVP rows stamped behind the current
	 * generation, stamping each with the current generation — only after
	 * wp_mail accepts. The generation unit makes this idempotent AND
	 * re-notify-capable: un-cancel then re-cancel bumps the generation, so
	 * the new state reaches attendees that were told the previous one.
	 *
	 * @param Rsvp_Repository $repository Shared repository instance.
	 * @return array{sent:int,skipped:int}
	 */
	private function sweep_status_notices( Rsvp_Repository $repository ): array {
		$stats = array(
			'sent'    => 0,
			'skipped' => 0,
		);

		$event_ids = Event_Status::unswept_event_ids();

		if ( array() === $event_ids ) {
			return $stats;
		}

		$rows = $repository->list_behind_generation( $event_ids, self::BATCH_LIMIT );

		foreach ( $rows as $row ) {
			$event_id = (int) $row['event_id'];
			$gen      = Event_Status::generation( $event_id );

			// Anonymized/legacy rows (email without @, ROADMAP 5.4) can never
			// receive mail: stamp them at the current generation so no future
			// tick re-examines (a false return alone would retry forever).
			if ( false === is_email( (string) ( $row['email'] ?? '' ) ) ) {
				$repository->mark_status_notified( (int) $row['id'], $gen );
				++$stats['skipped'];

				continue;
			}

			$sent = Notifier::send_status_change( $row, $event_id );

			if ( ! $sent ) {
				++$stats['skipped'];

				continue; // Mail outage: retried next tick, unstamped.
			}

			if ( $repository->mark_status_notified( (int) $row['id'], $gen ) ) {
				++$stats['sent'];
			} else {
				++$stats['skipped'];
			}
		}

		return $stats;
	}
}
