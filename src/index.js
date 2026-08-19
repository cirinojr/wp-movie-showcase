import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				label={ __( 'Movie Search', 'wp-movie-showcase' ) }
				instructions={ __(
					'The movie search form is rendered on the frontend.',
					'wp-movie-showcase'
				) }
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	save() {
		return null;
	},
} );
