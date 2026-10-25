/**
 * Shared editor pieces: attribute controls and previews used by more than
 * one block. Editor-only (imported from the blocks' index.tsx entries).
 */
import { useBlockProps } from '@wordpress/block-editor';
import { useEntityRecords } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';
import {
	Spinner,
	SelectControl,
	TextControl,
	ToggleControl,
	ComboboxControl,
	Disabled,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';

/** Minimal category record shape from useEntityRecords('taxonomy', ...). */
interface CategoryRecord {
	id: number;
	name: string;
	slug: string;
}

/** Minimal event record shape from useEntityRecords('postType', ...). */
interface EventRecord {
	id: number;
	title: { rendered: string };
}

/** Data hook for the category picker (list filter). */
export function useEventCategories(): {
	options: { value: string; label: string }[];
	resolved: boolean;
} {
	const { records, isResolving } = useEntityRecords< CategoryRecord >(
		'taxonomy',
		'eh_event_category',
		{ per_page: -1, context: 'view' }
	);
	const options = ( records ?? [] ).map( ( record ) => ( {
		value: String( record.id ),
		label: decodeEntities( record.name ),
	} ) );
	return { options, resolved: ! isResolving };
}

/** Data hook fetching published events for the single-event pickers. */
export function useEventOptions(): {
	eventOptions: { value: string; label: string }[];
	resolved: boolean;
} {
	const { records, isResolving } = useEntityRecords< EventRecord >(
		'postType',
		'eh_event',
		{
			per_page: 50,
			orderby: 'date',
			order: 'desc',
			context: 'view',
		}
	);
	const eventOptions = ( records ?? [] ).map( ( record ) => ( {
		value: String( record.id ),
		label: record.title?.rendered
			? decodeEntities( record.title.rendered )
			: `#${ record.id }`,
	} ) );
	return { eventOptions, resolved: ! isResolving };
}

/** Spinner row while core-data resolves. */
export function EditorLoading(): ReactElement {
	return (
		<div { ...useBlockProps() } style={ { minHeight: 80 } }>
			<Spinner />
			<span style={ { marginInlineStart: 8 } }>
				{ __( 'Loading events…', 'event-hub' ) }
			</span>
		</div>
	);
}

/**
 * Inspector panel for the event list's filter settings.
 * @param props
 * @param props.categoryId
 * @param props.postsPerPage
 * @param props.showFilters
 * @param props.onCategory
 * @param props.onPerPage
 * @param props.onShowFilters
 */
export function ListInspector( props: {
	categoryId: string;
	postsPerPage: string;
	showFilters: boolean;
	onCategory: ( value: string ) => void;
	onPerPage: ( value: string ) => void;
	onShowFilters: ( value: boolean ) => void;
} ): ReactElement {
	const { options, resolved } = useEventCategories();
	return (
		<>
			{ resolved ? (
				<ComboboxControl
					label={ __( 'Filter by category', 'event-hub' ) }
					value={ props.categoryId }
					onChange={ ( value ) => props.onCategory( value ?? '' ) }
					options={ [
						{
							value: '',
							label: __( 'All categories', 'event-hub' ),
						},
						...options,
					] }
					help={ __(
						'Leave on “All categories” to list every upcoming event.',
						'event-hub'
					) }
				/>
			) : (
				<Spinner />
			) }
			<TextControl
				type="number"
				min={ 1 }
				max={ 50 }
				label={ __( 'Events to show', 'event-hub' ) }
				value={ props.postsPerPage }
				onChange={ props.onPerPage }
				help={ __( 'Between 1 and 50.', 'event-hub' ) }
			/>
			<ToggleControl
				label={ __( 'Show category filter', 'event-hub' ) }
				checked={ props.showFilters }
				onChange={ props.onShowFilters }
			/>
		</>
	);
}

/** Static preview card used by both the list and the card editor. */
export function CardPreview(): ReactElement {
	return (
		<Disabled>
			<article className="eh-card">
				<h3 className="eh-card__title">
					{ __( 'Sample event title', 'event-hub' ) }
				</h3>
				<p className="eh-card__meta">
					{ __( 'Sat, Jan 17 · 6:00 pm — Main Hall', 'event-hub' ) }
				</p>
				<p className="eh-card__excerpt">
					{
						/* translators: placeholder text for the editor preview. */
						__(
							'A short excerpt of the event description shows here.',
							'event-hub'
						)
					}
				</p>
				<p className="eh-card__count">
					{ __( '12 people going', 'event-hub' ) }
				</p>
			</article>
		</Disabled>
	);
}

/**
 * SelectControl of published events for single-event blocks.
 * @param props
 * @param props.value
 * @param props.onChange
 */
export function EventPicker( props: {
	value: string;
	onChange: ( value: string ) => void;
} ): ReactElement {
	const { eventOptions, resolved } = useEventOptions();
	return (
		<SelectControl
			label={ __( 'Event', 'event-hub' ) }
			value={ props.value }
			onChange={ props.onChange }
			options={ [
				{
					value: '0',
					label: resolved
						? __( 'Select an event', 'event-hub' )
						: __( 'Loading events…', 'event-hub' ),
				},
				...eventOptions,
			] }
			help={ __(
				'Pick the event this block renders. Leave empty to show the current event in a Query Loop.',
				'event-hub'
			) }
		/>
	);
}
