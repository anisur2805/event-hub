<?php
/**
 * Plugin bootstrap: registers CPT, taxonomy, meta, REST, blocks, patterns, admin.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. Not a singleton by contract — bootstrap owns the single
 * instance; the static accessor only satisfies the WP-CLI migration command
 * and late hooks (docs/ARCHITECTURE.md).
 */
final class Plugin {

	/**
	 * The single instance, created on plugins_loaded.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * RSVP repository.
	 *
	 * @var Rsvp_Repository
	 */
	private Rsvp_Repository $repository;

	/**
	 * Creates the instance and wires the object graph. Private: bootstrap owns
	 * the single instance.
	 */
	private function __construct() {
		$this->repository = new Rsvp_Repository();
	}

	/**
	 * Accessor for the bootstrapped instance (WP-CLI + late hooks only).
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * RSVP repository accessor for Render_Blocks and late-bound consumers.
	 *
	 * @return Rsvp_Repository
	 */
	public function repository(): Rsvp_Repository {
		return $this->repository;
	}

	/**
	 * Registers all runtime hooks. Called once, from plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'admin_init', array( Migrations::class, 'maybe_migrate' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		$admin_page = new Admin_Page();
		add_action( 'admin_menu', array( $admin_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_page, 'enqueue_assets' ) );

		$csv_exporter = new Csv_Exporter( $this->repository );
		$csv_exporter->boot();

		/*
		 * WP-CLI migration command. Registered as a closure so the command
		 * class list stays minimal (docs/ARCHITECTURE.md step 3).
		 */
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'event-hub-migrate',
				static fn (): int => Migrations::migrate()
			);
		}
	}

	/**
	 * Registers the eh_event custom post type.
	 *
	 * @return void
	 */
	public function register_post_types(): void {
		register_post_type(
			'eh_event',
			array(
				'labels'          => array(
					'name'          => __( 'Events', 'event-hub' ),
					'singular_name' => __( 'Event', 'event-hub' ),
					'add_new_item'  => __( 'Add New Event', 'event-hub' ),
					'edit_item'     => __( 'Edit Event', 'event-hub' ),
					'new_item'      => __( 'New Event', 'event-hub' ),
					'view_item'     => __( 'View Event', 'event-hub' ),
					'search_items'  => __( 'Search Events', 'event-hub' ),
					'not_found'     => __( 'No events found', 'event-hub' ),
					'all_items'     => __( 'All Events', 'event-hub' ),
					'menu_name'     => __( 'Events', 'event-hub' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'rest_base'       => 'events-cpt',
				'menu_icon'       => 'dashicons-calendar-alt',
				'menu_position'   => 25,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				'has_archive'     => true,
				'rewrite'         => array(
					'slug'       => 'events',
					'with_front' => false,
				),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'template'        => array(
					array( 'event-hub/rsvp-button', array( 'eventId' => 0 ) ),
				),
			)
		);

		register_taxonomy(
			'eh_event_category',
			'eh_event',
			array(
				'labels'            => array(
					'name'          => __( 'Event Categories', 'event-hub' ),
					'singular_name' => __( 'Event Category', 'event-hub' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => 'event-category',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Registers event meta with auth callbacks. Keys are prefixed `eh_*`
	 * (docs/DECISIONS.md #3).
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$args = array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
		);

		register_post_meta( 'eh_event', 'eh_start', array( 'description' => __( 'Start datetime (site timezone, Y-m-d H:i:s).', 'event-hub' ) ) + $args );
		register_post_meta( 'eh_event', 'eh_end', array( 'description' => __( 'End datetime (site timezone, Y-m-d H:i:s).', 'event-hub' ) ) + $args );
		register_post_meta( 'eh_event', 'eh_venue', array( 'description' => __( 'Venue name.', 'event-hub' ) ) + $args );

		register_post_meta(
			'eh_event',
			'eh_capacity',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'description'   => __( 'Going capacity. Empty = unlimited (docs/ARCHITECTURE.md).', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
			)
		);
	}

	/**
	 * Registers the three blocks from the build output. Render templates live
	 * in includes/blocks/<name>/render.php and are wired via render_callback
	 * overrides (docs/ARCHITECTURE.md; render.php is not copied by wp-scripts,
	 * so block.json's `render` field is intentionally unused).
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$blocks = array(
			'event-list'  => array( Render_Blocks::class, 'render_event_list_block' ),
			'event-card'  => array( Render_Blocks::class, 'render_event_card_block' ),
			'rsvp-button' => array( Render_Blocks::class, 'render_rsvp_button_block' ),
		);

		foreach ( $blocks as $slug => $callback ) {
			$path = EVENT_HUB_DIR . 'build/blocks/' . $slug;

			// Graceful degradation: content stays server-renderable via REST
			// even when the frontend build has not been produced yet.
			if ( ! is_readable( $path . '/block.json' ) ) {
				continue;
			}

			register_block_type_from_metadata(
				$path,
				array(
					'render_callback' => $callback,
				)
			);
		}
	}

	/**
	 * Registers the plugin pattern category and the two pattern files from
	 * patterns/. Plugin patterns are not auto-scanned by WP core.
	 *
	 * @return void
	 */
	public function register_patterns(): void {
		register_block_pattern_category(
			'event-hub',
			array(
				'label' => __( 'Event Hub', 'event-hub' ),
			)
		);

		$patterns = array(
			'events-grid'         => array(
				'file'  => EVENT_HUB_DIR . 'patterns/events-grid.php',
				'title' => __( 'Events grid', 'event-hub' ),
			),
			'featured-event-hero' => array(
				'file'  => EVENT_HUB_DIR . 'patterns/featured-event-hero.php',
				'title' => __( 'Featured event hero', 'event-hub' ),
			),
		);

		foreach ( $patterns as $name => $pattern ) {
			if ( ! is_readable( $pattern['file'] ) ) {
				continue;
			}

			ob_start();
			include $pattern['file'];
			$content = (string) ob_get_clean();

			register_block_pattern(
				'event-hub/' . $name,
				array(
					'title'      => $pattern['title'],
					'categories' => array( 'event-hub' ),
					'content'    => $content,
				)
			);
		}
	}

	/**
	 * Loads translations from the plugin languages/ dir.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'event-hub',
			false,
			dirname( plugin_basename( EVENT_HUB_FILE ) ) . '/languages'
		);
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controller = new Rest_Controller( $this->repository );
		$controller->register_routes();
	}
}
