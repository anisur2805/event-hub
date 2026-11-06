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
}
