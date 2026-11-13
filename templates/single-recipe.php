<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Single Recipe Template
 *
 * Full single recipe view for frontend display with hero section,
 * ingredients, instructions, nutrition facts, reviews, and action buttons.
 *
 * Available variables:
 * @var object $recipe          Full recipe object with all fields
 * @var array  $view_config     Decoded JSON view configuration
 * @var string $css_vars_style  Inline CSS with --pxr-* variables
 * @var string $unique_id       Unique instance ID
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

// Recipe data extraction.
$recipe_id         = isset( $recipe->id ) ? absint( $recipe->id ) : 0;
$recipe_title             = isset( $recipe->title ) ? $recipe->title : '';
$description       = isset( $recipe->description ) ? $recipe->description : '';
$image_url         = isset( $recipe->image_url ) ? $recipe->image_url : '';
$image_alt         = isset( $recipe->image_alt ) ? $recipe->image_alt : $recipe_title;
$author_name       = isset( $recipe->author_name ) ? $recipe->author_name : '';
$published_date    = isset( $recipe->published_date ) ? $recipe->published_date : '';
$rating            = isset( $recipe->rating ) ? floatval( $recipe->rating ) : 0;
$rating_count      = isset( $recipe->rating_count ) ? absint( $recipe->rating_count ) : 0;
$prep_time         = isset( $recipe->prep_time ) ? absint( $recipe->prep_time ) : 0;
$cook_time         = isset( $recipe->cook_time ) ? absint( $recipe->cook_time ) : 0;
$total_time        = $prep_time + $cook_time;
$servings          = isset( $recipe->servings ) ? absint( $recipe->servings ) : 0;
$difficulty        = isset( $recipe->difficulty ) ? $recipe->difficulty : '';
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

