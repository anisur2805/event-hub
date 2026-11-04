<?php
/**
 * PHPUnit bootstrap for the Event Hub plugin test suite (wp-env).
 *
 * @package event-hub
 */

declare( strict_types=1 );

// Locate the WordPress core test suite: env var, wp-env path, or local extraction.
$_eh_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_eh_tests_dir || ! file_exists( $_eh_tests_dir . '/includes/functions.php' ) ) {
	$_eh_tests_dir = '';
	foreach ( array(
		'/private/tmp/eh-wp-tests/tests',
		'/wordpress-develop/tests/phpunit',
		'/var/www/wp-tests/wordpress/tests/phpunit',
	) as $_eh_candidate ) {
		if ( file_exists( $_eh_candidate . '/includes/functions.php' ) ) {
			$_eh_tests_dir = $_eh_candidate;
			break;
		}
	}
}

if ( '' === $_eh_tests_dir || null === $_eh_tests_dir ) {
	fwrite( STDERR, "Event Hub tests: WP core test suite not found (expected /wordpress-develop/tests/phpunit inside wp-env).\n" );
	exit( 1 );
}

/*
 * The WP test suite needs a wp-tests-config.php. The local runner
 * (bin/run-tests.sh) exports the path of its generated config; define the
 * core constant only when that file actually exists. Inside wp-env nothing
 * is exported: wp-env writes wp-tests-config.php next to the mounted test
 * suite (/wordpress-phpunit), which the WP core bootstrap resolves on its
 * own — defining the constant with a guessed path here (as this file once
 * did) is what broke the CI unit job ("wp-tests-config.php is missing").
 */
$_eh_config_env = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );

if ( is_string( $_eh_config_env ) && '' !== $_eh_config_env && file_exists( $_eh_config_env ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $_eh_config_env );
} elseif ( file_exists( $_eh_tests_dir . '/wp-tests-config.php' ) ) {
	// wp-env layout: the config is generated inside the mounted tests dir
	// (/wordpress-phpunit/wp-tests-config.php). The core fallback would look
	// one directory ABOVE the tests dir, which is wrong for that mount point.
	define( 'WP_TESTS_CONFIG_FILE_PATH', $_eh_tests_dir . '/wp-tests-config.php' );
}

require_once getcwd() . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

require_once $_eh_tests_dir . '/includes/functions.php';

// Force-activate Event Hub before the test install boots.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once dirname( __DIR__, 2 ) . '/event-hub.php';

		// The custom RSVP table migrates on admin_init in production; the
		// test suite never loads wp-admin, so create it up front.
		\EventHub\Migrations::maybe_migrate();
	}
);

require_once $_eh_tests_dir . '/includes/bootstrap.php';
