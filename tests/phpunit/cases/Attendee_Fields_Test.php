<?php
/**
 * Tests for the §5B slice — optional per-attendee phone + note (DB 1.7.0).
 *
 * Covers the schema columns, the repository round trip (including the ''
 * → NULL collapse), the BUG 4 storage-cap guards, the REST arg/serializer
 * contract, the anonymize-in-place pairing, the privacy exporter's
 * emit-only-when-set rule, and the CSV column pair.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Attendee fields (phone + note) test case.
 */
class Attendee_Fields_Test extends WP_UnitTestCase {

	/**
	 * Per-test unique email tag.
	 *
	 * @var string
	 */
	private string $email_tag = '';

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Fresh event per test; the boot migration mirrors a real request.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Migrations::maybe_migrate();

		$this->email_tag = wp_generate_password( 8, false, false );
		$this->event_id  = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->event_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $this->event_id, 'eh_end', '2099-05-01 20:00:00' );
	}

	/**
	 * Email for a distinct local part.
	 *
	 * @param string $local Local part.
	 * @return string
	 */
	private function email( string $local ): string {
		return $local . '-' . $this->email_tag . '@example.org';
	}

	/**
	 * Dispatches a POST /event-hub/v1/rsvp request.
	 *
	 * @param array<string,mixed> $body JSON body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function post( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/rsvp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/*
	 * Schema.
	 */

	/**
	 * A fresh install carries both columns, nullable (legacy rows), with the
	 * declared widths; the version option tracks the constant.
	 */
	public function test_schema_columns_exist_and_are_nullable(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'eh_rsvps';

		$phone = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'phone' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
		);
		$note  = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'attendee_note' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table from $wpdb->prefix.
		);

		self::assertNotNull( $phone, 'phone column must exist on a fresh 1.7.0 install' );
		self::assertNotNull( $note, 'attendee_note column must exist on a fresh 1.7.0 install' );
		self::assertSame( 'YES', (string) $phone->Null, 'phone must be nullable (legacy rows)' );
		self::assertSame( 'YES', (string) $note->Null, 'attendee_note must be nullable (legacy rows)' );
		self::assertStringContainsString( (string) Rsvp_Repository::MAX_PHONE, strtolower( (string) $phone->Type ) );
		self::assertStringContainsString( (string) Rsvp_Repository::MAX_NOTE, strtolower( (string) $note->Type ) );
		self::assertSame( EVENT_HUB_DB_VERSION, get_option( 'event_hub_db_version' ) );
	}

	/*
	 * Repository round trip.
	 */

	/**
	 * Values handed to create() come back out of the returned row, out of
	 * get(), and out of raw SQL.
	 */
	public function test_repository_round_trip(): void {
		global $wpdb;

		$repo   = new Rsvp_Repository();
		$result = $repo->create( 'Phone Probe', $this->email( 'roundtrip' ), 'going', $this->event_id, 1, '+1 555 0100', 'Window seat please' );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( '+1 555 0100', $result['rsvp']['phone'] ?? '' );
		self::assertSame( 'Window seat please', $result['rsvp']['attendee_note'] ?? '' );

		$id = (int) $result['rsvp']['id'];

		$stored = $repo->get( $id );

		self::assertIsArray( $stored );
		self::assertSame( '+1 555 0100', (string) $stored['phone'] );
		self::assertSame( 'Window seat please', (string) $stored['attendee_note'] );

		$raw = $wpdb->get_row(
			$wpdb->prepare( 'SELECT phone, attendee_note FROM ' . $wpdb->prefix . 'eh_rsvps WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix.
			ARRAY_A
		);

		self::assertSame( '+1 555 0100', (string) $raw['phone'] );
		self::assertSame( 'Window seat please', (string) $raw['attendee_note'] );
	}

	/**
	 * '' stores as SQL NULL (the NULLIF collapse), so "not given" is one
	 * value in the DB, not two.
	 */
	public function test_empty_values_store_as_null(): void {
		global $wpdb;

		$repo   = new Rsvp_Repository();
		$result = $repo->create( 'Blank Probe', $this->email( 'blank' ), 'going', $this->event_id );

		self::assertNotInstanceOf( \WP_Error::class, $result );

		$id = (int) $result['rsvp']['id'];

		$raw = $wpdb->get_row(
			$wpdb->prepare( 'SELECT phone, attendee_note FROM ' . $wpdb->prefix . 'eh_rsvps WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix.
			ARRAY_A
		);

		self::assertNull( $raw['phone'], 'empty phone must store as NULL' );
		self::assertNull( $raw['attendee_note'], 'empty note must store as NULL' );

		// NULL reads back as '' on every serialized surface.
		self::assertSame( '', $result['rsvp']['phone'] ?? 'x' );
		self::assertSame( '', $result['rsvp']['attendee_note'] ?? 'x' );
	}

	/*
	 * Storage caps (BUG 4 rule: reject before the INSERT).
	 */

	/**
	 * A phone past MAX_PHONE rejects with eh_invalid_phone and writes
	 * nothing — the column is never truncated behind a 201.
	 */
	public function test_phone_past_cap_rejects_without_writing(): void {
		$before = ( new Rsvp_Repository() )->counts( $this->event_id );

		$result = ( new Rsvp_Repository() )->create(
			'Long Phone',
			$this->email( 'longphone' ),
			'going',
			$this->event_id,
			1,
			str_repeat( '9', Rsvp_Repository::MAX_PHONE + 1 )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'eh_invalid_phone', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data( 'eh_invalid_phone' )['status'] );
		self::assertSame( $before, ( new Rsvp_Repository() )->counts( $this->event_id ), 'no row was written' );

		// Exactly at the cap is accepted (boundary, inclusive).
		$at_cap = ( new Rsvp_Repository() )->create(
			'Cap Phone',
			$this->email( 'capphone' ),
			'going',
			$this->event_id,
			1,
			str_repeat( '9', Rsvp_Repository::MAX_PHONE )
		);

		self::assertNotInstanceOf( \WP_Error::class, $at_cap );
	}

	/**
	 * A note past MAX_NOTE rejects with eh_invalid_note and writes nothing.
	 */
	public function test_note_past_cap_rejects_without_writing(): void {
		$before = ( new Rsvp_Repository() )->counts( $this->event_id );

		$result = ( new Rsvp_Repository() )->create(
			'Long Note',
			$this->email( 'longnote' ),
			'going',
			$this->event_id,
			1,
			'',
			str_repeat( 'n', Rsvp_Repository::MAX_NOTE + 1 )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'eh_invalid_note', $result->get_error_code() );
		self::assertSame( $before, ( new Rsvp_Repository() )->counts( $this->event_id ), 'no row was written' );

		$at_cap = ( new Rsvp_Repository() )->create(
			'Cap Note',
			$this->email( 'capnote' ),
			'going',
			$this->event_id,
			1,
			'',
			str_repeat( 'n', Rsvp_Repository::MAX_NOTE )
		);

		self::assertNotInstanceOf( \WP_Error::class, $at_cap );
	}

	/*
	 * REST contract.
	 */

	/**
	 * The public 201 echo stays non-PII (no phone/note — same rule as the
	 * email); the admin list (serialize_private) carries both.
	 */
	public function test_rest_public_echo_and_private_list(): void {
		wp_set_current_user( 0 );

		$response = $this->post(
			array(
				'eventId'   => $this->event_id,
				'name'      => 'Rest Phone',
				'email'     => $this->email( 'restphone' ),
				'status'    => 'going',
				'phone'     => '+44 20 7946 0958',
				'note'      => 'Arriving late',
				'formToken' => Form_Guard::issue_token( $this->event_id, time() - 10 ),
			)
		);

		self::assertSame( 201, $response->get_status() );

		// Public 201 echo: PII stays out (email, phone, note).
		$echo = $response->get_data()['rsvp'] ?? array();

		self::assertArrayNotHasKey( 'phone', $echo );
		self::assertArrayNotHasKey( 'note', $echo );
		self::assertArrayNotHasKey( 'email', $echo );

		// The row still stores what the attendee gave.
		$found = ( new Rsvp_Repository() )->find_existing( $this->event_id, $this->email( 'restphone' ) );

		self::assertIsArray( $found );
		self::assertSame( '+44 20 7946 0958', (string) $found['phone'] );
		self::assertSame( 'Arriving late', (string) $found['attendee_note'] );

		// Admin list (editor capability): the private serializer carries both.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$list = new \WP_REST_Request( 'GET', '/event-hub/v1/rsvps' );
		$list->set_param( 'eventId', $this->event_id );

		$list_response = rest_get_server()->dispatch( $list );

		self::assertSame( 200, $list_response->get_status() );

		$rows = $list_response->get_data()['rsvps'] ?? array();
		$mine = wp_list_filter( $rows, array( 'email' => $this->email( 'restphone' ) ) );
		$mine = array_values( $mine );

		self::assertCount( 1, $mine );
		self::assertSame( '+44 20 7946 0958', $mine[0]['phone'] );
		self::assertSame( 'Arriving late', $mine[0]['note'] );
	}

	/**
	 * Absent params serialize as '' (admin list), never null/undefined.
	 */
	public function test_rest_list_blank_defaults(): void {
		wp_set_current_user( 0 );

		$made = ( new Rsvp_Repository() )->create( 'Rest Blank', $this->email( 'restblank' ), 'going', $this->event_id );

		self::assertNotInstanceOf( \WP_Error::class, $made );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$list = new \WP_REST_Request( 'GET', '/event-hub/v1/rsvps' );
		$list->set_param( 'eventId', $this->event_id );

		$list_response = rest_get_server()->dispatch( $list );

		self::assertSame( 200, $list_response->get_status() );

		$rows = $list_response->get_data()['rsvps'] ?? array();
		$mine = array_values( wp_list_filter( $rows, array( 'email' => $this->email( 'restblank' ) ) ) );

		self::assertCount( 1, $mine );
		self::assertSame( '', $mine[0]['phone'] ?? 'x' );
		self::assertSame( '', $mine[0]['note'] ?? 'x' );
	}

	/**
	 * The REST surface enforces the caps too (repository guard reached
	 * through the controller): 400 with the matching code.
	 */
	public function test_rest_rejects_over_caps(): void {
		wp_set_current_user( 0 );

		$phone = $this->post(
			array(
				'eventId'   => $this->event_id,
				'name'      => 'Rest Long Phone',
				'email'     => $this->email( 'restlongphone' ),
				'status'    => 'going',
				'phone'     => str_repeat( '9', Rsvp_Repository::MAX_PHONE + 1 ),
				'formToken' => Form_Guard::issue_token( $this->event_id, time() - 10 ),
			)
		);

		self::assertSame( 400, $phone->get_status() );
		self::assertSame( 'eh_invalid_phone', $phone->get_data()['code'] );

		$note = $this->post(
			array(
				'eventId'   => $this->event_id,
				'name'      => 'Rest Long Note',
				'email'     => $this->email( 'restlongnote' ),
				'status'    => 'going',
				'note'      => str_repeat( 'n', Rsvp_Repository::MAX_NOTE + 1 ),
				'formToken' => Form_Guard::issue_token( $this->event_id, time() - 10 ),
			)
		);

		self::assertSame( 400, $note->get_status() );
		self::assertSame( 'eh_invalid_note', $note->get_data()['code'] );
	}

	/*
	 * Privacy pairing (5.4): export when set, erase both.
	 */

	/**
	 * The exporter emits Phone and Note only for rows that carry them.
	 */
	public function test_exporter_emits_fields_only_when_set(): void {
		$repo = new Rsvp_Repository();

		$repo->create( 'Pii With', $this->email( 'piiwith' ), 'going', $this->event_id, 1, '+1 555 0199', 'Vegetarian' );
		$repo->create( 'Pii Without', $this->email( 'piiwithout' ), 'going', $this->event_id );

		$with = Privacy::export_rows( $this->email( 'piiwith' ) );
		$names = wp_list_pluck( $with['data'][0]['data'], 'name' );
		$values = wp_list_pluck( $with['data'][0]['data'], 'value' );

		self::assertContains( 'Phone', $names );
		self::assertContains( 'Note', $names );
		self::assertContains( '+1 555 0199', $values );
		self::assertContains( 'Vegetarian', $values );

		$without = Privacy::export_rows( $this->email( 'piiwithout' ) );
		$names_without = wp_list_pluck( $without['data'][0]['data'], 'name' );

		self::assertNotContains( 'Phone', $names_without, 'unset phone must not appear in the export' );
		self::assertNotContains( 'Note', $names_without, 'unset note must not appear in the export' );
	}

	/**
	 * The eraser NULLs both columns in place (the anonymize-in-place
	 * contract extended to DB 1.7.0).
	 */
	public function test_eraser_nulls_both_columns(): void {
		global $wpdb;

		$repo   = new Rsvp_Repository();
		$result = $repo->create( 'Erase Fields', $this->email( 'erasefields' ), 'going', $this->event_id, 1, '+1 555 0177', 'Allergic to peanuts' );

		self::assertNotInstanceOf( \WP_Error::class, $result );

		$id = (int) $result['rsvp']['id'];

		$outcome = Privacy::erase_rows( $this->email( 'erasefields' ) );
		self::assertSame( 1, $outcome['items_removed'] );

		$raw = $wpdb->get_row(
			$wpdb->prepare( 'SELECT phone, attendee_note FROM ' . $wpdb->prefix . 'eh_rsvps WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix.
			ARRAY_A
		);

		self::assertNull( $raw['phone'], 'erase must clear the phone' );
		self::assertNull( $raw['attendee_note'], 'erase must clear the note' );
	}

	/*
	 * CSV export.
	 */

	/**
	 * The header pair and the row builder both carry the new columns.
	 */
	public function test_csv_carries_fields(): void {
		$exporter = new Csv_Exporter( new Rsvp_Repository() );

		self::assertSame( array_slice( Csv_Exporter::COLUMNS, -2 ), array( 'Phone', 'Note' ) );

		$row = $exporter->csv_row(
			$this->event_id,
			'CSV Event',
			array(
				'name'          => 'Csv Probe',
				'email'         => $this->email( 'csv' ),
				'status'        => 'going',
				'guests'        => 2,
				'created_at'    => '2099-04-01 10:00:00',
				'phone'         => '+1 555 0122',
				'attendee_note' => '=SUM(A1:A2)',
			)
		);

		self::assertCount( count( Csv_Exporter::COLUMNS ), $row );
		self::assertSame( "'+1 555 0122", $row[ count( $row ) - 2 ], 'leading + is a formula prefix — neutralized' );
		self::assertSame( "'=SUM(A1:A2)", $row[ count( $row ) - 1 ], 'neutralization still applies to the note cell' );
	}

	/**
	 * NULL / missing keys collapse to empty cells rather than notices.
	 */
	public function test_csv_blanks_nulls(): void {
		$exporter = new Csv_Exporter( new Rsvp_Repository() );

		$row = $exporter->csv_row(
			$this->event_id,
			'CSV Event',
			array(
				'name'       => 'Csv Blank',
				'email'      => $this->email( 'csvblank' ),
				'status'     => 'going',
				'guests'     => 1,
				'created_at' => '2099-04-01 10:00:00',
				'phone'      => null,
			)
		);

		self::assertSame( '', $row[ count( $row ) - 2 ] );
		self::assertSame( '', $row[ count( $row ) - 1 ] );
	}

	/*
	 * Front-end markup.
	 */

	/**
	 * The RSVP form renders both optional fields, wired to the store actions
	 * and carrying their maxlength caps.
	 */
	public function test_form_renders_both_fields(): void {
		wp_set_current_user( 0 );

		$html = Render_Blocks::render_rsvp_button( $this->event_id );

		self::assertStringContainsString( 'name="event_hub_phone"', $html );
		self::assertStringContainsString( 'type="tel"', $html );
		self::assertStringContainsString( 'name="event_hub_note"', $html );
		self::assertStringContainsString( 'maxlength="' . Rsvp_Repository::MAX_PHONE . '"', $html );
		self::assertStringContainsString( 'maxlength="' . Rsvp_Repository::MAX_NOTE . '"', $html );
		self::assertStringContainsString( 'data-wp-on--input="actions.setPhone"', $html );
		self::assertStringContainsString( 'data-wp-on--input="actions.setNote"', $html );

		// #30 rule: every bind target resolves in PHP (SSR context).
		self::assertStringContainsString( '"phone":"","note":""', $html );
	}
}
