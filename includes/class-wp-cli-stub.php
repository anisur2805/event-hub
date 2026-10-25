<?php
/**
 * Analyse-time WP-CLI stub for the plugin's static analysis runs.
 *
 * This file is loaded only by phpstan-bootstrap.php (PHPStan). It never
 * ships into the plugin's runtime path: WP-CLI defines the real WP_CLI class
 * before any plugin code runs under the CLI SAPI.
 *
 * @package event-hub
 */

declare( strict_types=1 );

/**
 * Minimal WP_CLI surface used by the plugin.
 */
final class WP_CLI {

	/**
	 * Registers a WP-CLI command. Signature matches the real API.
	 *
	 * @param string         $name  Command name.
	 * @param callable|mixed $cmd   Command implementation.
	 * @return void
	 */
	public static function add_command( string $name, $cmd ): void {
		unset( $name, $cmd );
	}
}
