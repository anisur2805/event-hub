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
 * Minimal %-style substitution (%s and %d consumed left to right).
 *
 * @param format Format string.
 * @param args   Substitution values.
 * @return Interpolated string.
 */
export function interpolate(
	format: string,
	...args: ( string | number )[]
): string {
	return format.replace( /%([sd])/g, ( match: string ) => {
		const value = args.shift();
		if ( value === undefined ) {
			return match;
		}
		return String( value );
	} );
}
