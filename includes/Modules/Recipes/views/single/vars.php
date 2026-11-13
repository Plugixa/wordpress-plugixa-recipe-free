<?php
/**
 * The variables every part of the single recipe view reads, taken from the presented recipe
 * and the view config. Included first by single-recipe.php (and by any other layout of the
 * recipe page) in the scope that then includes the parts.
 *
 * @var object $recipe      The presented recipe (Services\RecipePresenter).
 * @var array  $view_config The decoded view configuration.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.

// Recipe data extraction.
$recipe_id         = isset( $recipe->id ) ? absint( $recipe->id ) : 0;
$recipe_title             = isset( $recipe->title ) ? $recipe->title : '';
// h1 on the recipe's own page; a recipe embedded in a post is an h2 under the post's title.
$title_level       = isset( $title_level ) ? min( 6, max( 1, (int) $title_level ) ) : 1;
$description       = isset( $recipe->description ) ? $recipe->description : '';
$image_url         = isset( $recipe->image_url ) ? $recipe->image_url : '';
$image_alt         = isset( $recipe->image_alt ) ? $recipe->image_alt : $recipe_title;
$author_name       = isset( $recipe->author_name ) ? $recipe->author_name : '';
$published_date    = isset( $recipe->published_date ) ? $recipe->published_date : '';
// <time datetime> takes ISO 8601; the formatted date was printed there too.
$published_iso     = isset( $recipe->published_iso ) ? $recipe->published_iso : '';
$rating            = isset( $recipe->rating ) ? floatval( $recipe->rating ) : 0;
$rating_count      = isset( $recipe->rating_count ) ? absint( $recipe->rating_count ) : 0;
$prep_time         = isset( $recipe->prep_time ) ? absint( $recipe->prep_time ) : 0;
$cook_time         = isset( $recipe->cook_time ) ? absint( $recipe->cook_time ) : 0;
// The stored column counts rest time too; the sum is for a caller that did not select it.
$total_time        = isset( $recipe->total_time )
    ? absint( $recipe->total_time )
    : $prep_time + $cook_time + ( isset( $recipe->rest_time ) ? absint( $recipe->rest_time ) : 0 );
$servings          = isset( $recipe->servings ) ? absint( $recipe->servings ) : 0;
// The label ("Easy"); a caller that passes only the stored value shows that.
$difficulty        = ! empty( $recipe->difficulty_label ) ? $recipe->difficulty_label : ( isset( $recipe->difficulty ) ? $recipe->difficulty : '' );
$cuisine           = isset( $recipe->cuisine ) ? $recipe->cuisine : '';
$diet_badges       = isset( $recipe->diet_badges ) && is_array( $recipe->diet_badges ) ? $recipe->diet_badges : array();
$categories        = isset( $recipe->categories ) && is_array( $recipe->categories ) ? $recipe->categories : array();
$ingredient_groups = isset( $recipe->ingredient_groups ) && is_array( $recipe->ingredient_groups ) ? $recipe->ingredient_groups : array();
$instruction_groups = isset( $recipe->instruction_groups ) && is_array( $recipe->instruction_groups ) ? $recipe->instruction_groups : array();
$nutrition         = isset( $recipe->nutrition ) ? $recipe->nutrition : null;
$reviews           = isset( $recipe->reviews ) && is_array( $recipe->reviews ) ? $recipe->reviews : array();
$gallery_urls      = isset( $recipe->gallery_urls ) && is_array( $recipe->gallery_urls ) ? $recipe->gallery_urls : array();
$video_url         = isset( $recipe->video_url ) ? $recipe->video_url : '';
$video_embed       = isset( $recipe->video_embed ) ? $recipe->video_embed : '';
$print_url         = isset( $recipe->print_url ) ? $recipe->print_url : '#';
$share_url         = isset( $recipe->permalink ) ? $recipe->permalink : '';
$is_favorited      = isset( $recipe->is_favorited ) ? (bool) $recipe->is_favorited : false;

// Rating distribution for overview bars.
$rating_distribution = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );
foreach ( $reviews as $rev ) {
    $r = isset( $rev->rating ) ? absint( $rev->rating ) : 0;
    if ( $r >= 1 && $r <= 5 ) {
        $rating_distribution[ $r ]++;
    }
}
$total_reviews_for_bars = array_sum( $rating_distribution );

// View config.
$show_nutrition    = isset( $view_config['show_nutrition'] ) ? (bool) $view_config['show_nutrition'] : true;
$show_reviews      = isset( $view_config['show_reviews'] ) ? (bool) $view_config['show_reviews'] : true;
$show_share        = isset( $view_config['show_share'] ) ? (bool) $view_config['show_share'] : true;
$show_print        = isset( $view_config['show_print'] ) ? (bool) $view_config['show_print'] : true;
$show_favorite     = isset( $view_config['show_favorite'] ) ? (bool) $view_config['show_favorite'] : true;
$show_video        = isset( $view_config['show_video'] ) ? (bool) $view_config['show_video'] : true;
$show_gallery      = isset( $view_config['show_gallery'] ) ? (bool) $view_config['show_gallery'] : true;
$enable_reviews    = isset( $view_config['enable_reviews'] ) ? (bool) $view_config['enable_reviews'] : true;

$plugixa_recipe_global_settings = get_option( 'plugixa_recipe_settings', [] );

// A module switched off in the App Manager overrides the view config: its REST routes do not
// exist, so drawing its UI would draw buttons that 404.
if ( ! plugixa_recipe_app_enabled( 'nutrition' ) ) {
    $show_nutrition = false;
}
// Ratings are the Reviews module's data: the badges
// follow the module, not the per-view show_reviews option, which only hides the list.
$reviews_app_on = plugixa_recipe_app_enabled( 'reviews' );
if ( ! $reviews_app_on ) {
    $show_reviews = false;
    $enable_reviews = false;
}
if ( ! plugixa_recipe_app_enabled( 'favorites' ) ) {
    $show_favorite = false;
}

// Time formatting helper.
if ( ! function_exists( 'plugixa_recipe_format_time' ) ) {
    function plugixa_recipe_format_time( $minutes ) {
        if ( $minutes < 60 ) {
            /* translators: %d: number of minutes. */
            return sprintf( _n( '%d min', '%d mins', $minutes, 'plugixa-recipe' ), $minutes );
        }
        $hours = floor( $minutes / 60 );
        $mins  = $minutes % 60;
        if ( $mins === 0 ) {
            /* translators: %d: number of hours. */
            return sprintf( _n( '%d hr', '%d hrs', $hours, 'plugixa-recipe' ), $hours );
        }
        /* translators: 1: number of hours, 2: number of minutes. */
        return sprintf( __( '%1$d hr %2$d min', 'plugixa-recipe' ), $hours, $mins );
    }
}
