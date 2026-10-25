<?php
/**
 * RSVP button block render template.
 *
 * @package event-hub
 *
 * @var array<string,mixed>|null $attributes Block attributes (injected by WP core's render_callback).
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EventHub\Render_Blocks;

$attributes = ( isset( $attributes ) && is_array( $attributes ) ) ? $attributes : array();

$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;

// Also seed global server state; render_rsvp_button() re-sets it (merge-safe).
wp_interactivity_state(
	'event-hub',
	array(
		'restUrl' => esc_url_raw( rest_url() ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'user'    => is_user_logged_in() ? array(
			'name'  => wp_get_current_user()->display_name,
			'email' => wp_get_current_user()->user_email,
		) : null,
	)
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render_Blocks escapes all dynamic output.
echo Render_Blocks::render_rsvp_button_block( (array) $attributes );
