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
 * [plugixa_recipe_search] - a recipe search box.
 */
final class RecipeSearchWidget extends Widget {

    public function register(): void {
        add_shortcode( 'plugixa_recipe_search', [ $this, 'render_recipe_search' ] );
    }

    /**
     * Render [plugixa_recipe_search] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_recipe_search( $atts ): string {
        $atts = shortcode_atts( [
            'placeholder' => __( 'Search recipes...', 'plugixa-recipe' ),
            'style'       => 'simple', // simple, expanded
            'action'      => '',
            'title'       => '',
        ], $atts, 'plugixa_recipe_search' );

        $this->enqueue_assets();

        // For expanded style, load categories and cuisines
        $categories = [];
        $cuisines   = [];
        if ( $atts['style'] === 'expanded' ) {
            // Read-through object cache; these queries take no inputs.
            $categories = $this->widgets()->search_categories();
            $cuisines   = $this->widgets()->search_cuisines();
        }

        $form = $this->render_template( 'recipe-search', [
            'placeholder' => sanitize_text_field( $atts['placeholder'] ),
            'style'       => sanitize_key( $atts['style'] ),
            'action'      => esc_url( $atts['action'] ) ?: '',
            'title'       => sanitize_text_field( $atts['title'] ),
            'categories'  => $categories,
            'cuisines'    => $cuisines,
            'unique_id'   => 'pxr-widget-' . uniqid(),
        ] );

        return $form . $this->own_results( (string) $atts['action'] );
    }

    /** Set once the results below have been printed, so two forms on a page list them once. */
    private static bool $listed = false;

    /**
     * The results of a submitted search, when nothing else on the page will show them.
     *
     * The form posts back to its own page and a listing there answers it
     * (ListingShortcode::with_visitor_search()). On a page that holds the form and no
     * listing, a search reloaded the page and changed nothing. In that one case the form
     * lists the matches itself, through the same listing shortcode.
     *
     * It stays out of the way everywhere else: with an `action` (the results belong to that
     * page), and when this form was not written in the page's own content - a sidebar, a
     * footer, a page builder - because then the content cannot say whether a listing is on
     * the page, and a second list of the same recipes is worse than none.
     */
    private function own_results( string $action ): string {
        if ( self::$listed || '' !== $action ) {
            return '';
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- presence check of the read-only public search fields; see ListingShortcode::with_visitor_search().
        $submitted = ! empty( $_GET['plugixa_recipe_search'] ) || ! empty( $_GET['plugixa_recipe_category'] ) || ! empty( $_GET['plugixa_recipe_cuisine'] );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! $submitted ) {
            return '';
        }

        $page = get_queried_object();
        if ( ! $page instanceof \WP_Post || ! has_shortcode( $page->post_content, 'plugixa_recipe_search' ) ) {
            return '';
        }
        if ( has_shortcode( $page->post_content, 'plugixa_recipe' ) || has_shortcode( $page->post_content, 'plugixa_recipes' ) || has_block( 'plugixa-recipe/recipes', $page ) ) {
            return '';
        }

        self::$listed = true;

        return do_shortcode( '[plugixa_recipe]' );
    }

}
