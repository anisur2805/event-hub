<?php
/**
 * Event Hub — venue directions metabox (ROADMAP §5B).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Directions URL" field on the eh_venue edit screen. The venue post type
 * exposes no custom-fields UI by default in flow with the other plugin
 * fields (the block editor's Custom fields panel is buried and, for a
 * public URL, worth an explicit labeled box); this metabox is the single
 * organizer surface for `eh_venue_directions_url`. Save head is the classic
 * quartet: nonce + capability + sanitize + update.
 */
final class Venue_Meta_Box {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_eh_venue', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Adds the box (venue edit screen only; CPT registration guarantees the
	 * post type exists).
	 *
	 * @return void
	 */
	public function add(): void {
		add_meta_box(
			'event-hub-venue-directions',
			__( 'Directions link', 'event-hub' ),
			array( $this, 'render' ),
			'eh_venue',
			'normal',
			'high'
		);
	}

	/**
	 * Renders the URL input.
	 *
	 * @param WP_Post $post Current venue post.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( 'event_hub_venue_directions', 'event_hub_venue_directions_nonce' );

		$url = (string) get_post_meta( (int) $post->ID, 'eh_venue_directions_url', true );
		?>
		<p class="description">
			<?php esc_html_e( 'Absolute http(s) URL visitors follow for directions to this venue (a maps deep link, transit page or PDF map). Shown as a link on the event page whenever this venue is linked.', 'event-hub' ); ?>
		</p>
		<p>
			<label class="screen-reader-text" for="event-hub-venue-directions-url"><?php esc_html_e( 'Directions URL', 'event-hub' ); ?></label>
			<input
				type="url"
				id="event-hub-venue-directions-url"
				name="event_hub_venue_directions_url"
				class="large-text code"
				value="<?php echo esc_attr( $url ); ?>"
				placeholder="https://maps.example.com/directions"
			/>
		</p>
		<?php
	}

	/**
	 * Saves the sanitized URL (classic save-head quartet: nonce, capability,
	 * sanitize, write). Autosave/nonce/capability exits degrade to a no-op;
	 * an empty value DELETES the meta so clearing the field truly removes
	 * the link.
	 *
	 * @param int     $post_id Venue post ID.
	 * @param WP_Post $post    Venue post (WP core signature; the write keys
	 *                         on the ID — see the ignore note).
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP core save_post signature; the write keys on the ID.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['event_hub_venue_directions_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['event_hub_venue_directions_nonce'] ) ), 'event_hub_venue_directions' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['event_hub_venue_directions_url'] )
			? wp_unslash( (string) $_POST['event_hub_venue_directions_url'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_url directly below.
			: '';

		$url = sanitize_url( trim( $raw ) );

		if ( '' === $url ) {
			delete_post_meta( (int) $post_id, 'eh_venue_directions_url' );

			return;
		}

		update_post_meta( (int) $post_id, 'eh_venue_directions_url', $url );
	}
}
