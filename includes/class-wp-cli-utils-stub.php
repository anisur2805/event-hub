<?php
/**
 * Analyse-time WP_CLI\Utils function stub for the plugin's static analysis
 * runs.
 *
 * This file is loaded only by phpstan-bootstrap.php (PHPStan). It never
 * ships into the plugin's runtime path: WP-CLI itself provides the real
 * function when plugin code actually runs under the CLI SAPI.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	/**
	 * Renders items as table/json/csv/yaml/ids/count. Signature matches the
	 * real WP-CLI internal API.
	 *
	 * @param string                   $format Output format.
	 * @param iterable<int,mixed>      $items  Rows (associative rows or lists).
	 * @param array<int,string>|string $fields Visible fields.
	 * @return void
	 */
	function format_items( string $format, $items, $fields ): void {
		unset( $format, $items, $fields );
	}
}
