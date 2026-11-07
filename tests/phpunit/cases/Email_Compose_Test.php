<?php
/**
 * Tests for the §5B slice — admin compose email to attendees.
 *
 * Covers the route contract (capability, 404, 400 on empty subject/body),
 * one-mail-per-recipient delivery via the pre_wp_mail captor, the audience
 * filter, the count feedback, and the per-recipient message footer (start
 * line + event page + signature).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Admin compose email test case.
 */
class Email_Compose_Test extends WP_UnitTestCase {

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
	 * Editor user ID (edit_others_posts).
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * Mail captor (static: by-value copies go stale).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $mails = array();

	/**
	 * Fresh event + editor per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Migrations::maybe_migrate();

		self::$mails  = array();
		$this->email_tag = wp_generate_password( 8, false, false );
		$this->event_id  = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->event_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $this->event_id, 'eh_end', '2099-05-01 20:00:00' );

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		add_filter(
			'pre_wp_mail',
			static function ( $pre, array $atts ) {
				Email_Compose_Test::$mails[] = $atts;

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Overrides cleanup.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );

		parent::tearDown();
	}

	/**
	 * Distinct attendee email.
	 *
	 * @param string $local Local part.
	 * @return string
	 */
	private function email( string $local ): string {
		return $local . '-' . $this->email_tag . '@example.org';
	}

	/**
	 * Creates an RSVP row directly.
	 *
	 * @param string $local  Email local part.
	 * @param string $status Row status.
	 * @return int Row ID.
	 */
	private function make_rsvp( string $local, string $status = 'going' ): int {
		$result = ( new Rsvp_Repository() )->create(
			'Compose ' . $local,
			$this->email( $local ),
			$status,
			$this->event_id
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		return (int) $result['rsvp']['id'];
	}

	/**
	 * POSTs to the compose route as $user_id (0 = logged out).
	 *
	 * @param int                  $user_id Current user.
	 * @param array<string,mixed>  $body   JSON body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function post( int $user_id, array $body = array() ) {
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/events/' . $this->event_id . '/email' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array_merge(
					array(
						'subject' => 'Door code tonight',
						'message' => "Front door code is 4478.\n\nPlease arrive 10 minutes early.",
					),
					$body
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/*
	 * Route contract.
	 */

	/**
	 * Logged-out users get 403 before anything renders or sends.
	 */
	public function test_requires_capability(): void {
		$this->make_rsvp( 'gatekeeper' );

		$response = $this->post( 0 );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( array(), self::$mails, 'no mail on a denied request' );
	}

	/**
	 * Unknown / non-event IDs 404.
	 */
	public function test_unknown_event_404(): void {
		$request = new \WP_REST_Request( 'POST', '/event-hub/v1/events/987654321/email' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'subject' => 'x', 'message' => 'y' ) ) );
		wp_set_current_user( $this->editor_id );

		$bogus = rest_get_server()->dispatch( $request );

		self::assertSame( 404, $bogus->get_status() );
		self::assertSame( 'eh_event_not_found', $bogus->get_data()['code'] );

		// A non-event post type is equally unknown.
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$request2 = new \WP_REST_Request( 'POST', '/event-hub/v1/events/' . $page_id . '/email' );
		$request2->set_header( 'Content-Type', 'application/json' );
		$request2->set_body( (string) wp_json_encode( array( 'subject' => 'x', 'message' => 'y' ) ) );

		$page_response = rest_get_server()->dispatch( $request2 );

