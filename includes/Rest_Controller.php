<?php
/**
 * REST controller for the event-hub/v1 namespace.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin REST layer over Rsvp_Repository. Validates + sanitizes every parameter
 * server-side; never trusts the client (docs/API.md, docs/THREAT-MODEL.md).
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE_V1 = 'event-hub/v1';

	/**
	 * Max RSVP POSTs per IP per window.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_MAX = 10;

	/**
	 * Rate-limit window in seconds.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_WINDOW = 600;

	/**
	 * Repository dependency.
	 *
	 * @var Rsvp_Repository
	 */
	private Rsvp_Repository $repository;

	/**
	 * ICS exporter dependency.
	 *
	 * @var Ics_Exporter
	 */
	private Ics_Exporter $ics;

	/**
	 * Constructor.
	 *
	 * @param Rsvp_Repository|null $repository Optional repository (tests).
	 */
	public function __construct( ?Rsvp_Repository $repository = null ) {
		$this->repository = $repository ?? new Rsvp_Repository();
		$this->ics        = new Ics_Exporter();
	}

	/**
	 * Registers every route. Called from rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/rsvp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_rsvp' ),
				'permission_callback' => array( $this, 'rsvp_permission' ),
				'args'                => array(
					'eventId' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'name'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn ( $value ): string => self::normalize_name( (string) $value ),
						'validate_callback' => static fn ( $value ): bool => '' !== self::normalize_name( (string) $value ),
					),
					'email'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn ( $value ): string => (string) sanitize_email( (string) $value ),
						'validate_callback' => static fn ( $value ): bool => '' !== sanitize_email( (string) $value ) && false !== is_email( sanitize_email( (string) $value ) ),
					),
					'status'  => array(
						'required'          => false,
						'default'           => 'going',
						'type'              => 'string',
						'enum'              => array( 'going', 'waitlist' ),
						'sanitize_callback' => static fn ( $value ): string => (string) sanitize_key( (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'categories' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => static fn ( $value ): string => (string) sanitize_text_field( (string) $value ),
					),
					'perPage'    => array(
						'required'          => false,
						'default'           => 10,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 50,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/events/(?P<id>[0-9]+)/counts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'event_counts' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rsvps',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_rsvps' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'eventId' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
					),
					'page'    => array(
						'required'          => false,
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
					),
					'perPage' => array(
						'required'          => false,
						'default'           => 20,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
					),
					'status'  => array(
						'required'          => false,
						'type'              => 'string',
						'enum'              => array( 'going', 'waitlist', '' ),
						'sanitize_callback' => static fn ( $value ): string => (string) sanitize_key( (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rsvps/(?P<id>[0-9]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_rsvp' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/event/(?P<id>[0-9]+)/calendar\.ics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'single_ics' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $value ): int => absint( $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/events/calendar\.ics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'all_ics' ),
				'permission_callback' => '__return_true',
			)
		);

		// WP_Error data is never promoted to response headers by core, so the
		// 429 Retry-After header is attached at serve time; ICS bodies are
		// echoed raw there too (text/calendar must not be JSON-encoded).
		add_filter(
			'rest_pre_serve_request',
			array( $this, 'maybe_send_retry_after' ),
			10,
			4
		);
		add_filter(
			'rest_pre_serve_request',
			array( $this, 'maybe_serve_raw' ),
			10,
			4
		);
	}

	/**
	 * Sends `Retry-After` when the served response is an eh_rate_limited 429.
	 *
	 * @param bool             $served  Whether the request was already served.
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request used.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function maybe_send_retry_after( bool $served, $result, $request, $server ): bool {
		unset( $request, $server );

		if ( $served || ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		if ( 429 !== $result->get_status() ) {
			return $served;
		}

		$data = $result->get_data();

		if ( ! is_array( $data ) || 'eh_rate_limited' !== ( $data['code'] ?? '' ) ) {
			return $served;
		}

		$data_obj = $data['data'] ?? array();

		$retry_after = is_array( $data_obj ) ? absint( $data_obj['retryAfter'] ?? self::RATE_LIMIT_WINDOW ) : self::RATE_LIMIT_WINDOW;

		$this->ics->send_header( 'Retry-After', (string) max( 1, $retry_after ) );

		return $served;
	}

	/**
	 * Serves marked ICS responses raw: body bytes verbatim (CRLF intact)
	 * instead of JSON-encoded by the REST server.
	 *
	 * @param bool             $served  Whether the request was already served.
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request used.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool True when the ICS body was echoed here.
	 */
	public function maybe_serve_raw( bool $served, $result, $request, $server ): bool {
		unset( $request, $server );

		if ( $served || ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		if ( 'ics' !== ( $result->get_headers()['X-Event-Hub-Raw'] ?? '' ) ) {
			return $served;
		}

		echo (string) $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/calendar payload, escaped at generation (Ics_Exporter).

		return true;
	}

	/**
	 * Permission callback for POST /rsvp.
	 *
	 * CSRF guard (docs/THREAT-MODEL.md T4): when cookies are present we require
	 * a valid `wp_rest` nonce; cookie-less (stateless) clients proceed and are
	 * covered by the IP rate limit instead.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function rsvp_permission( WP_REST_Request $request ) {
		if ( empty( $_COOKIE ) ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$param = $request->get_param( '_wpnonce' );
			$nonce = is_string( $param ) ? $param : '';
		}

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'eh_rest_cookie_invalid',
				__( 'Cookie check failed: a valid REST nonce is required.', 'event-hub' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for gated routes (docs/DECISIONS.md #2).
	 *
	 * @return bool|WP_Error
	 */
	public function admin_permission() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to manage RSVPs.', 'event-hub' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /rsvp.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_rsvp( WP_REST_Request $request ) {
		$params = $this->request_params( $request );

		$event_id = absint( $params['eventId'] ?? 0 );

		if ( $event_id < 1 ) {
			return new WP_Error( 'eh_missing_param', __( 'A valid eventId is required.', 'event-hub' ), array( 'status' => 400 ) );
		}

		$named = $params['name'] ?? '';

		if ( ! is_string( $named ) || '' === self::normalize_name( $named ) ) {
			return new WP_Error( 'eh_invalid_name', __( 'Please provide a name of 1 to 100 characters.', 'event-hub' ), array( 'status' => 400 ) );
		}

		$raw_email = $params['email'] ?? '';

		if ( ! is_string( $raw_email ) || '' === sanitize_email( $raw_email ) || false === is_email( sanitize_email( $raw_email ) ) ) {
			return new WP_Error( 'eh_invalid_email', __( 'Please provide a valid email address.', 'event-hub' ), array( 'status' => 400 ) );
		}

		$status = $params['status'] ?? 'going';

		if ( ! is_string( $status ) || ! in_array( $status, array( 'going', 'waitlist' ), true ) ) {
			return new WP_Error( 'eh_invalid_status', __( 'Status must be either going or waitlist.', 'event-hub' ), array( 'status' => 400 ) );
		}

		$limited = $this->rate_limit_check();

		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$result = $this->repository->create(
			self::normalize_name( $named ),
			(string) sanitize_email( $raw_email ),
			$status,
			$event_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				// Public 201 body: counts only + non-PII rsvp echo (docs/API.md).
				'rsvp'   => $this->serialize_public_rsvp( $result['rsvp'] ),
				'counts' => $result['counts'],
			),
			201
		);
	}

	/**
	 * Public rsvp echo for the 201 body: never includes the email (PII rule).
	 *
	 * @param array<string,mixed> $row Repository row.
	 * @return array<string,mixed>
	 */
	private function serialize_public_rsvp( array $row ): array {
		return array(
			'id'        => (int) ( $row['id'] ?? 0 ),
			'eventId'   => (int) ( $row['event_id'] ?? 0 ),
			'name'      => (string) ( $row['name'] ?? '' ),
			'status'    => (string) ( $row['status'] ?? '' ),
			'createdAt' => self::to_iso8601( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	/**
	 * GET /events.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_events( WP_REST_Request $request ): WP_REST_Response {
		$params   = $this->request_params( $request );
		$per_page = isset( $params['perPage'] ) ? absint( $params['perPage'] ) : 10;
		$per_page = min( 50, max( 1, $per_page ) );

		$categories = array();

		if ( isset( $params['categories'] ) && is_string( $params['categories'] ) && '' !== $params['categories'] ) {
			$categories = array_filter( array_map( 'sanitize_title', explode( ',', $params['categories'] ) ) );
		}

		// Past events are excluded in SQL (same reasoning as
		// Render_Blocks::upcoming_events()): a PHP-only filter let the 41
		// past events heading the start-ASC order consume perPage and return
		// 9 items of 50 (stage-4 BUG 1). No-end events stay included.
		$now_sql = current_time( 'mysql' );

		$query_args = array(
			'post_type'      => 'eh_event',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'orderby'        => 'meta_value',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology sort on a bounded CPT.
			'meta_key'       => 'eh_start',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the upcoming filter IS the feature.
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => 'eh_end',
					'value'   => $now_sql,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => 'eh_end',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		if ( array() !== $categories ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- category filter is the feature.
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'eh_event_category',
					'field'    => 'slug',
					'terms'    => $categories,
				),
			);
		}

		$query  = new \WP_Query( $query_args );
		$now    = time();
		$events = array();

		foreach ( $query->posts as $post ) {
			$summary = Render_Blocks::summary( $post );

			// Sorting is start ASC; drop events whose end has already passed.
			$end_ts = $this->iso_to_timestamp( (string) $summary['end'] );

			if ( null !== $end_ts && $end_ts < $now ) {
				continue;
			}

			$events[] = $this->serialize_public( $summary );
		}

		return new WP_REST_Response( $events, 200 );
	}

	/**
	 * GET /events/{id}/counts.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function event_counts( WP_REST_Request $request ) {
		$params   = $this->request_params( $request );
		$event_id = absint( $params['id'] ?? 0 );
		$event    = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type || 'publish' !== $event->post_status ) {
			return new WP_Error( 'eh_event_not_found', __( 'No such published event.', 'event-hub' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'eventId' => $event_id,
				'counts'  => $this->repository->counts( $event_id ),
			),
			200
		);
	}

	/**
	 * GET /rsvps.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_rsvps( WP_REST_Request $request ) {
		$params   = $this->request_params( $request );
		$event_id = isset( $params['eventId'] ) ? absint( $params['eventId'] ) : 0;

		if ( $event_id < 1 ) {
			return new WP_Error( 'eh_missing_event', __( 'The eventId parameter is required.', 'event-hub' ), array( 'status' => 400 ) );
		}

		$page     = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $params['perPage'] ?? 20 ) ) );
		$status   = isset( $params['status'] ) && is_string( $params['status'] ) ? $params['status'] : '';

		$result = $this->repository->list( $event_id, $per_page, ( $page - 1 ) * $per_page, $status );

		return new WP_REST_Response(
			array(
				'rsvps'      => array_map( array( $this, 'serialize_private' ), $result['rsvps'] ),
				'total'      => $result['total'],
				'totalPages' => (int) ceil( $result['total'] / $per_page ),
			),
			200
		);
	}

	/**
	 * DELETE /rsvps/{id}.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_rsvp( WP_REST_Request $request ) {
		$params = $this->request_params( $request );
		$id     = absint( $params['id'] ?? 0 );

		$previous = $this->repository->delete( $id );

		if ( null === $previous ) {
			return new WP_Error( 'eh_rsvp_not_found', __( 'No RSVP found with that ID.', 'event-hub' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $this->serialize_private( $previous ),
			),
			200
		);
	}

	/**
	 * GET /event/{id}/calendar.ics.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function single_ics( WP_REST_Request $request ) {
		$params   = $this->request_params( $request );
		$event_id = absint( $params['id'] ?? 0 );
		$event    = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || 'eh_event' !== $event->post_type || 'publish' !== $event->post_status ) {
			return new WP_Error( 'eh_event_not_found', __( 'No such published event.', 'event-hub' ), array( 'status' => 404 ) );
		}

		$response = new WP_REST_Response( Render_Blocks::summary( $event ), 200 );

		return $this->ics->prepare_response( $response, array( $event_id ), 'event-' . $event_id . '.ics' );
	}

	/**
	 * GET /events/calendar.ics.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function all_ics( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$ids = get_posts(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology sort on a bounded CPT.
				'meta_key'       => 'eh_start',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$ids = array_map( 'absint', is_array( $ids ) ? $ids : array() );

		$response = new WP_REST_Response( null, 200 );

		return $this->ics->prepare_response( $response, $ids, 'events.ics' );
	}

	/**
	 * Merges the parameter sources in precedence order: JSON body (or
	 * form-encoded fallback) over query args, with URL regex captures first
	 * (they cannot be overridden by client bodies for path-identified routes).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 */
	private function request_params( WP_REST_Request $request ): array {
		$json = $request->get_json_params();

		$body = ( is_array( $json ) && array() !== $json )
			? $json
			: (array) $request->get_body_params();

		return array_merge(
			(array) $request->get_query_params(),
			$body,
			(array) $request->get_url_params()
		);
	}

	/**
	 * Normalizes a display name: trim, collapse internal whitespace, cap at 100
	 * characters, reject empty results.
	 *
	 * @param string $value Raw name.
	 * @return string
	 */
	public static function normalize_name( string $value ): string {
		$value = sanitize_text_field( $value );
		$value = (string) preg_replace( '/\s+/u', ' ', trim( $value ) );

		if ( strlen( $value ) > 100 ) {
			$value = (string) mb_substr( $value, 0, 100 );
		}

		return trim( $value );
	}

	/**
	 * Applies the per-IP rate limit on POST /rsvp (docs/API.md, THREAT-MODEL T8).
	 *
	 * @return true|WP_Error
	 */
	private function rate_limit_check() {
		// REMOTE_ADDR only (never X-Forwarded-For, THREAT-MODEL T8). Not
		// user-controlled text: hashed into the transient key as-is.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return true;
		}

		$key   = 'eh_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			$error = new WP_Error(
				'eh_rate_limited',
				__( 'Too many RSVP attempts. Please try again later.', 'event-hub' ),
				array(
					'status'     => 429,
					'retryAfter' => self::RATE_LIMIT_WINDOW,
				)
			);

			return $error;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Public serializer: counts + rsvpOpen only, never emails (T7).
	 *
	 * @param array<string,mixed> $summary Render_Blocks::summary() output.
	 * @return array<string,mixed>
	 */
	private function serialize_public( array $summary ): array {
		return array(
			'id'            => (int) $summary['id'],
			'title'         => (string) $summary['title'],
			// Client-rendered filter cards link with this (a missing value
			// produced "undefined" hrefs); it is public post data, not PII.
			'permalink'     => (string) $summary['permalink'],
			'excerpt'       => (string) $summary['excerpt'],
			'start'         => (string) $summary['start'],
			'end'           => (string) $summary['end'],
			'venue'         => (string) $summary['venue'],
			'capacity'      => $summary['capacity'],
			'counts'        => $summary['counts'],
			'categories'    => $summary['categories'],
			'categoryNames' => (array) $summary['category_names'],
			'rsvpOpen'      => (bool) $summary['rsvpOpen'],
		);
	}

	/**
	 * Gated serializer: includes email (docs/API.md GET /rsvps).
	 *
	 * @param array<string,mixed> $row Repository row.
	 * @return array<string,mixed>
	 */
	private function serialize_private( array $row ): array {
		return array(
			'id'        => (int) ( $row['id'] ?? 0 ),
			'eventId'   => (int) ( $row['event_id'] ?? 0 ),
			'name'      => (string) ( $row['name'] ?? '' ),
			'email'     => (string) ( $row['email'] ?? '' ),
			'status'    => (string) ( $row['status'] ?? '' ),
			'createdAt' => self::to_iso8601( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	/**
	 * Converts a stored site-time DATETIME to an ISO 8601 UTC string.
	 *
	 * @param string $value Stored datetime.
	 * @return string
	 */
	public static function to_iso8601( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $value, wp_timezone() );

		if ( false === $datetime ) {
			return $value;
		}

		return $datetime->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'c' );
	}

	/**
	 * Parses an ISO 8601 string to a Unix timestamp.
	 *
	 * @param string $value ISO 8601 value.
	 * @return int|null
	 */
	private function iso_to_timestamp( string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