// Global settings override — if a feature is disabled globally, override view config.
$plugixa_recipe_global_settings = get_option( 'plugixa_recipe_settings', [] );
if ( isset( $plugixa_recipe_global_settings['enable_nutrition'] ) && ! $plugixa_recipe_global_settings['enable_nutrition'] ) {
    $show_nutrition = false;
}
if ( isset( $plugixa_recipe_global_settings['enable_reviews'] ) && ! $plugixa_recipe_global_settings['enable_reviews'] ) {
    $show_reviews = false;
    $enable_reviews = false;
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
?>

<div id="<?php echo esc_attr( $unique_id ); ?>"
     class="pxr-custom-single pxr:mx-auto"
     style="<?php echo esc_attr( $css_vars_style ); ?>"
     data-pxr-recipe-id="<?php echo esc_attr( $recipe_id ); ?>"
     itemscope
     itemtype="https://schema.org/Recipe">

    <!-- Hero Section -->
    <header class="pxr-custom-single__hero pxr:mb-8">
        <?php if ( $image_url ) : ?>
            <!-- Two-Column Hero Grid: Image Left + Content Right -->
            <div class="pxr-custom-single__hero-grid">

                <!-- LEFT COLUMN: Image -->
                <div class="pxr-custom-single__hero-image-col">
                    <img src="<?php echo esc_url( $image_url ); ?>"
                         alt="<?php echo esc_attr( $image_alt ); ?>"
                         class="pxr-custom-single__hero-img"
                         itemprop="image" />
                </div>

                <!-- RIGHT COLUMN: All info + ingredients -->
                <div class="pxr-custom-single__hero-content-col">

                    <?php if ( ! empty( $categories ) ) : ?>
                        <div class="pxr-custom-single__categories pxr:mb-3">
                            <?php foreach ( $categories as $recipe_cat ) : ?>
                                <span class="pxr-custom-single__category-badge">
                                    <?php echo esc_html( is_array( $recipe_cat ) ? $recipe_cat['name'] : ( is_object( $recipe_cat ) ? $recipe_cat->name : $recipe_cat ) ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h1 class="pxr-custom-single__title pxr:text-3xl pxr:font-bold pxr:mb-3 pxr:leading-tight" itemprop="name">
                        <?php echo esc_html( $recipe_title ); ?>
                    </h1>

                    <?php if ( $description ) : ?>
                        <div class="pxr-custom-single__description pxr:text-base pxr:leading-relaxed pxr:mb-4 pxr:opacity-80" itemprop="description">
                            <?php echo wp_kses_post( $description ); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Meta Bar -->
                    <div class="pxr-custom-single__meta pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-4 pxr:mb-4 pxr:text-sm">
                        <?php if ( $author_name ) : ?>
                            <span class="pxr-custom-single__author pxr:flex pxr:items-center pxr:gap-1">
                                <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <span itemprop="author" itemscope itemtype="https://schema.org/Person">
                                    <span itemprop="name"><?php echo esc_html( $author_name ); ?></span>
                                </span>
                            </span>
                        <?php endif; ?>

                        <?php if ( $published_date ) : ?>
                            <span class="pxr-custom-single__date pxr:flex pxr:items-center pxr:gap-1">
                                <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <time itemprop="datePublished" datetime="<?php echo esc_attr( $published_date ); ?>">
                                    <?php echo esc_html( $published_date ); ?>
                                </time>
                            </span>
                        <?php endif; ?>

                        <?php if ( $rating > 0 ) : ?>
                            <span class="pxr-custom-single__rating pxr:flex pxr:items-center pxr:gap-1"
                                  itemprop="aggregateRating"
                                  itemscope
                                  itemtype="https://schema.org/AggregateRating">
                                <meta itemprop="ratingValue" content="<?php echo esc_attr( $rating ); ?>" />
                                <meta itemprop="reviewCount" content="<?php echo esc_attr( $rating_count ); ?>" />
                                <div class="pxr-custom-card__stars pxr:flex pxr:gap-0.5">
                                    <?php for ( $i = 1; $i <= 5; $i++ ) :
                                        $fill_class = 'pxr-custom-star--empty';
                                        if ( $i <= floor( $rating ) ) {
                                            $fill_class = 'pxr-custom-star--full';
                                        } elseif ( $i - $rating < 1 && $i - $rating > 0 ) {
                                            $fill_class = 'pxr-custom-star--half';
                                        }
                                    ?>
                                        <svg class="pxr-custom-star <?php echo esc_attr( $fill_class ); ?> pxr:w-4 pxr:h-4"
                                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    <?php endfor; ?>
                                </div>
                                <span class="pxr:opacity-70">(<?php echo esc_html( $rating_count ); ?>)</span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Compact Info Grid -->
                    <div class="pxr-custom-single__info-grid pxr:mb-4">

                        <?php if ( $prep_time ) : ?>
                            <div class="pxr-custom-single__info-grid-item">
                                <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div>
                                    <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Prep', 'plugixa-recipe' ); ?></span>
                                    <span class="pxr-custom-single__info-grid-value" itemprop="prepTime" content="PT<?php echo esc_attr( $prep_time ); ?>M">
                                        <?php echo esc_html( plugixa_recipe_format_time( $prep_time ) ); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $cook_time ) : ?>
                            <div class="pxr-custom-single__info-grid-item">
                                <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/>
                                </svg>
                                <div>
                                    <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Cook', 'plugixa-recipe' ); ?></span>
                                    <span class="pxr-custom-single__info-grid-value" itemprop="cookTime" content="PT<?php echo esc_attr( $cook_time ); ?>M">
                                        <?php echo esc_html( plugixa_recipe_format_time( $cook_time ) ); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $total_time ) : ?>
                            <div class="pxr-custom-single__info-grid-item">
                                <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div>
                                    <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Total', 'plugixa-recipe' ); ?></span>
                                    <span class="pxr-custom-single__info-grid-value" itemprop="totalTime" content="PT<?php echo esc_attr( $total_time ); ?>M">
                                        <?php echo esc_html( plugixa_recipe_format_time( $total_time ) ); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $servings ) : ?>
                            <div class="pxr-custom-single__info-grid-item">
                                <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                <div>
                                    <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Servings', 'plugixa-recipe' ); ?></span>
                                    <span class="pxr-custom-single__info-grid-value" itemprop="recipeYield" content="<?php echo esc_attr( $servings ); ?>">
                                        <?php echo esc_html( $servings ); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $difficulty ) : ?>
                            <div class="pxr-custom-single__info-grid-item">
                                <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                                <div>
                                    <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Difficulty', 'plugixa-recipe' ); ?></span>
                                    <span class="pxr-custom-single__info-grid-value"><?php echo esc_html( $difficulty ); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $cuisine ) : ?>
                            <div class="pxr-custom-single__info-grid-item">
                                <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div>
                                    <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Cuisine', 'plugixa-recipe' ); ?></span>
                                    <span class="pxr-custom-single__info-grid-value" itemprop="recipeCuisine"><?php echo esc_html( $cuisine ); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>

                    <!-- Action Buttons -->
                    <div class="pxr-custom-single__actions pxr:flex pxr:flex-wrap pxr:gap-3 pxr:mb-5">

                        <?php if ( $show_print ) : ?>
                            <a href="<?php echo esc_url( $print_url ); ?>"
                               class="pxr-custom-btn pxr-custom-btn--print pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:no-underline"
                               target="_blank"
                               rel="noopener noreferrer"
                               aria-label="<?php esc_attr_e( 'Print this recipe', 'plugixa-recipe' ); ?>">
                                <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                </svg>
                                <?php esc_html_e( 'Print', 'plugixa-recipe' ); ?>
                            </a>
                        <?php endif; ?>

                        <?php if ( $show_favorite ) : ?>
                            <button type="button"
                                    class="pxr-custom-btn pxr-custom-btn--favorite pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:cursor-pointer <?php echo $is_favorited ? 'pxr-custom-btn--favorited' : ''; ?>"
                                    data-pxr-favorite="<?php echo esc_attr( $recipe_id ); ?>"
                                    aria-label="<?php echo $is_favorited ? esc_attr__( 'Remove from favorites', 'plugixa-recipe' ) : esc_attr__( 'Add to favorites', 'plugixa-recipe' ); ?>"
                                    aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>">
                                <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="<?php echo $is_favorited ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                                </svg>
                                <span class="pxr-custom-btn__label">
                                    <?php echo $is_favorited ? esc_html__( 'Saved', 'plugixa-recipe' ) : esc_html__( 'Save', 'plugixa-recipe' ); ?>
                                </span>
                            </button>
                        <?php endif; ?>

                        <?php if ( $show_share ) : ?>
                            <div class="pxr-custom-share pxr:relative pxr:inline-flex">
                                <button type="button"
                                        class="pxr-custom-btn pxr-custom-btn--share pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:cursor-pointer"
                                        data-pxr-share-toggle
                                        aria-expanded="false"
                                        aria-haspopup="true"
                                        aria-label="<?php esc_attr_e( 'Share recipe', 'plugixa-recipe' ); ?>">
                                    <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                    </svg>
                                    <?php esc_html_e( 'Share', 'plugixa-recipe' ); ?>
                                </button>
                                <div class="pxr-custom-share__dropdown pxr:absolute pxr:top-full pxr:left-0 pxr:mt-2 pxr:py-2 pxr:rounded-lg pxr:shadow-lg pxr:border pxr:z-20 pxr:min-w-[160px] pxr:hidden"
                                     data-pxr-share-menu
                                     role="menu">
                                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>"
                                       class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       role="menuitem">
                                        <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                        </svg>
                                        <?php esc_html_e( 'Facebook', 'plugixa-recipe' ); ?>
                                    </a>
                                    <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $share_url ); ?>&text=<?php echo rawurlencode( $recipe_title ); ?>"
                                       class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       role="menuitem">
                                        <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                                        </svg>
                                        <?php esc_html_e( 'X', 'plugixa-recipe' ); ?>
                                    </a>
                                    <a href="https://pinterest.com/pin/create/button/?url=<?php echo rawurlencode( $share_url ); ?>&media=<?php echo rawurlencode( $image_url ); ?>&description=<?php echo rawurlencode( $recipe_title ); ?>"
                                       class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       role="menuitem">
                                        <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M12 0a12 12 0 00-4.373 23.17c-.1-.937-.19-2.38.04-3.406l1.315-5.572s-.336-.672-.336-1.664c0-1.558.904-2.721 2.028-2.721.956 0 1.418.718 1.418 1.579 0 .962-.613 2.4-.928 3.732-.264 1.114.559 2.023 1.659 2.023 1.99 0 3.52-2.098 3.52-5.124 0-2.68-1.926-4.554-4.676-4.554-3.184 0-5.053 2.388-5.053 4.857 0 .962.37 1.993.833 2.554a.335.335 0 01.077.32l-.31 1.264c-.05.2-.162.243-.374.146-1.395-.649-2.267-2.687-2.267-4.325 0-3.523 2.56-6.76 7.381-6.76 3.876 0 6.887 2.762 6.887 6.453 0 3.851-2.428 6.953-5.798 6.953-1.132 0-2.196-.588-2.56-1.283l-.697 2.654c-.252.973-.933 2.192-1.389 2.936A12 12 0 1012 0z"/>
                                        </svg>
                                        <?php esc_html_e( 'Pinterest', 'plugixa-recipe' ); ?>
                                    </a>
                                    <a href="mailto:?subject=<?php echo rawurlencode( $recipe_title ); ?>&body=<?php echo rawurlencode( $share_url ); ?>"
                                       class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                                       role="menuitem">
                                        <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                        </svg>
                                        <?php esc_html_e( 'Email', 'plugixa-recipe' ); ?>
                                    </a>
                                    <button type="button"
                                            class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:w-full pxr:text-left pxr:border-0 pxr:bg-transparent pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                                            data-pxr-copy-link="<?php echo esc_attr( $share_url ); ?>"
                                            role="menuitem">
                                        <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                        </svg>
                                        <?php esc_html_e( 'Copy Link', 'plugixa-recipe' ); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>

                    <!-- Servings Adjuster (moved into hero right column) -->
                    <?php if ( $servings ) : ?>
                        <div class="pxr-custom-single__servings-adjuster pxr:flex pxr:items-center pxr:gap-3 pxr:mb-5 pxr:py-3 pxr:px-4 pxr:rounded-lg pxr:border"
                             data-pxr-servings-adjuster
                             data-pxr-original-servings="<?php echo esc_attr( $servings ); ?>">
                            <label for="pxr-servings-input-<?php echo esc_attr( $unique_id ); ?>" class="pxr:font-medium pxr:text-sm">
                                <?php esc_html_e( 'Adjust Servings:', 'plugixa-recipe' ); ?>
                            </label>
                            <button type="button"
                                    class="pxr-custom-servings-btn pxr:w-8 pxr:h-8 pxr:rounded-full pxr:border pxr:flex pxr:items-center pxr:justify-center pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                                    data-pxr-servings-decrease
                                    aria-label="<?php esc_attr_e( 'Decrease servings', 'plugixa-recipe' ); ?>">
                                <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
                                </svg>
                            </button>
                            <input type="number"
                                   id="pxr-servings-input-<?php echo esc_attr( $unique_id ); ?>"
                                   class="pxr-custom-servings-input pxr:w-16 pxr:text-center pxr:py-1 pxr:px-2 pxr:border pxr:rounded-md pxr:font-semibold"
                                   value="<?php echo esc_attr( $servings ); ?>"
                                   min="1"
                                   max="100"
                                   data-pxr-servings-input
                                   aria-label="<?php esc_attr_e( 'Number of servings', 'plugixa-recipe' ); ?>" />
                            <button type="button"
                                    class="pxr-custom-servings-btn pxr:w-8 pxr:h-8 pxr:rounded-full pxr:border pxr:flex pxr:items-center pxr:justify-center pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                                    data-pxr-servings-increase
                                    aria-label="<?php esc_attr_e( 'Increase servings', 'plugixa-recipe' ); ?>">
                                <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Ingredients (moved into hero right column) -->
                    <?php if ( ! empty( $ingredient_groups ) ) : ?>
                        <div class="pxr-custom-single__ingredients">
                            <div class="pxr-custom-single__section pxr:p-5 pxr:rounded-lg pxr:border">
                                <h2 class="pxr-custom-single__section-title pxr:text-lg pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
                                    <?php esc_html_e( 'Ingredients', 'plugixa-recipe' ); ?>
                                </h2>
                                <?php foreach ( $ingredient_groups as $group ) : ?>
                                    <?php if ( ! empty( $group->name ) ) : ?>
                                        <h3 class="pxr-custom-single__group-title pxr:text-sm pxr:font-semibold pxr:uppercase pxr:tracking-wide pxr:mt-4 pxr:mb-2 pxr:opacity-70">
                                            <?php echo esc_html( $group->name ); ?>
                                        </h3>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $group->items ) && is_array( $group->items ) ) : ?>
                                        <ul class="pxr-custom-single__ingredient-list pxr:list-none pxr:p-0 pxr:m-0 pxr:space-y-2" role="list">
                                            <?php foreach ( $group->items as $ingredient ) : ?>
                                                <li class="pxr-custom-single__ingredient pxr:flex pxr:items-start pxr:gap-2">
                                                    <label class="pxr:flex pxr:items-start pxr:gap-2 pxr:cursor-pointer pxr:w-full">
                                                        <input type="checkbox"
                                                               class="pxr-custom-checkbox pxr:mt-1 pxr:flex-shrink-0 pxr:w-4 pxr:h-4 pxr:rounded pxr:border"
                                                               data-pxr-ingredient-check
                                                               aria-label="<?php echo esc_attr( $ingredient->text ?? '' ); ?>" />
                                                        <span class="pxr-custom-single__ingredient-text pxr:text-sm pxr:leading-relaxed"
                                                              itemprop="recipeIngredient"
                                                              data-pxr-amount="<?php echo esc_attr( $ingredient->amount ?? '' ); ?>"
                                                              data-pxr-unit="<?php echo esc_attr( $ingredient->unit ?? '' ); ?>">
                                                            <?php if ( ! empty( $ingredient->amount ) ) : ?>
                                                                <strong class="pxr-custom-single__ingredient-amount" data-pxr-ingredient-amount>
                                                                    <?php echo esc_html( $ingredient->amount ); ?>
                                                                </strong>
                                                            <?php endif; ?>
                                                            <?php if ( ! empty( $ingredient->unit ) ) : ?>
                                                                <span class="pxr-custom-single__ingredient-unit" data-pxr-ingredient-unit>
                                                                    <?php echo esc_html( $ingredient->unit ); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php echo esc_html( $ingredient->name ?? $ingredient->text ?? '' ); ?>
                                                            <?php if ( ! empty( $ingredient->notes ) ) : ?>
                                                                <em class="pxr:opacity-60">(<?php echo esc_html( $ingredient->notes ); ?>)</em>
                                                            <?php endif; ?>
                                                        </span>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- /.pxr-custom-single__hero-content-col -->

            </div><!-- /.pxr-custom-single__hero-grid -->

        <?php else : ?>
            <!-- No-image fallback: single-column layout -->
            <?php if ( ! empty( $categories ) ) : ?>
                <div class="pxr-custom-single__categories pxr:mb-3">
                    <?php foreach ( $categories as $recipe_cat ) : ?>
                        <span class="pxr-custom-single__category-badge">
                            <?php echo esc_html( is_array( $recipe_cat ) ? $recipe_cat['name'] : ( is_object( $recipe_cat ) ? $recipe_cat->name : $recipe_cat ) ); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h1 class="pxr-custom-single__title pxr:text-3xl pxr:font-bold pxr:mb-3 pxr:leading-tight" itemprop="name">
                <?php echo esc_html( $recipe_title ); ?>
            </h1>

            <?php if ( $description ) : ?>
                <div class="pxr-custom-single__description pxr:text-base pxr:leading-relaxed pxr:mb-4 pxr:opacity-80" itemprop="description">
                    <?php echo wp_kses_post( $description ); ?>
                </div>
            <?php endif; ?>

            <!-- Meta Bar -->
            <div class="pxr-custom-single__meta pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-4 pxr:mb-4 pxr:text-sm">
                <?php if ( $author_name ) : ?>
                    <span class="pxr-custom-single__author pxr:flex pxr:items-center pxr:gap-1">
                        <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span itemprop="author" itemscope itemtype="https://schema.org/Person">
                            <span itemprop="name"><?php echo esc_html( $author_name ); ?></span>
                        </span>
                    </span>
                <?php endif; ?>

                <?php if ( $published_date ) : ?>
                    <span class="pxr-custom-single__date pxr:flex pxr:items-center pxr:gap-1">
                        <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <time itemprop="datePublished" datetime="<?php echo esc_attr( $published_date ); ?>">
                            <?php echo esc_html( $published_date ); ?>
                        </time>
                    </span>
                <?php endif; ?>

                <?php if ( $rating > 0 ) : ?>
                    <span class="pxr-custom-single__rating pxr:flex pxr:items-center pxr:gap-1"
                          itemprop="aggregateRating"
                          itemscope
                          itemtype="https://schema.org/AggregateRating">
                        <meta itemprop="ratingValue" content="<?php echo esc_attr( $rating ); ?>" />
                        <meta itemprop="reviewCount" content="<?php echo esc_attr( $rating_count ); ?>" />
                        <div class="pxr-custom-card__stars pxr:flex pxr:gap-0.5">
                            <?php for ( $i = 1; $i <= 5; $i++ ) :
                                $fill_class = 'pxr-custom-star--empty';
                                if ( $i <= floor( $rating ) ) {
                                    $fill_class = 'pxr-custom-star--full';
                                } elseif ( $i - $rating < 1 && $i - $rating > 0 ) {
                                    $fill_class = 'pxr-custom-star--half';
                                }
                            ?>
                                <svg class="pxr-custom-star <?php echo esc_attr( $fill_class ); ?> pxr:w-4 pxr:h-4"
                                     viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            <?php endfor; ?>
                        </div>
                        <span class="pxr:opacity-70">(<?php echo esc_html( $rating_count ); ?>)</span>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Compact Info Grid -->
            <div class="pxr-custom-single__info-grid pxr:mb-4">
                <?php if ( $prep_time ) : ?>
                    <div class="pxr-custom-single__info-grid-item">
                        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Prep', 'plugixa-recipe' ); ?></span>
                            <span class="pxr-custom-single__info-grid-value" itemprop="prepTime" content="PT<?php echo esc_attr( $prep_time ); ?>M">
                                <?php echo esc_html( plugixa_recipe_format_time( $prep_time ) ); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $cook_time ) : ?>
                    <div class="pxr-custom-single__info-grid-item">
                        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/>
                        </svg>
                        <div>
                            <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Cook', 'plugixa-recipe' ); ?></span>
                            <span class="pxr-custom-single__info-grid-value" itemprop="cookTime" content="PT<?php echo esc_attr( $cook_time ); ?>M">
                                <?php echo esc_html( plugixa_recipe_format_time( $cook_time ) ); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $total_time ) : ?>
                    <div class="pxr-custom-single__info-grid-item">
                        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Total', 'plugixa-recipe' ); ?></span>
                            <span class="pxr-custom-single__info-grid-value" itemprop="totalTime" content="PT<?php echo esc_attr( $total_time ); ?>M">
                                <?php echo esc_html( plugixa_recipe_format_time( $total_time ) ); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $servings ) : ?>
                    <div class="pxr-custom-single__info-grid-item">
                        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <div>
                            <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Servings', 'plugixa-recipe' ); ?></span>
                            <span class="pxr-custom-single__info-grid-value" itemprop="recipeYield" content="<?php echo esc_attr( $servings ); ?>">
                                <?php echo esc_html( $servings ); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $difficulty ) : ?>
                    <div class="pxr-custom-single__info-grid-item">
                        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        <div>
                            <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Difficulty', 'plugixa-recipe' ); ?></span>
                            <span class="pxr-custom-single__info-grid-value"><?php echo esc_html( $difficulty ); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $cuisine ) : ?>
                    <div class="pxr-custom-single__info-grid-item">
                        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Cuisine', 'plugixa-recipe' ); ?></span>
                            <span class="pxr-custom-single__info-grid-value" itemprop="recipeCuisine"><?php echo esc_html( $cuisine ); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div class="pxr-custom-single__actions pxr:flex pxr:flex-wrap pxr:gap-3 pxr:mb-5">
                <?php if ( $show_print ) : ?>
                    <a href="<?php echo esc_url( $print_url ); ?>"
                       class="pxr-custom-btn pxr-custom-btn--print pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:no-underline"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Print this recipe', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        <?php esc_html_e( 'Print', 'plugixa-recipe' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( $show_favorite ) : ?>
                    <button type="button"
                            class="pxr-custom-btn pxr-custom-btn--favorite pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:cursor-pointer <?php echo $is_favorited ? 'pxr-custom-btn--favorited' : ''; ?>"
                            data-pxr-favorite="<?php echo esc_attr( $recipe_id ); ?>"
                            aria-label="<?php echo $is_favorited ? esc_attr__( 'Remove from favorites', 'plugixa-recipe' ) : esc_attr__( 'Add to favorites', 'plugixa-recipe' ); ?>"
                            aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>">
                        <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="<?php echo $is_favorited ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                        </svg>
                        <span class="pxr-custom-btn__label">
                            <?php echo $is_favorited ? esc_html__( 'Saved', 'plugixa-recipe' ) : esc_html__( 'Save', 'plugixa-recipe' ); ?>
                        </span>
                    </button>
                <?php endif; ?>
                <?php if ( $show_share ) : ?>
                    <div class="pxr-custom-share pxr:relative pxr:inline-flex">
                        <button type="button"
                                class="pxr-custom-btn pxr-custom-btn--share pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:cursor-pointer"
                                data-pxr-share-toggle
                                aria-expanded="false"
                                aria-haspopup="true"
                                aria-label="<?php esc_attr_e( 'Share recipe', 'plugixa-recipe' ); ?>">
                            <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                            </svg>
                            <?php esc_html_e( 'Share', 'plugixa-recipe' ); ?>
                        </button>
                        <div class="pxr-custom-share__dropdown pxr:absolute pxr:top-full pxr:left-0 pxr:mt-2 pxr:py-2 pxr:rounded-lg pxr:shadow-lg pxr:border pxr:z-20 pxr:min-w-[160px] pxr:hidden"
                             data-pxr-share-menu
                             role="menu">
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>"
                               class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                               target="_blank" rel="noopener noreferrer" role="menuitem">
                                <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                <?php esc_html_e( 'Facebook', 'plugixa-recipe' ); ?>
                            </a>
                            <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $share_url ); ?>&text=<?php echo rawurlencode( $recipe_title ); ?>"
                               class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                               target="_blank" rel="noopener noreferrer" role="menuitem">
                                <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                <?php esc_html_e( 'X', 'plugixa-recipe' ); ?>
                            </a>
                            <a href="mailto:?subject=<?php echo rawurlencode( $recipe_title ); ?>&body=<?php echo rawurlencode( $share_url ); ?>"
                               class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                               role="menuitem">
                                <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                <?php esc_html_e( 'Email', 'plugixa-recipe' ); ?>
                            </a>
                            <button type="button"
                                    class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:w-full pxr:text-left pxr:border-0 pxr:bg-transparent pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                                    data-pxr-copy-link="<?php echo esc_attr( $share_url ); ?>"
                                    role="menuitem">
                                <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                <?php esc_html_e( 'Copy Link', 'plugixa-recipe' ); ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Servings Adjuster -->
            <?php if ( $servings ) : ?>
                <div class="pxr-custom-single__servings-adjuster pxr:flex pxr:items-center pxr:gap-3 pxr:mb-5 pxr:py-3 pxr:px-4 pxr:rounded-lg pxr:border"
                     data-pxr-servings-adjuster
                     data-pxr-original-servings="<?php echo esc_attr( $servings ); ?>">
                    <label for="pxr-servings-input-noimg-<?php echo esc_attr( $unique_id ); ?>" class="pxr:font-medium pxr:text-sm">
                        <?php esc_html_e( 'Adjust Servings:', 'plugixa-recipe' ); ?>
                    </label>
                    <button type="button"
                            class="pxr-custom-servings-btn pxr:w-8 pxr:h-8 pxr:rounded-full pxr:border pxr:flex pxr:items-center pxr:justify-center pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                            data-pxr-servings-decrease
                            aria-label="<?php esc_attr_e( 'Decrease servings', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
                        </svg>
                    </button>
                    <input type="number"
                           id="pxr-servings-input-noimg-<?php echo esc_attr( $unique_id ); ?>"
                           class="pxr-custom-servings-input pxr:w-16 pxr:text-center pxr:py-1 pxr:px-2 pxr:border pxr:rounded-md pxr:font-semibold"
                           value="<?php echo esc_attr( $servings ); ?>"
                           min="1"
                           max="100"
                           data-pxr-servings-input
                           aria-label="<?php esc_attr_e( 'Number of servings', 'plugixa-recipe' ); ?>" />
                    <button type="button"
                            class="pxr-custom-servings-btn pxr:w-8 pxr:h-8 pxr:rounded-full pxr:border pxr:flex pxr:items-center pxr:justify-center pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                            data-pxr-servings-increase
                            aria-label="<?php esc_attr_e( 'Increase servings', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Ingredients (no-image layout) -->
            <?php if ( ! empty( $ingredient_groups ) ) : ?>
                <div class="pxr-custom-single__ingredients pxr:mb-6">
                    <div class="pxr-custom-single__section pxr:p-5 pxr:rounded-lg pxr:border">
                        <h2 class="pxr-custom-single__section-title pxr:text-lg pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
                            <?php esc_html_e( 'Ingredients', 'plugixa-recipe' ); ?>
                        </h2>
                        <?php foreach ( $ingredient_groups as $group ) : ?>
                            <?php if ( ! empty( $group->name ) ) : ?>
                                <h3 class="pxr-custom-single__group-title pxr:text-sm pxr:font-semibold pxr:uppercase pxr:tracking-wide pxr:mt-4 pxr:mb-2 pxr:opacity-70">
                                    <?php echo esc_html( $group->name ); ?>
                                </h3>
                            <?php endif; ?>
                            <?php if ( ! empty( $group->items ) && is_array( $group->items ) ) : ?>
                                <ul class="pxr-custom-single__ingredient-list pxr:list-none pxr:p-0 pxr:m-0 pxr:space-y-2" role="list">
                                    <?php foreach ( $group->items as $ingredient ) : ?>
                                        <li class="pxr-custom-single__ingredient pxr:flex pxr:items-start pxr:gap-2">
                                            <label class="pxr:flex pxr:items-start pxr:gap-2 pxr:cursor-pointer pxr:w-full">
                                                <input type="checkbox"
                                                       class="pxr-custom-checkbox pxr:mt-1 pxr:flex-shrink-0 pxr:w-4 pxr:h-4 pxr:rounded pxr:border"
                                                       data-pxr-ingredient-check
                                                       aria-label="<?php echo esc_attr( $ingredient->text ?? '' ); ?>" />
                                                <span class="pxr-custom-single__ingredient-text pxr:text-sm pxr:leading-relaxed"
                                                      itemprop="recipeIngredient"
                                                      data-pxr-amount="<?php echo esc_attr( $ingredient->amount ?? '' ); ?>"
                                                      data-pxr-unit="<?php echo esc_attr( $ingredient->unit ?? '' ); ?>">
                                                    <?php if ( ! empty( $ingredient->amount ) ) : ?>
                                                        <strong class="pxr-custom-single__ingredient-amount" data-pxr-ingredient-amount>
                                                            <?php echo esc_html( $ingredient->amount ); ?>
                                                        </strong>
                                                    <?php endif; ?>
                                                    <?php if ( ! empty( $ingredient->unit ) ) : ?>
                                                        <span class="pxr-custom-single__ingredient-unit" data-pxr-ingredient-unit>
                                                            <?php echo esc_html( $ingredient->unit ); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php echo esc_html( $ingredient->name ?? $ingredient->text ?? '' ); ?>
                                                    <?php if ( ! empty( $ingredient->notes ) ) : ?>
                                                        <em class="pxr:opacity-60">(<?php echo esc_html( $ingredient->notes ); ?>)</em>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </header>

    <!-- Instructions (full-width below hero) -->
    <?php if ( ! empty( $instruction_groups ) ) : ?>
        <section class="pxr-custom-single__instructions pxr:mb-8">
            <div class="pxr-custom-single__section">
                <h2 class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-6 pxr:pb-2 pxr:border-b">
                    <?php esc_html_e( 'Instructions', 'plugixa-recipe' ); ?>
                </h2>
                <?php
                $step_counter = 0;
                foreach ( $instruction_groups as $group ) :
                ?>
                    <?php if ( ! empty( $group->name ) ) : ?>
                        <h3 class="pxr-custom-single__group-title pxr:text-lg pxr:font-semibold pxr:mt-6 pxr:mb-4">
                            <?php echo esc_html( $group->name ); ?>
                        </h3>
                    <?php endif; ?>
                    <?php if ( ! empty( $group->steps ) && is_array( $group->steps ) ) : ?>
                        <ol class="pxr-custom-single__step-list pxr:list-none pxr:p-0 pxr:m-0 pxr:space-y-6">
                            <?php foreach ( $group->steps as $step ) :
                                $step_counter++;
                            ?>
                                <li class="pxr-custom-single__step pxr:flex pxr:gap-4"
                                    itemprop="recipeInstructions"
                                    itemscope
                                    itemtype="https://schema.org/HowToStep">
                                    <span class="pxr-custom-single__step-number pxr:flex-shrink-0 pxr:w-8 pxr:h-8 pxr:rounded-full pxr:flex pxr:items-center pxr:justify-center pxr:font-bold pxr:text-sm"
                                          aria-hidden="true">
                                        <?php echo esc_html( $step_counter ); ?>
                                    </span>
                                    <div class="pxr-custom-single__step-content pxr:flex-grow">
                                        <meta itemprop="position" content="<?php echo esc_attr( $step_counter ); ?>" />
                                        <div class="pxr-custom-single__step-text pxr:leading-relaxed" itemprop="text">
                                            <?php echo wp_kses_post( $step->text ?? '' ); ?>
                                        </div>
                                        <?php if ( ! empty( $step->image_url ) ) : ?>
                                            <div class="pxr-custom-single__step-image pxr:mt-3 pxr:rounded-lg pxr:overflow-hidden">
                                                <img src="<?php echo esc_url( $step->image_url ); ?>"
                                                     alt="<?php
                                                         /* translators: %d: step number */
                                                         echo esc_attr( sprintf( __( 'Step %d', 'plugixa-recipe' ), $step_counter ) );
                                                     ?>"
                                                     class="pxr:w-full pxr:h-auto pxr:rounded-lg"
                                                     loading="lazy"
                                                     itemprop="image" />
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $step->tip ) ) : ?>
                                            <div class="pxr-custom-single__step-tip pxr:mt-2 pxr:py-2 pxr:px-3 pxr:rounded-md pxr:text-sm pxr:border-l-4"
                                                 role="note">
                                                <strong><?php esc_html_e( 'Tip:', 'plugixa-recipe' ); ?></strong>
                                                <?php echo esc_html( $step->tip ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Video Section -->
    <?php if ( $show_video && $video_embed ) : ?>
        <section class="pxr-custom-single__video pxr:mb-8"
                 aria-labelledby="pxr-video-heading-<?php echo esc_attr( $unique_id ); ?>">
            <h2 id="pxr-video-heading-<?php echo esc_attr( $unique_id ); ?>"
                class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
                <?php esc_html_e( 'Video', 'plugixa-recipe' ); ?>
            </h2>
            <div class="pxr-custom-single__video-wrapper pxr:rounded-xl pxr:overflow-hidden"
                 itemprop="video"
                 itemscope
                 itemtype="https://schema.org/VideoObject">
                <meta itemprop="contentUrl" content="<?php echo esc_attr( $video_url ); ?>" />
                <meta itemprop="name" content="<?php echo esc_attr( $recipe_title ); ?>" />
                <div class="pxr-custom-single__video-embed">
                    <?php
                    // oEmbed player markup (YouTube / Vimeo <iframe>), late-escaped through
                    // the plugin's central allowlist, which permits the player element and
                    // its attributes but no <script>.
                    echo wp_kses( $video_embed, plugixa_recipe_allowed_html() );
                    ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Gallery Section -->
    <?php if ( $show_gallery && ! empty( $gallery_urls ) ) : ?>
        <section class="pxr-custom-single__gallery pxr:mb-8"
                 aria-labelledby="pxr-gallery-heading-<?php echo esc_attr( $unique_id ); ?>">
            <h2 id="pxr-gallery-heading-<?php echo esc_attr( $unique_id ); ?>"
                class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
                <?php esc_html_e( 'Images', 'plugixa-recipe' ); ?>
            </h2>
            <div class="pxr-custom-single__gallery-grid" data-pxr-gallery>
                <?php foreach ( $gallery_urls as $img ) : ?>
                    <a href="<?php echo esc_url( $img->full ); ?>"
                       class="pxr-custom-single__gallery-item glightbox"
                       data-gallery="pxr-recipe-gallery"
                       data-glightbox="title: <?php echo esc_attr( $img->alt ); ?>">
                        <img src="<?php echo esc_url( $img->url ); ?>"
                             alt="<?php echo esc_attr( $img->alt ); ?>"
                             class="pxr-custom-single__gallery-img"
                             loading="lazy"
                             itemprop="image" />
                        <div class="pxr-custom-single__gallery-overlay" aria-hidden="true">
                            <svg class="pxr:w-6 pxr:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/>
                            </svg>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Nutrition Facts -->
    <?php if ( $show_nutrition && $nutrition ) : ?>
        <section class="pxr-custom-single__nutrition pxr:mb-8" aria-labelledby="pxr-nutrition-heading-<?php echo esc_attr( $unique_id ); ?>">
            <h2 id="pxr-nutrition-heading-<?php echo esc_attr( $unique_id ); ?>"
                class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
                <?php esc_html_e( 'Nutrition Facts', 'plugixa-recipe' ); ?>
            </h2>
            <?php include plugin_dir_path( __FILE__ ) . 'nutrition-label.php'; ?>
        </section>
    <?php endif; ?>

    <?php
    /**
     * Extra sections contributed by the premium overlay (e.g. the "Additional Details"
     * custom-field list). The free build has nothing hooked here and renders nothing.
     */
    do_action( 'plugixa_recipe_single_recipe_sections', $recipe, $unique_id );
    ?>

    <!-- Rating Overview Section -->
    <section class="pxr-custom-single__rating-overview pxr:mb-8">
        <div class="pxr-custom-single__rating-overview-box pxr:rounded-xl pxr:border pxr:overflow-hidden">

            <?php if ( $total_reviews_for_bars > 0 ) : ?>
                <div class="pxr-custom-single__rating-overview-body pxr:p-6 pxr:flex pxr:flex-col sm:pxr:flex-row pxr:gap-6 pxr:items-start">

                    <!-- Left: Average Score -->
                    <div class="pxr-custom-single__rating-summary pxr:flex pxr:flex-col pxr:items-center pxr:text-center pxr:min-w-[140px] pxr:flex-shrink-0">
                        <span class="pxr-custom-single__rating-number pxr:text-5xl pxr:font-bold pxr:leading-none" style="color: var(--pxr-title-color, #1a1a2e);">
                            <?php echo esc_html( number_format( $rating, 1 ) ); ?>
                        </span>
                        <span class="pxr:text-sm pxr:opacity-60 pxr:mt-1" style="color: var(--pxr-text-color, #374151);">
                            <?php
                            /* translators: %s: max rating */
                            echo esc_html( sprintf( __( '/ %s', 'plugixa-recipe' ), '5' ) );
                            ?>
                        </span>
                        <div class="pxr:flex pxr:gap-1 pxr:mt-2">
                            <?php for ( $i = 1; $i <= 5; $i++ ) :
                                $star_class = 'pxr-custom-star--empty';
                                if ( $i <= floor( $rating ) ) {
                                    $star_class = 'pxr-custom-star--full';
                                } elseif ( $i - $rating < 1 && $i - $rating > 0 ) {
                                    $star_class = 'pxr-custom-star--half';
                                }
                            ?>
                                <svg class="pxr-custom-star <?php echo esc_attr( $star_class ); ?> pxr:w-5 pxr:h-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            <?php endfor; ?>
                        </div>
                        <span class="pxr:text-sm pxr:mt-2 pxr:opacity-60" style="color: var(--pxr-text-color, #374151);">
                            <?php
                            /* translators: %d: number of ratings */
                            echo esc_html( sprintf( _n( '%d rating', '%d ratings', $total_reviews_for_bars, 'plugixa-recipe' ), $total_reviews_for_bars ) );
                            ?>
                        </span>
                    </div>

                    <!-- Right: Distribution Bars -->
                    <div class="pxr-custom-single__rating-bars pxr:flex-1 pxr:w-full pxr:space-y-2">
                        <?php for ( $star = 5; $star >= 1; $star-- ) :
                            $count   = $rating_distribution[ $star ];
                            $percent = $total_reviews_for_bars > 0 ? round( ( $count / $total_reviews_for_bars ) * 100 ) : 0;
                        ?>
                            <div class="pxr-custom-single__rating-bar-row pxr:flex pxr:items-center pxr:gap-3">
                                <!-- Star label -->
                                <div class="pxr-custom-single__rating-bar-label pxr:flex pxr:items-center pxr:gap-1 pxr:min-w-[90px] pxr:flex-shrink-0">
                                    <?php for ( $star_index = 1; $star_index <= 5; $star_index++ ) : ?>
                                        <svg class="pxr-custom-star <?php echo $star_index <= $star ? 'pxr-custom-star--full' : 'pxr-custom-star--empty'; ?> pxr:w-3.5 pxr:h-3.5"
                                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    <?php endfor; ?>
                                </div>
                                <!-- Progress bar -->
                                <div class="pxr-custom-single__rating-bar-track pxr:flex-1 pxr:h-2.5 pxr:rounded-full pxr:overflow-hidden">
                                    <div class="pxr-custom-single__rating-bar-fill pxr:h-full pxr:rounded-full pxr:transition-all pxr:duration-500"
                                         style="width: <?php echo esc_attr( $percent ); ?>%;"
                                         role="progressbar"
                                         aria-valuenow="<?php echo esc_attr( $count ); ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="<?php echo esc_attr( $total_reviews_for_bars ); ?>"
                                         aria-label="<?php echo esc_attr( sprintf( '%d star: %d reviews', $star, $count ) ); ?>">
                                    </div>
                                </div>
                                <!-- Count -->
                                <span class="pxr-custom-single__rating-bar-count pxr:text-sm pxr:font-medium pxr:min-w-[24px] pxr:text-right pxr:opacity-70" style="color: var(--pxr-text-color, #374151);">
                                    <?php echo esc_html( $count ); ?>
                                </span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="pxr:p-6 pxr:text-center">
                    <p class="pxr:text-base pxr:opacity-60" style="color: var(--pxr-text-color, #374151);">
                        <?php esc_html_e( 'No ratings yet', 'plugixa-recipe' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Footer Row: Diet Badges + Social Share -->
            <div class="pxr-custom-single__rating-footer pxr:flex pxr:flex-wrap pxr:items-center pxr:justify-between pxr:gap-4 pxr:px-6 pxr:py-4 pxr:border-t" style="border-color: var(--pxr-border-color, #e5e7eb);">
                <!-- Diet Badges (left) -->
                <div class="pxr-custom-single__rating-footer-tags pxr:flex pxr:flex-wrap pxr:gap-2">
                    <?php if ( ! empty( $diet_badges ) ) : ?>
                        <?php foreach ( $diet_badges as $badge ) : ?>
                            <span class="pxr-custom-badge pxr-custom-badge--diet pxr:text-sm pxr:px-3 pxr:py-1 pxr:rounded-full pxr:font-medium"
                                  itemprop="suitableForDiet"
                                  content="https://schema.org/<?php echo esc_attr( str_replace( ' ', '', $badge ) ); ?>Diet">
                                <?php echo esc_html( $badge ); ?>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <!-- Social Share Icons (right) -->
                <div class="pxr-custom-single__rating-social pxr:flex pxr:items-center pxr:gap-2">
                    <!-- Facebook -->
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>"
                       class="pxr-custom-single__rating-social-link"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Share on Facebook', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                    <!-- X (Twitter) -->
                    <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $share_url ); ?>&text=<?php echo rawurlencode( $recipe_title ); ?>"
                       class="pxr-custom-single__rating-social-link"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Share on X', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                        </svg>
                    </a>
                    <!-- LinkedIn -->
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo rawurlencode( $share_url ); ?>"
                       class="pxr-custom-single__rating-social-link"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Share on LinkedIn', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                        </svg>
                    </a>
                    <!-- WhatsApp -->
                    <a href="https://api.whatsapp.com/send?text=<?php echo rawurlencode( $recipe_title . ' ' . $share_url ); ?>"
                       class="pxr-custom-single__rating-social-link"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Share on WhatsApp', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                    </a>
                    <!-- Pinterest -->
                    <a href="https://pinterest.com/pin/create/button/?url=<?php echo rawurlencode( $share_url ); ?>&media=<?php echo rawurlencode( $image_url ); ?>&description=<?php echo rawurlencode( $recipe_title ); ?>"
                       class="pxr-custom-single__rating-social-link"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Share on Pinterest', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 0a12 12 0 00-4.373 23.17c-.1-.937-.19-2.38.04-3.406l1.315-5.572s-.336-.672-.336-1.664c0-1.558.904-2.721 2.028-2.721.956 0 1.418.718 1.418 1.579 0 .962-.613 2.4-.928 3.732-.264 1.114.559 2.023 1.659 2.023 1.99 0 3.52-2.098 3.52-5.124 0-2.68-1.926-4.554-4.676-4.554-3.184 0-5.053 2.388-5.053 4.857 0 .962.37 1.993.833 2.554a.335.335 0 01.077.32l-.31 1.264c-.05.2-.162.243-.374.146-1.395-.649-2.267-2.687-2.267-4.325 0-3.523 2.56-6.76 7.381-6.76 3.876 0 6.887 2.762 6.887 6.453 0 3.851-2.428 6.953-5.798 6.953-1.132 0-2.196-.588-2.56-1.283l-.697 2.654c-.252.973-.933 2.192-1.389 2.936A12 12 0 1012 0z"/>
                        </svg>
                    </a>
                    <!-- Email -->
                    <a href="mailto:?subject=<?php echo rawurlencode( $recipe_title ); ?>&body=<?php echo rawurlencode( $share_url ); ?>"
                       class="pxr-custom-single__rating-social-link"
                       aria-label="<?php esc_attr_e( 'Share via Email', 'plugixa-recipe' ); ?>">
                        <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Reviews Section -->
    <?php if ( $show_reviews ) : ?>
        <section class="pxr-custom-single__reviews pxr:mb-8" aria-labelledby="pxr-reviews-heading-<?php echo esc_attr( $unique_id ); ?>">
            <h2 id="pxr-reviews-heading-<?php echo esc_attr( $unique_id ); ?>"
                class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
                <?php
                /* translators: %d: number of reviews */
                echo esc_html( sprintf( _n( '%d Review', '%d Reviews', $rating_count, 'plugixa-recipe' ), $rating_count ) );
                ?>
            </h2>

            <?php if ( ! empty( $reviews ) ) : ?>
                <?php
                $per_page_reviews = (int) ( $plugixa_recipe_global_settings['reviews_per_page'] ?? 10 );
                $reviews_page     = array_slice( $reviews, 0, $per_page_reviews );
                $has_more_reviews = count( $reviews ) > $per_page_reviews;
                ?>
                <div class="pxr-custom-single__review-list pxr:space-y-4 pxr:mb-6"
                     data-pxr-review-list>
                    <?php foreach ( $reviews_page as $review ) :
                        // Generate avatar initials and color.
                        $review_name   = $review->author_name ?? __( 'Anonymous', 'plugixa-recipe' );
                        $name_parts    = preg_split( '/\s+/', trim( $review_name ) );
                        $initials      = mb_strtoupper( mb_substr( $name_parts[0], 0, 1 ) );
                        if ( count( $name_parts ) > 1 ) {
                            $initials .= mb_strtoupper( mb_substr( end( $name_parts ), 0, 1 ) );
                        }
                        $avatar_colors = [ '#4F46E5', '#0891B2', '#059669', '#D97706', '#DC2626', '#7C3AED', '#DB2777', '#2563EB' ];
                        $avatar_color  = $avatar_colors[ crc32( $review_name ) % count( $avatar_colors ) ];
                        $helpful_count = (int) ( $review->helpful_count ?? 0 );
                    ?>
                        <div class="pxr-custom-review pxr:p-4 pxr:rounded-lg pxr:border"
                             itemscope
                             itemtype="https://schema.org/Review"
                             data-review-id="<?php echo esc_attr( $review->id ); ?>">
                            <div class="pxr-custom-review__header pxr:flex pxr:items-center pxr:justify-between pxr:mb-2">
                                <div class="pxr:flex pxr:items-center pxr:gap-3">
                                    <div class="pxr-custom-review__avatar pxr:w-9 pxr:h-9 pxr:rounded-full pxr:flex pxr:items-center pxr:justify-center pxr:text-xs pxr:font-bold pxr:text-white pxr:shrink-0 pxr:select-none"
                                         style="background-color: <?php echo esc_attr( $avatar_color ); ?>;">
                                        <?php echo esc_html( $initials ); ?>
                                    </div>
                                    <div>
                                        <span class="pxr-custom-review__author pxr:font-semibold pxr:text-sm pxr:block" itemprop="author">
                                            <?php echo esc_html( $review_name ); ?>
                                        </span>
                                        <?php if ( ! empty( $review->created_at ) ) : ?>
                                            <time class="pxr-custom-review__date pxr:text-xs pxr:opacity-60" itemprop="datePublished" datetime="<?php echo esc_attr( $review->created_at ); ?>">
                                                <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->created_at ) ) ); ?>
                                            </time>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ( ! empty( $review->rating ) ) : ?>
                                    <div class="pxr-custom-review__rating pxr:flex pxr:gap-0.5"
                                         itemprop="reviewRating"
                                         itemscope
                                         itemtype="https://schema.org/Rating">
                                        <meta itemprop="ratingValue" content="<?php echo esc_attr( $review->rating ); ?>" />
                                        <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                            <svg class="pxr-custom-star <?php echo $i <= $review->rating ? 'pxr-custom-star--full' : 'pxr-custom-star--empty'; ?> pxr:w-3.5 pxr:h-3.5"
                                                 viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                            </svg>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $review->review_text ) ) : ?>
                                <div class="pxr-custom-review__text pxr:text-sm pxr:leading-relaxed" itemprop="reviewBody">
                                    <?php echo wp_kses_post( $review->review_text ); ?>
                                </div>
                            <?php endif; ?>
                            <?php
                            // Display review images.
                            $review_image_ids = ! empty( $review->images ) ? json_decode( $review->images, true ) : [];
                            if ( ! empty( $review_image_ids ) && is_array( $review_image_ids ) ) : ?>
                                <div class="pxr-custom-review__images pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mt-3">
                                    <?php foreach ( $review_image_ids as $att_id ) :
                                        $img_url  = wp_get_attachment_image_url( (int) $att_id, 'thumbnail' );
                                        $full_url = wp_get_attachment_image_url( (int) $att_id, 'large' );
                                        if ( ! $img_url ) continue;
                                    ?>
                                        <a href="<?php echo esc_url( $full_url ?: $img_url ); ?>"
                                           target="_blank"
                                           rel="noopener"
                                           class="pxr-custom-review__image-link pxr:block pxr:w-16 pxr:h-16 pxr:rounded-lg pxr:overflow-hidden pxr:border pxr:transition-opacity hover:pxr:opacity-80">
                                            <img src="<?php echo esc_url( $img_url ); ?>"
                                                 alt="<?php esc_attr_e( 'Review photo', 'plugixa-recipe' ); ?>"
                                                 class="pxr:w-full pxr:h-full pxr:object-cover"
                                                 loading="lazy" />
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ( ! empty( $plugixa_recipe_global_settings['review_enable_helpful_votes'] ) ) : ?>
                                <div class="pxr-custom-review__actions pxr:flex pxr:items-center pxr:gap-3 pxr:mt-3">
                                    <button type="button"
                                            class="pxr-helpful-btn pxr:inline-flex pxr:items-center pxr:gap-1.5 pxr:px-3 pxr:py-1 pxr:rounded-full pxr:border pxr:text-xs pxr:font-medium pxr:cursor-pointer pxr:transition-colors pxr:duration-200 pxr:bg-transparent"
                                            data-pxr-helpful-btn
                                            data-review-id="<?php echo esc_attr( $review->id ); ?>"
                                            data-helpful-count="<?php echo esc_attr( $helpful_count ); ?>"
                                            aria-label="<?php esc_attr_e( 'Mark as helpful', 'plugixa-recipe' ); ?>">
                                        <svg class="pxr:w-3.5 pxr:h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" />
                                        </svg>
                                        <span data-pxr-helpful-label>
                                            <?php
                                            if ( $helpful_count > 0 ) {
                                                /* translators: %d: number of helpful votes. */
                                                printf( esc_html__( 'Helpful (%d)', 'plugixa-recipe' ), absint( $helpful_count ) );
                                            } else {
                                                esc_html_e( 'Helpful', 'plugixa-recipe' );
                                            }
                                            ?>
                                        </span>
                                    </button>
                                </div>
                            <?php endif; ?>
                            <?php if ( ! empty( $plugixa_recipe_global_settings['review_enable_admin_reply'] ) && ! empty( $review->admin_reply ) ) : ?>
                                <div class="pxr-custom-review__admin-reply pxr:mt-3 pxr:ml-12 pxr:p-3 pxr:rounded-lg pxr:border-l-4 pxr:text-sm" style="background: var(--pxr-bg-secondary, #f9fafb); border-left-color: var(--pxr-primary, #4F46E5);">
                                    <div class="pxr:flex pxr:items-center pxr:gap-2 pxr:mb-1">
                                        <svg class="pxr:w-3.5 pxr:h-3.5 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                        </svg>
                                        <span class="pxr:font-semibold pxr:text-xs"><?php esc_html_e( 'Admin', 'plugixa-recipe' ); ?></span>
                                        <?php if ( ! empty( $review->admin_reply_at ) ) : ?>
                                            <time class="pxr:text-xs pxr:opacity-60"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->admin_reply_at ) ) ); ?></time>
                                        <?php endif; ?>
                                    </div>
                                    <div class="pxr:leading-relaxed"><?php echo wp_kses_post( $review->admin_reply ); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( $has_more_reviews ) : ?>
                    <div class="pxr-custom-single__review-pagination pxr:text-center pxr:mb-6">
                        <button type="button"
                                class="pxr-load-more-reviews pxr:px-6 pxr:py-2 pxr:rounded-lg pxr:border pxr:text-sm pxr:font-medium pxr:cursor-pointer"
                                data-recipe-id="<?php echo esc_attr( $recipe_id ); ?>"
                                data-page="2"
                                data-total="<?php echo esc_attr( count( $reviews ) ); ?>">
                            <?php esc_html_e( 'Load More Reviews', 'plugixa-recipe' ); ?>
                        </button>
                        <span class="pxr-loading-spinner pxr:ml-2" style="display:none;">
                            <?php esc_html_e( 'Loading…', 'plugixa-recipe' ); ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( $enable_reviews ) : ?>
                <?php include plugin_dir_path( __FILE__ ) . 'review-form.php'; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php
    // Related Recipes — auto-injected at bottom of single recipe page
    $plugixa_recipe_widget_shortcodes = new \PlugixaRecipe\Frontend\WidgetShortcodes();
    $plugixa_recipe_related_html      = $plugixa_recipe_widget_shortcodes->render_related_recipes( [
        'recipe_id' => $recipe_id,
        'limit'     => 4,
        'columns'   => 4,
        'relation'  => 'both',
        'title'     => __( 'You May Also Like', 'plugixa-recipe' ),
    ] );
    // Late-escaped through the plugin's central allowlist. Every dynamic value inside is
    // already escaped at its own output site during that render; this is the belt-and-braces
    // pass the "escape late" guideline asks for at the echo itself.
    echo wp_kses( $plugixa_recipe_related_html, plugixa_recipe_allowed_html() );
    ?>

</div>
