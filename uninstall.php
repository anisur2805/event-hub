<?php
/**
 * Uninstall cleanup: drops the RSVP table, options, events and transients.
 *
 * @package event-hub
 */

declare( strict_types=1 );

// @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall-time bulk cleanup is deliberate.
// @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time unbatched deletes during uninstall.
// @phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin table is the point of uninstall.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Drop the RSVP table (T14, docs/THREAT-MODEL.md).
$event_hub_table = $wpdb->prefix . 'eh_rsvps';
$wpdb->query( 'DROP TABLE IF EXISTS ' . $event_hub_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name derives from $wpdb->prefix, never user input.

// Delete the schema version option.
delete_option( 'event_hub_db_version' );

// Delete all eh_event posts (forcing deletion skips the trash).
$event_hub_post_ids = get_posts(
	array(
		'post_type'      => 'eh_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

foreach ( (array) $event_hub_post_ids as $event_hub_post_id ) {
	wp_delete_post( (int) $event_hub_post_id, true );
}

// Remove any orphaned event meta left behind by forced deletions.
$event_hub_cleanup = $wpdb->prepare(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s, %s )",
	'eh_start',
	'eh_end',
	'eh_venue',
	'eh_capacity'
);
$wpdb->query( $event_hub_cleanup ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.

// Delete rate-limit transients (they expire naturally but leave no trace).
$event_hub_transients = $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_eh_rl_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_eh_rl_' ) . '%'
);
$wpdb->query( $event_hub_transients ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.
