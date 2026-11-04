<?php
/**
 * Reads wp-scripts asset manifests without a statically-known require path.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads `build/admin/*.asset.php` manifests emitted by wp-scripts.
 *
 * The require path is passed in at runtime, so PHPStan's file-existence rule
 * cannot statically fail on a PHP-only checkout (no `npm run build` yet) —
 * which is exactly the state of the CI php-static job. Existence is checked
 * with is_readable() in both layers; a missing manifest degrades to an empty
 * array, matching the previous inline behavior at both call sites.
 */
final class Asset_Manifest {

	/**
	 * Reads one manifest, or returns an empty shape when absent.
	 *
	 * @param string $asset_path Absolute path to a `*.asset.php` manifest.
	 * @return array{dependencies:list<string>,version:string} Manifest values, empty when unreadable.
	 */
	public static function read( string $asset_path ): array {
		if ( '' === $asset_path || ! is_readable( $asset_path ) ) {
			return array(
				'dependencies' => array(),
				'version'      => '',
			);
		}

		$manifest = require $asset_path; // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- manifest supplies the version downstream.

		if ( ! is_array( $manifest ) ) {
			return array(
				'dependencies' => array(),
				'version'      => '',
			);
		}

		return array(
			'dependencies' => isset( $manifest['dependencies'] ) && is_array( $manifest['dependencies'] )
				? array_values( array_map( 'strval', $manifest['dependencies'] ) )
				: array(),
			'version'      => isset( $manifest['version'] ) && is_scalar( $manifest['version'] )
				? (string) $manifest['version']
				: '',
		);
	}
}
