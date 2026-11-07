<?php
/**
 * Duplicate-event row action: clone an event as a fresh DRAFT.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Post;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ROADMAP §5B: the organizer's "Copy" verb on the Events list. Creates a
 * new eh_event DRAFT carrying the source's content and event setup, never
 * its RSVPs (custom-table rows stay behind), never its series rule (the
 * copy must not fork the source's spawn tree), never its attendee-facing
 * status (a fresh event starts Scheduled). Redirects back to the list
 * with an edit link.
 */
final class Event_Duplicator {

	/**
	 * Admin-post action slug.
	 *
	 * @var string
	 */
	public const ACTION = 'eh_duplicate_event';

	/**
	 * Nonce action shared by the row-action link and the handler.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'eh_duplicate_event';

	/**
	 * Event meta keys copied verbatim (values already schema-normalized at
	 * their own save time). Mirrors Series_Engine::spawn()'s copy list so
	 * "what a copy carries" stays aligned with "what an occurrence carries"
	 * — minus the series and status keys.
	 *
	 * @var array<int,string>
	 */
	private const META_COPY = array(
		'eh_start',
		'eh_end',
		Event_Timezone::META,
		'eh_venue',
		'eh_venue_id',
		'eh_organizer_id',
		'eh_capacity',
		'eh_rsvp_open_hours',
		'eh_rsvp_close_hours',
		Reminders::HOURS_META,
		'eh_external_url',
		'_eh_online_url',
	);

	/**
	 * Registers the admin_post handler. Called from Plugin::boot().
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Builds the row-action URL for the admin UI.
	 *
	 * @param int $event_id Source event post ID.
	 * @return string Nonced admin-post URL.
	 */
	public static function duplicate_url( int $event_id ): string {
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
	 * Validates the request, duplicates, redirects back to the list.
	 *
	 * @return void
	 */
	public function handle(): void {
		$redirect = $this->handle_request();

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Validates the request and duplicates without terminating the process
	 * — the testable core of handle() (admin-post requires exit; PHPUnit
	 * must survive a successful copy). Redirect target is returned so the
	 * caller controls navigation; tests assert on it directly.
	 *
	 * @return string Redirect URL after a successful copy.
	 */
	public function handle_request(): string {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to duplicate events.', 'event-hub' ),
				esc_html__( 'Forbidden', 'event-hub' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		$source_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$source    = 0 !== $source_id ? get_post( $source_id ) : null;

		if ( ! $source instanceof WP_Post || 'eh_event' !== $source->post_type ) {
			wp_die(
				esc_html__( 'Unknown event.', 'event-hub' ),
				esc_html__( 'Not found', 'event-hub' ),
				array( 'response' => 404 )
			);
		}

		$copy_id = $this->duplicate( $source_id, get_current_user_id() );

		if ( $copy_id instanceof WP_Error ) {
			wp_die(
				esc_html( $copy_id->get_error_message() ),
				esc_html__( 'Copy failed', 'event-hub' ),
				array( 'response' => 500 )
			);
		}

		return add_query_arg(
			array(
				'post_type' => 'eh_event',
				'eh_copied' => (int) $copy_id,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Creates the draft copy. Visible for tests (the handler exits).
	 *
	 * @param int $source_id Source event post ID.
	 * @param int $actor_id  Duplicating user (the copy's author).
	 * @return int|\WP_Error New post ID or error.
	 */
	public function duplicate( int $source_id, int $actor_id ): int|WP_Error {
		$source = get_post( $source_id );

		if ( ! $source instanceof WP_Post || 'eh_event' !== $source->post_type ) {
			return new WP_Error( 'eh_not_found', __( 'Unknown event.', 'event-hub' ), array( 'status' => 404 ) );
		}

		$inserted = wp_insert_post(
			array(
				'post_type'    => 'eh_event',
				'post_status'  => 'draft',
				'post_author'  => $actor_id,
				/* translators: %s: source event title. */
				'post_title'   => sprintf( __( 'Copy of %s', 'event-hub' ), (string) $source->post_title ),
				'post_content' => (string) $source->post_content,
				'post_excerpt' => (string) $source->post_excerpt,
				'post_parent'  => 0,
			),
			true
		);

		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		$new_id = (int) $inserted;

		if ( 0 === $new_id ) {
			return new WP_Error( 'eh_copy_failed', __( 'The event copy could not be created.', 'event-hub' ), array( 'status' => 500 ) );
		}

		foreach ( self::META_COPY as $key ) {
			update_post_meta( $new_id, $key, (string) get_post_meta( $source_id, $key, true ) );
		}

		// Terms: the copy lands in the source's categories so filtered lists
		// and the next publish behave like the source.
		$term_ids = wp_get_object_terms( $source_id, 'eh_event_category', array( 'fields' => 'ids' ) );

		if ( is_array( $term_ids ) && array() !== $term_ids ) {
			wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), 'eh_event_category' );
		}

		// Featured image rides along (calendar/list cards may show it).
		$thumb_id = (int) get_post_meta( $source_id, '_thumbnail_id', true );

		if ( $thumb_id > 0 ) {
			set_post_thumbnail( $new_id, $thumb_id );
		}

		return $new_id;
	}
}
