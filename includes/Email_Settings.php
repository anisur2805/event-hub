<?php
/**
 * Editable email copy — per-email subject + intro overrides (ROADMAP §5B).
 *
 * @package event-hub
 */

declare( strict_types=1 );

namespace EventHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings-backed overrides for Notifier's five email types. Each email has
 * an optional SUBJECT override and an optional INTRO paragraph prepended to
 * the body; the built-in copy is the default whenever an override is empty,
 * so the shipped wording survives an empty settings row untouched.
 *
 * Option shape (autoloaded, tiny): option `event_hub_email_copy` =
 * array< 'confirmation'|'reminder'|'cancel'|'promotion'|'status',
 * array{ subject: string, intro: string } >. Placeholders travel as literal
 * `%s` in stored copy and are substituted with the same values the default
 * subject uses (event title etc.) at send time.
 */
final class Email_Settings {

	/**
	 * Option name holding the overrides map.
	 *
	 * @var string
	 */
	public const OPTION = 'event_hub_email_copy';

	/**
	 * The five editable email keys (Notifier send methods, minus the
	 * organizer notice which rides Into the Notifications toggle).
	 *
	 * @var array<int,string>
	 */
	public const KEYS = array( 'confirmation', 'reminder', 'cancel', 'promotion', 'status' );

	/**
	 * Reads one email's stored override pair, sanitized for plain-text mail.
	 *
	 * @param string $key One of self::KEYS.
	 * @return array{subject:string, intro:string} Both '' when unset.
	 */
	public static function get( string $key ): array {
		if ( ! in_array( $key, self::KEYS, true ) ) {
			return array(
				'subject' => '',
				'intro'   => '',
			);
		}

		$stored = get_option( self::OPTION, array() );
		$row    = is_array( $stored ) ? ( $stored[ $key ] ?? array() ) : array();
		$row    = is_array( $row ) ? $row : array();

		return array(
			'subject' => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
			'intro'   => sanitize_textarea_field( (string) ( $row['intro'] ?? '' ) ),
		);
	}

	/**
	 * Resolves the subject for one email: the override with `%s`/`%1$s`
	 * placeholders substituted, else the default subject unchanged.
	 *
	 * @param string $key             Email key.
	 * @param string $default_subject Notifier's built-in subject (already
	 *                                fully formatted).
	 * @param string $placeholder_val The event-title value the default's
	 *                                placeholders stand for ('' = none).
	 * @return string Ready-to-send subject.
	 */
	public static function subject( string $key, string $default_subject, string $placeholder_val = '' ): string {
		$override = self::get( $key )['subject'];

		if ( '' === $override ) {
			return $default_subject;
		}

		return '' !== $placeholder_val
			? sprintf( $override, $placeholder_val )
			: $override;
	}

	/**
	 * Resolves the intro paragraph for one email: the stored intro with a
	 * `%s` event-title substitution, else '' (body proceeds unchanged).
	 *
	 * @param string $key             Email key.
	 * @param string $placeholder_val Event-title value for `%s` ('' = none).
	 * @return string Intro paragraph or ''.
	 */
	public static function intro( string $key, string $placeholder_val = '' ): string {
		$intro = self::get( $key )['intro'];

		if ( '' === $intro ) {
			return '';
		}

		return '' !== $placeholder_val
			? sprintf( $intro, $placeholder_val )
			: $intro;
	}

	/**
	 * Saves a full overrides map (admin-post path). Every key is normalized;
	 * completely-empty pairs drop out so the option shrinks to real edits.
	 *
	 * @param array<string,mixed> $raw Raw POST-shaped map.
	 * @return void
	 */
	public static function save( array $raw ): void {
		$clean = array();

		foreach ( self::KEYS as $key ) {
			$row     = is_array( $raw[ $key ] ?? null ) ? $raw[ $key ] : array();
			$subject = sanitize_text_field( (string) ( $row['subject'] ?? '' ) );
			$intro   = sanitize_textarea_field( (string) ( $row['intro'] ?? '' ) );

			if ( '' !== $subject || '' !== $intro ) {
				$clean[ $key ] = array(
					'subject' => $subject,
					'intro'   => $intro,
				);
			}
		}

		update_option( self::OPTION, $clean, true );
	}
}
