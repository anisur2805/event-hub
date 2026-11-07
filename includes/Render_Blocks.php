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
	 * @return array{id:int,title:string,permalink:string,excerpt:string,start:string,end:string,venue:string,externalUrl:string,capacity:int|null,counts:array{going:int,waitlist:int},rsvpOpen:bool,status:string,cutoffClosed:bool,cutoffNotOpen:bool,categories:string[],category_names:string[]}
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
				'externalUrl'    => '',
				'capacity'       => null,
				'counts'         => array(
					'going'    => 0,
					'waitlist' => 0,
				),
				'rsvpOpen'       => false,
				'status'         => '',
				'cutoffClosed'   => false,
				'cutoffNotOpen'  => false,
				'categories'     => array(),
				'category_names' => array(),
			);
		}

		$title     = get_the_title( $post );
		$permalink = (string) get_permalink( $post );
		$excerpt   = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		$excerpt   = (string) wp_specialchars_decode( $excerpt, ENT_QUOTES );
		$venue     = self::venue_label( $post->ID );

		$repository = Plugin::instance()->repository();
		$counts     = $repository->counts( $post->ID );
		$capacity   = self::capacity( $post->ID );

		// Cutoff verdicts (2.4): either boundary outside the window kills
		// rsvpOpen; the flags drive the button/pill message choice.
		$cutoffs   = $repository->rsvp_cutoffs( $post->ID );
		$rsvp_open = true;

		// Status (5.3): a cancelled/postponed event has no bookable surface.
		$status = Event_Status::get( $post->ID );

		if ( '' !== $status ) {
			$rsvp_open = false;
		}

		if ( null !== $capacity && $counts['going'] >= $capacity ) {
			$rsvp_open = false;
		}

		if ( $cutoffs['closed'] || $cutoffs['not_open'] ) {
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
			'externalUrl'    => self::external_url( $post->ID ),
			'capacity'       => $capacity,
			'counts'         => $counts,
			'rsvpOpen'       => $rsvp_open,
			'status'         => $status,
			'cutoffClosed'   => $cutoffs['closed'],
			'cutoffNotOpen'  => $cutoffs['not_open'],
			'categories'     => $categories,
			'category_names' => $category_names,
		);
	}

	/**
	 * The venue string every display surface shows (ROADMAP 5.1): the linked
	 * eh_venue post's title when `eh_venue_id` points at a published venue,
	 * else the legacy free-text `eh_venue` meta. One read path so cards,
	 * admin columns, REST and CLI can never drift.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Venue label ('' when unset).
	 */
	public static function venue_label( int $event_id ): string {
		$linked = self::linked_entity( $event_id, 'eh_venue_id', 'eh_venue' );

		return '' !== $linked['title'] ? $linked['title'] : (string) get_post_meta( $event_id, 'eh_venue', true );
	}

	/**
	 * The validated external registration URL for an event (ROADMAP 5A.4),
	 * or '' when the event uses the internal RSVP form. One read path so the
	 * front-end fold, REST surfaces and admin columns can never drift. The
	 * meta schema holds no URL pattern (legitimate links are too varied for
	 * a save-time regex), so THIS is the gate: esc_url_raw + a scheme/host
	 * requirement — junk, relative or non-http values degrade to '' and the
	 * event simply keeps its internal form (same read-side-twin shape as
	 * linked_entity()).
	 *
	 * @param int $event_id Event post ID.
	 * @return string Absolute http(s) URL, or '' when unset/invalid.
	 */
	public static function external_url( int $event_id ): string {
		$raw = trim( (string) get_post_meta( $event_id, 'eh_external_url', true ) );

		if ( '' === $raw ) {
			return '';
		}

		$url = esc_url_raw( $raw );

		if ( '' === $url ) {
			return '';
		}

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}

		return $url;
	}

	/**
	 * The gated online joining URL for a virtual event (ROADMAP 5.6), or ''
	 * when the event has none/it is junk. SECRET-bearing: the meta key is
	 * underscore-prefixed (`_eh_online_url`), which puts it behind core's
	 * protected-meta rule — it can never ride an anonymous /wp/v2 read the
	 * way eh_external_url does. Validity gate is read-side, exactly the
	 * 5A.4 twin (esc_url_raw + http/https + host); implausible saved values
	 * degrade to '' rather than 400ing a legitimate varied link.
	 *
	 * Callers are responsible for the ENTITLEMENT half (who may learn the
	 * URL): the REST read route checks the manage token resolves to a going
	 * row; the manage page renders it only for going rows; emails attach it
	 * only in going-recipient branches. This function itself does NO
	 * entitlement check — do not call it from a public serializer.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Absolute http(s) URL, or '' when unset/invalid.
	 */
	public static function online_url( int $event_id ): string {
		$raw = trim( (string) get_post_meta( $event_id, '_eh_online_url', true ) );

		if ( '' === $raw ) {
			return '';
		}

		$url = esc_url_raw( $raw );

		if ( '' === $url ) {
			return '';
		}

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}

		return $url;
	}

	/**
	 * Whether the event takes place online (5.6): an online URL is set and
	 * valid. The FACT is public (cards badge it, REST/JSON-LD expose it);
	 * only the URL value is gated.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public static function is_virtual( int $event_id ): bool {
		return '' !== self::online_url( $event_id );
	}

	/**
	 * Resolves a linked-entity meta ID to its published post, ignoring stale
	 * or non-existent IDs (fallback lives with the caller). The ID whitelist
	 * is the digits-only read-side twin of the meta schema pattern.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $meta_key Meta key holding the ID ('eh_venue_id' | 'eh_organizer_id').
	 * @param string $post_type Expected entity post type.
	 * @return array{title:string,id:int} Resolved entity ('' / 0 when unlinked or stale).
	 */
	public static function linked_entity( int $event_id, string $meta_key, string $post_type ): array {
		$empty = array(
			'title' => '',
			'id'    => 0,
		);

		$raw = trim( (string) get_post_meta( $event_id, $meta_key, true ) );

		if ( '' === $raw || ! ctype_digit( $raw ) || (int) $raw < 1 ) {
			return $empty;
		}

		$post = get_post( (int) $raw );

		if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return $empty;
		}

		return array(
			'title' => (string) get_the_title( $post ),
			'id'    => (int) $post->ID,
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
					<?php if ( self::is_virtual( (int) $s['id'] ) ) : ?>
						<p class="event-hub-card__online"><?php esc_html_e( 'Online', 'event-hub' ); ?></p>
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
	 * Resolves the card status pill: Cancelled > Postponed > Past > Waitlist
	 * (full, list building) > Sold out (full) > Few spots left (>=80%
	 * capacity, not full) > Open.
	 *
	 * Cancelled/Postponed outrank Past on purpose: a cancelled event never
	 * "took place", so the Past pill would hide what attendees need to see
	 * (5.3); a postponed event is not over either.
	 *
	 * @param array<string,mixed> $s Render_Blocks::summary() output.
	 * @return array{key:string,label:string,past:bool}
	 */
	private static function status_pill( array $s ): array {
		$past = self::is_past( $s );

		// Status (5.3) outranks everything — the state attendees must see.
		// 'past' stays false even for a past cancelled event: the pill is
		// Cancelled, not the muted Past styling.
		$status = (string) ( $s['status'] ?? '' );

		if ( '' !== $status ) {
			return array(
				'key'   => $status,
				'label' => 'cancelled' === $status
					? __( 'Cancelled', 'event-hub' )
					: __( 'Postponed', 'event-hub' ),
				'past'  => false,
			);
		}

		if ( $past ) {
			return array(
				'key'   => 'past',
				'label' => __( 'Past', 'event-hub' ),
				'past'  => true,
			);
		}

		// Cutoff-closed beats capacity states: an event whose registration
		// window shut still has seats, but the button is gone (2.4).
		if ( ! empty( $s['cutoffClosed'] ) ) {
			return array(
				'key'   => 'closed',
				'label' => __( 'Closed', 'event-hub' ),
				'past'  => false,
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
		$closed   = ! empty( $summary['cutoffClosed'] );
		$not_open = ! empty( $summary['cutoffNotOpen'] );
		$status   = (string) $summary['status'];

		$user = wp_get_current_user();

		// Directive-safe state: every data-wp-text/bind target must resolve in
		// PHP too (the server processor replaces JS-only getters with null and
		// wipes server-rendered content — DECISIONS.md #30).
		$going_text = self::count_text( (int) $counts['going'], (int) $counts['waitlist'], $past );

		$context = array(
			'eventId'      => $event_id,
			'capacity'     => $capacity,
			'counts'       => array(
				'going'    => (int) $counts['going'],
				'waitlist' => (int) $counts['waitlist'],
			),
			'mine'         => null,
			'busy'         => false,
			'feedback'     => '',
			'full'         => $full,
			'rsvpOpen'     => (bool) $summary['rsvpOpen'],
			'past'         => $past,
			'countText'    => $going_text,
			'name'         => 0 !== $user->ID ? $user->display_name : '',
			'email'        => 0 !== $user->ID ? $user->user_email : '',
			'guests'       => 1,
			// 5A.1: PHP NEVER pre-renders a token; the store fills this from
			// the 201 body client-side. Server default '' keeps the SSR
			// region directive-safe (every bind target resolves in PHP, #30).
			'manageUrl'    => '',
			'linkCopied'   => false,
			// Filled by the store alongside manageUrl; SSR default stays ''
			// because the panel itself is hidden until the 201 arrives.
			'successTitle' => '',
		);

		self::seed_interactivity_state( $user );

		if ( $past ) {
			// Past events are inert: plain markup only — the count line (when
			// the event drew attendees) plus the closed notice. No iAPI region,
			// no form, no feedback slot: the interactive region's top border +
			// empty feedback painted a stray outlined box on past cards.
			ob_start();
			?>
			<?php if ( '' !== $going_text ) : ?>
				<p class="event-hub-rsvp__count"><?php echo esc_html( $going_text ); ?></p>
			<?php endif; ?>
			<p class="event-hub-rsvp__closed"><?php esc_html_e( 'This event has already taken place.', 'event-hub' ); ?></p>
			<?php
			return (string) ob_get_clean();
		}

		if ( '' !== $status ) {
			// Cancelled/postponed (5.3) are inert like past, but name the
			// state — the write guard would 409 (cancelled) anyway, and a
			// postponed event must not invite signups while its date is
			// unsettled. The count line stays when there are rows.
			ob_start();
			?>
			<?php if ( '' !== $going_text ) : ?>
				<p class="event-hub-rsvp__count"><?php echo esc_html( $going_text ); ?></p>
			<?php endif; ?>
			<p class="event-hub-rsvp__closed">
				<?php
				echo 'cancelled' === $status
					? esc_html__( 'This event has been cancelled.', 'event-hub' )
					: esc_html__( 'This event has been postponed — the new date will be announced.', 'event-hub' );
				?>
			</p>
			<?php
			return (string) ob_get_clean();
		}

		if ( $closed || $not_open ) {
			// Cutoff modes (2.4) are inert like past, but honest about why:
			// the form is gone (the write guard would 409 anyway) and the
			// notice names the state. Count line stays when there are rows.
			ob_start();
			?>
			<?php if ( '' !== $going_text ) : ?>
				<p class="event-hub-rsvp__count"><?php echo esc_html( $going_text ); ?></p>
			<?php endif; ?>
			<p class="event-hub-rsvp__closed">
				<?php echo $not_open ? esc_html__( 'RSVPs have not opened yet.', 'event-hub' ) : esc_html__( 'RSVPs are closed.', 'event-hub' ); ?>
			</p>
			<?php
			return (string) ob_get_clean();
		}

		/*
		 * 5A.4 external registration: a validated eh_external_url folds the
		 * whole interactive region to ONE outbound link — no iAPI context,
		 * no directives, no form, so a third-party page can never fight the
		 * store and #30 has no bind targets to satisfy. Count line still
		 * renders (social proof); capacity math stays server-side but no
		 * internal RSVP can be created (the REST route is unreachable from
		 * this markup and create() remains the only write path for rows).
		 */
		$external_url = (string) ( $summary['externalUrl'] ?? '' );

		if ( '' !== $external_url ) {
			ob_start();
			?>
			<div class="event-hub-rsvp event-hub-rsvp--external">
				<?php if ( '' !== $going_text ) : ?>
					<p class="event-hub-rsvp__count"><?php echo esc_html( $going_text ); ?></p>
				<?php endif; ?>
				<a
					class="event-hub-rsvp__button event-hub-rsvp__register"
					href="<?php echo esc_url( $external_url ); ?>"
					target="_blank"
					rel="nofollow noopener noreferrer"
				><?php esc_html_e( 'Register', 'event-hub' ); ?></a>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		ob_start();
		?>
		<div
			class="event-hub-rsvp"
			data-wp-interactive="event-hub"
			<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper-safe attribute output. ?>
		>
			<?php if ( '' !== $going_text ) : ?>
				<p class="event-hub-rsvp__count">
					<span data-wp-text="context.countText"><?php echo esc_html( $going_text ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( $summary['rsvpOpen'] || $full ) : ?>
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
						<?php
						// Seats per RSVP (2.3): 1..MAX_GUESTS. Plain <select>
						// wired to context via two directives; PHP renders every
						// option so the no-JS markup stays complete.
						?>
						<label class="event-hub-rsvp__label" for="event-hub-guests-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Seats', 'event-hub' ); ?></label>
						<select
							id="event-hub-guests-<?php echo esc_attr( (string) $event_id ); ?>"
							class="event-hub-rsvp__input"
							data-wp-bind--value="context.guests"
							data-wp-on--input="actions.setGuests"
						>
							<?php for ( $seat = 1; $seat <= Rsvp_Repository::MAX_GUESTS; $seat++ ) : ?>
								<option value="<?php echo esc_attr( (string) $seat ); ?>"><?php echo esc_html( (string) number_format_i18n( $seat ) ); ?></option>
							<?php endfor; ?>
						</select>
						<?php
						// Honeypot + time-trap token (T15, Form_Guard). The trap
						// field is visually hidden (CSS clip); the token is opaque.
						?>
						<input
							type="text"
							name="<?php echo esc_attr( Form_Guard::HONEYPOT_FIELD ); ?>"
							value=""
							class="event-hub-rsvp__hp"
							tabindex="-1"
							autocomplete="off"
							aria-hidden="true"
						/>
						<input
							type="hidden"
							name="<?php echo esc_attr( Form_Guard::TOKEN_FIELD ); ?>"
							value="<?php echo esc_attr( Form_Guard::issue_token( $event_id ) ); ?>"
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
			<p class="event-hub-rsvp__feedback" data-wp-text="context.feedback"></p>
			<?php
			/*
			 * 5A.1 success panel. Hidden server-side (empty manageUrl +
			 * hidden class); the store fills the URL from the 201 and both
			 * binds flip together. Without JS it never renders visible —
			 * the form above stays the RSVP surface (no-JS honesty, #30).
			 * The confirmation emails stay opt-in: this panel works either
			 * way, because the token came in the 201 body.
			 */
			?>
			<div
				class="event-hub-rsvp__success"
				data-wp-bind--hidden="!context.manageUrl"
				data-wp-class--event-hub-rsvp__success--active="context.manageUrl"
			>
				<p class="event-hub-rsvp__success-title" data-wp-text="context.successTitle"></p>
				<p class="event-hub-rsvp__success-help"><?php esc_html_e( 'Keep this link — it manages your booking.', 'event-hub' ); ?></p>
				<p class="event-hub-rsvp__success-link">
					<a data-wp-bind--href="context.manageUrl" data-wp-text="context.manageUrl" href=""></a>
				</p>
				<p>
					<button
						type="button"
						class="event-hub-rsvp__button event-hub-rsvp__button--copy"
						data-wp-on--click="actions.copyManageLink"
						data-wp-text="context.linkCopied ? '<?php echo esc_js( __( 'Copied!', 'event-hub' ) ); ?>' : '<?php echo esc_js( __( 'Copy link', 'event-hub' ) ); ?>'"
					><?php esc_html_e( 'Copy link', 'event-hub' ); ?></button>
				</p>
				<p class="event-hub-rsvp__success-hint"><?php esc_html_e( 'You can also cancel or rename from the link at any time.', 'event-hub' ); ?></p>
			</div>
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

		// No-JS filter (DECISIONS.md #13): the controls sit in a GET <form>, so
		// a JavaScript-less submit reloads with ?eh_category=slug&eh_q=…&…. With
		// JS the store fetches REST and swaps cards client-side (filter.ts).
		// 5.5 adds keyword (eh_q), month (eh_list_month) and venue (eh_venue).
		// The list does NOT reuse the calendar's ?eh_month var: a calendar
		// block elsewhere on the page navigates months with it, and a shared
		// var would make calendar nav silently filter the list too.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filters, no state change.
		$get_filter = isset( $_GET['eh_category'] ) ? sanitize_title( wp_unslash( (string) $_GET['eh_category'] ) ) : '';
		if ( '' !== $get_filter ) {
			$category_slug = $get_filter;
		}

		$filters = array(
			'search' => isset( $_GET['eh_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eh_q'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter.
			'month'  => isset( $_GET['eh_list_month'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eh_list_month'] ) ) : '',
			'venue'  => isset( $_GET['eh_venue'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eh_venue'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter.
		);

		$events = self::upcoming_events( $per_page, '' !== $category_slug ? array( $category_slug ) : array(), $filters );

		$terms = get_terms(
			array(
				'taxonomy'   => 'eh_event_category',
				'hide_empty' => true,
			)
		);

		$options = is_array( $terms ) ? $terms : array();

		// Venue select options (5.5): venues actually linked to an UPCOMING
		// event — a picker listing venues with no upcoming events reads as
		// broken and filters to a guaranteed-empty list. The CPT is bounded;
		// one bounded ids re-query + object-cached meta reads beat raw SQL.
		// Only runs when filters are shown (no reason to pay otherwise).
		$venue_options = array();

		if ( $show_filters ) {
			$id_args           = self::upcoming_query_args( 200, array(), array() );
			$id_args['fields'] = 'ids';
			$id_query          = new \WP_Query( $id_args );

			$used_venue_ids = array();

			foreach ( $id_query->posts as $event_id ) {
				$venue_id = (string) get_post_meta( (int) $event_id, 'eh_venue_id', true );

				if ( '' !== $venue_id && ctype_digit( $venue_id ) ) {
					$used_venue_ids[ (int) $venue_id ] = true;
				}
			}

			foreach ( array_keys( $used_venue_ids ) as $venue_id ) {
				$venue_post = get_post( $venue_id );

				if ( $venue_post instanceof \WP_Post && 'eh_venue' === $venue_post->post_type && 'publish' === $venue_post->post_status ) {
					$venue_options[] = array(
						'id'    => $venue_id,
						'title' => (string) $venue_post->post_title,
					);
				}
			}

			usort(
				$venue_options,
				static fn ( array $a, array $b ): int => strcasecmp( $a['title'], $b['title'] )
			);
		}

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
			'search'       => $filters['search'],
			'venue'        => $filters['venue'],
			'month'        => $filters['month'],
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
			<?php if ( $show_filters ) : ?>
				<?php // The form shows whenever filters are on — search needs no terms; selects appear only with options. ?>
				<form class="event-hub-event-list__filterform" method="get" action="<?php echo esc_url( $form_action ); ?>" data-wp-on--submit="actions.filterSubmit">
					<?php if ( array() !== $options ) : ?>
						<label class="event-hub-event-list__filterlabel" for="event-hub-filter"><?php esc_html_e( 'Category', 'event-hub' ); ?></label>
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
					<?php endif; ?>
					<?php if ( array() !== $venue_options ) : ?>
						<label class="event-hub-event-list__filterlabel" for="event-hub-venue-filter"><?php esc_html_e( 'Venue', 'event-hub' ); ?></label>
						<select
							id="event-hub-venue-filter"
							class="event-hub-event-list__filter"
							name="eh_venue"
							data-wp-on--change="actions.filterCategory"
						>
							<option value=""><?php esc_html_e( 'All venues', 'event-hub' ); ?></option>
							<?php foreach ( $venue_options as $venue_option ) : ?>
								<option value="<?php echo esc_attr( (string) $venue_option['id'] ); ?>" <?php selected( (string) $filters['venue'], (string) $venue_option['id'] ); ?>><?php echo esc_html( $venue_option['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<label class="event-hub-event-list__filterlabel" for="event-hub-search"><?php esc_html_e( 'Search', 'event-hub' ); ?></label>
					<input
						type="search"
						id="event-hub-search"
						class="event-hub-event-list__filter event-hub-event-list__search"
						name="eh_q"
						value="<?php echo esc_attr( $filters['search'] ); ?>"
						placeholder="<?php esc_attr_e( 'Keyword…', 'event-hub' ); ?>"
					/>
					<label class="event-hub-event-list__filterlabel" for="event-hub-month"><?php esc_html_e( 'Month', 'event-hub' ); ?></label>
					<input
						type="month"
						id="event-hub-month"
						class="event-hub-event-list__filter event-hub-event-list__month"
						name="eh_list_month"
						value="<?php echo esc_attr( $filters['month'] ); ?>"
					/>
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
			<?php if ( $show_filters ) : ?>
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
								<p class="event-hub-card__online" data-eh-slot="online" hidden></p>
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
	 * Resolves the calendar's viewing month (ROADMAP §3.3): the
	 * `eh_month=YYYY-MM` GET value when valid, else the block's initialMonth
	 * attribute (YYYY-MM), else the current month. One resolver for both the
	 * no-JS GET navigation and the server-rendered initial state; the client
	 * store echoes the same format.
	 *
	 * @param string $initial_month Block attribute (YYYY-MM or '').
	 * @return array{year:int,month:int,iso:string} iso = YYYY-MM.
	 */
	public static function calendar_month( string $initial_month = '' ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector, no state change.
		$get_month = isset( $_GET['eh_month'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eh_month'] ) ) : '';

		$candidates = array( $get_month, $initial_month );

		foreach ( $candidates as $candidate ) {
			if ( 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $candidate ) ) {
				return array(
					'year'  => (int) substr( $candidate, 0, 4 ),
					'month' => (int) substr( $candidate, 5, 2 ),
					'iso'   => $candidate,
				);
			}
		}

		// Site-clock "now" (time() + gmt_offset): the same frame the stored
		// meta and the grid run in. Not a UTC timestamp — deliberate.
		$now = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

		return array(
			'year'  => (int) gmdate( 'Y', $now ),
			'month' => (int) gmdate( 'n', $now ),
			'iso'   => gmdate( 'Y-m', $now ),
		);
	}

	/**
	 * Loads events whose START falls inside the half-open month window
	 * [first day 00:00, first day of next month 00:00) — standard calendar
	 * semantics (an event belongs to the month it starts in, even if it runs
	 * into the next). Published only; the query is uncached cheap per render
	 * on a bounded CPT.
	 *
	 * @param array{year:int,month:int} $month Month to load.
	 * @return array<int,array<string,mixed>> summary() shapes, start ASC.
	 */
	public static function calendar_month_events( array $month ): array {
		// Window bounds in SITE time: stored eh_start meta is site-local, so
		// the comparison must be in the same frame (a UTC+X site would have
		// shifted every calendar day boundary by X hours otherwise). The
		// shared helper builds [first 00:00:00, last 23:59:59] — the closed
		// form of the half-open month window.
		list( $after_sql, $before_sql ) = self::month_window_sql( $month['year'], $month['month'] );

		$query = new \WP_Query(
			array(
				'post_type'      => 'eh_event',
				'post_status'    => 'publish',
				// A month grid must show a full month, not the first page of
				// one; the CPT is bounded (an events site rarely tops this).
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- full-month grid is the feature.
				'orderby'        => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chronology sort on a bounded CPT.
				'meta_key'       => 'eh_start',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the month filter IS the feature.
				'meta_query'     => array(
					array(
						'key'     => 'eh_start',
						'value'   => array( $after_sql, $before_sql ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		return array_map( array( self::class, 'summary' ), is_array( $query->posts ) ? $query->posts : array() );
	}

	/**
	 * Renders the event-calendar block (attribute: initialMonth, ROADMAP
	 * §3.3): a server-rendered month grid of published events keyed by the
	 * START day in the site timezone. No-JS navigation uses prev/next GET
	 * links carrying `eh_month=YYYY-MM`; with JS the calendar action
	 * (calendar.ts) swallows the click, fetches the same URL and swaps only
	 * the `.event-hub-calendar__body` child — the head with the nav links is
	 * never swapped, so its directives stay hydrated for every later click.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_calendar_block( array $attributes ): string {
		$month_iso = isset( $attributes['initialMonth'] ) ? sanitize_text_field( (string) $attributes['initialMonth'] ) : '';

		$month  = self::calendar_month( $month_iso );
		$events = self::calendar_month_events( $month );

		// Column order honors the site's start_of_week option (0 Sunday …
		// 6 Saturday); day 1 sits in column (w - start_of_week + 7) % 7.
		$start_of_week = (int) get_option( 'start_of_week' );

		// Site-timezone anchors at NOON: day-1 anchors cannot hit the
		// "-1 month from the 31st" overflow (modify() from the 1st always
		// lands on the 1st), and noon dodges DST edges at midnight.
		$anchor = static function ( string $time ): \DateTimeImmutable {
			return new \DateTimeImmutable( $time, wp_timezone() );
		};

		$first = $anchor( sprintf( '%04d-%02d-01 12:00:00', $month['year'], $month['month'] ) );

		$leading_blanks = ( ( (int) $first->format( 'w' ) ) - $start_of_week + 7 ) % 7;
		$days_in_month  = (int) $first->format( 't' );

		$prev_iso = $first->modify( '-1 month' )->format( 'Y-m' );
		$next_iso = $first->modify( '+1 month' )->format( 'Y-m' );

		$base_url = home_url( esc_url_raw( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/' ) );
		$base_url = remove_query_arg( 'eh_month', $base_url );

		$month_label = wp_date( 'F Y', $first->getTimestamp() );

		// Group the flat summary list by day-of-month. summary()['start'] is
		// ISO 8601 WITH the site offset (or parsed against the site zone when
		// offset-less), so format('j') is the site-local calendar day.
		$by_day = array();

		foreach ( $events as $summary ) {
			try {
				$start_dt = new \DateTimeImmutable( (string) $summary['start'], wp_timezone() );
			} catch ( \Exception $e ) {
				continue;
			}

			$by_day[ (int) $start_dt->format( 'j' ) ][] = $summary;
		}

		$event_count = count( $events );

		// "Today" marker in site time — the frame the grid indexes in.
		$now_dt      = new \DateTimeImmutable( 'now', wp_timezone() );
		$today_month = $now_dt->format( 'Y-m' );
		$today_day   = (int) $now_dt->format( 'j' );

		$context = array(
			'busy' => false,
		);

		ob_start();
		?>
		<div
			<?php echo get_block_wrapper_attributes( array( 'class' => 'event-hub-event-calendar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core helper output. ?>
			data-wp-interactive="event-hub"
			data-wp-router-region="event-hub-calendar"
			data-wp-class--is-busy="context.busy"
			<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper-safe attribute output. ?>
		>
			<div class="event-hub-calendar__head">
				<a
					class="event-hub-calendar__nav event-hub-calendar__nav--prev"
					href="<?php echo esc_url( add_query_arg( 'eh_month', $prev_iso, $base_url ) ); ?>"
					data-wp-on--click="actions.calendarNav"
				>&larr; <?php echo esc_html( wp_date( 'F Y', $first->modify( '-1 month' )->getTimestamp() ) ); ?></a>
				<a
					class="event-hub-calendar__nav event-hub-calendar__nav--next"
					href="<?php echo esc_url( add_query_arg( 'eh_month', $next_iso, $base_url ) ); ?>"
					data-wp-on--click="actions.calendarNav"
				><?php echo esc_html( wp_date( 'F Y', $first->modify( '+1 month' )->getTimestamp() ) ); ?> &rarr;</a>
			</div>

			<div class="event-hub-calendar__body">
				<p class="event-hub-calendar__label" aria-live="polite"><?php echo esc_html( $month_label ); ?></p>

				<table class="event-hub-calendar__grid" role="grid">
					<thead>
						<tr>
							<?php
							// Weekday labels: 2023-01-01 is a Sunday; column i
							// is (start_of_week + i) % 7 days after it. The
							// weekday of a fixed date is timezone-independent.
							$sunday = $anchor( '2023-01-01 12:00:00' );

							for ( $i = 0; $i < 7; $i++ ) {
								$dow = ( $start_of_week + $i ) % 7;
								$ts  = $sunday->modify( "+{$dow} day" )->getTimestamp();

								printf(
									'<th scope="col" aria-label="%1$s">%2$s</th>',
									esc_attr( wp_date( 'l', $ts ) ),
									esc_html( wp_date( 'D', $ts ) )
								);
							}
							?>
						</tr>
					</thead>
					<tbody>
						<?php
						$day = 1 - $leading_blanks;

						while ( $day <= $days_in_month ) {
							echo '<tr>';

							for ( $col = 0; $col < 7; $col++, $day++ ) {
								if ( $day < 1 || $day > $days_in_month ) {
									echo '<td class="event-hub-calendar__cell event-hub-calendar__cell--pad"></td>';
									continue;
								}

								$day_events = $by_day[ $day ] ?? array();
								$classes    = 'event-hub-calendar__cell';

								if ( $today_month === $month['iso'] && $today_day === $day ) {
									$classes .= ' event-hub-calendar__cell--today';
								}

								printf( '<td class="%s">', esc_attr( $classes ) );
								printf(
									'<span class="event-hub-calendar__daynum" aria-hidden="true">%s</span>',
									esc_html( (string) number_format_i18n( $day ) )
								);

								if ( array() !== $day_events ) {
									echo '<ul class="event-hub-calendar__events">';

									foreach ( $day_events as $day_summary ) {
										printf(
											'<li class="event-hub-calendar__event"><a href="%1$s">%2$s</a></li>',
											esc_url( (string) $day_summary['permalink'] ),
											esc_html( (string) $day_summary['title'] )
										);
									}

									echo '</ul>';
								}

								echo '</td>';
							}

							echo '</tr>';
						}
						?>
					</tbody>
				</table>

				<p class="event-hub-calendar__count"><?php echo esc_html( self::calendar_count_text( $event_count ) ); ?></p>

				<noscript>
					<p class="event-hub-calendar__noscript"><?php esc_html_e( 'Use the month links to browse other months.', 'event-hub' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shared "N events this month" line for the calendar footer.
	 *
	 * @param int $count Events in the rendered month.
	 * @return string
	 */
	public static function calendar_count_text( int $count ): string {
		return (string) sprintf(
			/* translators: %s: number of events. */
			_n( '%s event this month', '%s events this month', $count, 'event-hub' ),
			(string) number_format_i18n( $count )
		);
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
	 * "Add to calendar" links for the single-event page (ROADMAP 5A.3):
	 * Google Calendar's template URL and the Outlook web compose deep link,
	 * both plain GET URLs — no JS, no third-party script, the visitor just
	 * follows a link. Data comes from the same summary() fields the ICS
	 * feed uses, so the two surfaces never disagree. Datetimes are sent in
	 * each provider's documented UTC form (Google: compact ICS as in the
	 * ICS feed; Outlook: dashed ISO 8601) — an offset form would carry a
	 * raw '+' that decodes as a space provider-side. Every value is
	 * rawurlencode()d (add_query_arg does NOT encode values, so a title
	 * '&' would break the provider URL). Past events and events without a
	 * start render nothing (same honesty rule as the ICS feed).
	 *
	 * @param array<string,mixed> $s Event summary (Render_Blocks::summary()).
	 * @return string Markup or '' when nothing to offer.
	 */
	public static function calendar_links( array $s ): string {
		$start = (string) ( $s['start'] ?? '' );
		$end   = (string) ( $s['end'] ?? '' );

		if ( '' === $start ) {
			return '';
		}

		// Past events: the date block already says it is over (pill + past
		// modifier); an add-to-calendar link would be noise.
		if ( self::is_past( $s ) ) {
			return '';
		}

		try {
			$start_dt = new \DateTimeImmutable( $start );
			$end_dt   = '' !== $end ? new \DateTimeImmutable( $end ) : null;
		} catch ( \Exception $e ) {
			return ''; // Unparseable clock: never render a wrong-date link.
		}

		// summary() may hold wp-injected entities (&amp;); the ICS exporter
		// decodes before embedding — same rule here, or Google would file
		// the event under "Launch Night &amp; Signals".
		$title     = html_entity_decode( (string) ( $s['title'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$venue     = html_entity_decode( (string) ( $s['venue'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$permalink = (string) ( $s['permalink'] ?? '' );
		$excerpt   = wp_strip_all_tags( html_entity_decode( (string) ( $s['excerpt'] ?? '' ), ENT_QUOTES, 'UTF-8' ) );

		// The description carries the event page URL so the calendar entry
		// links back (mirrors the ICS DESCRIPTION approach).
		$description = '' !== $permalink
			? trim( $excerpt . "\n\n" . $permalink )
			: trim( $excerpt );

		$utc = new \DateTimeZone( 'UTC' );

		$calendar_args = array(
			'action'   => 'TEMPLATE',
			'text'     => $title,
			'dates'    => $start_dt->setTimezone( $utc )->format( 'Ymd\THis\Z' )
				. ( null !== $end_dt ? '/' . $end_dt->setTimezone( $utc )->format( 'Ymd\THis\Z' ) : '' ),
			'details'  => $description,
			'location' => $venue,
		);

		$outlook_args = array(
			'path'     => '/calendar/action/compose',
			'rru'      => 'addevent',
			'subject'  => $title,
			'startdt'  => $start_dt->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ),
			'body'     => $description,
			'location' => $venue,
		);

		if ( null !== $end_dt ) {
			$outlook_args['enddt'] = $end_dt->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' );
		}

		$google  = 'https://calendar.google.com/calendar/render?' . self::cal_query( $calendar_args );
		$outlook = 'https://outlook.live.com/calendar/0/deeplink/compose?' . self::cal_query( $outlook_args );

		ob_start();
		?>
		<div class="event-hub-single__cal">
			<a class="event-hub-single__cal-link" href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add to Google Calendar', 'event-hub' ); ?></a>
			<a class="event-hub-single__cal-link" href="<?php echo esc_url( $outlook ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add to Outlook', 'event-hub' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Builds one encoded query string for the calendar provider URLs:
	 * rawurlencode per value (a title '&' becomes %26 instead of a param
	 * break), empty values skipped entirely.
	 *
	 * @param array<string,string> $params Parameter map (key => raw value).
	 * @return string 'key=value&key=value' (values already encoded).
	 */
	private static function cal_query( array $params ): string {
		$pairs = array();

		foreach ( $params as $key => $value ) {
			if ( '' !== (string) $value ) {
				$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}

		return implode( '&', $pairs );
	}

	/**
	 * Renders the event-details view for the plugin's single-event fallback
	 * template (single eh_event posts on themes without their own template).
	 * Reuses the card vocabulary — status pill, capacity bar, category chips
	 * (links to the ?eh_category list filter), RSVP form — at single-page
	 * scale, plus the 5A.3 add-to-calendar links.
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
					<?php if ( self::is_virtual( (int) $s['id'] ) ) : ?>
						<?php // 5.6: public FACT only — joining details reach confirmed attendees via the manage page and emails (docblock DECISIONS #58). ?>
						<p class="event-hub-single__online"><?php esc_html_e( 'Takes place online — joining details are sent to confirmed attendees.', 'event-hub' ); ?></p>
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
			// 5A.3 add-to-calendar links (past events render '').
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- calendar_links() escapes internally.
			echo self::calendar_links( $s );
			?>
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
	 * @param int  $going    Going count.
	 * @param int  $waitlist Waitlist count.
	 * @param bool $past     Past events with zero rows render no count line.
	 * @return string
	 */
	private static function count_text( int $going, int $waitlist, bool $past = false ): string {
		if ( $past && $going < 1 && $waitlist < 1 ) {
			return '';
		}

		if ( $going < 1 && $waitlist < 1 ) {
			return __(
				'Be the first to RSVP.',
				'event-hub'
			);
		}

		return (string) sprintf(
			/* translators: 1: number going, 2: number of waitlisted seats. */
			_nx(
				'%1$s going · %2$s seat waitlisted',
				'%1$s going · %2$s seats waitlisted',
				$waitlist,
				'event count line',
				'event-hub'
			),
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
	 * 5.5 filters (all AND-combined with the upcoming window, all optional):
	 * - categories: slugs (existing param).
	 * - search: keyword over title/content/excerpt (WP_Query 's'); the query
	 *   matches ANY of them, which is what "search events" means.
	 * - month: YYYY-MM; events whose START falls in that month (same
	 *   window semantics as calendar_month_events()).
	 * - venue: digits = linked venue post ID (eh_venue_id); free text =
	 *   LIKE over the legacy eh_venue meta.
	 *
	 * The WP_Query argument construction itself lives in
	 * upcoming_query_args(); the REST list_events() reuses it so the block
	 * and the API can never disagree on what "upcoming" or a filter means
	 * (the stage-4 lesson: these paths DID drift once before).
	 *
	 * @param int                 $limit      Max events.
	 * @param array<int,string>   $categories Optional category slugs.
	 * @param array<string,mixed> $filters  Optional search/month/venue filters.
	 * @return array<int,array<string,mixed>>
	 */
	public static function upcoming_events( int $limit, array $categories = array(), array $filters = array() ): array {
		$query_args = self::upcoming_query_args( $limit, $categories, $filters );
		$query      = new \WP_Query( $query_args );
		$now        = time();
		$upcoming   = array();

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
	 * Builds the WP_Query argument array shared by upcoming_events() and the
	 * REST list_events() (5.5): upcoming window (end >= now OR no end), an
	 * optional category tax_query, and the optional search / month / venue
	 * filters.
	 *
	 * The meta_query shape: the base window is a nested two-clause OR
	 * GROUP inside a top-level AND (WP_Query's default relation). The
	 * month/venue clauses append at TOP level so they AND against the
	 * window — appending under the OR relation would let a past event
	 * match a venue filter alone.
	 *
	 * Every value is sanitized here; callers pass raw request input.
	 *
	 * @param int                 $limit      Max events.
	 * @param array<int,string>   $categories Optional category slugs.
	 * @param array<string,mixed> $filters    Optional search/month/venue.
	 * @return array<string,mixed> WP_Query args.
	 */
	public static function upcoming_query_args( int $limit, array $categories = array(), array $filters = array() ): array {
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
				'relation' => 'AND',
				// Windows group: end >= now OR no end (nested so later
				// top-level clauses AND against the whole group).
				array(
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

		$search = isset( $filters['search'] ) ? sanitize_text_field( (string) $filters['search'] ) : '';

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$month = isset( $filters['month'] ) ? (string) $filters['month'] : '';

		if ( 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			$window = self::month_window_sql( (int) substr( $month, 0, 4 ), (int) substr( $month, 5, 2 ) );

			$query_args['meta_query'][] = array(
				'key'     => 'eh_start',
				'value'   => $window,
				'compare' => 'BETWEEN',
				'type'    => 'DATETIME',
			);
		}

		$venue = isset( $filters['venue'] ) ? trim( (string) $filters['venue'] ) : '';

		if ( '' !== $venue ) {
			if ( ctype_digit( $venue ) ) {
				// Linked venue: eh_venue_id is the digits-only contract path.
				$query_args['meta_query'][] = array(
					'key'   => 'eh_venue_id',
					'value' => $venue,
				);
			} else {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_compare -- free-text venue filter is the feature.
				$query_args['meta_query'][] = array(
					'key'     => 'eh_venue',
					'value'   => $venue,
					'compare' => 'LIKE',
				);
			}
		}

		return $query_args;
	}

	/**
	 * Site-local [first 00:00:00, last 23:59:59] BETWEEN bounds for a month,
	 * shared by calendar_month_events() (grid) and the 5.5 month filter —
	 * one definition of "which events start in month X".
	 *
	 * @param int $year  Year (4 digits).
	 * @param int $month Month 1-12.
	 * @return array{0:string,1:string} SQL datetime bounds.
	 */
	private static function month_window_sql( int $year, int $month ): array {
		$window_start = gmmktime( 0, 0, 0, $month, 1, $year );
		$window_end   = gmmktime( 0, 0, 0, 12 === $month ? 1 : $month + 1, 1, 12 === $month ? $year + 1 : $year );

		$format = 'Y-m-d H:i:s';
		$offset = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

		// Site-local wall-clock of the window edges; the -1s trims
		// next-month midnight, which BETWEEN's inclusive edge would capture.
		return array(
			gmdate( $format, $window_start + $offset ),
			gmdate( $format, $window_end - 1 + $offset ),
		);
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
			/* translators: 1: number going, 2: number of waitlisted seats. */
			'%1$s going · %2$s seat waitlisted'         => _x(
				'%1$s going · %2$s seat waitlisted',
				'event count line',
				'event-hub'
			),
			/* translators: 1: number going, 2: number of waitlisted seats. */
			'%1$s going · %2$s seats waitlisted'        => _x(
				'%1$s going · %2$s seats waitlisted',
				'event count line',
				'event-hub'
			),
			'Be the first to RSVP.'                     => __( 'Be the first to RSVP.', 'event-hub' ),
			/* translators: %s: number of attendees. */
			'%s person going'                           => __( '%s person going', 'event-hub' ),
			/* translators: %s: number of attendees. */
			'%s people going'                           => __( '%s people going', 'event-hub' ),
			/* translators: %s: number of events found. */
			'%s event found'                            => __( '%s event found', 'event-hub' ),
			/* translators: %s: number of events. */
			'%s events found'                           => __( '%s events found', 'event-hub' ),
			'Open'                                      => __( 'Open', 'event-hub' ),
			'Closed'                                    => __( 'Closed', 'event-hub' ),
			'Cancelled'                                 => __( 'Cancelled', 'event-hub' ),
			'Postponed'                                 => __( 'Postponed', 'event-hub' ),
			'This event has been cancelled.'            => __( 'This event has been cancelled.', 'event-hub' ),
			'This event has been postponed — the new date will be announced.' => __( 'This event has been postponed — the new date will be announced.', 'event-hub' ),
			'Few spots left'                            => __( 'Few spots left', 'event-hub' ),
			'Waitlist'                                  => __( 'Waitlist', 'event-hub' ),
			'Sold out'                                  => __( 'Sold out', 'event-hub' ),
			'Past'                                      => __( 'Past', 'event-hub' ),
			'RSVPs are closed.'                         => __( 'RSVPs are closed.', 'event-hub' ),
			'RSVPs have not opened yet.'                => __( 'RSVPs have not opened yet.', 'event-hub' ),
			'Keep this link — it manages your booking.' => __( 'Keep this link — it manages your booking.', 'event-hub' ),
			'Copy link'                                 => __( 'Copy link', 'event-hub' ),
			'Copied!'                                   => __( 'Copied!', 'event-hub' ),
			'You can also cancel or rename from the link at any time.' => __( 'You can also cancel or rename from the link at any time.', 'event-hub' ),
			/* translators: 1: number going, 2: total capacity. */
			'%1$s of %2$s spots filled'                 => __( '%1$s of %2$s spots filled', 'event-hub' ),
		);
	}
}
