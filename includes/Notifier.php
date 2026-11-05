<?php
/**
 * Confirmation email for new RSVPs (ROADMAP §2.1).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the plain-text confirmation / manage-booking email. Opt-in via the
 * `event_hub_confirmations` option (Admin_Page toggle); a send failure never
 * touches the RSVP result — the 201 response must not depend on mail.
 */
final class Notifier {

	/**
	 * Option name driving the Admin_Page toggle.
	 *
	 * @var string
	 */
	public const OPTION = 'event_hub_confirmations';

	/**
	 * SMTP/socket timeout forced per send via phpmailer_init, so a dead
	 * mail host cannot hang a REST request for the PHP default (DECISIONS
	 * #39: timeout-safe failure).
	 *
	 * @var int
	 */
	private const TIMEOUT = 10;

	/**
	 * Whether confirmations are enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Builds the attendee-facing manage URL.
	 *
	 * @param string $raw_token Raw 64-hex-char manage token.
	 * @return string
	 */
	public static function manage_url( string $raw_token ): string {
		$url = add_query_arg(
			array( 'eh_manage' => rawurlencode( $raw_token ) ),
			home_url( '/' )
		);

		return esc_url_raw( $url );
	}

	/**
	 * Sends the confirmation email. Never throws; failures return false.
	 *
	 * @param array<string,mixed> $rsvp      Repository row (name, email, status).
	 * @param int                 $event_id  Event post ID.
	 * @param string              $raw_token Raw manage token (emailed once).
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_confirmation( array $rsvp, int $event_id, string $raw_token ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		$email = (string) ( $rsvp['email'] ?? '' );

		if ( '' === $email || false === is_email( $email ) ) {
			return false;
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: event title. */
			__( 'Your RSVP is confirmed: %s', 'event-hub' ),
			html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' )
		);

		$rows = array(
			sprintf( /* translators: %s: attendee name. */ __( 'Name: %s', 'event-hub' ), (string) ( $rsvp['name'] ?? '' ) ),
			sprintf( /* translators: %s: attendee email. */ __( 'Email: %s', 'event-hub' ), $email ),
			sprintf(
				/* translators: %s: attendance status (going / waitlist). */
				__( 'Status: %s', 'event-hub' ),
				'waitlist' === ( $rsvp['status'] ?? '' )
					? __( 'Waitlist', 'event-hub' )
					: __( 'Going', 'event-hub' )
			),
			sprintf(
				/* translators: %s: number of seats. */
				__( 'Seats: %s', 'event-hub' ),
				(string) number_format_i18n( isset( $rsvp['guests'] ) ? max( 1, (int) $rsvp['guests'] ) : 1 )
			),
		);

		$start = get_post_meta( $event_id, 'eh_start', true );

		if ( is_string( $start ) && '' !== trim( $start ) ) {
			$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $start, wp_timezone() );

			$rows[] = sprintf(
				/* translators: %s: event start date and time. */
				__( 'Starts: %s', 'event-hub' ),
				false !== $datetime
					? $datetime->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
					: $start
			);
		}

		$message = implode(
			"\r\n",
			array_merge(
				$rows,
				array(
					'',
					__( 'Manage your booking (cancel or update your name):', 'event-hub' ),
					self::manage_url( $raw_token ),
					'',
					sprintf(
						/* translators: %s: site name. */
						__( '— %s', 'event-hub' ),
						wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
					),
				)
			)
		);

		$timeout_hook = static function ( \PHPMailer\PHPMailer\PHPMailer $phpmailer ): void {
			// PHPMailer's typed property is camelCase by class design.
			$phpmailer->Timeout = self::TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- third-party property name.
		};

		add_action( 'phpmailer_init', $timeout_hook );

		$sent = wp_mail( $email, $subject, $message );

		remove_action( 'phpmailer_init', $timeout_hook );

		return is_bool( $sent ) ? $sent : false;
	}
}
