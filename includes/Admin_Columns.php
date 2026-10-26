<?php
/**
 * Events list-table columns and organizer row actions.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Organizer-side list UX on the wp-admin Events screen: When / Venue /
 * Capacity columns (sortable When), plus RSVPs and Export CSV deep links
 * into the RSVP manager. Data flows through Render_Blocks::summary() so the
 * list table reads the exact same normalized values the frontend renders.
 */
final class Admin_Columns {

	/**
	 * Registers all list-table hooks. Called from Plugin::boot().
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'manage_eh_event_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_eh_event_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-eh_event_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_when' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
	}

	/**
	 * Inserts the event columns after the title and drops the default date
	 * column (the When column replaces it with event start semantics).
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {
		unset( $columns['date'] );

		$rebuilt = array();

		foreach ( $columns as $key => $label ) {
			$rebuilt[ $key ] = $label;

			if ( 'title' === $key ) {
				$rebuilt['eh_when']     = __( 'When', 'event-hub' );
				$rebuilt['eh_venue']    = __( 'Venue', 'event-hub' );
				$rebuilt['eh_capacity'] = __( 'Capacity', 'event-hub' );
			}
		}

		// Title-less column sets (future core changes) still get the data.
		if ( ! isset( $rebuilt['eh_when'] ) ) {
			$rebuilt['eh_when']     = __( 'When', 'event-hub' );
			$rebuilt['eh_venue']    = __( 'Venue', 'event-hub' );
			$rebuilt['eh_capacity'] = __( 'Capacity', 'event-hub' );
		}

		return $rebuilt;
	}

	/**
	 * Renders one column cell. The "X of Y" capacity vocabulary deliberately
	 * matches the frontend's "X of Y spots filled" line.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Event post ID.
	 * @return void
	 */
	public function column_content( string $column, int $post_id ): void {
		if ( ! in_array( $column, array( 'eh_when', 'eh_venue', 'eh_capacity' ), true ) ) {
			return;
		}

		$s = Render_Blocks::summary( $post_id );

		switch ( $column ) {
			case 'eh_when':
				echo esc_html( $this->when_label( (string) $s['start'] ) );
				break;

			case 'eh_venue':
				$venue = trim( (string) $s['venue'] );
				echo '' !== $venue ? esc_html( $venue ) : esc_html__( '—', 'event-hub' );
				break;

			case 'eh_capacity':
				$capacity = $s['capacity'];

				if ( null === $capacity ) {
					echo esc_html__( 'Unlimited', 'event-hub' );
					break;
				}

				/* translators: 1: number going, 2: total capacity. */
				echo esc_html( sprintf( __( '%1$s of %2$s', 'event-hub' ), (string) number_format_i18n( (int) $s['counts']['going'] ), (string) number_format_i18n( (int) $capacity ) ) );
				break;
		}
	}

	/**
	 * Marks the When column sortable. The posts list table applies only the
	 * screen-scoped filter for custom post types (core steers custom-column
	 * authors at get_column_info()); the legacy per-post-type sortable hook
	 * would never fire here and leave a dead header.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['eh_when'] = 'eh_when';

		return $columns;
	}

	/**
	 * Maps the eh_when sort request onto the eh_start meta. Admin-only by
	 * construction (list-table ordering); the meta_key slow-query ignores
	 * mirror the chronology sorts elsewhere in the plugin.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function sort_when( \WP_Query $query ): void {
		if ( ! is_admin() || 'eh_event' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'eh_when' !== (string) $query->get( 'orderby' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology sort on a bounded CPT.
		$query->set( 'meta_key', 'eh_start' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC' );
	}

	/**
	 * Appends the RSVPs and Export CSV organizer actions. Capability matches
	 * the RSVP screen and the exporter, so the links never point at a page
	 * the user cannot open.
	 *
	 * @param array<string,string> $actions Existing row actions.
	 * @param WP_Post              $post    Current post.
	 * @return array<string,string>
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( 'eh_event' !== $post->post_type || ! current_user_can( 'edit_others_posts' ) ) {
			return $actions;
		}

		$actions['eh_rsvps'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=event-hub-rsvps&event=' . (int) $post->ID ) ),
			esc_html__( 'RSVPs', 'event-hub' )
		);

		$actions['eh_export_csv'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Csv_Exporter::export_url( (int) $post->ID ) ),
			esc_html__( 'Export CSV', 'event-hub' )
		);

		return $actions;
	}

	/**
	 * Formats the start meta with the site's date and time formats. Empty or
	 * unparsable input yields an em dash (rendered escaped by the caller).
	 *
	 * @param string $start_iso ISO 8601 (or site-format) start value.
	 * @return string
	 */
	private function when_label( string $start_iso ): string {
		if ( '' === trim( $start_iso ) ) {
			return __( '—', 'event-hub' );
		}

		// summary() returns ISO 8601 with offset (the constructor accepts it
		// directly); the site-format fallbacks cover raw/unparsed meta.
		try {
			$datetime = new \DateTimeImmutable( $start_iso, wp_timezone() );
		} catch ( \Exception $e ) {
			$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $start_iso, wp_timezone() );
		}

		if ( false === $datetime ) {
			return __( '—', 'event-hub' );
		}

		$format_date = get_option( 'date_format' );
		$format_time = get_option( 'time_format' );

		$format = trim( ( is_string( $format_date ) ? $format_date : 'F j, Y' ) . ' ' . ( is_string( $format_time ) ? $format_time : 'g:i a' ) );

		// wp_date() applies the site timezone and date_i18n localization to a
		// true Unix timestamp; no manual offset math.
		return wp_date( $format, $datetime->getTimestamp() );
	}
}
