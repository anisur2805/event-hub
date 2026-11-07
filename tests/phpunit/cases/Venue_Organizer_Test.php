<?php
/**
 * ROADMAP 5.1 regressions: venue/organizer entities, event link meta, and
 * the JSON-LD enrichment path.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Integration tests (real WP) for the linked-entity resolver and consumers.
 */
class Venue_Organizer_Test extends WP_UnitTestCase {

	/**
	 * Creates a published event with the given start meta.
	 *
	 * @param string $start Start datetime ('' = none).
	 * @return int Post ID.
	 */
	private static function make_event( string $start = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_event',
				'post_status' => 'publish',
			)
		);

		if ( '' !== $start ) {
			update_post_meta( $post_id, 'eh_start', $start );
		}

		return $post_id;
	}

	/**
	 * Creates a published entity post of the given type.
	 *
	 * @param string $post_type 'eh_venue' or 'eh_organizer'.
	 * @param string $title     Entity title.
	 * @return int Post ID.
	 */
	private static function make_entity( string $post_type, string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * Unlinked venue: label = legacy free text; linked_entity resolves empty.
	 */
	public function test_unlinked_venue_falls_back_to_free_text(): void {
		$event_id = self::make_event();
		update_post_meta( $event_id, 'eh_venue', 'Hall B' );

		$this->assertSame( 'Hall B', Render_Blocks::venue_label( $event_id ) );

		$linked = Render_Blocks::linked_entity( $event_id, 'eh_venue_id', 'eh_venue' );
		$this->assertSame( 0, $linked['id'] );
		$this->assertSame( '', $linked['title'] );
	}

	/**
	 * Linked venue: label = venue post title (legacy text ignored), summary
	 * carries it so cards/columns/REST/CLI all upgrade at once.
	 */
	public function test_linked_venue_title_wins_in_summary(): void {
		$venue_id = self::make_entity( 'eh_venue', 'Grand Hall' );
		$event_id = self::make_event( '2031-03-01 18:00:00' );
		update_post_meta( $event_id, 'eh_venue', 'Old free text' );
		update_post_meta( $event_id, 'eh_venue_id', (string) $venue_id );

		$this->assertSame( 'Grand Hall', Render_Blocks::venue_label( $event_id ) );

		$summary = Render_Blocks::summary( $event_id );
		$this->assertSame( 'Grand Hall', $summary['venue'] );
	}

	/**
	 * Stale IDs (deleted post, wrong type, non-published, junk) resolve to
	 * empty and the label falls back — a broken link can never blank a card.
	 */
	public function test_stale_ids_fall_back(): void {
		$event_id = self::make_event();
		update_post_meta( $event_id, 'eh_venue', 'Fallback Hall' );

		$deleted_id = self::make_entity( 'eh_venue', 'Doomed' );
		wp_delete_post( $deleted_id, true );

		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'eh_venue',
				'post_status' => 'draft',
				'post_title'  => 'Hidden Hall',
			)
		);
		$other_type_id = self::make_entity( 'eh_organizer', 'Wrong Type' );

		$cases = array(
			(string) $deleted_id,
			(string) $draft_id,
			(string) $other_type_id,
			'999999',
			'12ab',
			'-5',
			'0',
			'not-a-number',
		);

		foreach ( $cases as $case ) {
			update_post_meta( $event_id, 'eh_venue_id', $case );
			$this->assertSame( 0, Render_Blocks::linked_entity( $event_id, 'eh_venue_id', 'eh_venue' )['id'], "ID '{$case}' must resolve empty" );
			$this->assertSame( 'Fallback Hall', Render_Blocks::venue_label( $event_id ), "ID '{$case}' must fall back to free text" );
		}
	}

	/**
	 * Organizer resolution: type-gated the same way; unrelated post types do
	 * not satisfy the eh_organizer gate.
	 */
	public function test_organizer_resolution_type_gated(): void {
		$organizer_id = self::make_entity( 'eh_organizer', 'City Arts Council' );
		$event_id     = self::make_event();

		update_post_meta( $event_id, 'eh_organizer_id', (string) $organizer_id );

		$linked = Render_Blocks::linked_entity( $event_id, 'eh_organizer_id', 'eh_organizer' );
		$this->assertSame( $organizer_id, $linked['id'] );
		$this->assertSame( 'City Arts Council', $linked['title'] );
	}

	/**
	 * JSON-LD: linked venue emits name + address; unlinked keeps the
	 * name-only legacy Place; organizer appears only when linked (never
	 * fabricated from free text).
	 */
	public function test_json_ld_enrichment_and_fallback(): void {
		$structured_data = new Structured_Data();

		// Legacy-only event: name-only Place, no organizer key.
		$legacy_id = self::make_event( '2031-03-01 18:00:00' );
		update_post_meta( $legacy_id, 'eh_venue', 'Legacy Hall' );
		$graph = $structured_data->graph( Render_Blocks::summary( $legacy_id ) );

		$this->assertIsArray( $graph );
		$this->assertSame( 'Place', $graph['location']['@type'] );
		$this->assertSame( 'Legacy Hall', $graph['location']['name'] );
		$this->assertArrayNotHasKey( 'address', $graph['location'] );
		$this->assertArrayNotHasKey( 'organizer', $graph );

		// Linked venue + organizer: address joins the Place, organizer appears.
		$venue_id     = self::make_entity( 'eh_venue', 'Grand Hall' );
		$organizer_id = self::make_entity( 'eh_organizer', 'City Arts Council' );
		update_post_meta( $venue_id, 'eh_venue_address', '12 Mercer St' );

		$linked_id = self::make_event( '2031-04-01 18:00:00' );
		update_post_meta( $linked_id, 'eh_venue_id', (string) $venue_id );
		update_post_meta( $linked_id, 'eh_organizer_id', (string) $organizer_id );

		$graph = $structured_data->graph( Render_Blocks::summary( $linked_id ) );

		$this->assertIsArray( $graph );
		$this->assertSame( 'Grand Hall', $graph['location']['name'] );
		$this->assertSame( '12 Mercer St', $graph['location']['address'] );
		$this->assertSame( 'Organization', $graph['organizer']['@type'] );
		$this->assertSame( 'City Arts Council', $graph['organizer']['name'] );
	}

	/**
	 * Series spawn copies carry the linked-entity IDs so occurrences inherit
	 * the venue/organizer link (additive meta_copy keys).
	 */
	public function test_series_spawn_copies_entity_ids(): void {
		$venue_id     = self::make_entity( 'eh_venue', 'Spawn Hall' );
		$organizer_id = self::make_entity( 'eh_organizer', 'Spawn Org' );

		$seed_id = self::make_event( '2031-05-01 18:00:00' );
		update_post_meta( $seed_id, 'eh_venue_id', (string) $venue_id );
		update_post_meta( $seed_id, 'eh_organizer_id', (string) $organizer_id );
		update_post_meta( $seed_id, 'eh_series_freq', 'daily' );

		$engine = new Series_Engine();
		$engine->materialize( $seed_id );

		$children = get_posts(
			array(
				'post_type'   => 'eh_event',
				'post_parent' => $seed_id,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		$this->assertNotEmpty( $children, 'Series spawn must create occurrences' );

		$child_id = (int) $children[0]->ID;
		$this->assertSame( (string) $venue_id, get_post_meta( $child_id, 'eh_venue_id', true ) );
		$this->assertSame( (string) $organizer_id, get_post_meta( $child_id, 'eh_organizer_id', true ) );
		$this->assertSame( 'Spawn Hall', Render_Blocks::venue_label( $child_id ) );
	}

	/**
	 * Directions URL gate (§5B): junk/relative/non-http values degrade to ''
	 * on the READ side; a valid http(s) URL passes through untouched.
	 *
	 * @return void
	 */
	public function test_directions_url_read_gate(): void {
		$venue_id = self::make_entity( 'eh_venue', 'Gated Hall' );
		$event_id = self::make_event();
		update_post_meta( $event_id, 'eh_venue_id', (string) $venue_id );

		// Unset: ''.
		$this->assertSame( '', Render_Blocks::venue_directions_url( $event_id ) );

		// Relative, scheme-relative, non-http scheme: all '' (the 5A.4 twin
		// case list). Schemeless word junk is NOT in the list: esc_url_raw
		// repairs it to http://… (core localhost fallback) which passes the
		// host check — same accepted behavior the twin tests pin.
		$cases = array(
			'//example.com/map'  => '',
			'ftp://example.com'  => '',
			'javascript:alert(1' => '',
			'/relative/path'     => '',
		);

		foreach ( $cases as $raw => $expected ) {
			update_post_meta( $venue_id, 'eh_venue_directions_url', $raw );
			$this->assertSame( $expected, Render_Blocks::venue_directions_url( $event_id ), "'{$raw}' must degrade to ''" );
		}

		$good = 'https://maps.example.com/dir/?api=1&destination=Grand+Hall';
		update_post_meta( $venue_id, 'eh_venue_directions_url', $good );
		$this->assertSame( $good, Render_Blocks::venue_directions_url( $event_id ) );
	}

	/**
	 * Directions URL requires a LINKED venue: free-text-only events have
	 * nowhere to store a URL — the plugin never fabricates a directions link
	 * from the venue name.
	 *
	 * @return void
	 */
	public function test_directions_url_needs_linked_venue(): void {
		// Free-text venue only.
		$event_id = self::make_event();
		update_post_meta( $event_id, 'eh_venue', 'Name Only Hall' );

		$this->assertSame( '', Render_Blocks::venue_directions_url( $event_id ) );

		// A URL on an UNLINKED venue never leaks through the free text.
		$unlinked_venue_id = self::make_entity( 'eh_venue', 'Unlinked Hall' );
		update_post_meta( $unlinked_venue_id, 'eh_venue_directions_url', 'https://maps.example.com/unlinked' );
		$this->assertSame( '', Render_Blocks::venue_directions_url( $event_id ) );

		// Stale venue id degrades to ''.
		update_post_meta( $event_id, 'eh_venue_id', (string) ( $unlinked_venue_id + 4321 ) );
		$this->assertSame( '', Render_Blocks::venue_directions_url( $event_id ) );

		// Linking the venue lights it up.
		update_post_meta( $event_id, 'eh_venue_id', (string) $unlinked_venue_id );
		$this->assertSame( 'https://maps.example.com/unlinked', Render_Blocks::venue_directions_url( $event_id ) );
	}

	/**
	 * Single-event render: the venue line becomes an anchor when a valid
	 * directions URL exists and stays plain text otherwise.
	 *
	 * @return void
	 */
	public function test_single_render_venue_link(): void {
		$venue_id = self::make_entity( 'eh_venue', 'Linked Hall' );
		$event_id = self::make_event( '2099-06-01 18:00:00' );
		update_post_meta( $event_id, 'eh_venue_id', (string) $venue_id );

		// No URL: plain text venue line.
		$html = Render_Blocks::render_single_event( get_post( $event_id ) );
		$this->assertStringContainsString( 'event-hub-single__venue', $html );
		$this->assertStringNotContainsString( '<a href', $this->venue_line( $html ) );

		// With URL: the venue line anchors to it.
		$url = 'https://maps.example.com/dir?destination=Linked+Hall';
		update_post_meta( $venue_id, 'eh_venue_directions_url', $url );

		$html = Render_Blocks::render_single_event( get_post( $event_id ) );
		$line = $this->venue_line( $html );

		$this->assertStringContainsString( '<a href="' . esc_url( $url ) . '"', $line );
		$this->assertStringContainsString( 'Linked Hall</a>', $line );
	}

	/**
	 * Extracts the single-render venue paragraph from full HTML.
	 *
	 * @param string $html Rendered single-event HTML.
	 * @return string The <p class="event-hub-single__venue"> line.
	 */
	private function venue_line( string $html ): string {
		if ( 1 === preg_match( '/<p class="event-hub-single__venue">.*?<\/p>/s', $html, $m ) ) {
			return (string) $m[0];
		}

		return '';
	}

	/**
	 * Metabox save path (§5B): nonce + capability gates hold; a valid URL
	 * sanitizes into the meta; an emptied field DELETES the meta (clearing
	 * the field truly removes the link).
	 *
	 * @return void
	 */
	public function test_metabox_save_gates_and_delete(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$venue_id = self::make_entity( 'eh_venue', 'Box Hall' );
		$box      = new Venue_Meta_Box();

		// No nonce: no write.
		$_POST['event_hub_venue_directions_url'] = 'https://maps.example.com/no-nonce';
		$box->save( $venue_id, get_post( $venue_id ) );
		$this->assertSame( '', get_post_meta( $venue_id, 'eh_venue_directions_url', true ) );

		// With nonce: URL sanitizes in (whitespace trimmed).
		$_POST['event_hub_venue_directions_nonce'] = wp_create_nonce( 'event_hub_venue_directions' );
		$_POST['event_hub_venue_directions_url']   = '  https://maps.example.com/ok  ';
		$box->save( $venue_id, get_post( $venue_id ) );
		$this->assertSame( 'https://maps.example.com/ok', get_post_meta( $venue_id, 'eh_venue_directions_url', true ) );

		// Subscribed low-privilege user: no write.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['event_hub_venue_directions_url']   = 'https://maps.example.com/subscriber';
		$_POST['event_hub_venue_directions_nonce'] = wp_create_nonce( 'event_hub_venue_directions' );
		$box->save( $venue_id, get_post( $venue_id ) );
		$this->assertSame( 'https://maps.example.com/ok', get_post_meta( $venue_id, 'eh_venue_directions_url', true ) );

		// Back as admin, emptying the field deletes the meta.
		wp_set_current_user( $admin_id );
		$_POST['event_hub_venue_directions_url']   = '   ';
		$_POST['event_hub_venue_directions_nonce'] = wp_create_nonce( 'event_hub_venue_directions' );
		$box->save( $venue_id, get_post( $venue_id ) );
		$this->assertSame( '', get_post_meta( $venue_id, 'eh_venue_directions_url', true ) );

		unset( $_POST['event_hub_venue_directions_url'], $_POST['event_hub_venue_directions_nonce'] );
	}
}
