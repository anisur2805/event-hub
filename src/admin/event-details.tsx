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
	ComboboxControl,
	DateTimePicker,
	Notice,
	PanelRow,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import type { ReactElement, ReactNode } from 'react';

import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
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
	eh_venue_id?: string;
	eh_organizer_id?: string;
	eh_capacity?: string;
	eh_rsvp_open_hours?: string;
	eh_rsvp_close_hours?: string;
	eh_status?: string;
	eh_external_url?: string;
	eh_series_freq?: string;
	eh_series_interval?: string;
	eh_series_byweekday?: string;
	eh_series_until?: string;
	eh_reminder_hours?: string;
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

/** One selectable entry of the venue/organizer comboboxes. */
interface ComboboxOption {
	value: string;
	label: string;
}

/**
 * Combobox options for a linked-entity selector: the published entities plus
 * a flagged placeholder when the stored ID is not among them (unpublished or
 * deleted — the selection stays visible and clearable, never silently
 * snapped to another entity).
 *
 * @param storedId Stored meta ID ('' = nothing selected).
 * @param options  Published-entity options.
 * @param fallback Placeholder label for a stored-but-missing ID.
 * @return Option list for ComboboxControl.
 */
function withStored(
	storedId: string,
	options: Array< ComboboxOption >,
	fallback: string
): Array< ComboboxOption > {
	if ( '' === storedId ) {
		return options;
	}

	if ( options.some( ( option ) => option.value === storedId ) ) {
		return options;
	}

	return [ { value: storedId, label: fallback }, ...options ];
}

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

/** Detects a configured RSVP-window boundary (hours before start, 0 legal). */
const WINDOW_PATTERN = /^\d+$/;

/** Series frequency values (mirror of the REST meta schema enum). */
const SERIES_FREQS = [ '', 'daily', 'weekly', 'monthly' ] as const;

// Event status (5.3): same values as the eh_status meta enum (Plugin.php).
const STATUS_VALUES = [ '', 'cancelled', 'postponed' ] as const;

/** Valid Y-m-d series "until" value; '' legal. */
const SERIES_UNTIL_PATTERN = /^$|^\d{4}-\d{2}-\d{2}$/;

/** Weekday short labels, 0 = Sunday … 6 = Saturday (wp-field order). */
const WEEKDAY_LABELS = [
	__( 'Sun', 'event-hub' ),
	__( 'Mon', 'event-hub' ),
	__( 'Tue', 'event-hub' ),
	__( 'Wed', 'event-hub' ),
	__( 'Thu', 'event-hub' ),
	__( 'Fri', 'event-hub' ),
	__( 'Sat', 'event-hub' ),
];

/**
 * The panel body: the event-meta controls bound to the eh_* meta. All
 * strings are translatable (script translations are registered server-side);
 * no custom CSS — native components only.
 */
