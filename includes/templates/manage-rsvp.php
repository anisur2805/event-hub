<?php
/**
 * Manage-booking template (Event Hub, ROADMAP §2.1).
 *
 * Consumes variables prepared by Manage_Page::maybe_render():
 * - $event_hub_row    : repository row or null
 * - $event_hub_event  : WP_Post eh_event or null
 * - $event_hub_state  : eh_state query arg, sanitized whitelist value or ''
 *
 * The token is the bearer credential; the template never echoes it back
 * except into this page's own form fields (needed to authenticate the
 * admin_post handlers, THREAT-MODEL T16).
 *
 * @package event-hub
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template variables (from Manage_Page::maybe_render()).
 *
 * @var array<string,mixed>|null $event_hub_row    Repository row or null.
 * @var \WP_Post|null            $event_hub_event Event post or null.
 * @var string                   $event_hub_state Status arg or ''.
 */

$event_hub_states = array(
	'cancelled' => __( 'Your RSVP has been cancelled.', 'event-hub' ),
	'saved'     => __( 'Your changes have been saved.', 'event-hub' ),
	'invalid'   => __( 'This management link is not valid.', 'event-hub' ),
	'expired'   => __( 'Your session expired. Please reload the page and try again.', 'event-hub' ),
	'error'     => __( 'Something went wrong. Please try again.', 'event-hub' ),
);

get_header();
?>
<main id="primary" class="site-main eh-manage">
	<h1><?php esc_html_e( 'Manage your RSVP', 'event-hub' ); ?></h1>

	<?php if ( '' !== $event_hub_state && isset( $event_hub_states[ $event_hub_state ] ) ) : ?>
		<div class="eh-manage__notice" role="status">
			<p><?php echo esc_html( $event_hub_states[ $event_hub_state ] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( null === $event_hub_row || null === $event_hub_event ) : ?>
		<p><?php esc_html_e( 'This management link is not valid.', 'event-hub' ); ?></p>
	<?php else : ?>
		<?php
		$event_hub_token   = isset( $_GET['eh_manage'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eh_manage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only echo into this page's own forms (bearer credential, T16).
		$event_hub_can_act = '' !== $event_hub_token && 64 === strlen( $event_hub_token ) && ctype_xdigit( $event_hub_token );
		?>
		<section class="eh-manage__booking">
			<h2><?php echo esc_html( html_entity_decode( get_the_title( $event_hub_event ), ENT_QUOTES, 'UTF-8' ) ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Attendee:', 'event-hub' ); ?></strong>
				<?php echo esc_html( (string) $event_hub_row['name'] ); ?>
				&lt;<?php echo esc_html( (string) $event_hub_row['email'] ); ?>&gt;
			</p>
			<p>
				<strong><?php esc_html_e( 'Status:', 'event-hub' ); ?></strong>
				<?php
				echo esc_html(
					'waitlist' === $event_hub_row['status']
						? __( 'Waitlist', 'event-hub' )
						: __( 'Going', 'event-hub' )
				);
				?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Seats:', 'event-hub' ); ?></strong>
				<?php echo esc_html( (string) number_format_i18n( isset( $event_hub_row['guests'] ) ? max( 1, (int) $event_hub_row['guests'] ) : 1 ) ); ?>
			</p>
		</section>

		<?php if ( $event_hub_can_act ) : ?>
			<section class="eh-manage__name">
				<h3><?php esc_html_e( 'Update your name', 'event-hub' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( \EventHub\Manage_Page::ACTION_RENAME ); ?>" />
					<input type="hidden" name="eh_manage" value="<?php echo esc_attr( $event_hub_token ); ?>" />
					<?php wp_nonce_field( \EventHub\Manage_Page::ACTION_RENAME ); ?>
					<p>
						<label for="eh-manage-name"><?php esc_html_e( 'Name', 'event-hub' ); ?></label>
						<input type="text" id="eh-manage-name" name="eh_name" maxlength="100"
							value="<?php echo esc_attr( (string) $event_hub_row['name'] ); ?>" required />
					</p>
					<p><button type="submit"><?php esc_html_e( 'Save name', 'event-hub' ); ?></button></p>
				</form>
			</section>

			<section class="eh-manage__cancel">
				<h3><?php esc_html_e( 'Cancel your RSVP', 'event-hub' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( \EventHub\Manage_Page::ACTION_CANCEL ); ?>" />
					<input type="hidden" name="eh_manage" value="<?php echo esc_attr( $event_hub_token ); ?>" />
					<?php wp_nonce_field( \EventHub\Manage_Page::ACTION_CANCEL ); ?>
					<p><button type="submit"><?php esc_html_e( 'Cancel my RSVP', 'event-hub' ); ?></button></p>
				</form>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</main>
<?php

get_footer();
