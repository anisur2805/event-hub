/**
 * Event Hub — "Event details" editor panel (eh_event block editor sidebar).
 *
 * Edits the four eh_* meta fields via useEntityProp; storage format is
 * site-local 'Y-m-d H:i:s' (Render_Blocks::meta_iso() accepts both that and
 * the editor's 'Y-m-dTH:i:s' — the conversion here is a separator swap, no
 * timezone math). Capacity follows the render contract: a bare number limits
 * spots; empty (or non-numeric) means unlimited.
 *
 * wp.plugins.registerPlugin and wp.editPost.PluginDocumentSettingPanel are
 * consumed from the window.wp runtime: those two packages are not build-time
 * dependencies of this plugin (no bundling, dependency-extraction still maps
 * the *installed* imports below to wp-* handles). Editor_Panel.php declares
 * 'wp-plugins' + 'wp-edit-post' explicitly so the globals exist before this
 * module runs.
 */
import {
	DateTimePicker,
	Notice,
	PanelRow,
	TextControl,
	ToggleControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import type { ReactElement, ReactNode } from 'react';

import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

/**
 * Shape of the eh_event meta record this panel owns. The fields are optional:
 * the meta object itself may also be {} while the edited record is still
 * resolving on editor boot (see EventDetailsFields).
 */
interface EventHubEventMeta {
	eh_start?: string;
	eh_end?: string;
	eh_venue?: string;
	eh_capacity?: string;
	[ key: string ]: unknown;
}

/**
 * Minimal runtime facade for the two editor slot APIs. Property names match
 * wp-includes/js/dist (core 7.1.3 ships the singular PluginDocumentSettingPanel).
 */
interface WpEditorRuntime {
	plugins: {
		registerPlugin: (
			name: string,
			settings: { render: () => ReactElement }
		) => void;
	};
	editPost: {
		PluginDocumentSettingPanel: ( props: {
			name: string;
			title: string;
			initialOpen?: boolean;
			children: ReactNode;
		} ) => ReactElement;
	};
	date: {
		getSettings?: () => {
			formats: { time: string; [ key: string ]: string };
		};
	};
}

const wp = ( window as unknown as { wp?: WpEditorRuntime } ).wp;

/**
 * Normalizes any accepted stored format ('Y-m-d H:i:s' or 'Y-m-dTH:i:s',
 * seconds optional) to the picker's 'Y-m-dTHH:mm:ss'. Returns null for empty
 * or unparsable values — the picker then shows "no date" instead of crashing.
 *
 * @param raw Stored meta value.
 * @return Picker string or null.
 */
function toPickerValue( raw: string ): string | null {
	const match = raw
		.trim()
		.match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/ );

	if ( ! match ) {
		return null;
	}

	const [ , year, month, day, hour, minute, second ] = match;

	return `${ year }-${ month }-${ day }T${ hour }:${ minute }:${
		second ?? '00'
	}`;
}

/**
 * Converts the picker's 'Y-m-dTH:i(:s)' output back to the storage format
 * 'Y-m-d H:i:s' (site-local, seconds guaranteed).
 *
 * @param pickerValue Picker output.
 * @return Storage string ('' when the picker clears the field).
 */
function toStorageValue( pickerValue: string | null ): string {
	if ( ! pickerValue ) {
		return '';
	}

	const normalized = toPickerValue( pickerValue );

	return normalized ? normalized.replace( 'T', ' ' ) : '';
}

/** Detects a capacity meta that means "limited to N spots". */
const CAPACITY_PATTERN = /^\d+$/;

/**
 * The panel body: four controls bound to the eh_* meta. All strings are
 * translatable (script translations are registered server-side); no custom
 * CSS — native @wordpress/components only.
 */
