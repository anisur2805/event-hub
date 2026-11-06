<?php
/**
 * WP-CLI commands: `wp event-hub` namespace (list, rsvp export).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp event-hub list` — events with their core fields and RSVP counts.
 * Registered only under WP_CLI (Plugin::boot()); public methods become
 * subcommands, so `list` and the nested `rsvp` namespace live here.
 */
final class Cli {

	/**
	 * Repository dependency (RSVP counts).
	 *
	 * @var Rsvp_Repository
	 */
	private Rsvp_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Rsvp_Repository $repository RSVP repository.
	 */
	public function __construct( Rsvp_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Lists events: ID, title, start, venue, capacity, going/waitlist seats.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by post status. Default: publish (any = all statuses).
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. Default: table.
	 *
	 * @param array<int,string>    $args       Positional args (none).
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		unset( $args );

		$status = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : 'publish';

		if ( 'any' === $status ) {
			$status = 'any';
		}

		$events = get_posts(
			array(
				'post_type'        => 'eh_event',
				'post_status'      => $status,
				'posts_per_page'   => - 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- CLI export context, unbounded on purpose.
				'orderby'          => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology listing, bounded CPT (the admin When-sort pattern).
				'meta_key'         => 'eh_start',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$rows = array();

		foreach ( $events as $event ) {
			$summary  = Render_Blocks::summary( (int) $event->ID );
			$capacity = $summary['capacity'];
			$counts   = $this->repository->counts( (int) $event->ID );

			$rows[] = array(
				'ID'       => (int) $event->ID,
				'title'    => (string) $event->post_title,
				'start'    => (string) $summary['start'],
				'venue'    => (string) $summary['venue'],
				'capacity' => null === $capacity ? 'unlimited' : (string) $capacity,
				'going'    => (int) $counts['going'],
				'waitlist' => (int) $counts['waitlist'],
				'status'   => (string) $event->post_status,
			);
		}

		if ( array() === $rows ) {
			\WP_CLI::log( 'No events found.' );

			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		\WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'title', 'start', 'venue', 'capacity', 'going', 'waitlist', 'status' ) );
	}
}
