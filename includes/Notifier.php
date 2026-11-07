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

		// Virtual events (5.6): the joining URL is a confirmed-attendee
		// secret — ONLY the going branch may carry it. A waitlist row has no
		// spot yet, so its confirmation never includes it.
		if ( 'waitlist' !== ( $rsvp['status'] ?? '' ) ) {
			$online_url = Render_Blocks::online_url( $event_id );

			if ( '' !== $online_url ) {
				$rows[] = sprintf(
					/* translators: %s: online joining URL. */
					__( 'Join online: %s', 'event-hub' ),
					$online_url
				);
				$rows[] = __( 'This link is for you — please do not share it.', 'event-hub' );
			}
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

	/**
	 * Sends the pre-event reminder email (ROADMAP 5.2). Same contract as
	 * send_confirmation: never throws, false on any refusal; the caller
	 * stamps `reminded_at` only when this returns true.
	 *
	 * @param array<string,mixed> $rsvp     Repository row (name, email, status).
	 * @param int                 $event_id Event post ID.
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_reminder( array $rsvp, int $event_id ): bool {
		$email = (string) ( $rsvp['email'] ?? '' );

		if ( '' === $email || false === is_email( $email ) ) {
			return false;
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return false;
		}

		$event_title = html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' );

		$subject = sprintf(
			/* translators: %s: event title. */
			__( 'Reminder: %s is coming up', 'event-hub' ),
			$event_title
		);

		$rows = array(
			sprintf( /* translators: %s: attendee name. */ __( 'Name: %s', 'event-hub' ), (string) ( $rsvp['name'] ?? '' ) ),
			sprintf(
				/* translators: %s: attendance status (going / waitlist). */
				__( 'Status: %s', 'event-hub' ),
				'waitlist' === ( $rsvp['status'] ?? '' )
					? __( 'Waitlist', 'event-hub' )
					: __( 'Going', 'event-hub' )
			),
		);

		$rows[] = self::start_line( $event_id );

		// Virtual events (5.6): the reminder audience INCLUDES waitlist rows
		// (list_for_reminder() selects both statuses and prints their Status
		// line) — the joining URL must gate on the row's own status exactly
		// like send_confirmation. A waitlisted attendee has no spot yet, so
		// their reminder never carries the link.
		if ( 'waitlist' !== ( $rsvp['status'] ?? '' ) ) {
			$online_url = Render_Blocks::online_url( $event_id );

			if ( '' !== $online_url ) {
				$rows[] = sprintf(
					/* translators: %s: online joining URL. */
					__( 'Join online: %s', 'event-hub' ),
					$online_url
				);
				$rows[] = __( 'This link is for you — please do not share it.', 'event-hub' );
			}
		}

		$message = implode(
			"\r\n",
			array_merge(
				$rows,
				array(
					'',
					__( 'We look forward to seeing you.', 'event-hub' ),
					'',
					sprintf(
						/* translators: %s: site name. */
						__( '— %s', 'event-hub' ),
						wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
					),
				)
			)
		);

		return self::dispatch( $email, $subject, $message );
	}

	/**
	 * Sends the cancellation notice to the attendee whose RSVP was removed
	 * (their own action via the manage page — a confirmation, not an alert).
	 *
	 * @param array<string,mixed> $deleted  The removed RSVP row.
	 * @param int                 $event_id Event post ID.
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_cancel_notice( array $deleted, int $event_id ): bool {
		$email = (string) ( $deleted['email'] ?? '' );

		if ( '' === $email || false === is_email( $email ) ) {
			return false;
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: event title. */
			__( 'Your RSVP was cancelled: %s', 'event-hub' ),
			html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' )
		);

		$message = implode(
			"\r\n",
			array(
				sprintf( /* translators: %s: attendee name. */ __( 'Name: %s', 'event-hub' ), (string) ( $deleted['name'] ?? '' ) ),
				self::start_line( $event_id ),
				'',
				__( 'Your registration has been removed and your seats are free again. You can RSVP again at any time while the event is open.', 'event-hub' ),
				'',
				sprintf(
					/* translators: %s: site name. */
					__( '— %s', 'event-hub' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
			)
		);

		return self::dispatch( $email, $subject, $message );
	}

	/**
	 * Sends the new-RSVP notice to the organizer (author of the event post).
	 *
	 * @param array<string,mixed> $rsvp     The created RSVP row.
	 * @param int                 $event_id Event post ID.
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_organizer_notice( array $rsvp, int $event_id ): bool {
		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return false;
		}

		$organizer_id = (int) $event->post_author;

		if ( $organizer_id < 1 ) {
			return false;
		}

		$organizer = get_userdata( $organizer_id );

		if ( false === $organizer || '' === (string) $organizer->user_email ) {
			return false;
		}

		$event_title = html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' );

		$subject = sprintf(
			/* translators: 1: attendee name, 2: event title. */
			__( 'New RSVP: %1$s for %2$s', 'event-hub' ),
			(string) ( $rsvp['name'] ?? '' ),
			$event_title
		);

		$message = implode(
			"\r\n",
			array(
				sprintf( /* translators: %s: attendee name. */ __( 'Name: %s', 'event-hub' ), (string) ( $rsvp['name'] ?? '' ) ),
				sprintf( /* translators: %s: attendee email. */ __( 'Email: %s', 'event-hub' ), (string) ( $rsvp['email'] ?? '' ) ),
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
				'',
				sprintf(
					/* translators: %s: admin RSVP manager URL. */
					__( 'Manage RSVPs: %s', 'event-hub' ),
					admin_url( 'admin.php?page=event-hub-rsvps&event=' . $event_id )
				),
			)
		);

		return self::dispatch( (string) $organizer->user_email, $subject, $message );
	}

	/**
	 * The formatted "Starts: …" line for an event's stored start meta, or the
	 * raw value when unparseable; '' when the event has no start (reminder
	 * and cancel bodies skip the line entirely then — callers append as-is).
	 *
	 * @param int $event_id Event post ID.
	 * @return string Ready-to-print line ('' when no start exists).
	 */
	private static function start_line( int $event_id ): string {
		$start = get_post_meta( $event_id, 'eh_start', true );

		if ( ! is_string( $start ) || '' === trim( $start ) ) {
			return '';
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $start, wp_timezone() );

		return sprintf(
			/* translators: %s: event start date and time. */
			__( 'Starts: %s', 'event-hub' ),
			false !== $datetime
				? $datetime->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				: $start
		);
	}

	/**
	 * Sends the status-change notice to one attendee (ROADMAP 5.3). Called
	 * per RSVP row by the status sweep; the event status is re-read at send
	 * time so a mail outage that outlives an un-cancel sends nothing stale
	 * (the sweep's event list no longer contains the event in that case).
	 *
	 * @param array<string,mixed> $rsvp     Repository row (name, email).
	 * @param int                 $event_id Event post ID.
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_status_change( array $rsvp, int $event_id ): bool {
		$email = (string) ( $rsvp['email'] ?? '' );

		if ( '' === $email || false === is_email( $email ) ) {
			return false;
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return false;
		}

		$status = Event_Status::get( $event_id );

		if ( '' === $status ) {
			return false; // Back to scheduled since queued — send nothing.
		}

		$event_title = html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' );

		$subject = sprintf(
			/* translators: %s: event title. */
			__( 'Event update: %s', 'event-hub' ),
			$event_title
		);

		$rows = array(
			sprintf( /* translators: %s: attendee name. */ __( 'Name: %s', 'event-hub' ), (string) ( $rsvp['name'] ?? '' ) ),
		);

		if ( 'cancelled' === $status ) {
			$rows[] = __( 'Status: Cancelled', 'event-hub' );
			$rows[] = '';
			$rows[] = __( 'We are sorry — this event has been cancelled. Your RSVP stays on the list for the record, but the event will not take place.', 'event-hub' );
		} else {
			$start_line = self::start_line( $event_id );

			$rows[] = __( 'Status: Postponed', 'event-hub' );
			$rows[] = '';
			$rows[] = __( 'This event has been postponed — the scheduled date is no longer valid. The new date will be announced; your RSVP carries over.', 'event-hub' );

			if ( '' !== $start_line ) {
				$rows[] = sprintf(
					/* translators: %s: the no-longer-valid original start. */
					__( 'The previously scheduled time (%s) is no longer valid.', 'event-hub' ),
					(string) preg_replace( '/^Starts: /', '', $start_line )
				);
			}
		}

		$message = implode(
			"\r\n",
			array_merge(
				$rows,
				array(
					'',
					sprintf(
						/* translators: %s: admin RSVP manager URL. */
						__( 'Event page: %s', 'event-hub' ),
						(string) get_permalink( $event )
					),
					'',
					sprintf(
						/* translators: %s: site name. */
						__( '— %s', 'event-hub' ),
						wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
					),
				)
			)
		);

		return self::dispatch( $email, $subject, $message );
	}

	/**
	 * Sends the waitlist-promotion notice to the attendee (ROADMAP 5A.2).
	 * Fired by `event_hub_rsvp_promoted` after delete_and_promote() flips a
	 * waitlist row to going — the attendee learns their spot opened up
	 * without watching the page. Same guards as the cancel notice: a valid
	 * recipient and a live event; the row is re-read state from inside the
	 * promotion lock, so no extra re-check here.
	 *
	 * @param array<string,mixed> $rsvp     Promoted row (name, email).
	 * @param int                 $event_id Event post ID.
	 * @return bool True when wp_mail accepted the message.
	 */
	public static function send_promotion_notice( array $rsvp, int $event_id ): bool {
		$email = (string) ( $rsvp['email'] ?? '' );

		if ( '' === $email || false === is_email( $email ) ) {
			return false;
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return false;
		}

		$event_title = html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' );

		$subject = sprintf(
			/* translators: %s: event title. */
			__( 'You are in: %s', 'event-hub' ),
			$event_title
		);

		$message = implode(
			"\r\n",
			array(
				sprintf( /* translators: %s: attendee name. */ __( 'Name: %s', 'event-hub' ), (string) ( $rsvp['name'] ?? '' ) ),
				__( 'Status: Going', 'event-hub' ),
				self::start_line( $event_id ),
				'',
				__( 'Good news — a spot opened up and your waitlisted RSVP is now a confirmed booking. No action needed.', 'event-hub' ),
				'',
				sprintf(
					/* translators: %s: event page URL. */
					__( 'Event page: %s', 'event-hub' ),
					(string) get_permalink( $event )
				),
				'',
				sprintf(
					/* translators: %s: site name. */
					__( '— %s', 'event-hub' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
			)
		);

		// Virtual events (5.6): the freshly-promoted attendee is going, so
		// the joining URL rides the promotion notice too — they never saw it
		// while waitlisted (the confirmation with the link went out at the
		// waitlist status, i.e. without it).
		$online_url = Render_Blocks::online_url( $event_id );

		if ( '' !== $online_url ) {
			$message .= "\r\n\r\n" . sprintf(
				/* translators: %s: online joining URL. */
				__( 'Join online: %s', 'event-hub' ),
				$online_url
			) . "\r\n" . __( 'This link is for you — please do not share it.', 'event-hub' );
		}

		return self::dispatch( $email, $subject, $message );
	}

	/**
	 * Sends with the shared per-send SMTP timeout guard. Never throws.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $message Plain-text body.
	 * @return bool True when wp_mail accepted the message.
	 */
	private static function dispatch( string $to, string $subject, string $message ): bool {
		$timeout_hook = static function ( \PHPMailer\PHPMailer\PHPMailer $phpmailer ): void {
			// PHPMailer's typed property is camelCase by class design.
			$phpmailer->Timeout = self::TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- third-party property name.
		};

		add_action( 'phpmailer_init', $timeout_hook );

		$sent = wp_mail( $to, $subject, $message );

		remove_action( 'phpmailer_init', $timeout_hook );

		return is_bool( $sent ) ? $sent : false;
	}
}
