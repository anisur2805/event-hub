<?php
/**
 * Single event fallback template (Event Hub).
 *
 * Used when the active theme has no single-eh_event.php / singular.php —
 * WordPress resolves a single eh_event to the theme's index.php, and
 * Single_Template::maybe_render() swaps in this file. The theme's own
 * template always wins.
 *
 * @package event-hub
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

?>
<main id="primary" class="site-main">
	<?php
	while ( have_posts() ) :
		the_post();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_single_event() escapes internally.
		echo \EventHub\Render_Blocks::render_single_event( get_post() );
	endwhile;
	?>
</main>
<?php

get_footer();
