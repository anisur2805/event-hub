/**
 * Editor registration for the RSVP Button block. Server-rendered with the
 * shared `event-hub` store; the editor offers an event picker plus a static
 * preview of the form.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	TextControl,
	Button,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import metadata from './block.json';
import { EventPicker } from '../shared/edit-shared';
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
				label={ __( 'RSVP Button', 'event-hub' ) }
				instructions={ __(
					'The interactive RSVP form renders on the site; this is a static preview.',
					'event-hub'
				) }
			>
				<div className="eh-rsvp">
					<TextControl
						label={ __( 'Name', 'event-hub' ) }
						value=""
						onChange={ () => undefined }
						disabled
					/>
					<TextControl
						label={ __( 'Email', 'event-hub' ) }
						value=""
						onChange={ () => undefined }
						disabled
					/>
					<Button variant="primary" disabled>
						{ __( 'RSVP', 'event-hub' ) }
					</Button>
				</div>
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
