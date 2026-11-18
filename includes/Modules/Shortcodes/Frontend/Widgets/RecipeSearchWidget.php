<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets;

use PlugixaRecipe\Modules\Shortcodes\Frontend\VisitorFilters;

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

        // What the visitor already chose, narrowed the same way the listing reads it.
        $current  = VisitorFilters::read();
        $expanded = 'expanded' === $atts['style'];

        // The expanded form offers every filter a listing can be narrowed by. A simple form
        // needs the terms too when a link brought one along, to name it in its chip.
        $categories = [];
        $cuisines   = [];
        $courses    = [];
        // Read-through object cache; these queries take no inputs.
        if ( $expanded || '' !== $current['category'] ) {
            $categories = $this->widgets()->search_categories();
        }
        if ( $expanded || '' !== $current['cuisine'] ) {
            $cuisines = $this->widgets()->search_cuisines();
        }
        if ( $expanded || '' !== $current['course'] ) {
            $courses = $this->widgets()->search_courses();
        }

        $form = $this->render_template( 'recipe-search', [
            'placeholder' => sanitize_text_field( $atts['placeholder'] ),
            'style'       => sanitize_key( $atts['style'] ),
            'action'      => esc_url( $atts['action'] ) ?: '',
            'title'       => sanitize_text_field( $atts['title'] ),
            'categories'  => $expanded ? $categories : [],
            'cuisines'    => $expanded ? $cuisines : [],
            'courses'     => $expanded ? $courses : [],
            'current'     => $current,
            'active'      => $this->active_filters( $current, $categories, $cuisines, $courses ),
            'clear_url'   => remove_query_arg( array_values( self::FIELDS ) ),
            'unique_id'   => 'pxr-widget-' . uniqid(),
        ] );

        return $form . $this->own_results( (string) $atts['action'] );
    }

    /** A VisitorFilters key and the GET field that carries it. */
    private const FIELDS = [
        'search'     => 'plugixa_recipe_search',
        'category'   => 'plugixa_recipe_category',
        'cuisine'    => 'plugixa_recipe_cuisine',
        'course'     => 'plugixa_recipe_course',
        'max_time'   => 'plugixa_recipe_time',
        'difficulty' => 'plugixa_recipe_difficulty',
        'diet'       => 'plugixa_recipe_diet',
    ];

    /**
     * One chip per choice in force: what it says and the address of this page without it.
     *
     * Built from VisitorFilters::read(), so a chip can only name a filter the listing applied.
     * A term that no longer exists is named by its slug: the listing shows nothing for it,
     * and the chip is the way out.
     *
     * @param array<string, string|int> $current
     * @param array<int, object>        $categories
     * @param array<int, object>        $cuisines
     * @param array<int, object>        $courses
     * @return array<int, array{label:string, url:string}>
     */
    private function active_filters( array $current, array $categories, array $cuisines, array $courses ): array {
        $term = static function ( array $terms, string $slug ): string {
            foreach ( $terms as $candidate ) {
                if ( $candidate->slug === $slug ) {
                    return (string) $candidate->name;
                }
            }
            return $slug;
        };

        $labels = [
            /* translators: %s: the words a visitor searched for. */
            'search'     => '' !== $current['search'] ? sprintf( __( '"%s"', 'plugixa-recipe' ), $current['search'] ) : '',
            'category'   => '' !== $current['category'] ? $term( $categories, $current['category'] ) : '',
            'cuisine'    => '' !== $current['cuisine'] ? $term( $cuisines, $current['cuisine'] ) : '',
            'course'     => '' !== $current['course'] ? $term( $courses, $current['course'] ) : '',
            /* translators: %s: a length of time, e.g. "30 mins". */
            'max_time'   => $current['max_time'] ? sprintf( __( 'Ready in %s', 'plugixa-recipe' ), \PlugixaRecipe\Modules\Recipes\Services\RecipePresenter::duration_label( $current['max_time'] * MINUTE_IN_SECONDS ) ) : '',
            'difficulty' => (string) ( \PlugixaRecipe\Modules\Recipes\Enums::difficulty_labels()[ $current['difficulty'] ] ?? '' ),
            'diet'       => (string) ( \PlugixaRecipe\Modules\Recipes\DietFlags::labels()[ $current['diet'] ] ?? '' ),
        ];

        $active = [];
        foreach ( $labels as $key => $label ) {
            if ( '' !== $label ) {
                $active[] = [
                    'label' => $label,
                    'url'   => remove_query_arg( self::FIELDS[ $key ] ),
                ];
            }
        }

        return $active;
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

        if ( ! VisitorFilters::submitted() ) {
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
