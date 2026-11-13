/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe Gutenberg block — "Recipe" (plugixa-recipe/recipe): one whole recipe in a post.
 *
 * Plain JavaScript against the wp.* globals, like the Recipes block beside it: there is no
 * build step behind this file, so what ships IS the source. It is enqueued as the handle
 * "plugixa-recipe-single-block-editor" by PlugixaRecipe\Modules\Shortcodes\Blocks\SingleRecipeBlock.
 *
 * Rendering is server-side: the block stores the recipe's id and PHP draws the recipe with
 * the template its own page uses, so the editor preview and the front end cannot drift.
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
	var ComboboxControl = components.ComboboxControl;
	var Placeholder = components.Placeholder;

	// Published recipes, provided by wp_localize_script(). Empty on a site with none yet.
	var recipes = ( window.plugixaRecipeSingleBlockData || {} ).recipes || [];

	function picker( value, onChange ) {
		return el( ComboboxControl, {
			label: __( 'Recipe', 'plugixa-recipe' ),
			help: recipes.length
				? __( 'Type to search your published recipes.', 'plugixa-recipe' )
				: __( 'There is no published recipe yet.', 'plugixa-recipe' ),
			value: value || null,
			options: recipes,
			onChange: function ( id ) { onChange( parseInt( id, 10 ) || 0 ); },
			__nextHasNoMarginBottom: true
		} );
	}

	blocks.registerBlockType( 'plugixa-recipe/recipe', {
		apiVersion: 3,
		title: __( 'Recipe', 'plugixa-recipe' ),
		description: __( 'Show one whole recipe - ingredients, method, nutrition - inside this post.', 'plugixa-recipe' ),
		icon: 'food',
		category: 'widgets',
		keywords: [ __( 'recipe', 'plugixa-recipe' ), __( 'recipe card', 'plugixa-recipe' ), __( 'cooking', 'plugixa-recipe' ) ],
		supports: { html: false, align: [ 'wide', 'full' ] },

		// Kept in sync with the PHP registration in SingleRecipeBlock.php.
		attributes: {
			recipeId: { type: 'number', default: 0 },
			align: { type: 'string', default: '' }
		},

		edit: function ( props ) {
			var id = props.attributes.recipeId;
			var blockProps = useBlockProps();
			var set = function ( value ) { props.setAttributes( { recipeId: value } ); };

			var inspector = el(
				InspectorControls,
				{ key: 'inspector' },
				el( PanelBody, { title: __( 'Recipe', 'plugixa-recipe' ), initialOpen: true }, picker( id, set ) )
			);

			var body;
			if ( ! id ) {
				// Nothing chosen yet: the picker is the block.
				body = el(
					Placeholder,
					{ key: 'placeholder', icon: 'food', label: __( 'Recipe', 'plugixa-recipe' ), instructions: __( 'Choose the recipe this post is about.', 'plugixa-recipe' ) },
					el( 'div', { style: { width: '100%', maxWidth: '420px' } }, picker( id, set ) )
				);
			} else if ( serverSideRender ) {
				body = el( 'div', { key: 'preview' }, el( serverSideRender, {
					block: 'plugixa-recipe/recipe',
					attributes: { recipeId: id }
				} ) );
			} else {
				body = el(
					Placeholder,
					{ key: 'placeholder', icon: 'food', label: __( 'Recipe', 'plugixa-recipe' ) },
					__( 'The preview needs a newer version of WordPress. The recipe will still show on the front end.', 'plugixa-recipe' )
				);
			}

			return el( 'div', blockProps, [ inspector, body ] );
		},

		// Server-rendered: nothing is written into post content but the block's comment.
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
