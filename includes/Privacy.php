<?php
/**
 * WP Privacy exporters / erasers for RSVP PII (ROADMAP 5.4).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the plugin's RSVP data into Tools → Export / Erase Personal Data.
 *
 * Attendee PII lives in the eh_rsvps table (name, email). The exporter
 * groups all rows for the requested email address under one "Event
 * bookings" item. The eraser ANONYMIZES rows in place instead of deleting:
 * deleting would silently free capacity (skewing every count surface) and
 * fire the rsvp-cancelled action — emailing attendance notices to the very
 * person being erased. Anonymized rows keep capacity math truthful, keep
 * their checked-in stamps (organizer history), and become unreachable:
 * the placeholder email has no @, so is_email() rejects it and both mail
 * sweeps skip it (self-healing guard added in Reminders).
 *
 * WP calls the eraser once per page with ever-increasing $page; rows are
 * keyed by email, so page 1 already covers the plugin's whole per-email
 * surface — page N > 1 trucks no additional rows and reports "not
 * retained".
 */
final class Privacy {

	/**
	 * Registers the exporter and eraser callbacks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( array $exporters ): array {
				$exporters['event-hub'] = array(
					'exporter_friendly_name' => __( 'Event Hub bookings', 'event-hub' ),
					'callback'               => static fn ( string $email_address, int $page = 1 ): array => Privacy::export_rows( $email_address, $page ),
				);

				return $exporters;
			}
		);

		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( array $erasers ): array {
				$erasers['event-hub'] = array(
					'eraser_friendly_name' => __( 'Event Hub bookings', 'event-hub' ),
					'callback'             => static fn ( string $email_address, int $page = 1 ): array => Privacy::erase_rows( $email_address, $page ),
				);

				return $erasers;
			}
		);
	}

	/**
	 * Exporter callback: all RSVP rows for the requested email, as one
	 * grouped item. No match (or a junk address) yields an empty-items
	 * response — the core contract for "nothing here".
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number (1-based; pages beyond 1
	 *                              return empty — see class docblock).
	 * @return array{data:array<int,array{group_id:string,group_label:string,item_id:string,data:array<int,array{name:string,value:string}>}>}
	 */
	public static function export_rows( string $email_address, int $page = 1 ): array {
		$email = trim( (string) sanitize_email( $email_address ) );

		if ( '' === $email || $page > 1 ) {
			return array( 'data' => array() );
		}

		$rows = Plugin::instance()->repository()->list_by_email( $email );

		if ( array() === $rows ) {
			return array( 'data' => array() );
		}

		$item_data = array();

		foreach ( $rows as $row ) {
			$item_data[] = array(
				'name'  => __( 'Event', 'event-hub' ),
				'value' => (string) ( $row['event_title'] ?? '' ),
			);
			$item_data[] = array(
				'name'  => __( 'Name', 'event-hub' ),
				'value' => (string) ( $row['name'] ?? '' ),
			);
			$item_data[] = array(
				'name'  => __( 'Email', 'event-hub' ),
				'value' => (string) ( $row['email'] ?? '' ),
			);
			$item_data[] = array(
				'name'  => __( 'Status', 'event-hub' ),
				'value' => 'waitlist' === ( $row['status'] ?? '' )
					? __( 'Waitlist', 'event-hub' )
					: __( 'Going', 'event-hub' ),
			);
			$item_data[] = array(
				'name'  => __( 'Seats', 'event-hub' ),
				'value' => (string) number_format_i18n( max( 1, (int) ( $row['guests'] ?? 1 ) ) ),
			);
			$item_data[] = array(
				'name'  => __( 'Registered at', 'event-hub' ),
				'value' => self::display_datetime( (string) ( $row['created_at'] ?? '' ) ),
			);

			$checked_in = (string) ( $row['checked_in_at'] ?? '' );

			$item_data[] = array(
				'name'  => __( 'Checked in', 'event-hub' ),
				'value' => '' !== $checked_in
					? self::display_datetime( $checked_in )
					: __( 'No', 'event-hub' ),
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'event-hub',
					'group_label' => __( 'Event bookings', 'event-hub' ),
					'item_id'     => 'event-hub-rsvps',
					'data'        => $item_data,
				),
			),
		);
	}

	/**
	 * Eraser callback: anonymizes every row for the requested email and
	 * reports what happened per-item (the core eraser contract: an array
	 * with 'items_removed', 'items_retained', 'messages', 'done').
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number (the whole email surface is
	 *                              covered in one pass — see class docblock).
	 * @return array{items_removed:int,items_retained:int,messages:string[],done:bool}
	 */
	public static function erase_rows( string $email_address, int $page = 1 ): array {
		$response = array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);

		$email = trim( (string) sanitize_email( $email_address ) );

		if ( '' === $email || $page > 1 ) {
			return $response;
		}

		$rows = Plugin::instance()->repository()->list_by_email( $email );

		foreach ( $rows as $row ) {
			$anonymized = Plugin::instance()->repository()->anonymize_row( (int) $row['id'] );

			if ( is_wp_error( $anonymized ) ) {
				$response['items_retained'] = $response['items_retained'] + 1;

				$response['messages'][] = sprintf(
					/* translators: %d: RSVP row ID. */
					__( 'RSVP #%d could not be anonymized due to a database error and was retained.', 'event-hub' ),
					(int) $row['id']
				);

				continue;
			}

			$response['items_removed'] = $response['items_removed'] + 1;
		}

		return $response;
	}

	/**
	 * Formats a stored 'Y-m-d H:i:s' site-time datetime for the export
	 * display, or returns the raw string when unparseable.
	 *
	 * @param string $value Stored datetime.
	 * @return string
	 */
	private static function display_datetime( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $value, wp_timezone() );

		return false !== $datetime
			? $datetime->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
			: $value;
	}

	/**
	 * Whether a row already anonymized (placeholder email shape) — used by
	 * the reminders sweep's self-healing guard.
	 *
	 * @param string $email Stored email.
	 * @return bool
	 */
	public static function is_placeholder( string $email ): bool {
		return '' !== $email && ! str_contains( $email, '@' );
	}
}
