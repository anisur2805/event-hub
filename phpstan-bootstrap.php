<?php
/**
 * PHPStan bootstrap: stub the WP environment enough for static analysis.
 *
 * @package event-hub
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Load WP function stubs via the phpstan-wordpress extension at analyse time;
// this bootstrap only defines constants phpstan itself needs when loading files.
if ( ! defined( 'EVENT_HUB_VERSION' ) ) {
	define( 'EVENT_HUB_VERSION', '1.0.0' );
	define( 'EVENT_HUB_DB_VERSION', '1.0.0' );
	define( 'EVENT_HUB_FILE', __DIR__ . '/event-hub.php' );
	define( 'EVENT_HUB_DIR', __DIR__ . '/' );
	define( 'EVENT_HUB_URL', 'http://example.test/wp-content/plugins/event-hub/' );
}

/*
 * WP-CLI is not shipped with wordpress-stubs and is only touched behind a
 * defined( 'WP_CLI' ) guard at runtime. This analyse-time stub gives the
 * static call a signature so Plugin.php checks clean. Never loaded at
 * runtime: only PHPStan executes this file.
 */
if ( ! class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/includes/class-wp-cli-stub.php';
}
