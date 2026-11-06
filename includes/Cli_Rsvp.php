<?php
/**
 * WP-CLI RSVP commands: `wp event-hub rsvp export`.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp event-hub rsvp export <event-id>` — the same CSV rows the admin export
 * streams (shared row builder, neutralization, seats, ISO timestamps) so CLI
 * output can never drift from the dashboard's numbers.
 */
final class Cli_Rsvp {

	/**
	 * Repository dependency.
	 *
	 * @var Rsvp_Repository
	 */
	private Rsvp_Repository $repository;

	/**
	 * CSV exporter (row builder + neutralization, shared with the admin
	 * stream).
	 *
	 * @var Csv_Exporter
	 */
	private Csv_Exporter $exporter;

	/**
	 * Constructor.
	 *
	 * @param Rsvp_Repository $repository RSVP repository.
	 * @param Csv_Exporter    $exporter   CSV exporter (row builder).
	 */
	public function __construct( Rsvp_Repository $repository, Csv_Exporter $exporter ) {
		$this->repository = $repository;
		$this->exporter   = $exporter;
	}

	/**
	 * Exports one event's RSVPs as CSV.
	 *
	 * ## OPTIONS
	 *
	 * <event-id>
	 * : Event post ID to export.
	 *
	 * [--status=<status>]
	 * : Only rows with this status (going|waitlist). Default: all.
	 *
	 * [--file=<path>]
	 * : Write to a file (UTF-8 BOM included) instead of printing to stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp event-hub rsvp export 42
	 *     wp event-hub rsvp export 42 --status=waitlist --file=/tmp/waitlist.csv
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		$event_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		$this->assert_event( $event_id );

		$status = '';

		if ( isset( $assoc_args['status'] ) ) {
			$status = sanitize_key( (string) $assoc_args['status'] );

			if ( ! in_array( $status, array( 'going', 'waitlist' ), true ) ) {
				\WP_CLI::error( 'Invalid status. Use going or waitlist.' );
			}
		}

		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';

		$event_title = html_entity_decode( (string) get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' );

		$lines    = array( implode( ',', Csv_Exporter::COLUMNS ) );
		$per_page = 500;
		$offset   = 0;
		$total    = 0;

		while ( true ) {
			$result = $this->repository->list( $event_id, $per_page, $offset, $status );

			if ( array() === $result['rsvps'] ) {
				break;
			}

			foreach ( $result['rsvps'] as $rsvp ) {
				$lines[] = $this->csv_line( $event_id, $event_title, $rsvp );
				++$total;
			}

			$offset += count( $result['rsvps'] );

			if ( count( $result['rsvps'] ) < $per_page ) {
				break;
			}
		}

		$csv = implode( "\r\n", $lines ) . "\r\n";

		if ( '' !== $file ) {
			$written = file_put_contents( $file, "\xEF\xBB\xBF" . $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- explicit user-supplied --file target on a CLI command; one write, no FS abstraction value.

			if ( false === $written ) {
				\WP_CLI::error( "Could not write to {$file}." );
			}

			\WP_CLI::success( "Exported {$total} RSVPs to {$file}." );

			return;
		}

		\WP_CLI::line( $csv );
		\WP_CLI::log( "Exported {$total} RSVPs." );
	}

	/**
	 * Builds one RFC 4180 line via the shared row builder (fputcsv semantics:
	 * quote fields containing separator/quote/newline, double the quotes).
	 *
	 * @param int                 $event_id    Event post ID.
	 * @param string              $event_title Event title.
	 * @param array<string,mixed> $rsvp        Normalized RSVP row.
	 * @return string
	 */
	private function csv_line( int $event_id, string $event_title, array $rsvp ): string {
		$cells = $this->exporter->csv_row( $event_id, $event_title, $rsvp );

		$escaped = array_map(
			static function ( string $cell ): string {
				if ( preg_match( '/[",\r\n]/', $cell ) ) {
					return '"' . str_replace( '"', '""', $cell ) . '"';
				}

				return $cell;
			},
			$cells
		);

		return implode( ',', $escaped );
	}

	/**
	 * Errors out unless the ID is an eh_event post.
	 *
	 * @param int $event_id Event post ID.
	 * @return void
	 */
	private function assert_event( int $event_id ): void {
		$event = 0 < $event_id ? get_post( $event_id ) : null;

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			\WP_CLI::error( "Event {$event_id} does not exist." );
		}
	}
}
