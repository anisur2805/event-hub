/**
 * Editor registration for the Event Card block. Server-rendered; the editor
 * offers an event picker plus a static preview.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import metadata from './block.json';
import { CardPreview, EventPicker } from '../shared/edit-shared';
import { toEventId } from '../shared/to-event-id';

/** Block attributes per block.json. */
type Attributes = {
	eventId: number;
};

type EditProps = {
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
};

function Edit( { attributes, setAttributes }: EditProps ): ReactElement {
	const blockProps = useBlockProps();
	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'event-hub' ) }>
					<EventPicker
						value={ String( attributes.eventId ) }
						onChange={ ( value ) =>
							setAttributes( { eventId: toEventId( value ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Event Card', 'event-hub' ) }
				instructions={ __(
					'The selected event renders on the site; this is a static preview.',
					'event-hub'
				) }
			>
				<CardPreview />
			</Placeholder>
		</div>
	);
}

registerBlockType< Attributes >( metadata.name, {
	...metadata,
	edit: Edit,
	save() {
		return null; // Dynamic block; PHP renders.
	},
} );
