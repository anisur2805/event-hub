<?php
/**
 * Plugin Name:       Event Hub
 * Plugin URI:        https://example.com/event-hub
 * Description:       Events + RSVP with capacity-aware registration, admin RSVP management, ICS export, and Interactivity API blocks.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Event Hub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-hub
 * Domain Path:       /languages
 *
 * @package event-hub
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVENT_HUB_VERSION', '1.0.0' );
define( 'EVENT_HUB_DB_VERSION', '1.0.0' );
define( 'EVENT_HUB_FILE', __FILE__ );
define( 'EVENT_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVENT_HUB_URL', plugin_dir_url( __FILE__ ) );

$event_hub_autoload = EVENT_HUB_DIR . 'vendor/autoload.php';

if ( is_readable( $event_hub_autoload ) ) {
	require_once $event_hub_autoload;
} else {
	/*
	 * SPL autoloader fallback (docs/DECISIONS.md #8): maps the EventHub\ PSR-4
	 * namespace onto includes/ so the plugin runs with no dev dependencies.
	 */
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'EventHub\\';

			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = EVENT_HUB_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

unset( $event_hub_autoload );

/**
 * Boots the plugin once all plugins are loaded.
 *
 * @return void
 */
function event_hub_bootstrap(): void {
	EventHub\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'event_hub_bootstrap' );
