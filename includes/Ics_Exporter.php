<?php
/**
 * RFC 5545 iCalendar writer.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds VCALENDAR output for one or many eh_event posts. Escaping per RFC
 * 5545 §3.4.1; line folding at 75 octets; CRLF line endings (T10,
 * docs/THREAT-MODEL.md).
 */
final class Ics_Exporter {

	/**
	 * Maximum line length in octets before folding (RFC 5545 §3.1).
	 *
	 * @var int
	 */
	private const FOLD_LIMIT = 75;

	/**
	 * Builds the full VCALENDAR body for the given event IDs.
	 *
	 * @param array<int,int> $event_ids Published event post IDs.
	 * @return string The complete ICS text with CRLF endings.
	 */
	public function build( array $event_ids ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'PRODID:-//Event Hub//EN',
			'VERSION:2.0',
			'CALSCALE:GREGORIAN',
		);

		foreach ( $event_ids as $event_id ) {
			$event = get_post( $event_id );

			if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type || 'publish' !== $event->post_status ) {
				continue;
			}

			$summary   = Render_Blocks::summary( $event );
			$dtstamp   = gmdate( 'Ymd\THis\Z' );
			$uid       = $summary['id'] . '@event-hub.local';
			$permalink = (string) $summary['permalink'];
			// get_the_title() returns entity-encoded text; decode it so the
			// RFC 5545 escape below operates on literal characters.
			$summary_is = html_entity_decode( (string) $summary['title'], ENT_QUOTES, 'UTF-8' );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $this->escape_text( $uid );
			$lines[] = 'DTSTAMP:' . $dtstamp;
			$lines[] = 'DTSTART:' . $this->to_utc_datetime( (string) $summary['start'] );
			$lines[] = 'DTEND:' . $this->to_utc_datetime( (string) $summary['end'] );
			$lines[] = 'SUMMARY:' . $this->escape_text( $summary_is );
			$lines[] = 'LOCATION:' . $this->escape_text( (string) $summary['venue'] );
			$lines[] = 'DESCRIPTION:' . $this->escape_text( (string) $summary['excerpt'] );
			$lines[] = 'URL:' . $this->escape_text( $permalink );
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		$folded = array();

		foreach ( $lines as $line ) {
			foreach ( $this->fold( $line ) as $chunk ) {
				$folded[] = $chunk;
			}
		}

		return implode( "\r\n", $folded ) . "\r\n";
	}

	/**
	 * Wraps a built calendar in a REST response with calendar headers.
	 *
	 * @param WP_REST_Response $response  Response to decorate.
	 * @param array<int,int>   $event_ids Event IDs (re-resolved inside build()).
	 * @param string           $filename  Attachment filename.
	 * @return WP_REST_Response
	 */
	public function prepare_response( WP_REST_Response $response, array $event_ids, string $filename ): WP_REST_Response {
		$response->set_data( $this->build( $event_ids ) );

		$response->set_headers(
			array(
				'Content-Type'        => 'text/calendar; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
				'X-Robots-Tag'        => 'noindex',
				// Marker consumed at serve time (Rest_Controller): body must
				// go out raw, not JSON-encoded by the REST server.
				'X-Event-Hub-Raw'     => 'ics',
			)
		);

		return $response;
	}

	/**
	 * Sends a header (buffer-safe wrapper used by the Retry-After path too).
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function send_header( string $name, string $value ): void {
		if ( ! headers_sent() ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Converts an ISO 8601 datetime string to an RFC 5545 UTC DATE-TIME.
	 *
	 * @param string $iso ISO 8601 input (site-timezone aware) or UNIX-ish.
	 * @return string e.g. 20260423T180000Z, or 19700101T000000Z when absent.
	 */
	private function to_utc_datetime( string $iso ): string {
		if ( '' === $iso ) {
			return '19700101T000000Z';
		}

		try {
			$datetime = new \DateTimeImmutable( $iso );
		} catch ( \Exception $e ) {
			return '19700101T000000Z';
		}

		return $datetime->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	/**
	 * Escapes TEXT per RFC 5545 §3.3.11: backslash, semicolon, comma, and
	 * newlines (both raw LF and CRLF become literal \n). This also neutralizes
	 * CRLF header/property smuggling (T10).
	 *
	 * @param string $value Raw property value.
	 * @return string
	 */
	private function escape_text( string $value ): string {
		$value = str_replace( "\r\n", "\n", $value );
		$value = str_replace( array( "\r", "\n" ), '\\n', $value );

		return str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $value );
	}

	/**
	 * Folds a content line at 75 OCTETS (bytes), splitting between multibyte
	 * characters without breaking them, continuation lines prefixed with a
	 * single space (RFC 5545 §3.1).
	 *
	 * @param string $line Full logical line.
	 * @return array<int,string> Physical lines.
	 */
	private function fold( string $line ): array {
		$bytes    = strlen( $line );
		$limit    = self::FOLD_LIMIT;
		$chunks   = array();
		$offset   = 0;
		$is_first = true;

		while ( $offset < $bytes ) {
			$available = $is_first ? $limit : $limit - 1;
			$end       = min( $offset + max( 1, $available ), $bytes );

			// Never split a multibyte UTF-8 sequence: walk back to a lead byte.
			while ( $end < $bytes && $this->is_utf8_continuation_byte( ord( $line[ $end ] ) ) ) {
				--$end;
			}

			$prefix   = $is_first ? '' : ' ';
			$chunks[] = $prefix . substr( $line, $offset, $end - $offset );
			$offset   = $end;
			$is_first = false;
		}

		if ( array() === $chunks ) {
			return array( '' );
		}

		return $chunks;
	}

	/**
	 * Detects a UTF-8 continuation byte (10xxxxxx).
	 *
	 * @param int $byte Byte value.
	 * @return bool
	 */
	private function is_utf8_continuation_byte( int $byte ): bool {
		return 0x80 === ( $byte & 0xC0 );
	}
}