function EventDetailsFields(): ReactElement {
	const [ entityMeta, setMeta ] = useEntityProp(
		'postType',
		'eh_event',
		'meta'
	) as [ EventHubEventMeta | undefined, ( next: EventHubEventMeta ) => void, unknown ];

	// useEntityProp resolves the edited record asynchronously: meta is
	// undefined while a freshly mounted editor fetches its REST record.
	// Defaulting to an empty object renders blank controls until it arrives
	// — reading meta.eh_venue directly is what crashed the editor on boot.
	const meta: EventHubEventMeta = entityMeta ?? {};

	const venue = typeof meta.eh_venue === 'string' ? meta.eh_venue : '';
	const startRaw = typeof meta.eh_start === 'string' ? meta.eh_start : '';
	const endRaw = typeof meta.eh_end === 'string' ? meta.eh_end : '';
	const capacityRaw =
		typeof meta.eh_capacity === 'string' ? meta.eh_capacity : '';

	const limited = CAPACITY_PATTERN.test( capacityRaw );

	// Advisory validation: lexicographic comparison is chronological here
	// because both values are normalized to zero-padded 'YYYY-MM-DD HH:mm:ss'.
	const startStorage = toStorageValue( toPickerValue( startRaw ) );
	const endStorage = toStorageValue( toPickerValue( endRaw ) );
	const reversed =
		'' !== startStorage &&
		'' !== endStorage &&
		endStorage < startStorage;

	// The picker assumes a 12-hour clock from the site time format (an "a"
	// meridiem token); fall back to 24h when wp.date is unavailable.
	const twelveHour =
		wp?.date?.getSettings?.().formats.time.includes( 'a' ) ?? false;

	const update = ( key: keyof EventHubEventMeta, value: string ): void => {
		setMeta( { ...meta, [ key ]: value } );
	};

	return (
		<>
			<PanelRow>
				<span>{ __( 'Starts', 'event-hub' ) }</span>
			</PanelRow>
			<DateTimePicker
				currentDate={ toPickerValue( startRaw ) }
				onChange={ ( next ) => update( 'eh_start', toStorageValue( next ) ) }
				is12Hour={ twelveHour }
			/>
			<PanelRow>
				<span>{ __( 'Ends', 'event-hub' ) }</span>
			</PanelRow>
			<DateTimePicker
				currentDate={ toPickerValue( endRaw ) }
				onChange={ ( next ) => update( 'eh_end', toStorageValue( next ) ) }
				is12Hour={ twelveHour }
			/>
			{ reversed && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'The end time is before the start time.', 'event-hub' ) }
				</Notice>
			) }
			<PanelRow>
				<TextControl
					label={ __( 'Venue', 'event-hub' ) }
					value={ venue }
					placeholder={ __( 'e.g. Hall B, 12 Mercer St', 'event-hub' ) }
					onChange={ ( next ) => update( 'eh_venue', next ) }
					help={ __( 'Shown on the event page and event cards.', 'event-hub' ) }
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label={ __( 'Limit the number of spots', 'event-hub' ) }
					checked={ limited }
					help={
						limited
							? __( 'Attendees above this number are waitlisted.', 'event-hub' )
							: __( 'Leave off for unlimited spots.', 'event-hub' )
					}
					onChange={ ( checked ) => {
						if ( checked ) {
							// Re-enter editing with the previous number; a brand-new
							// limit starts empty and commits on first valid entry.
							update( 'eh_capacity', limited ? capacityRaw : '1' );
							return;
						}

						// Toggling off releases the limit: empty meta means
						// unlimited (render REGEXP guard ignores non-numbers too).
						update( 'eh_capacity', '' );
					} }
				/>
			</PanelRow>
			{ limited && (
				<PanelRow>
					<NumberControl
						label={ __( 'Capacity', 'event-hub' ) }
						min={ 1 }
						step={ 1 }
						value={ capacityRaw }
						onChange={ ( next ) => {
							const value = String( next ?? '' );

							// Commit only valid limits; intermediate empties leave
							// the stored limit untouched so capacity never silently
							// becomes unlimited while the toggle reads ON.
							if ( CAPACITY_PATTERN.test( value ) && parseInt( value, 10 ) >= 1 ) {
								update( 'eh_capacity', value );
							}
						} }
					/>
				</PanelRow>
			) }
		</>
	);
}

/**
 * Registers the sidebar panel. Guarded: a plugin file executed on a screen
 * without wp-plugins (never expected — Editor_Panel declares the handles)
 * would throw here, which is preferable to a silent no-op in development.
 */
if ( wp ) {
	wp.plugins.registerPlugin( 'event-hub-event-details', {
		render: () => (
			<wp.editPost.PluginDocumentSettingPanel
				name="event-hub-details"
				title={ __( 'Event details', 'event-hub' ) }
				initialOpen
			>
				<EventDetailsFields />
			</wp.editPost.PluginDocumentSettingPanel>
		),
	} );
}
