<?php
/**
 * Uninstall cleanup: drops the RSVP table, removes options, events,
 * occurrence children, orphaned event meta, series cron schedule, the
 * ICS rewrite rule and rate-limit transients.
 *
 * WP includes this file WITHOUT loading the plugin (no bootstrap, no
 * autoload), so every helper is either a core function or an explicitly
 * required plugin file.
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

/*
 * Table + schema-version option. Migrations::uninstall() is dependency-free
 * by contract (see that method) — this require is the single source of the
 * DROP, not a parallel inline copy.
 */
require_once __DIR__ . '/includes/Migrations.php';

\Migrations::uninstall();

/*
 * Plugin options beyond the schema version: the §2.1 email-confirmation
 * toggle. (Names must be hardcoded — the Notifier class is not loaded and
 * must not be, per the autoload note above.)
 */
delete_option( 'event_hub_confirmations' );

// Delete all eh_event posts (forcing deletion skips the trash). Children
// (series occurrences) are eh_event posts too — deleted by the same sweep.
$event_hub_post_ids = get_posts(
	array(
		'post_type'      => 'eh_event',
		'post_status'    => 'any',
		'posts_per_page' => - 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

foreach ( (array) $event_hub_post_ids as $event_hub_post_id ) {
	wp_delete_post( (int) $event_hub_post_id, true );
}

/*
 * Remove any orphaned event meta left behind by forced deletions — every
 * registered eh_* key: §1 core fields, §2.3/2.4 RSVP fields, §3.2 series
 * keys. Keys are hardcoded literals (no user input exists on this path);
 * must stay in sync with Plugin::register_meta().
 */
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta}
		WHERE meta_key IN ( 'eh_start', 'eh_end', 'eh_venue', 'eh_capacity',
		'eh_rsvp_open_hours', 'eh_rsvp_close_hours', 'eh_series_freq',
		'eh_series_interval', 'eh_series_byweekday', 'eh_series_until' )"
);

// Un-schedule the series horizon cron (Series_Engine::CRON_HOOK). An orphaned
// schedule is harmless (core drops hooks for inactive plugins) but leaves
// cron clutter; explicit removal keeps the site clean.
wp_clear_scheduled_hook( 'event_hub_series_tick' );

// Flush the ICS rewrite endpoint (and CPT rewrites) out of the stored rules;
// core regenerates on the next request.
delete_option( 'rewrite_rules' );

// Delete rate-limit transients (they expire naturally but leave no trace).
$event_hub_transients = $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_eh_rl_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_eh_rl_' ) . '%'
);
$wpdb->query( $event_hub_transients ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.
