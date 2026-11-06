<?php
/**
 * Analyse-time WP-CLI class stub for the plugin's static analysis runs.
 *
 * This file is loaded only by phpstan-bootstrap.php (PHPStan). It never
 * ships into the plugin's runtime path: WP-CLI defines the real WP_CLI
 * class before any plugin code runs under the CLI SAPI.
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
	 * @param string              $name Command name.
	 * @param callable|mixed      $cmd  Command implementation.
	 * @param array<string,mixed> $args Optional registration args (shortdesc, synopsis...).
	 * @return void
	 */
	public static function add_command( string $name, $cmd, array $args = array() ): void {
		unset( $name, $cmd, $args );
	}

	/**
	 * Displays an informational message preceded by the command name (STDOUT
	 * only when the command runs, suppressed with -q).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( string $message ): void {
		unset( $message );
	}

	/**
	 * Displays a message to STDOUT (the CSV payload's channel for exports).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function line( string $message = '' ): void {
		unset( $message );
	}

	/**
	 * Displays a success message prefixed with "Success: " and exits 0.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( string $message ): void {
		unset( $message );
	}

	/**
	 * Displays an error prefixed with "Error: " and halts with exit 1.
	 *
	 * @param string $message Message.
	 * @param mixed  $exit    True (exit 1), an int code, or false to continue.
	 * @return void
	 */
	public static function error( string $message, $exit = true ): void {
		unset( $message, $exit );
	}
}
