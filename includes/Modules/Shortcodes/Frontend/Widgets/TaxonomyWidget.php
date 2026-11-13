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
 * [plugixa_categories] and [plugixa_cuisines] - a list, cloud or dropdown of terms with links.
 */
final class TaxonomyWidget extends Widget {

    public function register(): void {
        add_shortcode( 'plugixa_categories', [ $this, 'render_categories' ] );
        add_shortcode( 'plugixa_cuisines', [ $this, 'render_cuisines' ] );
    }

    /**
     * Render [plugixa_categories] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_categories( $atts ): string {
        $atts = shortcode_atts( [
            'limit'      => 10,
            'orderby'    => 'display_order', // display_order, name, count
            'order'      => 'ASC',
            'show_count' => 'true',
            'style'      => 'list',          // list, pills, grid
            'exclude'    => '',
            'title'      => '',
        ], $atts, 'plugixa_categories' );

        return $this->render_taxonomy( 'category', $atts );
    }

    /**
     * Render [plugixa_cuisines] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_cuisines( $atts ): string {
        $atts = shortcode_atts( [
            'limit'      => 10,
            'orderby'    => 'display_order',
            'order'      => 'ASC',
            'show_count' => 'true',
            'style'      => 'list',
            'exclude'    => '',
            'title'      => '',
        ], $atts, 'plugixa_cuisines' );

        return $this->render_taxonomy( 'cuisine', $atts );
    }

    /**
     * Shared renderer for category/cuisine taxonomy widgets.
     */
    private function render_taxonomy( string $type, array $atts ): string {
        $archive_base = plugixa_recipe_url_slug( $type === 'cuisine' ? 'cuisine' : 'category' );

        // Build query
        $limit  = absint( $atts['limit'] ) ?: 10;
        $order  = strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        // Allow-list mapped to [table alias, column]. The alias is kept separate from the
        // column because the column is bound with the %i identifier placeholder in the query, and
        // %i quotes its whole argument as ONE identifier - passing "t.display_order" would
        // ask MySQL for a column literally named "t.display_order". 'count' sorts by the
        // recipe_count SELECT alias, which has no table qualifier.
        $orderby_map = [
            'name'          => [ 't.', 'name' ],
            'count'         => [ '', 'recipe_count' ],
            'display_order' => [ 't.', 'display_order' ],
        ];
        [ $order_alias, $orderby ] = $orderby_map[ $atts['orderby'] ] ?? [ 't.', 'display_order' ];

        // The ids to leave out; the repository binds them as one list.
        $exclude_ids = [];
        if ( ! empty( $atts['exclude'] ) ) {
            $exclude_ids = array_filter( array_map( 'absint', explode( ',', $atts['exclude'] ) ) );
        }

        // Language filter
        $multilingual = \PlugixaRecipe\Modules\Multilingual\Services\Multilingual::instance();
        $lang         = $multilingual->is_active() ? $multilingual->get_current_language() : '';

        // Terms with their count of PUBLISHED recipes, cached under the term entity and
        // 'recipes' - see WidgetRepository::terms_with_counts().
        $items = $this->widgets()->terms_with_counts( $type, $exclude_ids, $lang, $orderby, $order_alias, $order, $limit );

        if ( empty( $items ) ) {
            return '';
        }

        // Build permalink for each item
        foreach ( $items as $item ) {
            $item->permalink = home_url( "/{$archive_base}/" . rawurlencode( $item->slug ) . '/' );
            if ( ! empty( $item->image_id ) ) {
                $item->image_url = wp_get_attachment_image_url( (int) $item->image_id, 'thumbnail' ) ?: '';
            } else {
                $item->image_url = '';
            }
        }

        $this->enqueue_assets();

        return $this->render_template( 'categories', [
            'items'      => $items,
            'type'       => $type,
            'style'      => sanitize_key( $atts['style'] ),
            'show_count' => filter_var( $atts['show_count'], FILTER_VALIDATE_BOOLEAN ),
            'title'      => sanitize_text_field( $atts['title'] ),
            'unique_id'  => 'pxr-widget-' . uniqid(),
        ] );
    }

}
