<?php
/**
 * Organizer dashboard widget (ROADMAP §3.1): upcoming events with fill rate,
 * waitlist depth, and the newest RSVPs. Admin-gated: the widget registers
 * only for users holding edit_others_posts, and every value it prints is
 * aggregate data or admin-surface RSVP fields (emails allowed HERE only).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard widget "Event Hub". Server-rendered, read-only; links point at
 * the admin screen's summary tab and the event editor.
 */
final class Dashboard_Widget {

	/**
	 * Upcoming events shown in the widget (also the summary tab's limit —
	 * both surfaces share one scale).
	 *
	 * @var int
	 */
	public const EVENT_LIMIT = 5;

	/**
	 * Recent RSVP rows shown in the widget and the summary tab.
	 *
	 * @var int
	 */
	public const RECENT_LIMIT = 8;

	/**
	 * Registers the widget. Called on wp_dashboard_setup.
	 *
	 * @return void
	 */
	public function register(): void {
		// Gate at registration, not render: the widget never appears (not even
		// collapsed) for users who could not read its email column anyway.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		// wp_add_dashboard_widget() lives in wp-admin/includes/dashboard.php —
		// loaded by wp-admin page loads only, not REST/CLI/test contexts. A
		// guarded no-op beats a fatal outside the dashboard.
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'event-hub-dashboard',
			__( 'Event Hub', 'event-hub' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the widget body: upcoming events table + recent RSVPs list.
	 *
	 * @return void
	 */
	public function render(): void {
		$repository = Plugin::instance()->repository();
		$events     = Render_Blocks::upcoming_events( self::EVENT_LIMIT );
		$recent     = $repository->recent( self::RECENT_LIMIT );

		$admin_url = admin_url( 'admin.php?page=event-hub-rsvps&tab=summary' );

		if ( array() === $events && array() === $recent ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No upcoming events or RSVPs yet.', 'event-hub' )
			);

			return;
		}

		echo '<h3>' . esc_html__( 'Upcoming events', 'event-hub' ) . '</h3>';

		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No upcoming events.', 'event-hub' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><tbody>';

			foreach ( $events as $summary ) {
				$parts = self::fill_parts( $summary );

				echo '<tr>';

				printf(
					'<td><a href="%1$s">%2$s</a></td><td>%3$s</td>',
					esc_url( (string) $summary['permalink'] ),
					esc_html( (string) $summary['title'] ),
					esc_html( self::date_label( (string) $summary['start'] ) )
				);

				printf(
					'<td>%s</td><td>%s</td>',
					esc_html( $parts['fill'] ),
					esc_html( $parts['waitlist'] )
				);

				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Recent RSVPs', 'event-hub' ) . '</h3>';

		if ( array() === $recent ) {
			echo '<p>' . esc_html__( 'No RSVPs yet.', 'event-hub' ) . '</p>';
		} else {
			echo '<ul class="event-hub-dashboard__recent">';

			foreach ( $recent as $row ) {
				printf(
					'<li>%1$s — %2$s (%3$s, %4$s)</li>',
					esc_html( self::time_ago_label( (string) $row['created_at'] ) ),
					esc_html( (string) $row['name'] ),
					esc_html( (string) $row['email'] ),
					esc_html( self::status_label( (string) $row['status'], (int) $row['guests'] ) )
				);
			}

			echo '</ul>';
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $admin_url ),
			esc_html__( 'View the organizer summary', 'event-hub' )
		);
	}

	/**
	 * Widget-table cell strings for one event: fill ("12 / 40 seats (30%)",
	 * "12 seats (unlimited)" without capacity) and waitlist depth in seats
	 * (counts() is seat-based since DB 1.4.0 — DECISIONS #41).
	 *
	 * @param array<string,mixed> $summary Render_Blocks::summary() output.
	 * @return array{fill:string,waitlist:string}
	 */
	public static function fill_parts( array $summary ): array {
		$capacity = $summary['capacity'];
		$going    = (int) ( $summary['counts']['going'] ?? 0 );
		$waitlist = (int) ( $summary['counts']['waitlist'] ?? 0 );

		if ( null === $capacity ) {
			$fill = sprintf(
				/* translators: %s: number of seats booked. */
				__( '%s seats (unlimited)', 'event-hub' ),
				number_format_i18n( $going )
			);
		} else {
			$capacity = max( 0, (int) $capacity );

			// Clamp at 100%: the §2.3 admission rule can book a bigger party
			// than the remaining seats, so going MAY exceed capacity legally —
			// "130%" would read as a bug (same clamp as the front-end label).
			if ( $capacity > 0 ) {
				$percent = (int) floor( min( 100, ( $going / $capacity ) * 100 ) );
			} else {
				$percent = 100;
			}

			// 0 capacity = event is full by definition; percent stays 100.
			$fill = sprintf(
				/* translators: 1: seats booked, 2: total capacity, 3: percentage. */
				__( '%1$s / %2$s seats (%3$s%%)', 'event-hub' ),
				number_format_i18n( $going ),
				number_format_i18n( $capacity ),
				number_format_i18n( $percent )
			);
		}

		$waitlist_label = sprintf(
			/* translators: %s: number of waitlisted seats. */
			__( 'Waitlist: %s', 'event-hub' ),
			number_format_i18n( $waitlist )
		);

		return array(
			'fill'     => $fill,
			'waitlist' => $waitlist_label,
		);
	}

	/**
	 * Site-localized short date for an event start ISO string; em dash when
	 * missing/unparseable (Admin_Columns::when_label() convention).
	 *
	 * @param string $start_iso ISO 8601 start.
	 * @return string
	 */
	public static function date_label( string $start_iso ): string {
		if ( '' === trim( $start_iso ) ) {
			return __( '—', 'event-hub' );
		}

		try {
			$datetime = new \DateTimeImmutable( $start_iso, wp_timezone() );
		} catch ( \Exception $e ) {
			$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $start_iso, wp_timezone() );
		}

		if ( false === $datetime ) {
			return __( '—', 'event-hub' );
		}

		$format = get_option( 'date_format' );
		$format = is_string( $format ) && '' !== $format ? $format : 'F j, Y';

		return wp_date( $format, $datetime->getTimestamp() );
	}

