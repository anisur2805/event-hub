/**
 * Editor registration for the Event Calendar block. The block renders
 * server-side (render.php); the editor shows the optional initial-month
 * setting plus a static mini-preview.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import metadata from './block.json';

/** Block attributes per block.json. */
type Attributes = {
	initialMonth: string;
};

type EditProps = {
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
};

/** Letters valid in the YYYY-MM attribute; '' is legal (current month). */
const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

function Edit( { attributes, setAttributes }: EditProps ): ReactElement {
	const blockProps = useBlockProps();

	const rows = [ 0, 1, 2, 3 ];
	const cols = [ 0, 1, 2, 3, 4, 5, 6 ];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'event-hub' ) }>
					<TextControl
						label={ __( 'Initial month', 'event-hub' ) }
						help={ __(
							'First month the calendar shows, as YYYY–MM (for example 2026–11). Leave empty to always start at the current month.',
							'event-hub'
						) }
						value={ attributes.initialMonth }
						onChange={ ( value ) =>
							setAttributes( {
								initialMonth:
									'' === value.trim() ||
									MONTH_PATTERN.test( value.trim() )
										? value.trim()
										: attributes.initialMonth,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Event Calendar', 'event-hub' ) }
				instructions={ __(
					'The month grid renders on the site; this is a static preview.',
					'event-hub'
				) }
			>
				<div className="event-hub-calendar-preview">
					{ rows.map( ( r ) => (
						<div
							key={ r }
							className="event-hub-calendar-preview__row"
						>
							{ cols.map( ( c ) => (
								<span
									key={ c }
									className="event-hub-calendar-preview__cell"
								/>
							) ) }
						</div>
					) ) }
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
