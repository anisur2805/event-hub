/**
 * Editor registration for the Event List block. The block renders
 * server-side (render.php); the editor shows filter settings plus a static
 * preview list.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import metadata from './block.json';
import { CardPreview, ListInspector } from '../shared/edit-shared';

/** Block attributes per block.json. */
type Attributes = {
	categoryId: string;
	postsPerPage: number;
	showFilters: boolean;
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
					<ListInspector
						categoryId={
							attributes.categoryId ? attributes.categoryId : ''
						}
						postsPerPage={ String( attributes.postsPerPage ) }
						showFilters={ attributes.showFilters }
						onCategory={ ( value ) =>
							setAttributes( { categoryId: value } )
						}
						onPerPage={ ( value ) =>
							setAttributes( {
								postsPerPage: Math.min(
									Math.max(
										Number.parseInt( value, 10 ) || 6,
										1
									),
									50
								),
							} )
						}
						onShowFilters={ ( value ) =>
							setAttributes( { showFilters: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Event List', 'event-hub' ) }
				instructions={ __(
					'Upcoming events render on the site; this is a static preview.',
					'event-hub'
				) }
			>
				<div style={ { width: '100%' } }>
					<CardPreview />
					<CardPreview />
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