function EventDetailsFields(): ReactElement {
	const [ entityMeta, setMeta ] = useEntityProp(
		'postType',
		'eh_event',
		'meta'
	) as [
		EventHubEventMeta | undefined,
		( next: EventHubEventMeta ) => void,
		unknown,
	];

	// useEntityProp resolves the edited record asynchronously: meta is
	// undefined while a freshly mounted editor fetches its REST record.
	// Defaulting to an empty object renders blank controls until it arrives
	// — reading meta.eh_venue directly is what crashed the editor on boot.
	const meta: EventHubEventMeta = entityMeta ?? {};

	const venue = typeof meta.eh_venue === 'string' ? meta.eh_venue : '';
	const venueIdRaw =
		typeof meta.eh_venue_id === 'string' ? meta.eh_venue_id : '';
	const organizerIdRaw =
		typeof meta.eh_organizer_id === 'string' ? meta.eh_organizer_id : '';

	// Linked-entity pickers (5.1): published entities only, so a selection is
	// always a value the front-end resolver will honor. getEntityRecords is
	// typed through a minimal facade (the store descriptor string form is
	// not resolved by @wordpress/data's generic types here).
	const { venueOptions, organizerOptions } = useSelect( ( select ) => {
		const core = select( 'core' ) as {
			getEntityRecords: (
				kind: string,
				name: string,
				query?: Record< string, unknown >
			) => Array< { id: number; title: { rendered: string } } > | null;
		};

		const toOptions = (
			records: Array< { id: number; title: { rendered: string } } > | null
		): Array< ComboboxOption > =>
			( records ?? [] ).map( ( record ) => ( {
				value: String( record.id ),
				label: record.title.rendered || String( record.id ),
			} ) );

		return {
			venueOptions: toOptions(
				core.getEntityRecords( 'postType', 'eh_venue', {
					status: 'publish',
					per_page: 100,
					orderby: 'title',
					order: 'asc',
				} )
			),
			organizerOptions: toOptions(
				core.getEntityRecords( 'postType', 'eh_organizer', {
					status: 'publish',
					per_page: 100,
					orderby: 'title',
					order: 'asc',
				} )
			),
		};
	}, [] );
	const startRaw = typeof meta.eh_start === 'string' ? meta.eh_start : '';
	const endRaw = typeof meta.eh_end === 'string' ? meta.eh_end : '';
	const capacityRaw =
		typeof meta.eh_capacity === 'string' ? meta.eh_capacity : '';
	const openHoursRaw =
		typeof meta.eh_rsvp_open_hours === 'string'
			? meta.eh_rsvp_open_hours
			: '';
	const closeHoursRaw =
		typeof meta.eh_rsvp_close_hours === 'string'
			? meta.eh_rsvp_close_hours
			: '';

	const seriesFreqValidated =
		typeof meta.eh_series_freq === 'string' &&
		SERIES_FREQS.includes(
			meta.eh_series_freq as ( typeof SERIES_FREQS )[ number ]
		)
			? meta.eh_series_freq
			: '';
	// Plain-string alias: SelectControl's onChange hands back a bare string,
	// so binding its value to the narrowed union type fights the component.
	const seriesFreq: string = seriesFreqValidated;

	// Status (5.3): validated against the schema enum; junk = scheduled.
	const statusValidated =
		typeof meta.eh_status === 'string' &&
		STATUS_VALUES.includes(
			meta.eh_status as ( typeof STATUS_VALUES )[ number ]
		)
			? meta.eh_status
			: '';
	const status: string = statusValidated;
	const seriesIntervalRaw =
		typeof meta.eh_series_interval === 'string'
			? meta.eh_series_interval
			: '';
	const seriesByweekdayRaw =
		typeof meta.eh_series_byweekday === 'string'
			? meta.eh_series_byweekday
			: '';
	const seriesUntilRaw =
		typeof meta.eh_series_until === 'string' ? meta.eh_series_until : '';
	const reminderHoursRaw =
		typeof meta.eh_reminder_hours === 'string'
			? meta.eh_reminder_hours
			: '';
	const externalUrlRaw =
		typeof meta.eh_external_url === 'string' ? meta.eh_external_url : '';

	const limited = CAPACITY_PATTERN.test( capacityRaw );

	// RSVP window boundaries (2.4): hours before start. '0' is legal both
	// sides (close at start / open at start) — test the string, not truthiness.
	const hasOpen = WINDOW_PATTERN.test( openHoursRaw );
	const hasClose = WINDOW_PATTERN.test( closeHoursRaw );

	// Inverted window: closing before it opens. Both cutoffs count hours
	// BEFORE start, so openHours < closeHours flips the boundaries.
	const invertedWindow =
		hasOpen &&
		hasClose &&
		parseInt( openHoursRaw, 10 ) < parseInt( closeHoursRaw, 10 );

	// Recurrence (3.2): the by-weekday selection parsed from the stored list.
	const selectedDays = seriesByweekdayRaw
		.split( ',' )
		.map( ( piece ) => parseInt( piece.trim(), 10 ) )
		.filter( ( day ) => day >= 0 && day <= 6 );

	const toggleDay = ( day: number ): void => {
		const next = selectedDays.includes( day )
			? selectedDays.filter( ( kept ) => kept !== day )
			: [ ...selectedDays, day ].sort( ( a, b ) => a - b );

		update( 'eh_series_byweekday', next.join( ',' ) );
	};

	// A recurring event needs a start date; the engine no-ops without one.
	const seriesNeedsStart =
		'' !== seriesFreq && '' === toStorageValue( toPickerValue( startRaw ) );

	// Interval label per frequency (record lookup: no nested ternary).
	const intervalLabel =
		(
			{
				daily: __( 'Every N days', 'event-hub' ),
				weekly: __( 'Every N weeks', 'event-hub' ),
				monthly: __( 'Every N months', 'event-hub' ),
			} as Record< string, string >
		 )[ seriesFreq ] ?? __( 'Every N months', 'event-hub' );

	// Advisory validation: lexicographic comparison is chronological here
	// because both values are normalized to zero-padded 'YYYY-MM-DD HH:mm:ss'.
	const startStorage = toStorageValue( toPickerValue( startRaw ) );
	const endStorage = toStorageValue( toPickerValue( endRaw ) );
	const reversed =
		'' !== startStorage && '' !== endStorage && endStorage < startStorage;

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
				onChange={ ( next ) =>
					update( 'eh_start', toStorageValue( next ) )
				}
				is12Hour={ twelveHour }
			/>
			<PanelRow>
				<span>{ __( 'Ends', 'event-hub' ) }</span>
			</PanelRow>
			<DateTimePicker
				currentDate={ toPickerValue( endRaw ) }
				onChange={ ( next ) =>
					update( 'eh_end', toStorageValue( next ) )
				}
				is12Hour={ twelveHour }
			/>
			{ reversed && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The end time is before the start time.',
						'event-hub'
					) }
				</Notice>
			) }
			{ invertedWindow && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The RSVP window closes before it opens. Closing hours must be smaller than opening hours.',
						'event-hub'
					) }
				</Notice>
			) }
			<PanelRow>
				<ComboboxControl
					label={ __( 'Venue', 'event-hub' ) }
					value={ venueIdRaw }
					options={ withStored(
						venueIdRaw,
						venueOptions,
						__(
							'Selected venue (unpublished or deleted)',
							'event-hub'
						)
					) }
					onChange={ ( next ) => update( 'eh_venue_id', next ?? '' ) }
					help={ __(
						'Reuse a venue across events. Manage venues under Events → Venues.',
						'event-hub'
					) }
				/>
			</PanelRow>
			<PanelRow>
				<ComboboxControl
					label={ __( 'Organizer', 'event-hub' ) }
					value={ organizerIdRaw }
					options={ withStored(
						organizerIdRaw,
						organizerOptions,
						__(
							'Selected organizer (unpublished or deleted)',
							'event-hub'
						)
					) }
					onChange={ ( next ) =>
						update( 'eh_organizer_id', next ?? '' )
					}
					help={ __(
						'Reuse an organizer across events. Manage organizers under Events → Organizers.',
						'event-hub'
					) }
				/>
			</PanelRow>
			<PanelRow>
				<TextControl
					label={ __( 'Venue note (free text)', 'event-hub' ) }
					value={ venue }
					placeholder={ __(
						'e.g. Hall B, 12 Mercer St',
						'event-hub'
					) }
					onChange={ ( next ) => update( 'eh_venue', next ) }
					help={ __(
						'Used when no venue is selected above (shown on cards and the event page).',
						'event-hub'
					) }
				/>
			</PanelRow>
			<PanelRow>
				<TextControl
					label={ __( 'Reminder (hours before start)', 'event-hub' ) }
					type="number"
					min={ 0 }
					step={ 1 }
					value={ reminderHoursRaw }
					onChange={ ( next ) => {
						const value = String( next ?? '' );

						if ( '' === value || /^\d+$/.test( value ) ) {
							update( 'eh_reminder_hours', value );
						}
					} }
					help={ __(
						'Attendees get a reminder email this many hours before the start. Empty = 24. 0 = no reminder.',
						'event-hub'
					) }
				/>
			</PanelRow>
			<PanelRow>
				<TextControl
					label={ __( 'External registration URL', 'event-hub' ) }
					type="url"
					value={ externalUrlRaw }
					placeholder={ __( 'https://…', 'event-hub' ) }
					help={
						'' !== externalUrlRaw.trim()
							? __(
									'Replaces the RSVP form with a Register button linking here. Leave empty to use the built-in RSVP form.',
									'event-hub'
								)
							: __(
									'Optional. Use an external ticketing page instead of the built-in RSVP form.',
									'event-hub'
								)
					}
					onChange={ ( next ) => {
						// Commit only plausible absolute http(s) URLs; intermediate
						// keystrokes leave the stored value untouched (the same
						// commit-only-valid discipline as capacity/cutoffs).
						const value = next.trim();

						if (
							'' === value ||
							/^https?:\/\/.+\..+/.test( value )
						) {
							update( 'eh_external_url', value );
						}
					} }
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label={ __( 'Limit the number of spots', 'event-hub' ) }
					checked={ limited }
					help={
						limited
							? __(
									'Attendees above this number are waitlisted.',
									'event-hub'
								)
							: __(
									'Leave off for unlimited spots.',
									'event-hub'
								)
					}
					onChange={ ( checked ) => {
						if ( checked ) {
							// Re-enter editing with the previous number; a brand-new
							// limit starts empty and commits on first valid entry.
							update(
								'eh_capacity',
								limited ? capacityRaw : '1'
							);
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
					<TextControl
						label={ __( 'Capacity', 'event-hub' ) }
						type="number"
						min={ 1 }
						step={ 1 }
						value={ capacityRaw }
						onChange={ ( next ) => {
							const value = String( next ?? '' );

							// Commit only valid limits; intermediate empties leave
							// the stored limit untouched so capacity never silently
							// becomes unlimited while the toggle reads ON.
							if (
								CAPACITY_PATTERN.test( value ) &&
								parseInt( value, 10 ) >= 1
							) {
								update( 'eh_capacity', value );
							}
						} }
					/>
				</PanelRow>
			) }
			<PanelRow>
				<TextControl
					label={ __(
						'Open RSVPs (hours before start)',
						'event-hub'
					) }
					type="number"
					min={ 0 }
					step={ 1 }
					value={ openHoursRaw }
					placeholder={ __( 'Always open', 'event-hub' ) }
					help={ __(
						'Leave empty to accept RSVPs from publish time. 0 opens them right at the start time.',
						'event-hub'
					) }
					onChange={ ( next ) => {
						const value = String( next ?? '' );

						// Commit only whole numbers; '' clears the boundary.
						if ( '' === value || WINDOW_PATTERN.test( value ) ) {
							update( 'eh_rsvp_open_hours', value );
						}
					} }
				/>
			</PanelRow>
			<PanelRow>
				<TextControl
					label={ __(
						'Close RSVPs (hours before start)',
						'event-hub'
					) }
					type="number"
					min={ 0 }
					step={ 1 }
					value={ closeHoursRaw }
					placeholder={ __( 'Never closes', 'event-hub' ) }
					help={ __(
						'Leave empty to keep RSVPs open until the event ends. 0 closes them right at the start time.',
						'event-hub'
					) }
					onChange={ ( next ) => {
						const value = String( next ?? '' );

						if ( '' === value || WINDOW_PATTERN.test( value ) ) {
							update( 'eh_rsvp_close_hours', value );
						}
					} }
				/>
			</PanelRow>
			<PanelRow>
				<SelectControl
					label={ __( 'Status', 'event-hub' ) }
					value={ status }
					options={
						[
							{
								value: '',
								label: __( 'Scheduled', 'event-hub' ),
							},
							{
								value: 'cancelled',
								label: __( 'Cancelled', 'event-hub' ),
							},
							{
								value: 'postponed',
								label: __( 'Postponed', 'event-hub' ),
							},
						] as Array< {
							value: string;
							label: string;
						} >
					}
					onChange={ ( next ) => update( 'eh_status', next ) }
					help={ __(
						'Cancelled events stop taking RSVPs; attendees with an RSVP are emailed when notifications are on. Postponed keeps the list — announce the new date by rescheduling.',
						'event-hub'
					) }
				/>
			</PanelRow>
			<PanelRow>
				<SelectControl
					label={ __( 'Repeats', 'event-hub' ) }
					value={ seriesFreq }
					options={
						[
							{
								value: '',
								label: __( 'Does not repeat', 'event-hub' ),
							},
							{
								value: 'daily',
								label: __( 'Daily', 'event-hub' ),
							},
							{
								value: 'weekly',
								label: __( 'Weekly', 'event-hub' ),
							},
							{
								value: 'monthly',
								label: __( 'Monthly', 'event-hub' ),
							},
						] as Array< {
							value: string;
							label: string;
						} >
					}
					onChange={ ( next ) => update( 'eh_series_freq', next ) }
					help={ __(
						'Upcoming copies of this event are created automatically (up to 6 months ahead). Each copy has its own RSVP list.',
						'event-hub'
					) }
				/>
			</PanelRow>
			{ '' !== seriesFreq && (
				<>
					<PanelRow>
						<TextControl
							label={ intervalLabel }
							type="number"
							min={ 1 }
							step={ 1 }
							value={ seriesIntervalRaw }
							onChange={ ( next ) => {
								const value = String( next ?? '' );

								if (
									'' === value ||
									WINDOW_PATTERN.test( value )
								) {
									update( 'eh_series_interval', value );
								}
							} }
						/>
					</PanelRow>
					{ 'weekly' === seriesFreq && (
						<PanelRow>
							<div>
								<span className="components-base-control__label">
									{ __( 'Repeat on', 'event-hub' ) }
								</span>
								<div style={ { display: 'flex', gap: '8px' } }>
									{ WEEKDAY_LABELS.map( ( label, day ) => (
										<ToggleControl
											key={ day }
											label={ label }
											checked={ selectedDays.includes(
												day
											) }
											onChange={ () => toggleDay( day ) }
										/>
									) ) }
								</div>
							</div>
						</PanelRow>
					) }
					<PanelRow>
						<TextControl
							label={ __( 'Until (Y–m–d)', 'event-hub' ) }
							type="text"
							value={ seriesUntilRaw }
							placeholder={ __( 'e.g. 2027–06–30', 'event-hub' ) }
							help={ __(
								'Leave empty to keep generating 6 months ahead on an ongoing basis.',
								'event-hub'
							) }
							onChange={ ( next ) => {
								const value = next.trim();

								if ( SERIES_UNTIL_PATTERN.test( value ) ) {
									update( 'eh_series_until', value );
								}
							} }
						/>
					</PanelRow>
					{ seriesNeedsStart && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Repeats needs a start date. Set one above and the copies will be created on save.',
								'event-hub'
							) }
						</Notice>
					) }
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Already-created copies keep their dates when you change this schedule. Deleting this event never deletes the copies.',
							'event-hub'
						) }
					</Notice>
				</>
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