	/**
	 * RSVP status + seat size ("Going · 2 seats", single seat omitted — the
	 * default needs no suffix).
	 *
	 * @param string $status Row status.
	 * @param int    $guests Seats on the row.
	 * @return string
	 */
	public static function status_label( string $status, int $guests ): string {
		$status_text = 'waitlist' === $status
			? __( 'Waitlist', 'event-hub' )
			: __( 'Going', 'event-hub' );

		if ( $guests <= 1 ) {
			return $status_text;
		}

		return sprintf(
			/* translators: 1: status, 2: number of seats. */
			__( '%1$s · %2$s seats', 'event-hub' ),
			$status_text,
			number_format_i18n( max( 1, $guests ) )
		);
	}

	/**
	 * Human "x ago" for a stored site-time DATETIME; falls back to the raw
	 * formatted date beyond a day and to the date itself when unparseable.
	 *
	 * @param string $mysql_datetime Stored created_at (site time, Y-m-d H:i:s).
	 * @return string
	 */
	public static function time_ago_label( string $mysql_datetime ): string {
		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $mysql_datetime, wp_timezone() );

		if ( false === $datetime ) {
			return __( '—', 'event-hub' );
		}

		$diff = time() - $datetime->getTimestamp();

		if ( $diff >= 0 && $diff < DAY_IN_SECONDS ) {
			/* translators: %s: human-readable time difference, e.g. "5 mins". */
			return sprintf( __( '%s ago', 'event-hub' ), human_time_diff( $datetime->getTimestamp() ) );
		}

		$format = get_option( 'date_format' );
		$format = is_string( $format ) && '' !== $format ? $format : 'F j, Y';

		return wp_date( $format, $datetime->getTimestamp() );
	}
}
