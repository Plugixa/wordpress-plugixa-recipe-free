<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets;

// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams -- False positive: this file contains no get_posts()/WP_Query call. "exclude" here is a SHORTCODE attribute naming plugin-owned custom-table category/cuisine IDs to omit; it is absint()-mapped and bound as %d placeholders, and never reaches WP_Query.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Frontend\Assets;
use PlugixaRecipe\Modules\Shortcodes\Repositories\WidgetRepository;

/**
 * What the widget shortcodes share: rendering a template (with the child-theme override),
 * the common assets, the settings they read and the card shape two of them draw.
 */
abstract class Widget {

    /** Built on first use, so a widget stays constructible with no arguments. */
    private ?WidgetRepository $widget_repository = null;

    /** The reads every widget shortcode goes through. */
    protected function widgets(): WidgetRepository {
        if (null === $this->widget_repository) {
            $this->widget_repository = new WidgetRepository();
        }

        return $this->widget_repository;
    }

    /** Register this widget's shortcode tag(s). */
    abstract public function register(): void;

    /**
     * Render a widget template and return the HTML.
     */
    protected function render_template( string $template_name, array $data ): string {
        $template = plugixa_recipe_template( "widgets/{$template_name}.php" );

        // Allow theme override
        $theme_template = get_stylesheet_directory() . "/plugixa-recipe/widgets/{$template_name}.php";
        if ( file_exists( $theme_template ) ) {
            $template = $theme_template;
        }

        if ( '' === $template || ! file_exists( $template ) ) {
            return '';
        }

        ob_start();
        extract( $data, EXTR_SKIP );
        include $template;
        return ob_get_clean();
    }

    /**
     * Enqueue frontend CSS (shared with main shortcode CSS).
     */
    protected function enqueue_assets(): void {
        Assets::widgets();
    }

    /**
     * Enrich a recipe with all data recipe-card.php needs.
     */
    protected function enrich_recipe_for_card( array $recipe ): object {
        $recipe_id = (int) $recipe['id'];

        // Image
        if ( ! empty( $recipe['image_id'] ) ) {
            $recipe['image_url']    = wp_get_attachment_image_url( (int) $recipe['image_id'], 'medium_large' ) ?: '';
            $recipe['image_srcset'] = wp_get_attachment_image_srcset( (int) $recipe['image_id'], 'medium_large' ) ?: '';
        } else {
            $recipe['image_url']    = '';
            $recipe['image_srcset'] = '';
        }

        // Categories — child rows cached under the owning recipe.
        $recipe['categories'] = $this->widgets()->card_categories( $recipe_id );

        // Cuisines — child rows cached under the owning recipe.
        $recipe['cuisines'] = $this->widgets()->card_cuisines( $recipe_id );

        // Diet badges
        $recipe['diet_badges'] = [];
        if ( ! empty( $recipe['is_vegetarian'] ) ) $recipe['diet_badges'][] = __( 'Vegetarian', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_vegan'] ) )      $recipe['diet_badges'][] = __( 'Vegan', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_gluten_free'] ) ) $recipe['diet_badges'][] = __( 'Gluten-Free', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_dairy_free'] ) )  $recipe['diet_badges'][] = __( 'Dairy-Free', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_keto'] ) )        $recipe['diet_badges'][] = __( 'Keto', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_low_carb'] ) )    $recipe['diet_badges'][] = __( 'Low-Carb', 'plugixa-recipe' );

        // Excerpt
        if ( ! empty( $recipe['description'] ) ) {
            $plain = wp_strip_all_tags( $recipe['description'] );
            $recipe['excerpt'] = mb_strlen( $plain ) > 150
                ? mb_substr( $plain, 0, 150 ) . "\u{2026}"
                : $plain;
        } else {
            $recipe['excerpt'] = '';
        }

        // Like/favorite status (per-user state: not cached).
        $recipe['like_count'] = isset( $recipe['like_count'] ) ? (int) $recipe['like_count'] : 0;
        if ( is_user_logged_in() ) {
            $uid = get_current_user_id();
            $recipe['is_liked']     = $this->widgets()->has_liked( $uid, $recipe_id );
            $recipe['is_favorited'] = $this->widgets()->has_favorited( $uid, $recipe_id );
        } else {
            $recipe['is_liked']     = false;
            $recipe['is_favorited'] = false;
        }

        // Template aliases
        $recipe['rating']    = $recipe['rating_average'] ?? 0;
        $recipe['cuisine']   = ! empty( $recipe['cuisines'][0]['name'] ) ? $recipe['cuisines'][0]['name'] : '';
        $slug                = sanitize_title( $recipe['title'] ?? '' );
        $recipe['permalink'] = plugixa_recipe_permalink( (int) $recipe_id, $slug );
        $recipe['print_url'] = home_url( "/recipe-print/{$recipe_id}/?auto=1" );

        return (object) $recipe;
    }

    /**
     * Get plugin settings.
     */
    protected function get_settings(): array {
        $defaults = [
            'show_nutrition'     => true,
            'show_reviews'       => true,
            'enable_print'       => true,
            'excerpt_length'     => 150,
            'date_format'        => 'M j, Y',
        ];

        return array_merge( $defaults, (array) get_option( 'plugixa_recipe_settings', [] ) );
    }
}
