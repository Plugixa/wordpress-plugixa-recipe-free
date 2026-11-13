/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe Gutenberg block — "Recipes" (plugixa-recipe/recipes).
 *
 * Deliberately written in plain JavaScript against the wp.* globals rather than JSX:
 * there is no build step behind this file, so what ships IS the source. It is enqueued
 * as the handle "plugixa-recipe-block-editor" by PlugixaRecipe\Blocks\RecipeBlock.
 *
 * Rendering is server-side. The block stores only its four attributes and the PHP
 * render_callback reuses the [plugixa_recipe] shortcode, so the editor preview and the
 * front end can never drift apart. ServerSideRender shows that same output in the editor.
 */

( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	if ( ! blocks || ! element || ! blockEditor ) {
		return;
	}

	var el = element.createElement;
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var Placeholder = components.Placeholder;

	// Provided by wp_localize_script(). Falsy on a site with no saved views/shortcodes yet.
	var data = window.plugixaRecipeBlockData || {};
	var shortcodeOptions = data.shortcodes || [ { label: __( '-- Select a Shortcode --', 'plugixa-recipe' ), value: 0 } ];
	var viewOptions = data.views || [ { label: __( '-- Default View --', 'plugixa-recipe' ), value: 0 } ];
	var categoryRows = data.categories || [];

	var categoryOptions = categoryRows.map( function ( row ) {
		return { label: row.name, value: String( row.slug || row.id ) };
	} );

	blocks.registerBlockType( 'plugixa-recipe/recipes', {
		apiVersion: 3,
		title: __( 'Recipes', 'plugixa-recipe' ),
		description: __( 'Display your recipes using a saved View or a saved Shortcode.', 'plugixa-recipe' ),
		icon: 'food',
		category: 'widgets',
		keywords: [ __( 'recipe', 'plugixa-recipe' ), __( 'food', 'plugixa-recipe' ), __( 'cooking', 'plugixa-recipe' ) ],
		supports: { html: false, align: [ 'wide', 'full' ] },

		// Kept in sync with the PHP registration in class-recipe-block.php.
		attributes: {
			shortcodeId: { type: 'number', default: 0 },
			viewId: { type: 'number', default: 0 },
			categories: { type: 'string', default: '' },
			limit: { type: 'number', default: 12 }
		},

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps ? useBlockProps() : {};

			var inspector = el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Recipe Settings', 'plugixa-recipe' ), initialOpen: true },

					el( SelectControl, {
						label: __( 'Saved shortcode', 'plugixa-recipe' ),
						help: __( 'Pick a saved shortcode to use its full configuration. Overrides the settings below.', 'plugixa-recipe' ),
						value: String( a.shortcodeId || 0 ),
						options: shortcodeOptions.map( function ( o ) {
							return { label: o.label, value: String( o.value ) };
						} ),
						onChange: function ( v ) { set( { shortcodeId: parseInt( v, 10 ) || 0 } ); },
						__nextHasNoMarginBottom: true
					} ),

					el( SelectControl, {
						label: __( 'View', 'plugixa-recipe' ),
						help: __( 'The saved View that controls layout, colours and typography.', 'plugixa-recipe' ),
						value: String( a.viewId || 0 ),
						options: viewOptions.map( function ( o ) {
							return { label: o.label, value: String( o.value ) };
						} ),
						onChange: function ( v ) { set( { viewId: parseInt( v, 10 ) || 0 } ); },
						__nextHasNoMarginBottom: true
					} ),

					categoryOptions.length
						? el( SelectControl, {
							label: __( 'Categories', 'plugixa-recipe' ),
							help: __( 'Leave empty to show every category. Hold Ctrl/Cmd to pick several.', 'plugixa-recipe' ),
							multiple: true,
							value: a.categories ? a.categories.split( ',' ) : [],
							options: categoryOptions,
							onChange: function ( v ) {
								set( { categories: Array.isArray( v ) ? v.join( ',' ) : String( v || '' ) } );
							},
							__nextHasNoMarginBottom: true
						} )
						: null,

					el( RangeControl, {
						label: __( 'Number of recipes', 'plugixa-recipe' ),
						value: a.limit || 12,
						onChange: function ( v ) { set( { limit: parseInt( v, 10 ) || 12 } ); },
						min: 1,
						max: 50,
						__nextHasNoMarginBottom: true
					} )
				)
			);

			// ServerSideRender renders through the same render_callback the front end uses.
			// If it is unavailable (very old core), fall back to a Placeholder rather than
			// rendering nothing, so the block is still selectable and configurable.
			var preview = serverSideRender
				? el( serverSideRender, {
					block: 'plugixa-recipe/recipes',
					attributes: a
				} )
				: el(
					Placeholder,
					{ icon: 'food', label: __( 'Recipes', 'plugixa-recipe' ) },
					__( 'The preview needs a newer version of WordPress. The block will still render on the front end.', 'plugixa-recipe' )
				);

			return el( 'div', blockProps, [ inspector, el( 'div', { key: 'preview' }, preview ) ] );
		},

		// Server-rendered: nothing is written into post content.
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
