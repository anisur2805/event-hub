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
	 * Series (recurring events) engine.
	 *
	 * @var Series_Engine
	 */
	private Series_Engine $series_engine;

	/**
	 * Pre-event reminder cron.
	 *
	 * @var Reminders
	 */
	private Reminders $reminders;

	/**
	 * Creates the instance and wires the object graph. Private: bootstrap owns
	 * the single instance.
	 */
	private function __construct() {
		$this->repository    = new Rsvp_Repository();
		$this->series_engine = new Series_Engine();
		$this->reminders     = new Reminders();
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
	 * Series engine accessor (deactivation hook un-schedules its cron).
	 *
	 * @return Series_Engine
	 */
	public function series_engine(): Series_Engine {
		return $this->series_engine;
	}

	/**
	 * Reminders accessor (deactivation hook un-schedules its cron).
	 *
	 * @return Reminders
	 */
	public function reminders(): Reminders {
		return $this->reminders;
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

		add_action( 'wp_enqueue_scripts', array( $this, 'register_front_styles' ) );

		/*
		 * Migration check on `init`, not `admin_init`: front-end templates
		 * (archive cards, single page) read counts() and would query columns
		 * a pending migration has not added yet — a deployed-but-not-yet-
		 * visited wp-admin would log DB errors on every front-end request.
		 * maybe_migrate() is one cached option read when current.
		 */
		add_action( 'init', array( Migrations::class, 'maybe_migrate' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		/*
		 * Cascade: permanent deletion of an event removes its RSVP rows
		 * (trash keeps them; restore brings the event back with its list).
		 */
		add_action( 'before_delete_post', array( $this, 'maybe_delete_event_rsvps' ) );

		$admin_page = new Admin_Page();
		add_action( 'admin_menu', array( $admin_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_page, 'enqueue_assets' ) );
		$admin_page->boot();

		$dashboard_widget = new Dashboard_Widget();
		add_action( 'wp_dashboard_setup', array( $dashboard_widget, 'register' ) );

		$csv_exporter = new Csv_Exporter( $this->repository );
		$csv_exporter->boot();

		$privacy = new Privacy();
		$privacy->boot();

		$admin_columns = new Admin_Columns();
		$admin_columns->boot();

		$this->series_engine->boot();
		$this->reminders->boot();

		/*
		 * Notifications (ROADMAP 5.2). The organizer notice rides a dedicated
		 * action fired in Rest_Controller's 201 path (after the RSVP write
		 * commits — never inside a lock); the cancel notice rides the
		 * repository-side cancelled action. Both degrade silently when mail
		 * is off: wp_mail failures never touch API responses (Notifier
		 * contract, DECISIONS #39).
		 */
		add_action(
			'event_hub_rsvp_created',
			static function ( array $rsvp, int $event_id ): void {
				Notifier::send_organizer_notice( $rsvp, $event_id );
			},
			10,
			2
		);

		add_action(
			'event_hub_rsvp_cancelled',
			static function ( array $deleted, int $event_id ): void {
				Notifier::send_cancel_notice( $deleted, $event_id );
			},
			10,
			2
		);

		add_action(
			'event_hub_rsvp_promoted',
			static function ( array $promoted, int $event_id ): void {
				// Gated by the notifications master switch (5A.2): promotions
				// are notice mail, not confirmations — one toggle controls
				// everything the attendee did not directly request.
				if ( Reminders::enabled() ) {
					Notifier::send_promotion_notice( $promoted, $event_id );
				}
			},
			10,
			2
		);

		/*
		 * Status transitions (ROADMAP 5.3). Both hooks, same shape: a FIRST
		 * write on an event without the meta fires added_post_meta, every
		 * later write fires updated_post_meta (save_post alone would miss
		 * meta-only edits; updated_* alone would miss the first one). Core
		 * short-circuits unchanged single-key writes BEFORE its hooks, so
		 * reaching either listener means the value truly changed.
		 * Transitions INTO a non-scheduled status bump the notice generation
		 * (the attendee sweep's re-notify unit) and fire the action the
		 * integration hook rides; back-to-scheduled bumps nothing (un-cancel
		 * is silent by design — the next transition re-notifies anyway).
		 */
		$status_transition = static function ( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
			if ( Event_Status::META !== $meta_key ) {
				return;
			}

			$post = get_post( $object_id );

			if ( ! $post instanceof \WP_Post || 'eh_event' !== $post->post_type ) {
				return;
			}

			// Bump + action fire only for real transitions INTO a
			// non-scheduled status; back-to-scheduled (and junk DB/CLI
			// writes, which read as scheduled) stay silent — but still
			// propagate below so children never disagree with the seed.
			if ( in_array( $meta_value, Event_Status::STATUSES, true ) ) {
				Event_Status::bump_generation( $object_id );

				do_action( 'event_hub_event_status_changed', $object_id, (string) $meta_value );
			}

			/*
			 * Series propagation (5.3): children follow the seed both ways
			 * (an un-cancel must not leave occurrences cancelled). Each
			 * child's own write re-enters this closure, bumping ITS
			 * generation so ITS rows sweep — depth stays 1 because children
			 * are never seeds (no post_parent chains).
			 */
			$children = get_posts(
				array(
					'post_type'      => 'eh_event',
					'post_status'    => 'any',
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded engine cap, matches materialize().
					'post_parent'    => $object_id,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			foreach ( $children as $child_id ) {
				if ( Event_Status::get( (int) $child_id ) === $meta_value ) {
					continue;
				}

				update_post_meta( (int) $child_id, Event_Status::META, (string) $meta_value );
			}
		};

		add_action( 'added_post_meta', $status_transition, 10, 4 );
		add_action( 'updated_post_meta', $status_transition, 10, 4 );

		$editor_panel = new Editor_Panel();
		$editor_panel->boot();

		$single_template = new Single_Template();
		$single_template->boot();

		$manage_page = new Manage_Page();
		$manage_page->boot();

		$structured_data = new Structured_Data();
		$structured_data->boot();

		/*
		 * WP-CLI commands. The `event-hub` namespace dispatches to Cli's
		 * public methods; Cli_Rsvp nests under `event-hub rsvp`. Registered
		 * as explicit instances so the repository/exporter dependencies flow
		 * through the constructor (no service location inside commands).
		 */
		if ( defined( '\\WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command(
				'event-hub-migrate',
				static fn (): int => Migrations::migrate()
			);

			\WP_CLI::add_command( 'event-hub', new Cli( $this->repository ) );
			\WP_CLI::add_command( 'event-hub rsvp', new Cli_Rsvp( $this->repository, $csv_exporter ) );
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

				/*
				 * 'custom-fields' is required for the registered eh_* meta
				 * (show_in_rest) to appear in the REST item schema — without
				 * it the Posts controller never adds the 'meta' property and
				 * the editor panel has nothing to bind to.
				 */
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
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

		/*
		 * Venue + Organizer entities (ROADMAP 5.1): reusable, editor-managed,
		 * events link one of each via eh_venue_id / eh_organizer_id meta.
		 * public ⇒ REST headless reads work; publicly_queryable ⇒ false means
		 * NO front-end routes (and no permalinks — JSON-LD therefore never
		 * fabricates entity URLs). No rewrites ⇒ no flush needed.
		 */
		register_post_type(
			'eh_venue',
			array(
				'labels'             => array(
					'name'          => __( 'Venues', 'event-hub' ),
					'singular_name' => __( 'Venue', 'event-hub' ),
					'add_new_item'  => __( 'Add New Venue', 'event-hub' ),
					'edit_item'     => __( 'Edit Venue', 'event-hub' ),
					'search_items'  => __( 'Search Venues', 'event-hub' ),
					'not_found'     => __( 'No venues found', 'event-hub' ),
					'all_items'     => __( 'All Venues', 'event-hub' ),
					'menu_name'     => __( 'Venues', 'event-hub' ),
				),
				'public'             => true,
				'publicly_queryable' => false,
				'show_in_rest'       => true,
				'rest_base'          => 'venues',
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=eh_event',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		register_post_type(
			'eh_organizer',
			array(
				'labels'             => array(
					'name'          => __( 'Organizers', 'event-hub' ),
					'singular_name' => __( 'Organizer', 'event-hub' ),
					'add_new_item'  => __( 'Add New Organizer', 'event-hub' ),
					'edit_item'     => __( 'Edit Organizer', 'event-hub' ),
					'search_items'  => __( 'Search Organizers', 'event-hub' ),
					'not_found'     => __( 'No organizers found', 'event-hub' ),
					'all_items'     => __( 'All Organizers', 'event-hub' ),
					'menu_name'     => __( 'Organizers', 'event-hub' ),
				),
				'public'             => true,
				'publicly_queryable' => false,
				'show_in_rest'       => true,
				'rest_base'          => 'organizers',
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=eh_event',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
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

		/*
		 * Linked entities (ROADMAP 5.1): post ID of an eh_venue / eh_organizer
		 * post, digits-only (same save-time schema as eh_capacity — junk
		 * writes 400 at the REST meta schema). Read side: Render_Blocks::
		 * linked_entity() ignores stale/missing IDs and falls back to the
		 * legacy free-text fields, so an ID never MUST exist.
		 */
		foreach ( array( 'eh_venue_id', 'eh_organizer_id' ) as $entity_key ) {
			register_post_meta(
				'eh_event',
				$entity_key,
				array(
					'type'          => 'string',
					'single'        => true,
					'description'   => __( 'Linked venue or organizer post ID. Empty = not linked. (ROADMAP 5.1)', 'event-hub' ),
					'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
					'show_in_rest'  => array(
						'schema' => array(
							'type'    => 'string',
							'pattern' => '^$|^[0-9]+$',
							'default' => '',
						),
					),
				)
			);
		}

		register_post_meta(
			'eh_venue',
			'eh_venue_address',
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Street address of the venue (schema.org location address). (ROADMAP 5.1)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		/*
		 * Reminder window (ROADMAP 5.2): hours before eh_start. Same
		 * digits-only save-time schema as eh_capacity (BUG 5 pattern);
		 * Reminders::window_hours() is the read-side twin. '0' = reminders
		 * off for this event ('0' IS digits; junk = 24h default).
		 */
		register_post_meta(
			'eh_event',
			Reminders::HOURS_META,
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Hours before start when reminder emails go out. Empty = 24. Zero = no reminders. (ROADMAP 5.2)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'pattern' => '^$|^[0-9]+$',
						'default' => '',
					),
				),
			)
		);

		register_post_meta(
			'eh_event',
			'eh_capacity',
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Going capacity. Empty = unlimited (docs/ARCHITECTURE.md).', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),

				/*
				 * BUG 5: reject non-numeric, non-empty capacities at save
				 * time (REST meta update → 400). The pattern must live in
				 * show_in_rest['schema'] — core's WP_REST_Meta_Fields
				 * validates against that schema only (verified in
				 * get_registered_fields() → rest_validate_value_from_schema()).
				 * The empty string stays legal: '' = unlimited contract.
				 */
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'pattern' => '^$|^[0-9]+$',
						'default' => '',
					),
				),
			)
		);

		/*
		 * RSVP window cutoffs (ROADMAP 2.4). Hours before eh_start when the
		 * RSVP window opens / closes; '' = absent boundary (always-open on
		 * that side). Same digits-only schema as eh_capacity — junk is
		 * rejected at save time, and Rsvp_Repository::parse_hours() is the
		 * read-side twin of this pattern.
		 */
		foreach ( array( 'eh_rsvp_open_hours', 'eh_rsvp_close_hours' ) as $hours_key ) {
			register_post_meta(
				'eh_event',
				$hours_key,
				array(
					'type'          => 'string',
					'single'        => true,
					'description'   => __( 'Hours before start when RSVPs open or close. Empty = no boundary. (ROADMAP 2.4)', 'event-hub' ),
					'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
					'show_in_rest'  => array(
						'schema' => array(
							'type'    => 'string',
							'pattern' => '^$|^[0-9]+$',
							'default' => '',
						),
					),
				)
			);
		}

		/*
		 * Recurring events (ROADMAP 3.2) — the four series meta keys on the
		 * SEED event. Non-empty eh_series_freq = "this event is a series
		 * seed"; Series_Engine materializes child occurrences. Patterns are
		 * the same save-time rejection discipline as eh_capacity (BUG 5):
		 * junk REST writes 400 at the meta schema; the engine's parse_rules()
		 * is the defensive read-side twin for DB/CLI writes.
		 */
		register_post_meta(
			'eh_event',
			'eh_series_freq',
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Recurrence frequency. Empty = one-off event; daily, weekly or monthly = series seed. (ROADMAP 3.2)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'enum'    => array( '', 'daily', 'weekly', 'monthly' ),
						'default' => '',
					),
				),
			)
		);

		register_post_meta(
			'eh_event',
			'eh_series_interval',
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Recurrence interval (every N days/weeks/months). Empty = 1. (ROADMAP 3.2)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'pattern' => '^$|^[0-9]+$',
						'default' => '',
					),
				),
			)
		);

		register_post_meta(
			'eh_event',
			'eh_series_byweekday',
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Weekly recurrence weekdays, 0=Sunday..6=Saturday, comma-separated. Empty = the start weekday. (ROADMAP 3.2)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'pattern' => '^$|^[0-6](,[0-6])*$',
						'default' => '',
					),
				),
			)
		);

		register_post_meta(
			'eh_event',
			'eh_series_until',
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Last date occurrences are generated (Y-m-d, inclusive). Empty = rolling 6-month horizon. (ROADMAP 3.2)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'pattern' => '^$|^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
						'default' => '',
					),
				),
			)
		);

		/*
		 * Event status (ROADMAP 5.3): the attendee-facing state. The enum is
		 * the same save-time rejection discipline as eh_series_freq (junk
		 * REST writes 400 at the schema; Event_Status::get() is the defensive
		 * read-side twin for DB/CLI writes — junk reads as scheduled).
		 */
		register_post_meta(
			'eh_event',
			Event_Status::META,
			array(
				'type'          => 'string',
				'single'        => true,
				'description'   => __( 'Event status. Empty = scheduled; cancelled or postponed. (ROADMAP 5.3)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'    => 'string',
						'enum'    => array( '', 'cancelled', 'postponed' ),
						'default' => '',
					),
				),
			)
		);

		/*
		 * Internal notice-generation counter (Event_Status::COLUMN seam).
		 * Never in REST: only the status sweep writes it.
		 */
		register_post_meta(
			'eh_event',
			Event_Status::GENERATION_META,
			array(
				'type'          => 'integer',
				'single'        => true,
				'description'   => __( 'Internal: attendee status-notice generation counter. (ROADMAP 5.3)', 'event-hub' ),
				'auth_callback' => static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
				'show_in_rest'  => false,
			)
		);
	}

	/**
	 * Registers the shared front-end stylesheet. The four block.json files
	 * reference this handle as their `style`, so WordPress enqueues it when
	 * any Event Hub block renders and dedupes across multiple blocks; the
	 * single-event template enqueues it directly.
	 *
	 * @return void
	 */
	public function register_front_styles(): void {
		$css = EVENT_HUB_DIR . 'build/css/event-hub.css';
		if ( ! is_readable( $css ) ) {
			return; // Graceful degradation when the build has not run.
		}

		wp_register_style(
			'event-hub-blocks',
			EVENT_HUB_URL . 'build/css/event-hub.css',
			array(),
			EVENT_HUB_VERSION
		);

		// RTL locales get the mirrored sheet (build output alongside the
		// LTR file) when the built stylesheet exists.
		if ( is_readable( EVENT_HUB_DIR . 'build/css/event-hub-rtl.css' ) ) {
			wp_style_add_data( 'event-hub-blocks', 'rtl', 'replace' );
		}
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
			'event-list'       => array( Render_Blocks::class, 'render_event_list_block' ),
			'event-card'       => array( Render_Blocks::class, 'render_event_card_block' ),
			'rsvp-button'      => array( Render_Blocks::class, 'render_rsvp_button_block' ),
			'event-categories' => array( Render_Blocks::class, 'render_event_categories_block' ),
			'event-calendar'   => array( Render_Blocks::class, 'render_calendar_block' ),
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

	/**
	 * Permanent event deletion cleanup: hard-deleting an eh_event post removes
	 * its RSVP rows via the before_delete_post hook (ROADMAP hygiene;
	 * DECISIONS #35). Anything else — other post types, revisions, trash —
	 * exits without a query.
	 *
	 * @param int|string $post_id Post ID (core passes an int; defensive string cast).
	 * @return void
	 */
	public function maybe_delete_event_rsvps( $post_id ): void {
		$post_id = absint( $post_id );

		if ( $post_id < 1 ) {
			return;
		}

		$post = get_post( $post_id );

		// The eh_event gate alone excludes revisions (type 'revision'), plain
		// posts and every other post type; no second type check needed.
		if ( ! $post instanceof \WP_Post || 'eh_event' !== $post->post_type ) {
			return;
		}

		$this->repository->delete_all_for_event( $post_id );
	}
}
