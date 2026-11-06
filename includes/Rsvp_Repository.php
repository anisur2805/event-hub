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
	 * Maximum seats one RSVP row may reserve (ROADMAP §2.3). Shared by the
	 * repository guard, the REST arg schema, and the front-end seat picker.
	 *
	 * @var int
	 */
	public const MAX_GUESTS = 10;

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
	 * Also mints this row's manage token (DECISIONS #39): 32 random bytes
	 * hashed with SHA-256 for storage; the raw token is returned once so the
	 * caller can put it in the confirmation email — it is never persisted in
	 * plaintext and cannot be recovered from the DB.
	 *
	 * @param string $name     Sanitized attendee name.
	 * @param string $email    Sanitized, validated email address.
	 * @param string $status   Either 'going' or 'waitlist'.
	 * @param int    $event_id Event post ID.
	 * @param int    $guests   Seats this row reserves (1..MAX_GUESTS, ROADMAP §2.3).
	 * @return array{rsvp:array<string,mixed>,counts:array{going:int,waitlist:int},manage_token:string}|WP_Error
	 */
	public function create( string $name, string $email, string $status, int $event_id, int $guests = 1 ) {
		global $wpdb;

		if ( 'waitlist' !== $status && 'going' !== $status ) {
			return new WP_Error( 'eh_invalid_status', __( 'Status must be either going or waitlist.', 'event-hub' ), array( 'status' => 400 ) );
		}

		if ( $guests < 1 || $guests > self::MAX_GUESTS ) {
			return new WP_Error( 'eh_invalid_guests', __( 'Guests must be between 1 and 10.', 'event-hub' ), array( 'status' => 400 ) );
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

		// Cancelled events (5.3) take no more RSVPs. Postponed stays open —
		// people may still join for the new date; the pill and the inert
		// front-end branch are what keep the surface honest.
		if ( Event_Status::blocked( $event_id ) ) {
			return new WP_Error( 'eh_event_cancelled', __( 'This event has been cancelled.', 'event-hub' ), array( 'status' => 409 ) );
		}

		$end_gmt = $this->post_meta_gmt( $event_id, 'eh_end' );

		// Unknown end date is treated as not-passed (conservative: never block a
		// valid RSVP on missing data; phase 5 data audit owns content hygiene).
		if ( null !== $end_gmt && $end_gmt < time() ) {
			return new WP_Error( 'eh_event_passed', __( 'This event has already ended.', 'event-hub' ), array( 'status' => 409 ) );
		}

		// RSVP window cutoffs (ROADMAP 2.4): close-hours gates the whole
		// surface (going + waitlist — a registration window that stayed open
		// for the waitlist alone would surprise organizers), open-hours
		// gates everything until the window starts. Missing-data verdicts
		// report open, so a missing clock never blocks (same rule as above).
		$cutoffs = $this->rsvp_cutoffs( $event_id );

		if ( $cutoffs['closed'] ) {
			return new WP_Error( 'eh_rsvp_closed', __( 'RSVPs for this event are closed.', 'event-hub' ), array( 'status' => 409 ) );
		}

		if ( $cutoffs['not_open'] ) {
			return new WP_Error( 'eh_rsvp_not_open', __( 'RSVPs for this event have not opened yet.', 'event-hub' ), array( 'status' => 409 ) );
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
		 * never returns NULL (SUM over zero rows is NULL → COALESCE to 0), so
		 * `going` rows insert only while the booked SEATS (SUM(guests), DB
		 * 1.4.0; every legacy row carries guests = 1) stay strictly below
		 * capacity. The row's own guests value is deliberately NOT part of
		 * the predicate: admitting a party of 4 into 2 free seats would
		 * overbook on arrival; the seat check admits the ROW, and the front
		 * end steers oversized parties to the waitlist (rsvpGoing).
		 */
		// The manage-token hash is server-generated (random_bytes), not user
		// input; it rides the same prepared statement as the row values.
		$raw_token  = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $raw_token );

		// Assemble the statement first; phpcs:ignore below sits on the query
		// line that holds the $table variable (value from $wpdb->prefix, never
		// user input). All values remain placeholders.
		$insert_query = 'INSERT INTO ' . $table . " (event_id, name, email, status, guests, created_at, manage_token)
			SELECT %d, %s, %s, %s, %d, %s, %s FROM DUAL
			WHERE %s = 'waitlist'
			   OR ( (SELECT COALESCE( SUM( guests ), 0 ) FROM " . $table . " WHERE event_id = %d AND status = 'going')
			        < COALESCE( (SELECT CAST(m.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} m
			                     WHERE m.post_id = %d AND m.meta_key = 'eh_capacity'
			                       AND m.meta_value REGEXP '^[0-9]+$'), 999999999 ) )"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table derives from $wpdb->prefix.

		$insert_sql = $wpdb->prepare(
			$insert_query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled above; values are placeholders.
			$event_id,
			$name,
			$email,
			$status,
			$guests,
			current_time( 'mysql' ),
			$token_hash,
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
			'rsvp'         => $row,
			'counts'       => $this->counts( $event_id ),
			'manage_token' => $raw_token,
		);
	}

	/**
	 * Counts booked SEATS per status for an event, plus how many of the going
	 * rows are checked in (ROADMAP §2.2). going/waitlist are SUM(guests)
	 * totals since DB 1.4.0 — capacity math is seat-based (§2.3); every
	 * legacy row carries guests = 1, and checkedIn stays a row count (a
	 * party checks in as it arrives, the stamp is per row).
	 *
	 * @param int $event_id Event post ID.
	 * @return array{going:int,waitlist:int,checkedIn:int}
	 */
	public function counts( int $event_id ): array {
		global $wpdb;

		$table = $this->table();

		$query = "SELECT status, COALESCE( SUM( guests ), 0 ) AS total, SUM( CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END ) AS checked
			FROM {$table} WHERE event_id = %d GROUP BY status";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			ARRAY_A
		);

		$counts = array(
			'going'     => 0,
			'waitlist'  => 0,
			'checkedIn' => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = (string) ( $row['status'] ?? '' );

				// Checked rows are counted per status group and summed —
				// one query serves all three keys (archive cards call this
				// per card, so the saved round-trip compounds).
				$counts['checkedIn'] += (int) ( $row['checked'] ?? 0 );

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
	 * Most recent RSVP rows across ALL events, newest first (ROADMAP §3.1
	 * dashboard surfaces). Rows keep the full internal shape — including the
	 * email — so callers must be admin-context (both dashboard consumers are
	 * capability-gated; the public serializers never call this).
	 *
	 * @param int $limit Maximum rows (minimum 1).
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 10 ): array {
		global $wpdb;

		$table = $this->table();

		// id DESC is the tiebreak for rows created within the same second.
		$query = "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'normalize_row' ), $rows ) : array();
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
				// The going-seats subquery targets the same table as the
				// UPDATE — MySQL rejects that directly (error 1093), so the
				// SUM lives inside a derived table, which materializes
				// against the post-delete state before the update applies.
				// The guard still re-checks SEATS (SUM(guests), §2.3) against
				// capacity inside the statement, exactly like create()'s
				// INSERT...SELECT predicate; a waitlist row holding guests
				// promotes only when its own party size is NOT counted yet —
				// the UPDATE flips the whole row, so the guard is seats-
				// before-flip, same shape as before (row-based COUNT).
				$promote_query = "UPDATE {$table} SET status = 'going'
					WHERE id = %d AND status = 'waitlist'
					  AND ( (SELECT gc.going FROM (SELECT COALESCE( SUM( guests ), 0 ) AS going FROM {$table} WHERE event_id = %d AND status = 'going') AS gc)
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

		/*
		 * Post-try, a row was provably deleted: every path that removes
		 * nothing returns earlier (pre-lock no-row, in-lock concurrent
		 * delete, DB errors). The WP_Error paths also return before here,
		 * so a committed-but-errored delete (promotion failure) reports its
		 * error without claiming a clean cancellation (#34 asymmetry).
		 *
		 * An RSVP row was removed from an event (attendee cancel via the
		 * manage page, or an admin delete). Fired after RELEASE_LOCK —
		 * listeners may email/log freely.
		 *
		 * @param array<string,mixed> $deleted  The removed RSVP row.
		 * @param int                 $event_id Event post ID.
		 */
		do_action( 'event_hub_rsvp_cancelled', $result['deleted'], $event_id );

		return $result;
	}

	/**
	 * Sets or clears an RSVP's check-in timestamp (ROADMAP §2.2).
	 *
	 * Plain single-column UPDATE by primary key. No advisory lock and no
	 * capacity predicate: this writes/reads only `checked_in_at`, which no
	 * capacity computation consumes — the lock in create()/delete_and_promote()
	 * exists to serialize `going`-count transitions, and this is not one.
	 *
	 * @param int  $id         RSVP row ID.
	 * @param bool $checked_in True to stamp now, false to clear.
	 * @return array<string,mixed>|WP_Error|null Updated row; `null` when the
	 *                                           row vanished (unknown ID or
	 *                                           concurrent delete); `eh_db_error` 500
	 *                                           on statement failure.
	 */
	public function set_checked_in( int $id, bool $checked_in ) {
		global $wpdb;

		$table = $this->table();

		// Two statements because %s placeholders cannot bind SQL NULL; the
		// clear is its own literal-NULL query rather than a conditional string.
		$update_query = $checked_in
			? "UPDATE {$table} SET checked_in_at = %s WHERE id = %d"
			: "UPDATE {$table} SET checked_in_at = NULL WHERE id = %d";

		$suppress = $wpdb->suppress_errors( true );

		try {
			$raw = false === $checked_in
				? $wpdb->query(
					$wpdb->prepare( $update_query, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
				)
				: $wpdb->query(
					$wpdb->prepare( $update_query, current_time( 'mysql' ), $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
				);

			$error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		// query() returns false only on failure; 0 affected rows is a legal
		// no-op (already stamped / already null) and must not read as an error.
		if ( false === $raw && '' !== $error ) {
			return new WP_Error(
				'eh_db_error',
				__( 'The check-in could not be saved due to a database error.', 'event-hub' ),
				array( 'status' => 500 )
			);
		}

		$row = $this->get( $id );

		if ( null === $row ) {
			return null;
		}

		return $row;
	}

	/**
	 * Rows due for the pre-event reminder (ROADMAP 5.2): going/waitlist rows
	 * of UPCOMING events whose start falls inside the reminder window
	 * (event-dependent, passed as an ID list), that have never been reminded.
	 * The caller supplies the event-ID set after resolving per-event windows;
	 * this method only bounds and fetches (per-event clock logic lives in
	 * Reminders).
	 *
	 * @param array<int,int> $event_ids Upcoming event IDs inside their window.
	 * @param int            $limit     Max rows to return.
	 * @return array<int,array<string,mixed>> RSVP rows keyed by id.
	 */
	public function list_for_reminder( array $event_ids, int $limit = 100 ): array {
		global $wpdb;

		$event_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $event_ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);

		if ( array() === $event_ids || $limit < 1 ) {
			return array();
		}

		$table        = $this->table();
		$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
		$limit        = min( 500, $limit );

		$query = "SELECT * FROM {$table}
			WHERE event_id IN ( {$placeholders} )
			  AND reminded_at IS NULL
			ORDER BY id ASC
			LIMIT %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, array_merge( $event_ids, array( $limit ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix; IN placeholders counted above.
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexed = array();

		foreach ( $rows as $row ) {
			$indexed[ (int) $row['id'] ] = $row;
		}

		return $indexed;
	}

	/**
	 * Stamps an RSVP as reminded. Single-column UPDATE by PK (same pooled
	 * pattern as set_checked_in: no capacity math consumes reminded_at, so
	 * no advisory lock).
	 *
	 * @param int $id RSVP row ID.
	 * @return bool True when a row was stamped (false: gone / no update).
	 */
	public function mark_reminded( int $id ): bool {
		global $wpdb;

		$table = $this->table();

		$update_query = "UPDATE {$table} SET reminded_at = %s WHERE id = %d AND reminded_at IS NULL";

		$suppress = $wpdb->suppress_errors( true );

		try {
			$updated = (int) $wpdb->query(
				$wpdb->prepare( $update_query, current_time( 'mysql' ), $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			);

			$error = (string) $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		return $updated > 0 && '' === $error;
	}

	/**
	 * Rows owed a status notice (ROADMAP 5.3): their status_notified_gen
	 * sits behind the event's current generation. Cancelled/draft rows can
	 * appear behind only while their event is in a non-scheduled state —
	 * events back to scheduled never enter the sweep's event list, so their
	 * stale rows are never re-examined.
	 *
	 * @param array<int,int> $event_ids Event post IDs (pre-validated).
	 * @param int            $limit     Max rows (clamped 1..500).
	 * @return array<int,array<string,mixed>> Rows keyed by row id.
	 */
	public function list_behind_generation( array $event_ids, int $limit = 100 ): array {
		global $wpdb;

		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( array() === $event_ids || $limit < 1 ) {
			return array();
		}

		$table          = $this->table();
		$gen_conditions = array();

		foreach ( $event_ids as $event_id ) {
			$gen              = Event_Status::generation( $event_id );
			$gen_conditions[] = $wpdb->prepare(
				'( event_id = %d AND status_notified_gen < %d )',
				$event_id,
				$gen
			);
		}

		$limit = min( 500, $limit );

		$query = 'SELECT * FROM ' . $table . '
			WHERE ' . implode( ' OR ', $gen_conditions ) . '
			ORDER BY id ASC
			LIMIT %d';

		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- per-event conditions prepared above; LIMIT bound here.
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexed = array();

		foreach ( $rows as $row ) {
			$indexed[ (int) $row['id'] ] = $row;
		}

		return $indexed;
	}

	/**
	 * Stamps an RSVP with the generation it was notified about. Single-column
	 * UPDATE by PK (same pattern as mark_reminded).
	 *
	 * @param int $id            RSVP row ID.
	 * @param int $generation    The status-notice generation just emailed.
	 * @return bool True when a row was stamped.
	 */
	public function mark_status_notified( int $id, int $generation ): bool {
		global $wpdb;

		$table = $this->table();

		$update_query = "UPDATE {$table} SET status_notified_gen = %d WHERE id = %d AND status_notified_gen < %d";

		$suppress = $wpdb->suppress_errors( true );

		try {
			$updated = (int) $wpdb->query(
				$wpdb->prepare( $update_query, $generation, $id, $generation ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			);

			$error = (string) $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		return $updated > 0 && '' === $error;
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
		// of an event must not fatal then. $table derives from $wpdb->prefix;
		// no user values.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier interpolation, see above.
		if ( 0 !== $wpdb->query( 'SHOW TABLES LIKE "' . $table . '"' ) ) {
			$delete_query = "DELETE FROM {$table} WHERE event_id = %d";

			return (int) $wpdb->query(
				$wpdb->prepare( $delete_query, $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
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
	 * Resolves a raw manage token to its row (DECISIONS #39). The DB stores
	 * only SHA-256 hashes, so lookup hashes the presented token and compares
	 * against the indexed manage_token column. Legitimate tokens are 64 hex
	 * chars; anything else cannot match and exits before the query.
	 *
	 * @param string $raw_token Raw token from the manage link.
	 * @return array<string,mixed>|null Row (internal shape, token hash stripped) or null.
	 */
	public function find_by_token( string $raw_token ): ?array {
		global $wpdb;

		$candidate = trim( $raw_token );

		if ( 64 !== strlen( $candidate ) || ! ctype_xdigit( $candidate ) ) {
			return null;
		}

		$table = $this->table();

		$token_query = 'SELECT * FROM ' . $table . ' WHERE manage_token = %s';

		$row = $wpdb->get_row(
			$wpdb->prepare( $token_query, hash( 'sha256', $candidate ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Updates an RSVP's display name (manage page "update name" action).
	 * Single-column UPDATE by primary key; status and timestamps untouched.
	 *
	 * @param int    $id   RSVP row ID.
	 * @param string $name New sanitized name.
	 * @return array<string,mixed>|WP_Error|null Updated row; null when the row
	 *                                           vanished; eh_db_error on failure.
	 */
	public function update_name( int $id, string $name ) {
		global $wpdb;

		$table = $this->table();

		$update_query = "UPDATE {$table} SET name = %s WHERE id = %d";

		$suppress = $wpdb->suppress_errors( true );

		try {
			$raw   = $wpdb->query(
				$wpdb->prepare( $update_query, $name, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
			);
			$error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		if ( false === $raw && '' !== $error ) {
			return new WP_Error(
				'eh_db_error',
				__( 'The name could not be saved due to a database error.', 'event-hub' ),
				array( 'status' => 500 )
			);
		}

		return $this->get( $id );
	}

	/**
	 * Shapes a raw row into typed, camelCase keys.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ): array {
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'event_id'      => (int) ( $row['event_id'] ?? 0 ),
			'name'          => (string) ( $row['name'] ?? '' ),
			'email'         => (string) ( $row['email'] ?? '' ),
			'status'        => (string) ( $row['status'] ?? '' ),
			'guests'        => isset( $row['guests'] ) ? max( 1, (int) $row['guests'] ) : 1,
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
			'checked_in_at' => isset( $row['checked_in_at'] )
				? (string) $row['checked_in_at']
				: null,
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

	/**
	 * Parses an RSVP-cutoff hours meta string (ROADMAP §2.4) into a
	 * non-negative integer. Contract mirrors parse_capacity() (BUG 5): ''
	 * / missing = no cutoff (null); digit-only = that many hours (0 = right
	 * at start); anything else = no cutoff — the save-time meta schema
	 * rejects junk, so legacy junk keeps the forgiving default.
	 *
	 * @param string $raw Raw meta value.
	 * @return int|null Hours, or null when no cutoff is configured.
	 */
	public static function parse_hours( string $raw ): ?int {
		if ( '' === trim( $raw ) || 1 !== preg_match( '/^[0-9]+$/', $raw ) ) {
			return null;
		}

		return (int) $raw;
	}

	/**
	 * Cutoff verdicts for one event (ROADMAP §2.4), keyed by which boundary
	 * sits inside / outside the window now — shape used by the render layer
	 * and the write guard alike.
	 *
	 * @param int $post_id Event post ID.
	 * @return array{closed:bool,not_open:bool} closed: at/after start minus
	 *                                           close-hours (0 closes right at start). not_open: before
	 *                                           start minus open-hours. Missing/unparseable clocks or
	 *                                           cutoff meta always report open (conservative — the same
	 *                                           rule as the eh_event_passed guard: never block on missing data).
	 */
	public function rsvp_cutoffs( int $post_id ): array {
		$verdicts = array(
			'closed'   => false,
			'not_open' => false,
		);

		$start = $this->post_meta_gmt( $post_id, 'eh_start' );

		if ( null === $start ) {
			return $verdicts;
		}

		$close_hours = self::parse_hours( (string) get_post_meta( $post_id, 'eh_rsvp_close_hours', true ) );

		if ( null !== $close_hours && $start - $close_hours * HOUR_IN_SECONDS <= time() ) {
			$verdicts['closed'] = true;
		}

		$open_hours = self::parse_hours( (string) get_post_meta( $post_id, 'eh_rsvp_open_hours', true ) );

		if ( null !== $open_hours && $start - $open_hours * HOUR_IN_SECONDS > time() ) {
			$verdicts['not_open'] = true;
		}

		return $verdicts;
	}
}
