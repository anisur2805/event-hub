/**
 * Parse a picker string value into the numeric eventId attribute.
 * @param value
 */
export function toEventId( value: string ): number {
	const parsed = Number.parseInt( value, 10 );
	return Number.isNaN( parsed ) || parsed < 0 ? 0 : parsed;
}
