<?php
/**
 * RSVP persistence: all {prefix}eh_rsvps SQL and the capacity-safe write path.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for RSVP rows. No output escaping here (data layer only); every
 * query is prepared. Direct, cache-free SQL is the design: capacity correctness
 * requires a fresh read inside the write statement (docs/ARCHITECTURE.md
 * "Capacity-safe write"), so the uncached-direct-query warnings are accepted.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class Rsvp_Repository {

	/**
	 * Full table name, resolved lazily from $wpdb.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eh_rsvps';
	}

	/**
	 * Creates an RSVP using the capacity-safe INSERT ... SELECT guarded by an
	 * advisory lock (docs/ARCHITECTURE.md "Capacity-safe write", #14).
	 *
	 * @param string $name     Sanitized attendee name.
	 * @param string $email    Sanitized, validated email address.
	 * @param string $status   Either 'going' or 'waitlist'.
	 * @param int    $event_id Event post ID.
	 * @return array{rsvp:array<string,mixed>,counts:array{going:int,waitlist:int}}|WP_Error
	 */
	public function create( string $name, string $email, string $status, int $event_id ) {
		global $wpdb;

		if ( 'waitlist' !== $status && 'going' !== $status ) {
			return new WP_Error( 'eh_invalid_status', __( 'Status must be either going or waitlist.', 'event-hub' ), array( 'status' => 400 ) );
		}

		// VARCHAR(191) storage cap: a longer address would be truncated
		// silently by MySQL while the API still answered 201 (stage-4 BUG 4 —
		// a 212-char address persisted as 191 bytes). Reject past 190 before
		// the INSERT so no data is corrupted.
		if ( strlen( $email ) > 190 ) {
			return new WP_Error( 'eh_invalid_email', __( 'Please provide a valid email address.', 'event-hub' ), array( 'status' => 400 ) );
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type || 'publish' !== $event->post_status ) {
			return new WP_Error( 'eh_event_not_found', __( 'No such published event.', 'event-hub' ), array( 'status' => 404 ) );
		}

		$end_gmt = $this->post_meta_gmt( $event_id, 'eh_end' );

		// Unknown end date is treated as not-passed (conservative: never block a
		// valid RSVP on missing data; phase 5 data audit owns content hygiene).
		if ( null !== $end_gmt && $end_gmt < time() ) {
			return new WP_Error( 'eh_event_passed', __( 'This event has already ended.', 'event-hub' ), array( 'status' => 409 ) );
		}

		$lock = 'eh_rsvp_' . $event_id;

		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, 2 )', $lock )
		);

		if ( '1' !== (string) $acquired ) {
			return new WP_Error( 'eh_busy', __( 'The event is busy processing another RSVP. Please retry.', 'event-hub' ), array( 'status' => 409 ) );
		}

		/*
		 * Existing-registration check (inside the lock): maps the common case
		 * to the contract error `eh_duplicate` regardless of requested status
		 * or remaining capacity. The UNIQUE(event_id,email) key below remains
		 * the authoritative race guard — a concurrent duplicate that slips
		 * past this read still fails inside the INSERT and maps to the same
		 * error code.
		 */
		$existing = $this->find_existing( $event_id, $email );

		if ( null !== $existing ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );

			return new WP_Error( 'eh_duplicate', __( 'This email address is already registered for the event.', 'event-hub' ), array( 'status' => 409 ) );
		}

		$table = $this->table();

		/*
		 * Single atomic statement: capacity is re-checked inside the INSERT's
		 * WHERE clause, so there is no check-then-insert gap. Waitlist rows
		 * bypass capacity. Missing/empty capacity meta => unlimited. The
		 * subquery on postmeta re-checks capacity inside the statement and
		 * never returns NULL (COUNT is 0 for a missing meta row), so `going`
		 * rows insert only while the going count is strictly below capacity.
		 */
		// Assemble the statement first; phpcs:ignore below sits on the query
		// line that holds the $table variable (value from $wpdb->prefix, never
		// user input). All values remain placeholders.
		$insert_query = 'INSERT INTO ' . $table . " (event_id, name, email, status, created_at)
			SELECT %d, %s, %s, %s, %s FROM DUAL
			WHERE %s = 'waitlist'
			   OR ( (SELECT COUNT(*) FROM " . $table . " WHERE event_id = %d AND status = 'going')
			        < COALESCE( (SELECT CAST(m.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} m
			                     WHERE m.post_id = %d AND m.meta_key = 'eh_capacity'
			                       AND m.meta_value REGEXP '^[0-9]+$'), 999999999 ) )"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table derives from $wpdb->prefix.

		$insert_sql = $wpdb->prepare(
			$insert_query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled above; values are placeholders.
			$event_id,
			$name,
			$email,
			$status,
			current_time( 'mysql' ),
			$status,
			$event_id,
			$event_id
		);

		$suppress = $wpdb->suppress_errors( true );

		try {
			$inserted = $wpdb->query( $insert_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$db_error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $suppress );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}

		if ( false === $inserted || 0 === $inserted ) {
			// MySQL reports the UNIQUE violation as "Duplicate entry ... for
			// key ..." (SQLSTATE 23000 / error 1062); the message text is the
			// reliable discriminator since wpdb does not expose errno.
			$is_duplicate = false !== stripos( $db_error, 'duplicate entry' )
				|| false !== stripos( $db_error, 'uniq_event_email' );

			if ( $is_duplicate ) {
				return new WP_Error( 'eh_duplicate', __( 'This email address is already registered for the event.', 'event-hub' ), array( 'status' => 409 ) );
			}

			$error = new WP_Error( 'eh_capacity_full', __( 'This event is fully booked.', 'event-hub' ), array( 'status' => 409 ) );
			$error->add_data( array( 'counts' => $this->counts( $event_id ) ), 'eh_capacity_full' );

			return $error;
		}

		$row = $this->get( (int) $wpdb->insert_id );

		if ( null === $row ) {
			return new WP_Error( 'eh_busy', __( 'The RSVP could not be read back after insert.', 'event-hub' ), array( 'status' => 409 ) );
		}

		return array(
			'rsvp'   => $row,
			'counts' => $this->counts( $event_id ),
		);
	}

	/**
	 * Counts going and waitlist rows for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{going:int,waitlist:int}
	 */
	public function counts( int $event_id ): array {
		global $wpdb;

		$table = $this->table();

		$query = "SELECT status, COUNT(*) AS total FROM {$table} WHERE event_id = %d GROUP BY status";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			ARRAY_A
		);

		$counts = array(
			'going'    => 0,
			'waitlist' => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = (string) ( $row['status'] ?? '' );

				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = (int) $row['total'];
				}
			}
		}

		return $counts;
	}

	/**
	 * Paginated RSVP listing for one event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param int    $per_page Rows per page.
	 * @param int    $offset   Row offset.
	 * @param string $status   Optional status filter ('going'|'waitlist').
	 * @return array{rsvps:array<int,array<string,mixed>>,total:int}
	 */
	public function list( int $event_id, int $per_page, int $offset, string $status = '' ): array {
		global $wpdb;

		$table = $this->table();

		$where  = 'event_id = %d';
		$params = array( $event_id );

		if ( 'going' === $status || 'waitlist' === $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( $count_query, ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix; $where built from fixed fragments.
		);

		$list_params   = $params;
		$list_params[] = max( 1, $per_page ); // LIMIT placeholder comes first.
		$list_params[] = max( 0, $offset );   // OFFSET placeholder comes second.

		$list_query = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $list_query, ...$list_params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix; $where built from fixed fragments.
			ARRAY_A
		);

		return array(
			'rsvps' => is_array( $rows ) ? array_map( array( $this, 'normalize_row' ), $rows ) : array(),
			'total' => $total,
		);
	}

	/**
	 * Deletes an RSVP row and, once a going seat is free, promotes the
	 * earliest waitlist row (docs/ROADMAP.md §1.3). Delete and promotion share
	 * the advisory lock that serializes all same-event writes (#14) and the
	 * promotion keeps the write single-statement: its WHERE clause re-counts
	 * `going` against the current eh_capacity meta, so a capacity lowered
	 * below the existing going count blocks the flip exactly like create()'s
	 * INSERT guard.
	 *
	 * Fires `event_hub_rsvp_promoted` (promoted row, event ID) after the lock
	 * releases, so listeners may email or log without holding the event lock.
	 * Failed writes surface as WP_Error `eh_db_error` (500) instead of
	 * silently reporting success.
	 *
	 * @param int $id RSVP row ID.
	 * @return array{deleted:array<string,mixed>|null,promoted:array<string,mixed>|null}|WP_Error
	 *               `deleted === null` means no row with that ID; nothing ran.
	 */
	public function delete_and_promote( int $id ) {
		global $wpdb;

		$previous = $this->get( $id );

		if ( null === $previous ) {
			return array(
				'deleted'  => null,
				'promoted' => null,
			);
		}

		$event_id = (int) $previous['event_id'];
		$lock     = 'eh_rsvp_' . $event_id;
		$table    = $this->table();

		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, 2 )', $lock )
		);

		if ( '1' !== (string) $acquired ) {
			return new WP_Error( 'eh_busy', __( 'The event is busy processing another RSVP. Please retry.', 'event-hub' ), array( 'status' => 409 ) );
		}

		$result = array(
			'deleted'  => null,
			'promoted' => null,
		);

		$suppress = $wpdb->suppress_errors( true );

		try {
			// Re-read inside the lock: a concurrent delete may have removed
			// the row between the pre-check and lock acquisition.
			$previous = $this->get( $id );

			if ( null === $previous ) {
				return $result;
			}

			$delete_query = "DELETE FROM {$table} WHERE id = %d";

			$deleted_rows = (int) $wpdb->query(
				$wpdb->prepare( $delete_query, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			);

			// A failed DELETE (disk full, lock wait, corruption) must not masquerade
			// as success: the admin would see a deleted row that is still there.
			$delete_error = $wpdb->last_error;

			if ( $deleted_rows < 1 && '' !== $delete_error ) {
				return new WP_Error(
					'eh_db_error',
					__( 'The RSVP could not be deleted due to a database error.', 'event-hub' ),
					array( 'status' => 500 )
				);
			}

			$result['deleted'] = $previous;

			// Pick the earliest waitlist row (FIFO, id tiebreak) and flip it
			// in one guarded UPDATE: the capacity subquery is evaluated while
			// the delete above already freed the seat, and the outer
			// status re-check makes a concurrent steal impossible (no other
			// event write can run under this event's advisory lock).
			$pick_query = "SELECT id FROM {$table} WHERE event_id = %d AND status = 'waitlist' ORDER BY created_at ASC, id ASC LIMIT 1";

			$pick_id = absint(
				(string) $wpdb->get_var(
					$wpdb->prepare( $pick_query, $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
				)
			);

			if ( $pick_id > 0 ) {
				// The going-count subquery targets the same table as the
				// UPDATE — MySQL rejects that directly (error 1093), so the
				// COUNT lives inside a derived table, which materializes
				// against the post-delete state before the update applies.
				// The guard still re-checks capacity inside the statement,
				// exactly like create()'s INSERT...SELECT predicate.
				$promote_query = "UPDATE {$table} SET status = 'going'
					WHERE id = %d AND status = 'waitlist'
					  AND ( (SELECT gc.going FROM (SELECT COUNT(*) AS going FROM {$table} WHERE event_id = %d AND status = 'going') AS gc)
					        < COALESCE( (SELECT CAST(m.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} m
					                     WHERE m.post_id = %d AND m.meta_key = 'eh_capacity'
					                       AND m.meta_value REGEXP '^[0-9]+$'), 999999999 ) )";

				$promotion_error = '';

				$updated = (int) $wpdb->query(
					$wpdb->prepare( $promote_query, $pick_id, $event_id, $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table derives from $wpdb->prefix.
				);

				$promotion_error = (string) $wpdb->last_error;

				// rows_affected 0 has two meanings: the guard blocked the flip
				// (legitimate), or the statement failed outright. Only
				// $wpdb->last_error distinguishes them, so it was captured
				// under suppress_errors before reset.
				if ( 0 === $updated && '' !== $promotion_error ) {
					return new WP_Error(
						'eh_db_error',
						__( 'The waitlist promotion failed due to a database error.', 'event-hub' ),
						array( 'status' => 500 )
					);
				}

				if ( $updated > 0 ) {
					$result['promoted'] = $this->get( $pick_id );
				}
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}

		if ( null !== $result['promoted'] ) {
			/**
			 * A waitlisted attendee was promoted to going after a deletion.
			 *
			 * @param array<string,mixed> $promoted Promoted RSVP row.
			 * @param int                 $event_id Event post ID.
			 */
			do_action( 'event_hub_rsvp_promoted', $result['promoted'], $event_id );
		}

		return $result;
	}

	/**
	 * Deletes every RSVP row for one event. Runs on the event post's
	 * permanent deletion (before_delete_post) so the eh_rsvps table never
	 * accumulates orphaned rows pointing at dead post IDs.
	 *
	 * @param int $event_id Event post ID.
	 * @return int Rows removed.
	 */
	public function delete_all_for_event( int $event_id ): int {
		global $wpdb;

		$table = $this->table();

		// Graceful degradation: on a fresh install where wp-admin (and the
		// migration hook) never ran, the table may not exist — a hard delete
		// of an event must not fatal then. phpcs ignore: status filter is a
		// fixed fragment, not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table derives from $wpdb->prefix; no user values.
		if ( 0 !== $wpdb->query( 'SHOW TABLES LIKE "' . $table . '"' ) ) {
			return (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$table} WHERE event_id = %d", $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			);
		}

		return 0;
	}

	/**
	 * Fetches one RSVP row.
	 *
	 * @param int $id RSVP row ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = $this->table();

		$get_query = "SELECT * FROM {$table} WHERE id = %d";

		$row = $wpdb->get_row(
			$wpdb->prepare( $get_query, $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Finds an existing RSVP by event + email (used for duplicate messaging).
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $email    Email address.
	 * @return array<string,mixed>|null
	 */
	public function find_existing( int $event_id, string $email ): ?array {
		global $wpdb;

		$table = $this->table();

		$find_query = "SELECT * FROM {$table} WHERE event_id = %d AND email = %s";

		$row = $wpdb->get_row(
			$wpdb->prepare( $find_query, $event_id, $email ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Shapes a raw row into typed, camelCase keys.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ): array {
		return array(
			'id'         => (int) ( $row['id'] ?? 0 ),
			'event_id'   => (int) ( $row['event_id'] ?? 0 ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'email'      => (string) ( $row['email'] ?? '' ),
			'status'     => (string) ( $row['status'] ?? '' ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Resolves a site-timezone-aware meta datetime to a UTC timestamp.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return int|null Unix timestamp, or null when unset/unparseable.
	 */
	private function post_meta_gmt( int $post_id, string $meta_key ): ?int {
		$raw = (string) get_post_meta( $post_id, $meta_key, true );

		if ( '' === trim( $raw ) ) {
			return null;
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );

		if ( false === $datetime ) {
			return null;
		}

		return $datetime->getTimestamp();
	}
}
