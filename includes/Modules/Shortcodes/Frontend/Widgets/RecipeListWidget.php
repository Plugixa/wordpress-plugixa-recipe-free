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
 * [plugixa_latest_recipes] and [plugixa_popular_recipes] - a compact recipe list.
 */
final class RecipeListWidget extends Widget {

    public function register(): void {
        add_shortcode( 'plugixa_latest_recipes', [ $this, 'render_latest_recipes' ] );
        add_shortcode( 'plugixa_popular_recipes', [ $this, 'render_popular_recipes' ] );
    }

    /**
     * Render [plugixa_latest_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_latest_recipes( $atts ): string {
        $atts = shortcode_atts( [
            'limit'          => 5,
            'show_thumbnail' => 'true',
            'show_category'  => 'true',
            'show_date'      => 'true',
            'thumbnail_size' => 'thumbnail',
            'category'       => '',
            'title'          => __( 'Latest Recipes', 'plugixa-recipe' ),
        ], $atts, 'plugixa_latest_recipes' );

        $recipes = $this->query_compact_recipes( [
            'limit'          => absint( $atts['limit'] ) ?: 5,
            'orderby'        => 'published_at',
            'order'          => 'DESC',
            'category'       => sanitize_text_field( $atts['category'] ),
            'thumbnail_size' => sanitize_key( $atts['thumbnail_size'] ),
        ] );

        if ( empty( $recipes ) ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_template( 'latest-recipes', [
            'recipes'        => $recipes,
            'show_thumbnail' => filter_var( $atts['show_thumbnail'], FILTER_VALIDATE_BOOLEAN ),
            'show_category'  => filter_var( $atts['show_category'], FILTER_VALIDATE_BOOLEAN ),
            'show_date'      => filter_var( $atts['show_date'], FILTER_VALIDATE_BOOLEAN ),
            'title'          => sanitize_text_field( $atts['title'] ),
            'unique_id'      => 'pxr-widget-' . uniqid(),
        ] );
    }

    /**
     * Render [plugixa_popular_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_popular_recipes( $atts ): string {
        $atts = shortcode_atts( [
            'limit'          => 5,
            'orderby'        => 'views', // views, rating
            'show_thumbnail' => 'true',
            'show_category'  => 'true',
            'show_rating'    => 'false',
            'show_views'     => 'false',
            'title'          => __( 'Popular Recipes', 'plugixa-recipe' ),
        ], $atts, 'plugixa_popular_recipes' );

        $orderby = $atts['orderby'] === 'rating' ? 'rating_average' : 'view_count';

        $recipes = $this->query_compact_recipes( [
            'limit'          => absint( $atts['limit'] ) ?: 5,
            'orderby'        => $orderby,
            'order'          => 'DESC',
            'thumbnail_size' => 'thumbnail',
        ] );

        if ( empty( $recipes ) ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_template( 'popular-recipes', [
            'recipes'        => $recipes,
            'show_thumbnail' => filter_var( $atts['show_thumbnail'], FILTER_VALIDATE_BOOLEAN ),
            'show_category'  => filter_var( $atts['show_category'], FILTER_VALIDATE_BOOLEAN ),
            'show_rating'    => filter_var( $atts['show_rating'], FILTER_VALIDATE_BOOLEAN ),
            'show_views'     => filter_var( $atts['show_views'], FILTER_VALIDATE_BOOLEAN ),
            'title'          => sanitize_text_field( $atts['title'] ),
            'unique_id'      => 'pxr-widget-' . uniqid(),
        ] );
    }

    /**
     * Query recipes for compact list widgets (latest/popular).
     * Lightweight enrichment: image + first category + permalink only.
     */
    private function query_compact_recipes( array $config ): array {
        // The category filter: a comma-separated list of slugs, or none.
        $cat_slugs = ! empty( $config['category'] )
            ? array_filter( array_map( 'trim', explode( ',', $config['category'] ) ) )
            : [];

        // Language filter
        $multilingual = \PlugixaRecipe\Modules\Multilingual\Services\Multilingual::instance();
        $lang         = $multilingual->is_active() ? $multilingual->get_current_language() : '';

        $allowed_orderby = [ 'published_at', 'created_at', 'view_count', 'rating_average' ];
        $orderby = in_array( $config['orderby'], $allowed_orderby, true ) ? $config['orderby'] : 'published_at';
        $order   = strtoupper( $config['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = absint( $config['limit'] ) ?: 5;

        $results = $this->widgets()->compact_recipes( $cat_slugs, $lang, $orderby, $order, $limit );

        if ( empty( $results ) ) {
            return [];
        }

        $thumbnail_size = $config['thumbnail_size'] ?? 'thumbnail';

        // Batch-load first category for all recipes — read-through object cache; key = recipe ids.
        $recipe_ids = wp_list_pluck( $results, 'id' );
        $cat_rows   = $this->widgets()->categories_of( $recipe_ids );

        // Index: first category per recipe
        $cat_map = [];
        foreach ( $cat_rows as $row ) {
            $rid = (int) $row->recipe_id;
            if ( ! isset( $cat_map[ $rid ] ) ) {
                $cat_map[ $rid ] = $row;
            }
        }

        // Enrich each recipe
        $settings = $this->get_settings();
        foreach ( $results as $recipe ) {
            $rid = (int) $recipe->id;

            // Image
            if ( ! empty( $recipe->image_id ) ) {
                $recipe->image_url = wp_get_attachment_image_url( (int) $recipe->image_id, $thumbnail_size ) ?: '';
            } else {
                $recipe->image_url = '';
            }

            // First category
            $recipe->category_name = isset( $cat_map[ $rid ] ) ? $cat_map[ $rid ]->name : '';
            $recipe->category_slug = isset( $cat_map[ $rid ] ) ? $cat_map[ $rid ]->slug : '';

            // Permalink
            $slug = sanitize_title( $recipe->title ?? '' );
            $recipe->permalink = plugixa_recipe_permalink( (int) $rid, $slug );

            // Formatted date
            $date_format    = $settings['date_format'] ?? 'M j, Y';
            $date_str       = $recipe->published_at ?? $recipe->created_at;
            $recipe->date   = $date_str ? date_i18n( $date_format, strtotime( $date_str ) ) : '';

            // Aliases
            $recipe->rating = floatval( $recipe->rating_average ?? 0 );
        }

        return $results;
    }

}
