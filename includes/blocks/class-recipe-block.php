<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Blocks;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Frontend\Shortcodes;

/**
 * RecipeBlock Class - Registers the Gutenberg block 'plugixa-recipe/recipes'.
 *
 * Server-side rendering callback that reuses the same logic as the shortcode system.
 */
class RecipeBlock {

    /**
     * Register the Gutenberg block.
     */
    public function register(): void {
        if (!function_exists('register_block_type')) {
            return;
        }

        // The block is server-rendered through the shortcode, so ServerSideRender shows the
        // real frontend markup in the editor - which needs the real frontend stylesheet.
        // Without it the preview renders unstyled: recipe images fall back to their
        // intrinsic size and fill the canvas as full-width blobs.
        $frontend_css = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if (file_exists($frontend_css)) {
            wp_register_style(
                'plugixa-recipe-block-editor-style',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Registered programmatically rather than from a block.json. There is no build
        // step for this block, so there is no generated metadata file to read: the
        // attribute schema below is the single source of truth and is mirrored in
        // assets/blocks/recipe-block/index.js. (A block.json branch used to sit here
        // pointing at assets/build/blocks/, a directory that has never existed.)
        register_block_type('plugixa-recipe/recipes', [
            'api_version'     => 3,
            'editor_script'   => 'plugixa-recipe-block-editor',
            'editor_style'    => 'plugixa-recipe-block-editor-style',
            'render_callback' => [$this, 'render_callback'],
            'attributes'      => [
                'shortcodeId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'viewId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'categories' => [
                    'type'    => 'string',
                    'default' => '',
                ],
                'limit' => [
                    'type'    => 'number',
                    'default' => 12,
                ],
            ],
        ]);

        // Register editor script.
        //
        // assets/blocks/recipe-block/index.js is hand-written plain JavaScript against the
        // wp.* globals - there is no build step behind it, so the shipped file IS the source.
        // It therefore lives beside the other hand-written assets and never under a build/
        // directory. Until 1.0.2 this pointed at assets/build/blocks/, which has never
        // existed: the handle below was never registered, so the block registered with an
        // editor_script that core could not find and could not be inserted in the editor at
        // all, while readme.txt advertised it.
        $editor_js = PLUGIXA_RECIPE_PATH . 'assets/blocks/recipe-block/index.js';

        if (file_exists($editor_js)) {
            wp_register_script(
                'plugixa-recipe-block-editor',
                PLUGIXA_RECIPE_URL . 'assets/blocks/recipe-block/index.js',
                // wp-block-editor, not the deprecated wp-editor: InspectorControls and
                // useBlockProps live there, and api_version 3 expects it.
                ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            // Pass data to the block editor. The object name must be a valid JavaScript
            // identifier: wp_localize_script() emits `var <name> = {...}`, so the previous
            // hyphenated 'plugixa-recipeBlockData' would have been a syntax error that took
            // the whole inline script - and the block with it - down.
            wp_localize_script('plugixa-recipe-block-editor', 'plugixaRecipeBlockData', [
                'shortcodes' => $this->get_shortcodes_for_editor(),
                'views'      => $this->get_views_for_editor(),
                'categories' => $this->get_categories_for_editor(),
            ]);

            wp_set_script_translations('plugixa-recipe-block-editor', 'plugixa-recipe');
        }
    }

    /**
     * Server-side render callback for the block.
     *
     * Uses the same logic as the [plugixa_recipes] shortcode.
     *
     * @param array $attributes Block attributes.
     * @return string Rendered HTML.
     */
    public function render_callback(array $attributes): string {
        $shortcode_atts = [];

        if (!empty($attributes['shortcodeId'])) {
            $shortcode_atts['id'] = (int) $attributes['shortcodeId'];
        }

        if (!empty($attributes['viewId'])) {
            $shortcode_atts['view'] = (int) $attributes['viewId'];
        }

        if (!empty($attributes['categories'])) {
            $shortcode_atts['categories'] = sanitize_text_field($attributes['categories']);
        }

        if (!empty($attributes['limit'])) {
            $shortcode_atts['limit'] = (int) $attributes['limit'];
        }

        // Reuse the shortcode rendering logic
        $shortcodes = new Shortcodes();
        return $shortcodes->render_recipes($shortcode_atts);
    }

    /**
     * Get available shortcodes for the block editor dropdown.
     */
    private function get_shortcodes_for_editor(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_shortcodes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, block-editor load only.
        $results = $wpdb->get_results(
            $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $table),
            ARRAY_A
        );

        $options = [['label' => __('-- Select a Shortcode --', 'plugixa-recipe'), 'value' => 0]];
        foreach ($results ?: [] as $row) {
            $options[] = ['label' => $row['name'], 'value' => (int) $row['id']];
        }

        return $options;
    }

    /**
     * Get available views for the block editor dropdown.
     */
    private function get_views_for_editor(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, block-editor load only.
        $results = $wpdb->get_results(
            $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $table),
            ARRAY_A
        );

        $options = [['label' => __('-- Default View --', 'plugixa-recipe'), 'value' => 0]];
        foreach ($results ?: [] as $row) {
            $options[] = ['label' => $row['name'], 'value' => (int) $row['id']];
        }

        return $options;
    }

    /**
     * Get available categories for the block editor.
     */
    private function get_categories_for_editor(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_categories';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, block-editor load only.
        $results = $wpdb->get_results(
            $wpdb->prepare('SELECT id, name, slug FROM %i ORDER BY display_order ASC', $table),
            ARRAY_A
        );

        return $results ?: [];
    }
}
