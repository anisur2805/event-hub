<?php
/**
 * ROADMAP §5B regressions: settings screen (editable email copy) + Event
 * List compact layout.
 *
 * Email copy: the five Notifier mails read per-email subject/intro
 * overrides from Email_Settings (option event_hub_email_copy); empty
 * fields keep the built-in copy byte-identical.
 *
 * Layout: layout=list renders .event-hub-row children instead of cards;
 * the default and junk values keep the grid.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Settings + layout tests.
 */
class Settings_Layout_Test extends WP_UnitTestCase {

	/**
	 * Captured mails (to => atts).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $mails = array();

	/**
	 * Creates a published event with start/end meta.
	 *
	 * @return int Post ID.
	 */
	private static function make_event(): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
				'post_title'  => 'Settings Layout Event',
			)
		);

		update_post_meta( $post_id, 'eh_start', '2099-05-01 18:00:00' );
		update_post_meta( $post_id, 'eh_end', '2099-05-01 20:00:00' );
		update_post_meta( $post_id, Event_Status::META, '' );

		return $post_id;
	}

	/**
	 * Mail captor via static property (a returned by-value copy is stale).
	 *
	 * @return void
	 */
	public static function capture_mail(): void {
		add_filter(
			'pre_wp_mail',
			static function ( $pre, array $atts ) {
				self::$mails[] = $atts;

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Resets the mail capture before every test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		self::$mails = array();
	}

	/**
	 * Overrides cleanup: the option persists between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( Email_Settings::OPTION );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( Reminders::OPTION );
		delete_option( Notifier::OPTION );

		parent::tearDown();
	}

	/**
	 * Defaults: no option row → every email's built-in subject survives
	 * byte-identical and no intro paragraph is prepended.
	 *
	 * @return void
	 */
	public function test_defaults_keep_builtin_copy(): void {
		$event_id = self::make_event();

		delete_option( Email_Settings::OPTION );

		$rsvp = array(
			'name'   => 'Defaults User',
			'email'  => 'defaults@example.com',
			'status' => 'going',
			'guests' => 1,
		);

		update_option( Notifier::OPTION, true );
		update_option( Reminders::OPTION, false );

		self::capture_mail();

		$this->assertTrue( Notifier::send_confirmation( $rsvp, $event_id, str_repeat( 'a', 64 ) ) );

		$this->assertCount( 1, self::$mails );
		$this->assertSame( 'Your RSVP is confirmed: Settings Layout Event', self::$mails[0]['subject'] );

		$body = (string) self::$mails[0]['message'];

		// No intro override: the body leads with the data rows.
		$this->assertStringStartsWith( 'Name: Defaults User', $body );
	}

	/**
	 * Subject + intro overrides ride through the confirmation email: the
	 * subject keeps its %s substitution (event title) and the intro leads
	 * the body as its own paragraph ahead of the data rows.
	 *
	 * @return void
	 */
	public function test_confirmation_overrides_apply(): void {
		$event_id = self::make_event();

		Email_Settings::save(
			array(
				'confirmation' => array(
					'subject' => 'See you at %s!',
					'intro'   => 'Custom intro about %s. Bring a friend.',
				),
			)
		);

		update_option( Notifier::OPTION, true );
		update_option( Reminders::OPTION, false );

		self::capture_mail();

		$this->assertTrue(
			Notifier::send_confirmation(
				array(
					'name'   => 'Override User',
					'email'  => 'override@example.com',
					'status' => 'going',
					'guests' => 2,
				),
				$event_id,
				str_repeat( 'b', 64 )
			)
		);

		$this->assertCount( 1, self::$mails );
		$this->assertSame( 'See you at Settings Layout Event!', self::$mails[0]['subject'] );

		$body = (string) self::$mails[0]['message'];

		$this->assertStringStartsWith( "Custom intro about Settings Layout Event. Bring a friend.\r\n", $body );
		$this->assertStringContainsString( 'Name: Override User', $body );
		// The manage link survives — the intro cannot displace it.
		$this->assertStringContainsString( 'Manage your booking', $body );
	}

	/**
	 * The save path sanitizes: markup is stripped from subject/intro, junk
	 * keys are dropped, and completely-empty pairs vanish (option shrinks
	 * to real edits only).
	 *
	 * @return void
	 */
	public function test_save_sanitizes_and_drops_empty(): void {
		Email_Settings::save(
			array(
				'confirmation' => array(
					'subject' => '<b>Bold</b> %s',
					'intro'   => "Line one\r\n<br>Line two",
				),
				'bogus_key'    => array( 'subject' => 'nope' ),
				'reminder'     => array(
					'subject' => '',
					'intro'   => '   ',
				),
			)
		);

		$stored = get_option( Email_Settings::OPTION );

		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'bogus_key', $stored );
		$this->assertArrayNotHasKey( 'reminder', $stored, 'An all-empty pair drops out.' );
		$this->assertSame( 'Bold %s', $stored['confirmation']['subject'] );

		// sanitize_textarea_field strips tags but keeps the (CRLF) line
		// break — plain-text mail emits it as the body newline.
		$this->assertSame( "Line one\r\nLine two", $stored['confirmation']['intro'] );

		// The reader hands back the same values (sanitized read side).
		$this->assertSame( 'Bold %s', Email_Settings::get( 'confirmation' )['subject'] );
	}

	/**
	 * The settings tab renders the five email blocks with their fields,
	 * and the toggles moved there still render.
	 *
	 * @return void
	 */
	public function test_settings_tab_renders_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$page = new Admin_Page();

		ob_start();

		$_GET['tab'] = 'settings';
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Email copy', $html );
		$this->assertStringContainsString( 'eh_email_copy[confirmation][subject]', $html );
		$this->assertStringContainsString( 'eh_email_copy[promotion][intro]', $html );
		$this->assertStringContainsString( 'eh_email_copy[status][subject]', $html );
		$this->assertStringContainsString( 'event_hub_confirmations', $html );
		$this->assertStringContainsString( 'event_hub_notifications', $html );
	}

	/**
	 * Default grid: render_event_list_block keeps the plain wrapper class
	 * and emits cards (no --layout-list modifier, no rows).
	 *
	 * @return void
	 */
	public function test_grid_layout_default(): void {
		$event_id = self::make_event();

		$html = Render_Blocks::render_event_list_block(
			array(
				'categoryId'   => '',
				'postsPerPage' => 6,
				'showFilters'  => false,
				'layout'       => 'grid',
			)
		);

		$this->assertStringNotContainsString( 'event-hub-event-list--layout-list', $html );
		$this->assertStringContainsString( 'event-hub-card', $html );
		$this->assertStringContainsString( 'Settings Layout Event', $html );
	}

	/**
	 * List layout: layout=list emits the modifier class and compact rows
	 * (title link, meta time, count) instead of cards; the card template
	 * is gone (client-side filtering never injects grid cards into rows).
	 *
	 * @return void
	 */
	public function test_list_layout_renders_rows(): void {
		self::make_event();

		$html = Render_Blocks::render_event_list_block(
			array(
				'categoryId'   => '',
				'postsPerPage' => 6,
				'showFilters'  => true,
				'layout'       => 'list',
			)
		);

		$this->assertStringContainsString( 'event-hub-event-list--layout-list', $html );
		$this->assertStringContainsString( 'event-hub-row__title', $html );
		$this->assertStringContainsString( 'Settings Layout Event', $html );
		$this->assertStringNotContainsString( 'event-hub-card__', $html, 'No grid-card markup leaks into the list layout.' );
		$this->assertStringNotContainsString( 'data-eh-card-tpl', $html );
	}

	/**
	 * Junk layout values fall back to the grid (the pre-layout look) — a
	 * forged attribute cannot produce an unknown render mode.
	 *
	 * @return void
	 */
	public function test_junk_layout_falls_back_to_grid(): void {
		self::make_event();

		$html = Render_Blocks::render_event_list_block(
			array(
				'layout' => 'nonsense',
			)
		);

		$this->assertStringNotContainsString( 'event-hub-event-list--layout-list', $html );
		$this->assertStringContainsString( 'event-hub-card', $html );
	}
}
