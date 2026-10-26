<?php
/**
 * Shared server-render helpers for the three Event Hub blocks.
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

use WP_Post;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public API used by the render.php templates under includes/blocks/
 * (docs/ARCHITECTURE.md): summary(), render_card(), render_rsvp_button().
 * All output is escaped.
 */
final class Render_Blocks {

	/**
	 * Builds the normalized event summary. Accepts a post ID or WP_Post.
	 *
	 * @param int|WP_Post $event Event post ID or object.
	 * @return array{id:int,title:string,permalink:string,excerpt:string,start:string,end:string,venue:string,capacity:int|null,counts:array{going:int,waitlist:int},rsvpOpen:bool,categories:string[],category_names:string[]}
	 */
	public static function summary( int|WP_Post $event ): array {
		$post = get_post( $event );

		if ( ! $post instanceof WP_Post ) {
			return array(
				'id'             => 0,
				'title'          => '',
				'permalink'      => '',
				'excerpt'        => '',
				'start'          => '',
				'end'            => '',
				'venue'          => '',
				'capacity'       => null,
				'counts'         => array(
					'going'    => 0,
					'waitlist' => 0,
				),
				'rsvpOpen'       => false,
				'categories'     => array(),
				'category_names' => array(),
			);
		}

		$title     = get_the_title( $post );
		$permalink = (string) get_permalink( $post );
		$excerpt   = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		$excerpt   = (string) wp_specialchars_decode( $excerpt, ENT_QUOTES );
		$venue     = (string) get_post_meta( $post->ID, 'eh_venue', true );

		$counts   = Plugin::instance()->repository()->counts( $post->ID );
		$capacity = self::capacity( $post->ID );

		$rsvp_open = true;

		if ( null !== $capacity && $counts['going'] >= $capacity ) {
			$rsvp_open = false;
		}

		$categories     = array();
		$category_names = array();
		$terms          = get_the_terms( $post, 'eh_event_category' );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[]     = (string) $term->slug;
				$category_names[] = (string) $term->name;
			}
		}

		return array(
			'id'             => (int) $post->ID,
			'title'          => $title,
			'permalink'      => $permalink,
			'excerpt'        => $excerpt,
			'start'          => self::meta_iso( $post->ID, 'eh_start' ),
			'end'            => self::meta_iso( $post->ID, 'eh_end' ),
			'venue'          => $venue,
			'capacity'       => $capacity,
			'counts'         => $counts,
			'rsvpOpen'       => $rsvp_open,
			'categories'     => $categories,
			'category_names' => $category_names,
		);
	}

	/**
	 * Builds the complete escaped card HTML for one event summary.
	 *
	 * Anatomy (PROBLEM-3 editorial design): bold date block (day large, month
	 * small, aria-hidden — the datetime line repeats the date for screen
	 * readers), status pill, title, meta, capacity bar, RSVP form.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return string
	 */
	public static function render_card( array $s ): string {
		if ( empty( $s['id'] ) ) {
			return '';
		}

		$day   = self::date_part( (string) $s['start'], 'j' );
		$month = self::date_part( (string) $s['start'], 'M' );
		$pill  = self::status_pill( $s );
		$past  = $pill['past'];
		$bar   = null !== $s['capacity'] ? self::capacity_bar( $s ) : '';
		$terms = (array) $s['category_names'];
		$thumb = get_the_post_thumbnail(
			(int) $s['id'],
			'medium',
			array(
				'loading' => 'lazy',
				'class'   => 'event-hub-card__img',
			)
		);

		ob_start();
		?>
		<article class="event-hub-card<?php echo $past ? ' event-hub-card--past' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant string. ?>">
			<div class="event-hub-card__layout">
				<?php if ( '' !== $thumb ) : ?>
					<figure class="event-hub-card__thumb"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_post_thumbnail() returns safe core HTML. ?></figure>
				<?php endif; ?>
				<div class="event-hub-card__date" aria-hidden="true">
					<span class="event-hub-card__day"><?php echo esc_html( $day ); ?></span>
					<span class="event-hub-card__month"><?php echo esc_html( $month ); ?></span>
				</div>
				<div class="event-hub-card__body">
					<p class="event-hub-card__pill event-hub-card__pill--<?php echo esc_attr( (string) $pill['key'] ); ?>"><?php echo esc_html( (string) $pill['label'] ); ?></p>
					<h3 class="event-hub-card__title">
						<a href="<?php echo esc_url( (string) $s['permalink'] ); ?>"><?php echo esc_html( (string) $s['title'] ); ?></a>
					</h3>
					<p class="event-hub-card__datetime">
						<time datetime="<?php echo esc_attr( (string) $s['start'] ); ?>"><?php echo esc_html( self::human_range( (string) $s['start'], (string) $s['end'] ) ); ?></time>
					</p>
					<?php if ( '' !== (string) $s['venue'] ) : ?>
						<p class="event-hub-card__venue"><?php echo esc_html( (string) $s['venue'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== (string) $s['excerpt'] ) : ?>
						<p class="event-hub-card__excerpt"><?php echo esc_html( (string) $s['excerpt'] ); ?></p>
					<?php endif; ?>
					<?php if ( array() !== $terms ) : ?>
						<p class="event-hub-card__categories"><?php echo esc_html( implode( ', ', $terms ) ); ?></p>
					<?php endif; ?>
					<?php
					if ( '' !== $bar ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- capacity_bar() escapes internally.
						echo $bar;
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_rsvp_button() escapes internally.
					echo self::render_rsvp_button( (int) $s['id'] );
					?>
				</div>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Formats one date part of an ISO 8601 value ('j' day, 'M' month).
	 * Empty on absent/unparsable input.
	 *
	 * @param string $iso    ISO 8601 datetime.
	 * @param string $format PHP date format character.
	 * @return string
	 */
	private static function date_part( string $iso, string $format ): string {
		if ( '' === $iso ) {
			return '';
		}

		try {
			return ( new \DateTimeImmutable( $iso ) )->format( $format );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Resolves the card status pill: Past > Waitlist (full, list building) >
	 * Sold out (full) > Few spots left (>=80% capacity, not full) > Open.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return array{key:string,label:string,past:bool}
	 */
	private static function status_pill( array $s ): array {
		$past = self::is_past( $s );

		if ( $past ) {
			return array(
				'key'   => 'past',
				'label' => __( 'Past', 'event-hub' ),
				'past'  => true,
			);
		}

		$capacity = null !== $s['capacity'] ? (int) $s['capacity'] : null;
		$going    = (int) $s['counts']['going'];
		$waitlist = (int) $s['counts']['waitlist'];

		if ( null !== $capacity && $going >= $capacity ) {
			// Full: show "Waitlist" once the list has takers, "Sold out" before.
			if ( $waitlist > 0 ) {
				return array(
					'key'   => 'waitlist',
					'label' => __( 'Waitlist', 'event-hub' ),
					'past'  => false,
				);
			}

			return array(
				'key'   => 'soldout',
				'label' => __( 'Sold out', 'event-hub' ),
				'past'  => false,
			);
		}

		if ( null !== $capacity && $capacity > 0 && $going / $capacity >= 0.8 ) {
			return array(
				'key'   => 'few',
				'label' => __( 'Few spots left', 'event-hub' ),
				'past'  => false,
			);
		}

		return array(
			'key'   => 'open',
			'label' => __( 'Open', 'event-hub' ),
			'past'  => false,
		);
	}

	/**
	 * True when the event's end (or start, when no end exists) has passed.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return bool
	 */
	private static function is_past( array $s ): bool {
		$end_ref = self::iso_to_timestamp( (string) $s['end'] );

		if ( null === $end_ref ) {
			$end_ref = self::iso_to_timestamp( (string) $s['start'] );
		}

		return null !== $end_ref && $end_ref < time();
	}

	/**
	 * Capacity progress bar (going of capacity spots), ARIA-progressbar
	 * semantics; percentage capped at 100.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return string
	 */
	private static function capacity_bar( array $s ): string {
		$capacity = max( 0, (int) $s['capacity'] );
		$going    = max( 0, (int) $s['counts']['going'] );
		$pct      = $capacity > 0 ? min( 100, (int) round( ( $going / $capacity ) * 100 ) ) : 100;
		$label    = self::capacity_label( $s );

		return sprintf(
			'<div class="event-hub-card__progress" role="progressbar" aria-valuemin="0" aria-valuemax="%1$s" aria-valuenow="%2$s" aria-valuetext="%3$s"><span class="event-hub-card__progress-fill" style="width:%4$s%%"></span></div>',
			esc_attr( (string) $capacity ),
			esc_attr( (string) min( $going, $capacity ) ),
			esc_attr( $label ),
			esc_attr( (string) $pct )
		);
	}

	/**
	 * Builds the RSVP button markup (iAPI context contract in
	 * docs/ARCHITECTURE.md "iAPI store event-hub").
	 *
	 * UX decision: one [RSVP] (going) button by default; when capacity is
	 * reached (rsvpOpen false, capacity set) the button switches to
	 * [Join waitlist]. Both states use the two actions rsvpGoing /
	 * rsvpWaitlist; exactly one is rendered per state so the frontend store
	 * has a single handler per variant (no data-attribute prop plumbing).
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function render_rsvp_button( int $event_id ): string {
		$event = get_post( $event_id );

		if ( ! $event instanceof WP_Post || 'eh_event' !== $event->post_type || 'publish' !== $event->post_status ) {
			return '';
		}

		$summary  = self::summary( $event );
		$counts   = $summary['counts'];
		$capacity = $summary['capacity'];
		$full     = null !== $capacity && $counts['going'] >= $capacity;
		$past     = self::is_past( $summary );

		$user = wp_get_current_user();

		// Directive-safe state: every data-wp-text/bind target must resolve in
		// PHP too (the server processor replaces JS-only getters with null and
		// wipes server-rendered content — DECISIONS.md #30).
		$going_text = self::count_text( (int) $counts['going'], (int) $counts['waitlist'] );

		$context = array(
			'eventId'   => $event_id,
			'capacity'  => $capacity,
			'counts'    => array(
				'going'    => (int) $counts['going'],
				'waitlist' => (int) $counts['waitlist'],
			),
			'mine'      => null,
			'busy'      => false,
			'feedback'  => '',
			'full'      => $full,
			'rsvpOpen'  => (bool) $summary['rsvpOpen'],
			'countText' => $going_text,
			'name'      => 0 !== $user->ID ? $user->display_name : '',
			'email'     => 0 !== $user->ID ? $user->user_email : '',
		);

		self::seed_interactivity_state( $user );

		ob_start();
		?>
		<div
			class="event-hub-rsvp"
			data-wp-interactive="event-hub"
			<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper-safe attribute output. ?>
		>
			<p class="event-hub-rsvp__count">
				<span data-wp-text="context.countText"><?php echo esc_html( $going_text ); ?></span>
			</p>
			<?php if ( $past ) : ?>
				<p class="event-hub-rsvp__closed"><?php esc_html_e( 'This event has already taken place.', 'event-hub' ); ?></p>
			<?php else : ?>
				<?php if ( $summary['rsvpOpen'] ) : ?>
					<div class="event-hub-rsvp__form">
						<label class="event-hub-rsvp__label" for="event-hub-name-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Name', 'event-hub' ); ?></label>
						<input
							id="event-hub-name-<?php echo esc_attr( (string) $event_id ); ?>"
							class="event-hub-rsvp__input"
							type="text"
							name="event_hub_name"
							autocomplete="name"
							data-wp-bind--value="context.name"
							data-wp-on--input="actions.setName"
						/>
						<label class="event-hub-rsvp__label" for="event-hub-email-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Email', 'event-hub' ); ?></label>
						<input
							id="event-hub-email-<?php echo esc_attr( (string) $event_id ); ?>"
							class="event-hub-rsvp__input"
							type="email"
							name="event_hub_email"
							autocomplete="email"
							data-wp-bind--value="context.email"
							data-wp-on--input="actions.setEmail"
						/>
					</div>
				<?php endif; ?>
				<?php if ( $full ) : ?>
					<button
						type="button"
						class="event-hub-rsvp__button event-hub-rsvp__button--waitlist"
						data-wp-bind--disabled="context.busy"
						data-wp-on--click="actions.rsvpWaitlist"
					><?php esc_html_e( 'Join waitlist', 'event-hub' ); ?></button>
				<?php else : ?>
					<button
						type="button"
						class="event-hub-rsvp__button event-hub-rsvp__button--going"
						data-wp-bind--disabled="context.busy"
						data-wp-on--click="actions.rsvpGoing"
					><?php esc_html_e( 'RSVP', 'event-hub' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
			<p class="event-hub-rsvp__feedback" data-wp-text="context.feedback"></p>
			<noscript>
				<p class="event-hub-rsvp__noscript"><?php esc_html_e( 'Enable JavaScript to RSVP.', 'event-hub' ); ?></p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the event-list block (attributes: categoryId, postsPerPage,
	 * showFilters). Called as render_callback from Plugin::register_blocks().
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_event_list_block( array $attributes ): string {
		$category_slug = isset( $attributes['categoryId'] ) ? sanitize_title( (string) $attributes['categoryId'] ) : '';
		$per_page      = isset( $attributes['postsPerPage'] ) ? max( 1, absint( $attributes['postsPerPage'] ) ) : 6;
		$show_filters  = ! isset( $attributes['showFilters'] ) || (bool) $attributes['showFilters'];

		// No-JS filter (DECISIONS.md #13): the select sits in a GET <form>, so
		// a JavaScript-less submit reloads with ?eh_category=slug. With JS the
		// store fetches REST and swaps cards client-side (filter.ts).
		$get_filter = isset( $_GET['eh_category'] ) ? sanitize_title( wp_unslash( (string) $_GET['eh_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter, no state change.
		if ( '' !== $get_filter ) {
			$category_slug = $get_filter;
		}

		$events = self::upcoming_events( $per_page, '' !== $category_slug ? array( $category_slug ) : array() );

		$terms = get_terms(
			array(
				'taxonomy'   => 'eh_event_category',
				'hide_empty' => true,
			)
		);

		$options = is_array( $terms ) ? $terms : array();

		$event_count = count( $events );
		$found_text  = (string) sprintf(
			/* translators: %s: number of events found. */
			_n( '%s event found', '%s events found', $event_count, 'event-hub' ),
			(string) number_format_i18n( $event_count )
		);

		$context = array(
			'categories'   => array_map(
				static fn( $term ): array => array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				),
				$options
			),
			'selected'     => $category_slug,
			'postsPerPage' => $per_page,
			'foundText'    => $found_text,
			'busy'         => false,
			'hasError'     => false,
		);

		$form_action = esc_url(
			home_url(
				esc_url_raw( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/' )
			)
		);

		self::seed_interactivity_state( wp_get_current_user() );

		ob_start();
		?>
		<div
			<?php echo get_block_wrapper_attributes( array( 'class' => 'event-hub-event-list' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core helper output. ?>
			data-wp-interactive="event-hub"
			data-wp-router-region="event-hub-list"
			data-wp-class--is-busy="context.busy"
			data-wp-class--has-error="context.hasError"
			<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper-safe attribute output. ?>
		>
			<?php if ( $show_filters && array() !== $options ) : ?>
				<form class="event-hub-event-list__filterform" method="get" action="<?php echo esc_url( $form_action ); ?>" data-wp-on--submit="actions.filterSubmit">
					<label class="event-hub-event-list__filterlabel" for="event-hub-filter"><?php esc_html_e( 'Filter by category', 'event-hub' ); ?></label>
					<select
						id="event-hub-filter"
						class="event-hub-event-list__filter"
						name="eh_category"
						data-wp-on--change="actions.filterCategory"
					>
						<option value=""><?php esc_html_e( 'All categories', 'event-hub' ); ?></option>
						<?php foreach ( $options as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $category_slug, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="event-hub-event-list__apply"><?php esc_html_e( 'Apply', 'event-hub' ); ?></button>
				</form>
			<?php endif; ?>
			<p class="event-hub-event-list__found" data-wp-text="context.foundText" role="status" aria-live="polite"><?php echo esc_html( $found_text ); ?></p>
			<div class="event-hub-event-list__grid eh-list-grid">
				<?php
				if ( array() === $events ) {
					printf(
						'<p class="event-hub-event-list__empty">%s</p>',
						esc_html__( 'No upcoming events.', 'event-hub' )
					);
				}

				foreach ( $events as $summary ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_card() escapes internally.
					echo self::render_card( $summary );
				}
				?>
			</div>
			<?php if ( $show_filters && array() !== $options ) : ?>
				<?php // Card skeleton for client-side filtering (filter.ts clones it); slot placeholders only, no live directives. ?>
				<template data-eh-card-tpl>
					<article class="event-hub-card event-hub-card--filtered">
						<div class="event-hub-card__layout">
							<div class="event-hub-card__date" aria-hidden="true">
								<span class="event-hub-card__day" data-eh-slot="day"></span>
								<span class="event-hub-card__month" data-eh-slot="month"></span>
							</div>
							<div class="event-hub-card__body">
								<p class="event-hub-card__pill" data-eh-slot="pill"></p>
								<h3 class="event-hub-card__title"><a data-eh-slot="titlelink" href="#"><span data-eh-slot="title"></span></a></h3>
								<p class="event-hub-card__datetime"><time data-eh-slot="datetime"></time></p>
								<p class="event-hub-card__venue" data-eh-slot="venue"></p>
								<p class="event-hub-card__excerpt" data-eh-slot="excerpt"></p>
								<p class="event-hub-card__categories" data-eh-slot="categories"></p>
								<div class="event-hub-card__progress" role="progressbar"><span class="event-hub-card__progress-fill" data-eh-slot="barfill"></span></div>
								<p class="event-hub-rsvp__count"><span data-eh-slot="count"></span></p>
								<p class="event-hub-card__details"><a data-eh-slot="link" href="#"><?php esc_html_e( 'View details', 'event-hub' ); ?></a></p>
							</div>
						</div>
					</article>
				</template>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the event-card block (attribute: eventId).
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_event_card_block( array $attributes ): string {
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
		$summary  = self::summary( $event_id );

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'event-hub-event-card' ) );

		$card = self::render_card( $summary );

		if ( '' === $card ) {
			return '';
		}

		return '<div ' . $wrapper . '>' . $card . '</div>';
	}

	/**
	 * Renders the event-details view for the plugin's single-event fallback
	 * template (single eh_event posts on themes without their own template).
	 * Reuses the card vocabulary — status pill, capacity bar, category chips
	 * (links to the ?eh_category list filter), RSVP form — at single-page
	 * scale. Add-to-calendar ships with PROBLEM-4.
	 *
	 * @param WP_Post $post The single event post.
	 * @return string
	 */
	public static function render_single_event( WP_Post $post ): string {
		$s = self::summary( $post );

		if ( empty( $s['id'] ) ) {
			return '';
		}

		$day   = self::date_part( (string) $s['start'], 'j' );
		$month = self::date_part( (string) $s['start'], 'M' );
		$pill  = self::status_pill( $s );
		$bar   = null !== $s['capacity'] ? self::capacity_bar( $s ) : '';
		$terms = (array) $s['category_names'];
		$thumb = get_the_post_thumbnail(
			$post,
			'large',
			array(
				'loading' => 'lazy',
				'class'   => 'event-hub-single__img',
			)
		);

		ob_start();
		?>
		<article class="event-hub-single<?php echo $pill['past'] ? ' event-hub-single--past' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant string. ?>">
			<?php if ( '' !== $thumb ) : ?>
				<figure class="event-hub-single__image"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_post_thumbnail() returns safe core HTML. ?></figure>
			<?php endif; ?>
			<header class="event-hub-single__hero">
				<?php if ( '' !== $day ) : ?>
					<div class="event-hub-single__date" aria-hidden="true">
						<span class="event-hub-single__day"><?php echo esc_html( $day ); ?></span>
						<span class="event-hub-single__month"><?php echo esc_html( $month ); ?></span>
					</div>
				<?php endif; ?>
				<div class="event-hub-single__heading">
					<p class="event-hub-single__pill event-hub-card__pill event-hub-card__pill--<?php echo esc_attr( (string) $pill['key'] ); ?>"><?php echo esc_html( (string) $pill['label'] ); ?></p>
					<?php the_title( '<h1 class="event-hub-single__title">', '</h1>' ); ?>
					<p class="event-hub-single__datetime">
						<time datetime="<?php echo esc_attr( (string) $s['start'] ); ?>"><?php echo esc_html( self::human_range( (string) $s['start'], (string) $s['end'] ) ); ?></time>
					</p>
					<?php if ( '' !== (string) $s['venue'] ) : ?>
						<p class="event-hub-single__venue"><?php echo esc_html( (string) $s['venue'] ); ?></p>
					<?php endif; ?>
				</div>
			</header>
			<?php if ( array() !== $terms ) : ?>
				<ul class="event-hub-single__categories event-hub-event-categories">
					<?php foreach ( $s['categories'] as $i => $slug ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( 'eh_category', rawurlencode( (string) $slug ), home_url( '/' ) ) ); ?>"><?php echo esc_html( (string) $terms[ $i ] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( '' !== $bar ) : ?>
				<div class="event-hub-single__capacity">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- capacity_bar() escapes internally.
					echo $bar;
					?>
					<p class="event-hub-single__capacity-label"><?php echo esc_html( self::capacity_label( $s ) ); ?></p>
				</div>
			<?php endif; ?>
			<div class="event-hub-single__content">
				<?php the_content(); ?>
			</div>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_rsvp_button() escapes internally.
			echo self::render_rsvp_button( (int) $s['id'] );
			?>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the rsvp-button block (attribute: eventId).
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_rsvp_button_block( array $attributes ): string {
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;

		$markup = self::render_rsvp_button( $event_id );

		if ( '' === $markup ) {
			return '';
		}

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'event-hub-rsvp-block' ) ) . '>' . $markup . '</div>';
	}

	/**
	 * Renders the event-categories block (attribute: showCounts). Term links
	 * reuse the event-list no-JS filter (?eh_category=slug) so filtering works
	 * with JS disabled; zero terms renders a sensible empty state.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_event_categories_block( array $attributes ): string {
		$show_counts = ! isset( $attributes['showCounts'] ) || (bool) $attributes['showCounts'];

		$terms = get_terms(
			array(
				'taxonomy'   => 'eh_event_category',
				'hide_empty' => true,
			)
		);

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'event-hub-event-categories' ) );

		if ( ! is_array( $terms ) || array() === $terms ) {
			return '<div ' . $wrapper . '><p class="event-hub-event-categories__empty">' .
				esc_html__( 'No categories yet.', 'event-hub' ) . '</p></div>';
		}

		$out = '<div ' . $wrapper . '><ul class="event-hub-event-categories__list">';

		foreach ( $terms as $term ) {
			$url = add_query_arg( 'eh_category', rawurlencode( $term->slug ), home_url( '/' ) );

			$out .= '<li class="event-hub-event-categories__item">';
			$out .= '<a class="event-hub-event-categories__link" href="' . esc_url( $url ) . '">' . esc_html( $term->name );

			if ( $show_counts ) {
				/* translators: %s: number of events in the category. */
				$out .= ' <span class="event-hub-event-categories__count">' . esc_html( sprintf( _x( '(%s)', 'category count wrapper', 'event-hub' ), (string) number_format_i18n( (int) $term->count ) ) ) . '</span>';
			}

			$out .= '</a></li>';
		}

		$out .= '</ul></div>';

		return $out;
	}

	/**
	 * Human-readable date/time range. Same-day events (the common case) show
	 * "date · start–end"; multi-day events show "start date, time – end date,
	 * time" so the FE always exposes the end date (front-end gap found in
	 * review: an overnight/multi-day event displayed only the start date).
	 *
	 * @param string $start_iso ISO 8601 start.
	 * @param string $end_iso   ISO 8601 end.
	 * @return string
	 */
	private static function human_range( string $start_iso, string $end_iso ): string {
		$format_date = get_option( 'date_format' );
		$format_time = get_option( 'time_format' );

		$format_date = is_string( $format_date ) ? $format_date : 'F j, Y';
		$format_time = is_string( $format_time ) ? $format_time : 'g:i a';

		if ( '' === $start_iso ) {
			return '';
		}

		try {
			$start = new \DateTimeImmutable( $start_iso );
			$end   = '' !== $end_iso ? new \DateTimeImmutable( $end_iso ) : null;
		} catch ( \Exception $e ) {
			return '';
		}

		$label = $start->format( $format_date );

		if ( $end instanceof \DateTimeImmutable && $end->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ) {
			// Different calendar days: both dates, each with its time.
			return $start->format( $format_date . ', ' . $format_time )
				. ' – ' . $end->format( $format_date . ', ' . $format_time );
		}

		if ( $end instanceof \DateTimeImmutable ) {
			$label .= ' · ' . $start->format( $format_time );
			$label .= '–' . $end->format( $format_time );
		}

		return $label;
	}

	/**
	 * Builds the live count line ("X going · Y waitlisted").
	 *
	 * @param int $going    Going count.
	 * @param int $waitlist Waitlist count.
	 * @return string
	 */
	private static function count_text( int $going, int $waitlist ): string {
		if ( $going < 1 && $waitlist < 1 ) {
			return __(
				'Be the first to RSVP.',
				'event-hub'
			);
		}

		return (string) sprintf(
			/* translators: 1: number going, 2: number waitlisted. */
			_x( '%1$s going · %2$s waitlisted', 'event count line', 'event-hub' ),
			(string) number_format_i18n( $going ),
			(string) number_format_i18n( $waitlist )
		);
	}

	/**
	 * Seeds the global Interactivity state once per request. Both the list
	 * block and the RSVP button call it: an all-past grid renders no RSVP
	 * buttons, and without this call the client filter would fetch an empty
	 * base URL (the /event-hub/v1 404 observed in testing). Merges are cheap —
	 * wp_interactivity_state() deep-merges repeated calls.
	 *
	 * @param WP_User $user Current user (from render_rsvp_button()'s fetch).
	 * @return void
	 */
	private static function seed_interactivity_state( WP_User $user ): void {
		wp_interactivity_state(
			'event-hub',
			array(
				'restUrl' => esc_url_raw( rest_url() ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'user'    => 0 !== $user->ID ? array(
					'name'  => $user->display_name,
					'email' => $user->user_email,
				) : null,
				'i18n'    => self::view_strings(),
			)
		);
	}

	/**
	 * Loads upcoming published events as summaries, start ASC, end >= now.
	 *
	 * The past filter runs in SQL (meta_query), not PHP: with many past
	 * events heading the start-ASC order, a PHP-only filter let them exhaust
	 * the limit and blank the list (stage-4 BUG 1 — "4 fetched, 0 future"
	 * with 180 upcoming events live). Events with no end meta stay included
	 * (conservative: never silently hide an event on missing data).
	 *
	 * @param int               $limit      Max events.
	 * @param array<int,string> $categories Optional category slugs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function upcoming_events( int $limit, array $categories = array() ): array {
		$now_sql = current_time( 'mysql' );

		$query_args = array(
			'post_type'      => 'eh_event',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, $limit ),
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

		$query    = new \WP_Query( $query_args );
		$now      = time();
		$upcoming = array();

		foreach ( $query->posts as $post ) {
			$summary = self::summary( $post );
			$end_ts  = self::iso_to_timestamp( (string) $summary['end'] );

			if ( null !== $end_ts && $end_ts < $now ) {
				continue;
			}

			$upcoming[] = $summary;
		}

		return $upcoming;
	}

	/**
	 * Reads eh_start/eh_end meta as ISO 8601 (site timezone aware).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key (eh_start|eh_end).
	 * @return string
	 */
	private static function meta_iso( int $post_id, string $meta_key ): string {
		$raw = (string) get_post_meta( $post_id, $meta_key, true );

		if ( '' === trim( $raw ) ) {
			return '';
		}

		// The editor stores ISO (Y-m-d\TH:i:s); WP-CLI/imports often write
		// site format (Y-m-d H:i:s). Accept both, interpret in site timezone.
		$datetime = date_create_immutable_from_format( 'Y-m-d\TH:i:s', $raw, wp_timezone() );

		if ( false === $datetime ) {
			$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );
		}

		if ( false === $datetime ) {
			return '';
		}

		return $datetime->format( 'c' );
	}

	/**
	 * Reads the capacity meta with the REGEXP unsigned guard; missing/empty
	 * means unlimited (null).
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	private static function capacity( int $post_id ): ?int {
		$raw = (string) get_post_meta( $post_id, 'eh_capacity', true );

		return self::parse_capacity( $raw );
	}

	/**
	 * Parses an eh_capacity meta string into a capacity integer. The single
	 * source of truth for "what is a valid capacity", shared with
	 * Rsvp_Repository (SQL side uses the same [0-9]+ REGEXP contract).
	 *
	 * Contract (BUG 5): '' / missing = unlimited (null); a digit-only string
	 * = that number; anything else (junk, negative, "12abc") = unlimited
	 * with the admin list-table warning the operator instead (the meta is
	 * rejected at save-time by the registration schema; legacy junk keeps
	 * the historical unlimited meaning rather than silently re-meaning it).
	 *
	 * @param string $raw Raw meta value.
	 * @return int|null Capacity, or null for unlimited/invalid.
	 */
	public static function parse_capacity( string $raw ): ?int {
		if ( '' === trim( $raw ) || 1 !== preg_match( '/^[0-9]+$/', $raw ) ) {
			return null;
		}

		return (int) $raw;
	}

	/**
	 * Translated "X of Y spots filled" text shared by the bar's ARIA label
	 * and the single-event visible caption.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return string
	 */
	private static function capacity_label( array $s ): string {
		$capacity = max( 0, (int) $s['capacity'] );
		$going    = max( 0, (int) $s['counts']['going'] );

		// Over-capacity legacy data (capacity reduced after publish): public
		// surfaces clamp to "N of N spots filled" so the wording can't claim
		// the impossible "45 of 40" (BUG 6/7). Admin surfaces (list table,
		// CSV export) keep the true counts.
		$going = min( $going, $capacity );

		/* translators: 1: number going, 2: total capacity. */
		return sprintf( __( '%1$s of %2$s spots filled', 'event-hub' ), (string) number_format_i18n( $going ), (string) number_format_i18n( $capacity ) );
	}

	/**
	 * Parses an ISO 8601 string to a Unix timestamp.
	 *
	 * @param string $value ISO value.
	 * @return int|null
	 */
	private static function iso_to_timestamp( string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Every user-facing string the Interactivity view modules can display,
	 * pre-translated server-side (module builds cannot import the classic
	 * i18n package on WP 7.1.3). Keys are the msgids the JS helpers resolve.
	 *
	 * @return array<string,string>
	 */
	public static function view_strings(): array {
		return array(
			'Please enter your name.'                   => __( 'Please enter your name.', 'event-hub' ),
			'Please enter a valid email address.'       => __( 'Please enter a valid email address.', 'event-hub' ),
			"You're already registered."                => __( "You're already registered.", 'event-hub' ),
			'You are registered.'                       => __( 'You are registered.', 'event-hub' ),
			'You are on the waitlist.'                  => __( 'You are on the waitlist.', 'event-hub' ),
			'This event is full — join the waitlist.'   => __( 'This event is full — join the waitlist.', 'event-hub' ),
			'Too many attempts — try again in a few minutes.' => __( 'Too many attempts — try again in a few minutes.', 'event-hub' ),
			'This event has already taken place.'       => __( 'This event has already taken place.', 'event-hub' ),
			'Something went wrong — please try again.'  => __( 'Something went wrong — please try again.', 'event-hub' ),
			'Could not load events — please try again.' => __( 'Could not load events — please try again.', 'event-hub' ),
			/* translators: %s: number of attendees. */
			'%s person going'                           => __( '%s person going', 'event-hub' ),
			/* translators: %s: number of attendees. */
			'%s people going'                           => __( '%s people going', 'event-hub' ),
			/* translators: %s: number of events. */
				'%s event found'                        => __( '%s event found', 'event-hub' ),
			/* translators: %s: number of events. */
			'%s events found'                           => __( '%s events found', 'event-hub' ),
			'Open'                                      => __( 'Open', 'event-hub' ),
			'Few spots left'                            => __( 'Few spots left', 'event-hub' ),
			'Waitlist'                                  => __( 'Waitlist', 'event-hub' ),
			'Sold out'                                  => __( 'Sold out', 'event-hub' ),
			'Past'                                      => __( 'Past', 'event-hub' ),
			/* translators: 1: number going, 2: total capacity. */
			'%1$s of %2$s spots filled'                 => __( '%1$s of %2$s spots filled', 'event-hub' ),
		);
	}
}
