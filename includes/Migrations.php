<?php
/**
 * Versioned database migrations for the Event Hub plugin.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs dbDelta schema migrations when EVENT_HUB_DB_VERSION advances.
 */
final class Migrations {

	/**
	 * Hook name for a future WP-Cron fallback. Not scheduled in v1.0.0;
	 * admin_init + WP-CLI cover the migration trigger.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'event_hub_migrate_cron';

	/**
	 * Option name holding the installed schema version.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'event_hub_db_version';

	/**
	 * Runs the migration when the installed version is older than the code
	 * version. Hooked on admin_init; safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( version_compare( $installed, EVENT_HUB_DB_VERSION, '>=' ) ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Executes all pending migrations in order and stores the new version.
	 *
	 * @return int Number of migration steps run.
	 */
	public static function migrate(): int {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'eh_rsvps';

		// DDL exactly as specified in docs/ARCHITECTURE.md "DB DDL". dbDelta
		// conventions: two spaces after PRIMARY KEY, lowercase types, one
		// field per line, KEY/UNIQUE KEY spelled out.
		$sql = "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	event_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(191) NOT NULL,
	email VARCHAR(191) NOT NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'going',
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY  (id),
	UNIQUE KEY uniq_event_email (event_id, email)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, EVENT_HUB_DB_VERSION, true );

		return 1;
	}

	/**
	 * Drops the RSVP table and deletes the schema version option. Called from
	 * uninstall.php only (T14 docs/THREAT-MODEL.md).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name derives from $wpdb->prefix; uninstall-time DDL by design.

		delete_option( self::VERSION_OPTION );
	}
}
