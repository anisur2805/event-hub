<?php
/**
 * Event Hub — "Event details" panel enqueuer for the eh_event block editor.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the editor sidebar panel (build/admin/event-details.js) on
 * eh_event edit screens. The panel edits eh_* meta via the REST API; meta
 * registration lives in Plugin::register_meta().
 */
final class Editor_Panel {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel script with translations. Skips non-eh_event
	 * screens so other post types' editors stay untouched.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'eh_event' !== $screen->post_type ) {
			return;
		}

		$script = EVENT_HUB_DIR . 'build/admin/event-details.js';

		// The panel is an enhancement to the editor; without the build the
		// editor keeps working (meta stays editable via WP-CLI), so degrade
		// gracefully instead of erroring.
		if ( ! is_readable( $script ) ) {
			return;
		}

		$asset_path = EVENT_HUB_DIR . 'build/admin/event-details.asset.php';

		$asset = array();

		if ( is_readable( $asset_path ) ) {
			// The is_readable() guard above satisfies PHPStan's static
			// file-existence check for the require.
			$asset = (array) require $asset_path; // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version comes from the manifest below.
		}

		$deps = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();

		/*
		 * registerPlugin + PluginDocumentSettingPanel are consumed from the
		 * wp.plugins / wp.editPost runtime globals (the TSX imports them from
		 * window.wp, not from bundled packages), so their handles are
		 * declared here instead of relying on dependency extraction.
		 */
		$deps = array_unique( array_merge( $deps, array( 'wp-plugins', 'wp-edit-post' ) ) );

		wp_enqueue_script(
			'event-hub-editor-panel',
			EVENT_HUB_URL . 'build/admin/event-details.js',
			$deps,
			isset( $asset['version'] ) ? (string) $asset['version'] : EVENT_HUB_VERSION,
			true
		);

		wp_set_script_translations( 'event-hub-editor-panel', 'event-hub' );
	}
}
