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
 * [plugixa_recipe_of_day] - one recipe, the same for every visitor until midnight.
 */
final class RecipeOfDayWidget extends Widget {

    public function register(): void {
        add_shortcode( 'plugixa_recipe_of_day', [ $this, 'render_recipe_of_day' ] );
    }

    /**
     * Render [plugixa_recipe_of_day] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_recipe_of_day( $atts ): string {
        $atts = shortcode_atts( [
            'style'       => 'card',  // card, compact
            'show_rating' => 'true',
            'show_time'   => 'true',
            'title'       => __( 'Recipe of the Day', 'plugixa-recipe' ),
        ], $atts, 'plugixa_recipe_of_day' );

        $recipe = $this->get_recipe_of_day();

        if ( ! $recipe ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_template( 'recipe-of-day', [
            'recipe'      => $recipe,
            'style'       => sanitize_key( $atts['style'] ),
            'show_rating' => filter_var( $atts['show_rating'], FILTER_VALIDATE_BOOLEAN ),
            'show_time'   => filter_var( $atts['show_time'], FILTER_VALIDATE_BOOLEAN ),
            'title'       => sanitize_text_field( $atts['title'] ),
            'unique_id'   => 'pxr-widget-' . uniqid(),
        ] );
    }

    /**
     * Get the "recipe of the day" — deterministic per day via transient + seeded random.
     */
    private function get_recipe_of_day(): ?object {
        $transient_key = 'plugixa_recipe_of_day_pick';
        $recipe_id     = get_transient( $transient_key );

        if ( $recipe_id ) {
            // Verify the recipe still exists and is published — read-through object cache.
            $recipe = $this->widgets()->published_pick( $recipe_id );

            if ( $recipe ) {
                return $this->enrich_recipe_for_card( $recipe );
            }
        }

        // Pick a new recipe — prefer featured, fallback to any published
        $daily_seed = crc32( gmdate( 'Y-m-d' ) );

        // Read-through object cache; key = the daily seed (the only input).
        $recipe = $this->widgets()->featured_pick( $daily_seed );

        if ( ! $recipe ) {
            $recipe = $this->widgets()->any_pick( $daily_seed );
        }

        if ( ! $recipe ) {
            return null;
        }

        set_transient( $transient_key, $recipe['id'], DAY_IN_SECONDS );

        return $this->enrich_recipe_for_card( $recipe );
    }

}
