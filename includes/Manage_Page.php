<?php
/**
 * Token-gated manage-booking page (ROADMAP §2.1, DECISIONS #39).
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
 * Front-end booking management. The manage URL (?eh_manage=<token>) rides
 * template_include before wp_head; the token is the bearer credential for
 * these anonymous actions (no session => cookie nonce has no meaning here,
 * THREAT-MODEL T16). All writes go through admin_post handlers that
 * re-derive the row from the posted token.
 */
final class Manage_Page {

	/**
	 * GET query var carrying the raw manage token.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'eh_manage';

	/**
	 * Admin_post action slugs.
	 *
	 * @var string
	 */
	public const ACTION_CANCEL = 'eh_manage_cancel';
	public const ACTION_RENAME = 'eh_manage_rename';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'template_include', array( $this, 'maybe_render' ), 99 );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_CANCEL, array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::ACTION_RENAME, array( $this, 'handle_rename' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_RENAME, array( $this, 'handle_rename' ) );
	}

	/**
	 * Renders the manage page when the query var holds a plausible token,
	 * regardless of whether the token is valid — an unknown token shows the
	 * invalid state on the same chrome (no enumeration oracle).
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function maybe_render( string $template ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET routing var; writes are nonce-free by design (T16).
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ) : '';

		$state_only = '' === $token && isset( $_GET['eh_state'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bare post-cancel confirmation (no token exists anymore).

		if ( '' === $token && ! $state_only ) {
			return $template;
		}

		$row = '' !== $token ? ( new Rsvp_Repository() )->find_by_token( $token ) : null;

		status_header( 200 );
		nocache_headers();

		$event_hub_row   = $row;
		$event_hub_event = null;

		if ( null !== $row ) {
			$event_candidate = get_post( (int) $row['event_id'] );

			if ( $event_candidate instanceof \WP_Post && 'eh_event' === $event_candidate->post_type ) {
				$event_hub_event = $event_candidate;
			}
		}

		$event_hub_state = isset( $_GET['eh_state'] ) ? sanitize_key( wp_unslash( (string) $_GET['eh_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state, fixed whitelist in template.

		// 5A.2: FIFO waitlist position for the status line (0 when the row is
		// going or missing — the template shows the plain status then).
		$event_hub_position = ( null !== $row && isset( $event_hub_row['id'] ) )
			? ( new Rsvp_Repository() )->waitlist_position( (int) $event_hub_row['id'] )
			: 0;

		require EVENT_HUB_DIR . 'includes/templates/manage-rsvp.php';

		return '';
	}

	/**
	 * Shared handler core: validate token + nonce, resolve row/event.
	 *
	 * @param string $action admin_post action slug (nonce field check).
	 * @return array{row:array<string,mixed>,event:\WP_Post,token:string}|WP_Error
	 */
	private function resolve_request( string $action ) {
		$token = isset( $_POST[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::QUERY_VAR ] ) ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'eh_manage_missing', __( 'This management link is not valid.', 'event-hub' ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			return new WP_Error( 'eh_manage_expired', __( 'Your session expired. Please go back and reload the page.', 'event-hub' ) );
		}

		$row = ( new Rsvp_Repository() )->find_by_token( $token );

		if ( null === $row ) {
			return new WP_Error( 'eh_manage_invalid', __( 'This management link is not valid.', 'event-hub' ) );
		}

		$event = get_post( (int) $row['event_id'] );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type ) {
			return new WP_Error( 'eh_manage_invalid', __( 'This management link is not valid.', 'event-hub' ) );
		}

		return array(
			'row'   => $row,
			'event' => $event,
			'token' => $token,
		);
	}

	/**
	 * Cancels the RSVP via repository delete_and_promote() so waitlist
	 * promotion (§1.3) fires exactly as it does for admin deletes. Redirects
	 * back to the manage page (GPC redirect; message via query arg).
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		$ctx = $this->resolve_request( self::ACTION_CANCEL );

		if ( $ctx instanceof WP_Error ) {
			wp_safe_redirect( self::redirect_url( null, 'invalid' ) );
			exit;
		}

		$repository = new Rsvp_Repository();
		$result     = $repository->delete_and_promote( (int) $ctx['row']['id'] );

		if ( $result instanceof WP_Error ) {
			// Row still exists — the token stays valid, keep the page usable.
			wp_safe_redirect( self::redirect_url( $ctx['token'], 'error' ) );
			exit;
		}

		if ( null === $result['deleted'] ) {
			// Concurrently vanished: nothing to manage anymore.
			wp_safe_redirect( self::redirect_url( null, 'invalid' ) );
			exit;
		}

		// Success: the row (and with it the token) is gone. A redirect back
		// to ?eh_manage= would render the invalid state, so the confirmation
		// rides home_url('/') as a bare state arg — rendered by the
		// state-only branch of maybe_render().
		wp_safe_redirect(
			esc_url_raw( add_query_arg( array( 'eh_state' => 'cancelled' ), home_url( '/' ) ) )
		);
		exit;
	}

	/**
	 * Updates the RSVP's display name. Redirects back with a status arg.
	 *
	 * @return void
	 */
	public function handle_rename(): void {
		$ctx = $this->resolve_request( self::ACTION_RENAME );

		if ( $ctx instanceof WP_Error ) {
			$state = 'eh_manage_expired' === $ctx->get_error_code() ? 'expired' : 'invalid';
			wp_safe_redirect( self::redirect_url( null, $state ) );
			exit;
		}

		$raw_name = isset( $_POST['eh_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['eh_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in resolve_request(); normalize_name() sanitizes again.
		$name     = Rest_Controller::normalize_name( $raw_name );

		if ( '' === $name ) {
			wp_safe_redirect( self::redirect_url( $ctx['token'], 'invalid' ) );
			exit;
		}

		$repository = new Rsvp_Repository();
		$updated    = $repository->update_name( (int) $ctx['row']['id'], $name );

		if ( $updated instanceof WP_Error || null === $updated ) {
			wp_safe_redirect( self::redirect_url( $ctx['token'], 'error' ) );
			exit;
		}

		wp_safe_redirect( self::redirect_url( $ctx['token'], 'saved' ) );
		exit;
	}

	/**
	 * Builds a safe redirect back to the manage page (or plain home when the
	 * token is gone). Status args are a fixed whitelist, never user input.
	 *
	 * @param string|null $token Raw token (null when invalid/expired).
	 * @param string      $state One of cancelled|saved|invalid|expired|error.
	 * @return string
	 */
	private static function redirect_url( ?string $token, string $state ): string {
		$states = array( 'cancelled', 'saved', 'invalid', 'expired', 'error' );

		if ( null === $token || ! in_array( $state, $states, true ) ) {
			return home_url( '/' );
		}

		return esc_url_raw( add_query_arg( array( 'eh_state' => $state ), self::manage_url_raw( $token ) ) );
	}

	/**
	 * The manage URL without output escaping (redirect target; esc_url_raw
	 * applied inside).
	 *
	 * @param string $raw_token Raw token.
	 * @return string
	 */
	private static function manage_url_raw( string $raw_token ): string {
		return add_query_arg( array( self::QUERY_VAR => rawurlencode( $raw_token ) ), home_url( '/' ) );
	}
}
