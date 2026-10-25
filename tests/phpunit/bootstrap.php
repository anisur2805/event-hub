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
 * The WP test suite needs a wp-tests-config.php; we generate one pointing at a
 * dedicated test database (see bin/run-tests.sh).
 */
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', '/private/tmp/eh-wp-tests/wp-tests-config.php' );
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
