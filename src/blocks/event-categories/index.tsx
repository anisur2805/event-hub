/**
 * Editor registration for the Event Categories block. Pure server-rendered
 * output; the editor shows a static preview of the site's term list.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import type { ReactElement } from 'react';
import metadata from './block.json';

/** One term as returned by core-data. */
type Term = {
	id: number;
	name: string;
	count: number;
};

/** Block attributes per block.json. */
type Attributes = {
	showCounts: boolean;
};

type EditProps = {
	attributes: Attributes;
};

function Edit( { attributes }: EditProps ): ReactElement {
	const blockProps = useBlockProps();

	const terms: Term[] | null = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'eh_event_category', {
				per_page: -1,
				hide_empty: true,
			} ) as Term[] | null,
		[]
	);

	return (
		<div { ...blockProps }>
			{ ! terms?.length ? (
				<p>{ __( 'No categories yet.', 'event-hub' ) }</p>
			) : (
				<ul className="event-hub-event-categories__list">
					{ terms.map( ( term ) => (
						<li
							className="event-hub-event-categories__item"
							key={ term.id }
						>
							<span className="event-hub-event-categories__link">
								{ term.name }
								{ attributes.showCounts && (
									<>
										{ ' ' }
										<span className="event-hub-event-categories__count">
											{
												/* translators: %s: number of events. */
												sprintf(
													'(%s)',
													Number( term.count ).toLocaleString()
												)
											}
										</span>
									</>
								) }
							</span>
						</li>
					) ) }
				</ul>
			) }
			<p className="event-hub-event-categories__hint">
				{ sprintf(
					/* translators: %s: number of categories. Use plural form. */
					_n(
						'Shows %d category and links it to the event list filter.',
						'Shows %d categories and links them to the event list filter.',
						terms?.length ?? 0
					),
					terms?.length ?? 0
				) }
			</p>
		</div>
	);
}

registerBlockType< Attributes >( metadata.name, {
	...metadata,
	edit: Edit,
	save: () => null, // Dynamic block; PHP renders.
} );
