<?php
/**
 * Admin CSV export with formula-injection neutralization.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams RSVP rows as CSV from admin-post.php. Capability + nonce gated
 * (docs/API.md "Non-REST endpoints", THREAT-MODEL T5/T9).
 */
final class Csv_Exporter {

	/**
	 * Admin-post action slug.
	 *
	 * @var string
	 */
	public const ACTION = 'eh_export_rsvps';

	/**
	 * Nonce action for the export link.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'eh_export_rsvps';

	/**
	 * UTF-8 byte-order mark emitted before the header row.
	 *
	 * @var string
	 */
	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Characters that start a formula when a cell begins with them.
	 *
	 * @var array<int,string>
	 */
	private const FORMULA_PREFIXES = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Repository dependency.
	 *
	 * @var Rsvp_Repository
	 */
	private Rsvp_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Rsvp_Repository|null $repository Optional repository (tests).
	 */
	public function __construct( ?Rsvp_Repository $repository = null ) {
		$this->repository = $repository ?? new Rsvp_Repository();
	}

	/**
	 * Registers the admin_post handler. Called from Plugin::boot().
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Validates the request and streams the CSV.
	 *
	 * @return void
	 */
	public function handle(): void {
		$this->handle_request();
		exit;
	}

	/**
	 * Validates the request and streams the CSV without terminating the
	 * process — the testable core of handle() (admin-post requires exit;
	 * PHPUnit must survive a successful export).
	 *
	 * @return void
	 */
	public function handle_request(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to export RSVPs.', 'event-hub' ),
				esc_html__( 'Forbidden', 'event-hub' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$event    = 0 !== $event_id ? get_post( $event_id ) : null;

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			wp_die(
				esc_html__( 'Unknown event.', 'event-hub' ),
				esc_html__( 'Not found', 'event-hub' ),
				array( 'response' => 404 )
			);
		}

		$this->stream( $event_id, get_the_title( $event ) );
	}

	/**
	 * Builds the export URL for the admin UI.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Nonced admin-post URL.
	 */
	public static function export_url( int $event_id ): string {
		return (string) wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'event_id' => $event_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
	}

	/**
	 * Header row, shared by the admin stream and the WP-CLI export (single
	 * source of truth so the two surfaces cannot drift).
	 *
	 * @var array<int,string>
	 */
	public const COLUMNS = array(
		'Event ID',
		'Event Title',
		'Name',
		'Email',
		'Status',
		'Seats',
		'Registered At',
		'Checked In At',
	);

	/**
	 * Builds one CSV row from a normalized repository row. Public: the
	 * WP-CLI export command rides this exact builder (neutralization
	 * included) instead of a parallel implementation.
	 *
	 * @param int                 $event_id    Event post ID.
	 * @param string              $event_title Event title (entity-decoded by the caller).
	 * @param array<string,mixed> $rsvp        Normalized RSVP row (Rsvp_Repository::list()).
	 * @return array<int,string>
	 */
	public function csv_row( int $event_id, string $event_title, array $rsvp ): array {
		$checked_in_at = isset( $rsvp['checked_in_at'] )
			? Rest_Controller::to_iso8601( (string) $rsvp['checked_in_at'] )
			: '';

		return array(
			$this->neutralize( (string) $event_id ),
			$this->neutralize( $event_title ),
			$this->neutralize( (string) ( $rsvp['name'] ?? '' ) ),
			$this->neutralize( (string) ( $rsvp['email'] ?? '' ) ),
			$this->neutralize( (string) ( $rsvp['status'] ?? '' ) ),
			$this->neutralize( (string) ( isset( $rsvp['guests'] ) ? max( 1, (int) $rsvp['guests'] ) : 1 ) ),
			$this->neutralize( Rest_Controller::to_iso8601( (string) ( $rsvp['created_at'] ?? '' ) ) ),
			$this->neutralize( $checked_in_at ),
		);
	}

	/**
	 * Streams every RSVP of one event as CSV to php://output.
	 *
	 * @param int    $event_id    Event post ID.
	 * @param string $event_title Event title (unescaped stream output).
	 * @return void
	 */
	private function stream( int $event_id, string $event_title ): void {
		// The title arrives entity-encoded from get_the_title(); cells must
		// hold literal characters so neutralization applies to real values.
		$event_title = html_entity_decode( $event_title, ENT_QUOTES, 'UTF-8' );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="event-' . $event_id . '-rsvps.csv"' );
			header( 'X-Robots-Tag: noindex' );
			header( 'Cache-Control: no-store' );
		}

		echo self::BOM; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw byte stream, CSV protocol.

		$handle = fopen( 'php://output', 'wb' );

		if ( false === $handle ) {
			return;
		}

		fputcsv(
			$handle,
			array_map(
				array( $this, 'neutralize' ),
				self::COLUMNS
			),
			',',
			'"',
			'\\'
		);

		$per_page = 500;
		$offset   = 0;

		while ( true ) {
			$result = $this->repository->list( $event_id, $per_page, $offset );

			if ( array() === $result['rsvps'] ) {
				break;
			}

			foreach ( $result['rsvps'] as $rsvp ) {
				fputcsv(
					$handle,
					$this->csv_row( $event_id, $event_title, $rsvp ),
					',',
					'"',
					'\\'
				);
			}

			$offset += count( $result['rsvps'] );

			if ( count( $result['rsvps'] ) < $per_page ) {
				break;
			}
		}

		// Stream closes are irrelevant to WP_Filesystem semantics on
		// php://output; the sniff's warning is accepted here.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output streaming has no WP_Filesystem equivalent.
	}

	/**
	 * Neutralizes CSV formula injection (T9): when the first byte is one of
	 * = + - @ TAB CR, prefix the cell with an apostrophe (docs/API.md).
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	public function neutralize( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], self::FORMULA_PREFIXES, true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
