<?php
/**
 * Tests for Render_Blocks::render_event_categories_block() — BUG-2.
 *
 * Contract: term list with ?eh_category filter links, optional counts,
 * escaped output, and a non-empty zero-terms empty state.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_UnitTestCase;

/**
 * Event Categories block render callback test case.
 */
class Event_Categories_Block_Test extends WP_UnitTestCase {

	/**
	 * Removes the fixture taxonomy terms created by other tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( (array) get_terms(
			array(
				'taxonomy'   => 'eh_event_category',
				'hide_empty' => false,
			)
		) as $term ) {
			if ( $term instanceof \WP_Term ) {
				wp_delete_term( $term->term_id, 'eh_event_category' );
			}
		}

		parent::tear_down();
	}

	/**
	 * Zero terms must render a visible empty state, never an empty string.
	 *
	 * @return void
	 */
	public function test_empty_state_with_zero_terms(): void {
		$out = Render_Blocks::render_event_categories_block( array() );

		$this->assertStringContainsString( 'No categories yet.', $out );
		$this->assertStringContainsString( 'event-hub-event-categories__empty', $out );
		$this->assertSame( 0, substr_count( $out, '<a ' ), 'No links when no terms.' );
	}

	/**
	 * Creates one published event assigned to the given category name/slug.
	 *
	 * @param string $name Term name.
	 * @param string $slug Term slug.
	 * @return int Term ID.
	 */
	private function create_categorized_event( string $name, string $slug ): int {
		$term_ids = wp_set_object_terms(
			self::factory()->post->create(
				array(
					'post_type'   => 'eh_event',
					'post_status' => 'publish',
				)
			),
			$name,
			'eh_event_category'
		);

		// wp_set_object_terms with a name creates the term; fetch its id.
		$term = get_term_by( 'slug', $slug, 'eh_event_category' );

		return $term instanceof \WP_Term ? (int) $term->term_id : (int) ( $term_ids[0] ?? 0 );
	}

	/**
	 * With terms: one link per term carrying the eh_category slug, escaped
	 * name, and count markup when showCounts is true.
	 *
	 * @return void
	 */
	public function test_terms_render_links_and_counts(): void {
		$this->create_categorized_event( 'Music & Arts', 'music-arts' );
		$term2 = $this->create_categorized_event( 'Workshops', 'workshops' );

		$out = Render_Blocks::render_event_categories_block( array( 'showCounts' => true ) );

		$this->assertStringContainsString( 'event-hub-event-categories__list', $out );
		$this->assertSame( 2, substr_count( $out, '<a class="event-hub-event-categories__link"' ) );

		// Slug travels in the query arg for the no-JS list filter.
		$this->assertStringContainsString( 'eh_category=music-arts', $out );

		// The ampersand in the term name must be escaped, not raw.
		$this->assertStringContainsString( 'Music &amp; Arts', $out );
		$this->assertStringNotContainsString( 'Music & Arts</a>', $out );

		// Count wrapper present when showCounts = true.
		$this->assertStringContainsString( 'event-hub-event-categories__count', $out );
	}

	/**
	 * showCounts=false must omit the count span.
	 *
	 * @return void
	 */
	public function test_show_counts_false_omits_count(): void {
		$this->create_categorized_event( 'Solo', 'solo' );

		$out = Render_Blocks::render_event_categories_block( array( 'showCounts' => false ) );

		$this->assertStringContainsString( 'event-hub-event-categories__link', $out );
		$this->assertStringNotContainsString( 'event-hub-event-categories__count', $out );
	}
}
