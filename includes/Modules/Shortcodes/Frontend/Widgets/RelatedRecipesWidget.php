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
 * [plugixa_related_recipes] - cards for recipes sharing a category or cuisine. Also drawn
 * under the single recipe page.
 */
final class RelatedRecipesWidget extends Widget {

    public function register(): void {
        add_shortcode( 'plugixa_related_recipes', [ $this, 'render_related_recipes' ] );
    }

    /**
     * Render [plugixa_related_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_related_recipes( $atts ): string {
        $atts = shortcode_atts( [
            'recipe_id' => 0,
            'limit'     => 4,
            'columns'   => 4,
            'relation'  => 'both', // category, cuisine, both
            'title'     => __( 'You May Also Like', 'plugixa-recipe' ),
        ], $atts, 'plugixa_related_recipes' );

        // Auto-detect recipe ID from single recipe page
        $recipe_id = absint( $atts['recipe_id'] );
        if ( ! $recipe_id ) {
            $recipe_id = absint( get_query_var( 'plugixa_recipe_id', 0 ) );
        }

        if ( ! $recipe_id ) {
            return '';
        }

        $recipes = $this->query_related_recipes( $recipe_id, [
            'limit'    => absint( $atts['limit'] ) ?: 4,
            'relation' => sanitize_key( $atts['relation'] ),
        ] );

        if ( empty( $recipes ) ) {
            return '';
        }

        $this->enqueue_assets();

        $columns = absint( $atts['columns'] ) ?: 4;

        return $this->render_template( 'related-recipes', [
            'recipes'   => $recipes,
            'columns'   => $columns,
            'title'     => sanitize_text_field( $atts['title'] ),
            'unique_id' => 'pxr-widget-' . uniqid(),
            // Provide a minimal view_config for recipe-card.php
            'view_config' => [
                'show_image'      => true,
                'show_rating'     => true,
                'show_meta'       => true,
                'show_difficulty' => true,
                'show_diet'       => false,
                'show_cuisine'    => false,
                'show_excerpt'    => false,
            ],
        ] );
    }

    /**
     * Query related recipes based on shared categories/cuisines.
     */
    private function query_related_recipes( int $recipe_id, array $config ): array {
        $limit    = absint( $config['limit'] ) ?: 4;
        $relation = $config['relation'] ?? 'both';

        // Get current recipe's category and cuisine IDs
        $cat_ids     = [];
        $cuisine_ids = [];

        // Read-through object cache; child rows cached under the owning recipe.
        if ( in_array( $relation, [ 'category', 'both' ], true ) ) {
            $cat_ids = $this->widgets()->category_ids_of( $recipe_id );
        }

        if ( in_array( $relation, [ 'cuisine', 'both' ], true ) ) {
            $cuisine_ids = $this->widgets()->cuisine_ids_of( $recipe_id );
        }

        if ( empty( $cat_ids ) && empty( $cuisine_ids ) ) {
            return [];
        }

        // At least one of the two id lists is non-empty here; the query relies on that -
        // see WidgetRepository::related_recipes().
        $results = $this->widgets()->related_recipes( $recipe_id, $cat_ids, $cuisine_ids, $limit );

        if ( empty( $results ) ) {
            return [];
        }

        // Full enrichment for recipe-card.php
        return array_map( [ $this, 'enrich_recipe_for_card' ], $results );
    }
}
