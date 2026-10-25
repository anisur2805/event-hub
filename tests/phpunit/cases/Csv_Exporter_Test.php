<?php
/**
 * Tests for Csv_Exporter::handle() — the admin-post CSV export.
 *
 * Covers the BUG-1 fix contract: capability gate, nonce verification,
 * event validation, injection neutralization (docs/API.md, T9).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * CSV export handler test case.
 */
class Csv_Exporter_Test extends WP_UnitTestCase {

	/**
	 * Admin user ID (edit_others_posts).
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID (no caps).
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Sets up users and one event.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->event_id      = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_title'  => 'Smoke Test Event',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Runs the admin-post handler as a user with a nonce; returns [code, output].
	 *
	 * The nonce is minted AFTER the user switch: nonces are bound to the
	 * session token of the current user, so ordering matters.
	 *
	 * @param int    $user_id   User to act as.
	 * @param string $nonce     Nonce value ('auto' = mint as $user_id).
	 * @return array{0:int,1:string}
	 */
	private function run_handle( int $user_id, string $nonce ): array {
		wp_set_current_user( $user_id );

		if ( 'auto' === $nonce ) {
			$nonce = wp_create_nonce( Csv_Exporter::NONCE_ACTION );
		}

		$_GET['action']       = Csv_Exporter::ACTION;
		$_GET['event_id']     = (string) $this->event_id;
		$_REQUEST['_wpnonce'] = $nonce;

		$code = 200;

		ob_start();

		try {
			( new Csv_Exporter() )->handle_request();
		} catch ( \WPDieException $e ) {
			$code = $e->getCode() ? (int) $e->getCode() : 403;
		}

		return array( $code, (string) ob_get_clean() );
	}

	/**
	 * Valid editor + valid nonce: CSV streams with BOM, header row, and the
	 * injection cell neutralized.
	 *
	 * @return void
	 */
	public function test_valid_request_streams_csv(): void {
		$repo = new Rsvp_Repository();
		$repo->create( '=CMD|\' + del', 'evil@example.com', 'going', $this->event_id );

		list( $code, $output ) = $this->run_handle( $this->admin_id, 'auto' );

		$this->assertSame( 200, $code );
		$this->assertSame( "\xEF\xBB\xBF", substr( $output, 0, 3 ), 'UTF-8 BOM must open the stream.' );
		$this->assertStringContainsString( '"Event ID","Event Title",Name,Email,Status,"Registered At"', $output );
		// The formula cell must be prefixed with an apostrophe.
		$this->assertStringContainsString( '\'=CMD', $output );
	}

	/**
	 * Invalid nonce must wp_die with no CSV output.
	 *
	 * @return void
	 */
	public function test_bad_nonce_dies(): void {
		list( $code, $output ) = $this->run_handle( $this->admin_id, 'forged123' );

		$this->assertNotSame( 200, $code, 'A forged nonce must not stream CSV.' );
		$this->assertSame( '', $output );
	}

	/**
	 * User without edit_others_posts must wp_die with no CSV output.
	 *
	 * @return void
	 */
	public function test_no_capability_dies(): void {
		list( $code, $output ) = $this->run_handle( $this->subscriber_id, 'auto' );

		$fake = $this->admin_id; // keep property read for PHPStan.
		$this->assertGreaterThan( 0, $fake );
		$this->assertNotSame( 200, $code );
		$this->assertSame( '', $output );
	}

	/**
	 * Nonexistent event id must wp_die 404.
	 *
	 * @return void
	 */
	public function test_unknown_event_dies(): void {
		wp_set_current_user( $this->admin_id );

		$_GET['action']       = Csv_Exporter::ACTION;
		$_GET['event_id']     = '999999';
		$_REQUEST['_wpnonce'] = wp_create_nonce( Csv_Exporter::NONCE_ACTION );

		try {
			( new Csv_Exporter() )->handle_request();
			$this->fail( 'Unknown event must wp_die.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
		}
	}

	/**
	 * Neutralize() unit coverage: = + - @ TAB CR prefixed; safe cells pass.
	 *
	 * @return void
	 */
	public function test_neutralize_prefixes_formula_characters(): void {
		$exporter = new Csv_Exporter();

		$this->assertSame( '\'=sum(a1)', $exporter->neutralize( '=sum(a1)' ) );
		$this->assertSame( '\'+1234', $exporter->neutralize( '+1234' ) );
		$this->assertSame( '\'-1', $exporter->neutralize( '-1' ) );
		$this->assertSame( '\'@import', $exporter->neutralize( '@import' ) );
		$this->assertSame( "'\tTabbed", $exporter->neutralize( "\tTabbed" ) );
		$this->assertSame( 'plain', $exporter->neutralize( 'plain' ) );
		$this->assertSame( '', $exporter->neutralize( '' ) );
	}
}
