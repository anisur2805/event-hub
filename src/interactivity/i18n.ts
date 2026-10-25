/**
 * Module-side translations.
 *
 * WordPress does not ship `@wordpress/i18n` as a script module (WP 7.1.3),
 * and the module build forbids classic-package imports. PHP therefore seeds
 * `state.i18n` (a map of msgid to translated string) via
 * `wp_interactivity_state()`; these helpers resolve lookups against it with
 * an identity fallback for untranslated strings.
 */

import { store } from '@wordpress/interactivity';

import { type EventHubStore } from './types';

/**
 * Translate a msgid using the server-provided map.
 *
 * @param msgid Original string.
 * @return Translated string or the msgid itself.
 */
export function translate( msgid: string ): string {
	const { state } = store< EventHubStore >( 'event-hub' );
	return state.i18n?.[ msgid ] ?? msgid;
}

/**
 * Singular/plural selection. The server map holds both templates; count
 * interpolation happens in the caller via interpolate().
 *
 * @param single Singular msgid.
 * @param plural Plural msgid.
 * @param count  Count driving the choice.
 * @return Selected msgid (resolved at render).
 */
export function choosePlural(
	single: string,
	plural: string,
	count: number
): string {
	if ( 1 === count ) {
		return translate( single );
	}
	return translate( plural );
}

/**
 * Minimal %-style substitution. Supports bare (%s, %d, consumed left to
 * right) and numbered (%1$s, %2$d, …) WordPress placeholders; a format
 * never mixes the two styles.
 *
 * @param format Format string.
 * @param args   Substitution values.
 * @return Interpolated string.
 */
export function interpolate(
	format: string,
	...args: ( string | number )[]
): string {
	return format.replace(
		/%(\d+)\$([sd])|%([sd])/g,
		(
			match: string,
			num: string | undefined,
			numType: string | undefined,
			plainType: string | undefined
		) => {
			if ( num !== undefined && numType !== undefined ) {
				const positional = args[ parseInt( num, 10 ) - 1 ];
				return positional === undefined ? match : String( positional );
			}
			const sequential = args.shift();
			return sequential === undefined ? match : String( sequential );
		}
	);
}