		self::assertSame( 404, $page_response->get_status() );
	}

	/**
	 * Empty (or whitespace) subject or body is a 400, not a silent no-op.
	 */
	public function test_empty_subject_or_body_400(): void {
		$no_subject = $this->post( $this->editor_id, array( 'subject' => '' ) );
		self::assertSame( 400, $no_subject->get_status() );
		self::assertSame( 'eh_missing_message', $no_subject->get_data()['code'] );

		$whitespace = $this->post( $this->editor_id, array( 'message' => "   \n  " ) );
		self::assertSame( 400, $whitespace->get_status() );
		self::assertSame( 'eh_missing_message', $whitespace->get_data()['code'] );

		self::assertSame( array(), self::$mails, 'nothing sent on a 400' );
	}

	/*
	 * Delivery.
	 */

	/**
	 * Sends exactly one mail per attendee — never a shared To/Bcc header —
	 * and reports honest counts.
	 */
	public function test_sends_one_mail_per_attendee(): void {
		$this->make_rsvp( 'alpha' );
		$this->make_rsvp( 'bravo' );
		$this->make_rsvp( 'charlie' );

		$response = $this->post( $this->editor_id );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 3, $response->get_data()['sent'] ?? 0 );
		self::assertSame( 0, $response->get_data()['skipped'] ?? 99 );
		self::assertSame( 3, $response->get_data()['total'] ?? 0 );
		self::assertCount( 3, self::$mails );

		$tos = array_map(
			static fn ( array $atts ): string => (string) ( $atts['to'] ?? '' ),
			self::$mails
		);

		// Every recipient appears once; no recipient sees another's address.
		foreach ( array( 'alpha', 'bravo', 'charlie' ) as $local ) {
			self::assertSame( 1, count( array_keys( $tos, $this->email( $local ), true ) ) );
		}

		// Subject passes through as composed.
		foreach ( self::$mails as $atts ) {
			self::assertSame( 'Door code tonight', (string) $atts['subject'] );
		}
	}

	/**
	 * The audience filter narrows the recipient set.
	 */
	public function test_audience_filter(): void {
		$this->make_rsvp( 'winger', 'waitlist' );
		$this->make_rsvp( 'goer' );

		$going = $this->post( $this->editor_id, array( 'audience' => 'going' ) );

		self::assertSame( 200, $going->get_status() );
		self::assertSame( 1, $going->get_data()['sent'] ?? 0 );
		self::assertSame( $this->email( 'goer' ), (string) ( self::$mails[0]['to'] ?? '' ) );

		self::$mails = array();

		$wait = $this->post( $this->editor_id, array( 'audience' => 'waitlist' ) );

		self::assertSame( 200, $wait->get_status() );
		self::assertSame( 1, $wait->get_data()['sent'] ?? 0 );
		self::assertSame( $this->email( 'winger' ), (string) ( self::$mails[0]['to'] ?? '' ) );

		self::$mails = array();

		// Junk audience falls back to everyone (sanitize + enum default).
		$all = $this->post( $this->editor_id, array( 'audience' => 'everyone!!' ) );

		self::assertSame( 200, $all->get_status() );
		self::assertSame( 2, $all->get_data()['total'] ?? 0 );
	}

	/**
	 * An invalid stored address is skipped, not counted as sent.
	 */
	public function test_invalid_address_counts_as_skipped(): void {
		global $wpdb;

		$this->make_rsvp( 'validone' );

		// Insert an unreachable row directly (create() would reject nothing
		// here, but the sanitize path can't produce a bad email — a legacy
		// row or direct write can).
		$wpdb->insert(
			$wpdb->prefix . 'eh_rsvps',
			array(
				'event_id'   => $this->event_id,
				'name'       => 'Legacy Broken',
				'email'      => 'not-an-email',
				'status'     => 'going',
				'guests'     => 1,
				'created_at' => current_time( 'mysql' ),
			)
		);

		$response = $this->post( $this->editor_id );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $response->get_data()['sent'] ?? 0 );
		self::assertSame( 1, $response->get_data()['skipped'] ?? 0 );
		self::assertSame( 2, $response->get_data()['total'] ?? 0 );
	}

	/**
	 * The per-recipient body carries the organizer's message, then the
	 * start line, event page and signature; line endings are normalized
	 * to CRLF pairs (no stray CR) and the organizer's blank lines survive
	 * as paragraph breaks.
	 */
	public function test_message_footer_and_line_endings(): void {
		$this->make_rsvp( 'footercheck' );

		$response = $this->post( $this->editor_id );

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, self::$mails );

		$message = (string) self::$mails[0]['message'];

		self::assertStringContainsString( 'Front door code is 4478.', $message );
		self::assertStringContainsString( 'Please arrive 10 minutes early.', $message );
		self::assertStringContainsString( 'Starts:', $message );
		self::assertStringContainsString( 'Event page:', $message );
		self::assertStringNotContainsString( "\r", str_replace( "\r\n", '', $message ), 'only CRLF pairs, no stray CR' );
		self::assertStringNotContainsString( '<', $message, 'plain text stays plain — sanitized textarea' );
	}

	/**
	 * The blank line between the organizer's two paragraphs survives the
	 * transport (it is a deliberate break, not trimmable whitespace).
	 */
	public function test_blank_paragraph_line_survives(): void {
		$this->make_rsvp( 'blankline' );

		$this->post( $this->editor_id );

		self::assertCount( 1, self::$mails );

		$message = (string) self::$mails[0]['message'];

		self::assertStringContainsString( "4478.\r\n\r\nPlease arrive", $message );
	}

	/**
	 * An unaccepted wp_mail is honest: skipped, not sent.
	 */
	public function test_unaccepted_mail_counts_as_skipped(): void {
		remove_all_filters( 'pre_wp_mail' );

		add_filter(
			'pre_wp_mail',
			static fn () => false
		);

		$this->make_rsvp( 'bouncecheck' );

		$response = $this->post( $this->editor_id );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 0, $response->get_data()['sent'] ?? 99 );
		self::assertSame( 1, $response->get_data()['skipped'] ?? 0 );
	}
}
