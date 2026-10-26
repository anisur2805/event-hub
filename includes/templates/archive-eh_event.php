<?php
/**
 * Event archive fallback template (Event Hub).
 *
 * Used when the active theme has no archive-eh_event.php / archive.php for
 * the eh_event CPT archive and category term archives. Fires on /events/ and
 * /event-category/<slug>/ when WordPress resolves to index.php.
 *
 * @package event-hub
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

?>
<main id="primary" class="site-main event-hub-archive">
	<?php if ( have_posts() ) : ?>
		<header class="page-header event-hub-archive__header">
			<h1 class="page-title"><?php echo esc_html( wp_strip_all_tags( (string) get_the_archive_title() ) ); ?></h1>
			<?php
			$event_hub_archive_desc = get_the_archive_description();

			if ( '' !== trim( (string) $event_hub_archive_desc ) ) {
				echo '<div class="archive-description">' . wp_kses_post( $event_hub_archive_desc ) . '</div>';
			}
			?>
		</header>
		<div class="event-hub-archive__grid">
			<?php
			while ( have_posts() ) :
				the_post();

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_card() escapes internally.
				echo \EventHub\Render_Blocks::render_card( \EventHub\Render_Blocks::summary( get_post() ) );
			endwhile;
			?>
		</div>
	<?php else : ?>
		<p class="event-hub-archive__empty"><?php esc_html_e( 'No events found.', 'event-hub' ); ?></p>
	<?php endif; ?>
	<?php
	the_posts_pagination(
		array(
			'prev_text' => __( 'Previous', 'event-hub' ),
			'next_text' => __( 'Next', 'event-hub' ),
		)
	);
	?>
</main>
<?php

get_footer();
