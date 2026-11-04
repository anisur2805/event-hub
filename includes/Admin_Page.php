<?php
/**
 * Event Hub admin page — RSVP management screen.
 *
 * PHP registers the menu page and enqueues the React app (build/admin);
 * boot data travels via window.eventHubAdmin (inline script per
 * docs/ARCHITECTURE.md "Admin page seam").
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin RSVP management screen (admin.php?page=event-hub-rsvps).
 */
final class Admin_Page {

	/**
	 * Menu slug; drives the enqueue hook check toplevel_page_{slug}.
	 *
	 * @var string
	 */
	private const SLUG = 'event-hub-rsvps';

	/**
	 * Registers the menu page. Called on admin_menu.
	 *
	 * @return void
	 */
	public function register(): void {
		add_menu_page(
			__( 'Event Hub RSVPs', 'event-hub' ),
			__( 'Event Hub', 'event-hub' ),
			'edit_others_posts',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-calendar',
			26
		);
	}

	/**
	 * Registers the confirmation-email toggle's admin-post handler. Called
	 * from Plugin::boot().
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_post_eh_toggle_confirmations', array( $this, 'handle_toggle' ) );
	}

	/**
	 * Handles the settings toggle POST. Admin context => nonce AND
	 * capability checked (standard admin-post pair).
	 *
	 * @return void
	 */
	public function handle_toggle(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change this setting.', 'event-hub' ),
				esc_html__( 'Forbidden', 'event-hub' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'eh_toggle_confirmations' );

		$enabled = isset( $_POST['event_hub_confirmations'] )
			? sanitize_key( wp_unslash( (string) $_POST['event_hub_confirmations'] ) )
			: '';

		update_option( Notifier::OPTION, 'on' === $enabled );

		wp_safe_redirect(
			add_query_arg(
				array( 'eh_settings' => 'saved' ),
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
	}

	/**
	 * Enqueues the React app + boot data. Called on admin_enqueue_scripts.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		$script = EVENT_HUB_DIR . 'build/admin/index.js';

		// The admin build is produced by wp-scripts; without it the page
		// renders its container with a note instead of a fatal.
		if ( ! is_readable( $script ) ) {
			return;
		}

		// The .asset.php manifest is emitted next to the entry by wp-scripts.
		$asset       = Asset_Manifest::read( EVENT_HUB_DIR . 'build/admin/index.asset.php' );
		$script_deps = $asset['dependencies'];

		// Style gets no JS dependency list: manifest deps are script handles
		// (react-jsx-runtime, wp-element, wp-i18n) and WP_Styles checks its
		// own registry, which produced the "_wp_style_add warning on 6.9+".
		// read() guarantees a string; only a malformed manifest yields ''.
		$version = '' !== $asset['version'] ? $asset['version'] : EVENT_HUB_VERSION;

		wp_enqueue_style(
			'event-hub-admin',
			EVENT_HUB_URL . 'build/admin/index.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'event-hub-admin',
			EVENT_HUB_URL . 'build/admin/index.js',
			$script_deps,
			$version,
			true
		);

		// Only published events appear in the picker, sorted by start meta.
		// The meta_key sort is intentional (event chronology).
		$events    = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'meta_value',
				'meta_key'       => 'eh_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed per-post meta lookup on a bounded CPT.
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$events    = is_array( $events ) ? $events : array();
		$event_ids = array_map(
			static fn ( \WP_Post $post ): int => (int) $post->ID,
			$events
		);

		// Deep link from the Events list-table "RSVPs" action: ?event=ID
		// selects that event when it exists. Read-only GET, no state change.
		$requested = isset( $_GET['event'] ) ? absint( wp_unslash( (string) $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter, no state change.
		$initial   = in_array( $requested, $event_ids, true ) ? $requested : ( array() !== $event_ids ? $event_ids[0] : 0 );

		// Complete export URLs are built server-side (BUG-1 fix): JS only
		// picks the one matching the selected event; no URL concatenation.
		$exports = array();
		foreach ( $events as $post ) {
			// wp_nonce_url() HTML-encodes separators (&amp;) for template
			// output; this data travels as JSON into a DOM-set href where no
			// entity decoding happens, so hand the client a literal ampersand.
			$url                        = html_entity_decode( Csv_Exporter::export_url( (int) $post->ID ), ENT_QUOTES, 'UTF-8' );
			$exports[ (int) $post->ID ] = esc_url_raw( $url );
		}

		wp_add_inline_script(
			'event-hub-admin',
			'window.eventHubAdmin = ' . wp_json_encode(
				array(
					'restUrl'        => esc_url_raw( rest_url() ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'events'         => array_map(
						static fn ( \WP_Post $post ): array => array(
							'id'    => (int) $post->ID,
							'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
						),
						$events
					),
					'initialEventId' => $initial,
					'exportUrls'     => $exports,
				)
			) . ';',
			'before'
		);

		wp_set_script_translations( 'event-hub-admin', 'event-hub' );
	}

	/**
	 * Renders the settings form + React mount point. Called by
	 * add_menu_page callback.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$confirmations = Notifier::enabled();
		?>
		<div class="wrap event-hub-admin-page">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( isset( $_GET['eh_settings'] ) && 'saved' === sanitize_key( wp_unslash( (string) $_GET['eh_settings'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'event-hub' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eh_toggle_confirmations" />
				<?php wp_nonce_field( 'eh_toggle_confirmations' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Confirmation emails', 'event-hub' ); ?></th>
						<td>
							<label for="event-hub-confirmations">
								<input
									type="checkbox"
									id="event-hub-confirmations"
									name="event_hub_confirmations"
									value="on"
									<?php checked( $confirmations ); ?>
								/>
								<?php esc_html_e( 'Send RSVP confirmation emails with a manage-booking link', 'event-hub' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'RSVPs', 'event-hub' ); ?></h2>
			<div id="event-hub-rsvps-root">
				<?php if ( ! is_readable( EVENT_HUB_DIR . 'build/admin/index.js' ) ) : ?>
					<p><?php esc_html_e( 'The admin app bundle is missing — run the plugin build.', 'event-hub' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
