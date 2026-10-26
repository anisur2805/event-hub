<?php
/**
 * Plugin front-end fallback template for single events.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders single eh_event posts when the active theme has no template for
 * them (classic themes without single-eh_event.php / singular.php resolve to
 * index.php). A theme's own template always wins: the filter only takes over
 * when WP resolved to the theme's index.php last-resort fallback.
 */
final class Single_Template {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'template_include', array( $this, 'maybe_render' ), 99 );
	}

	/**
	 * Takes over single-event rendering only on the theme's index.php
	 * fallback. Runs before wp_head, so the wp_enqueue_scripts hook added
	 * here still fires normally.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function maybe_render( string $template ): string {
		if ( ! is_singular( 'eh_event' ) ) {
			return $template;
		}

		if ( 'index.php' !== basename( $template ) ) {
			return $template;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		return EVENT_HUB_DIR . 'includes/templates/single-eh_event.php';
	}

	/**
	 * Enqueues the shared stylesheet and the RSVP view module. The module id
	 * is core's generated handle for the rsvp-button block's
	 * viewScriptModule (register_block_script_module_id(): block name with
	 * slashes hyphenated + "-view-script-module").
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( wp_style_is( 'event-hub-blocks', 'registered' ) ) {
			wp_enqueue_style( 'event-hub-blocks' );
		}

		if ( is_readable( EVENT_HUB_DIR . 'build/blocks/rsvp-button/view.js' ) && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'event-hub-rsvp-button-view-script-module' );
		}
	}
}
