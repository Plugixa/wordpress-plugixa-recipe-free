<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets;

// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams -- False positive: this file contains no get_posts()/WP_Query call. "exclude" here is a SHORTCODE attribute naming plugin-owned custom-table category/cuisine IDs to omit; it is absint()-mapped and bound as %d placeholders, and never reaches WP_Query.

defined( 'ABSPATH' ) || exit;

/**
 * [plugixa_recipe_count] - the number of published recipes, as a badge or text.
 */
final class RecipeCountWidget extends Widget {

    public function register(): void {
        add_shortcode( 'plugixa_recipe_count', [ $this, 'render_recipe_count' ] );
    }

    /**
     * Render [plugixa_recipe_count] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_recipe_count( $atts ): string {
        $atts = shortcode_atts( [
            'prefix'   => '',
            'suffix'   => ' ' . __( 'Recipes', 'plugixa-recipe' ),
            'style'    => 'badge', // badge, text
            'category' => '',
        ], $atts, 'plugixa_recipe_count' );

        $category = sanitize_text_field( $atts['category'] );
        $cache_key = 'plugixa_recipe_count_cache' . ( $category ? '_' . $category : '' );

        $count = get_transient( $cache_key );
        if ( false === $count ) {
            if ( $category ) {
                $count = $this->widgets()->count_published_in_category( $category );
            } else {
                $count = $this->widgets()->count_published();
            }

            set_transient( $cache_key, $count, HOUR_IN_SECONDS );
        }

        $this->enqueue_assets();

        return $this->render_template( 'recipe-count', [
            'count'  => (int) $count,
            'prefix' => sanitize_text_field( $atts['prefix'] ),
            'suffix' => sanitize_text_field( $atts['suffix'] ),
            'style'  => sanitize_key( $atts['style'] ),
        ] );
    }

}
