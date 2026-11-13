<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Recipe Card Partial Template
 *
 * Reusable card component included by grid, slider, masonry, and list templates.
 *
 * Available variables:
 * @var object $recipe       Single recipe object
 * @var array  $view_config  Decoded JSON view configuration
 * @var string $layout       Current layout context: 'grid', 'slider', 'masonry', 'list'
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$recipe_id       = isset( $recipe->id ) ? absint( $recipe->id ) : 0;
$recipe_title           = isset( $recipe->title ) ? $recipe->title : '';
$permalink       = isset( $recipe->permalink ) ? $recipe->permalink : '#';
$image_url       = isset( $recipe->image_url ) ? $recipe->image_url : '';
$image_alt       = isset( $recipe->image_alt ) ? $recipe->image_alt : $recipe_title;
$excerpt         = isset( $recipe->excerpt ) ? $recipe->excerpt : '';
$rating          = isset( $recipe->rating ) ? floatval( $recipe->rating ) : 0;
$rating_count    = isset( $recipe->rating_count ) ? absint( $recipe->rating_count ) : 0;
$prep_time       = isset( $recipe->prep_time ) ? absint( $recipe->prep_time ) : 0;
$cook_time       = isset( $recipe->cook_time ) ? absint( $recipe->cook_time ) : 0;
// The stored column counts rest time too; the sum is for a caller that did not select it.
$total_time      = isset( $recipe->total_time )
    ? absint( $recipe->total_time )
    : $prep_time + $cook_time + ( isset( $recipe->rest_time ) ? absint( $recipe->rest_time ) : 0 );
$servings        = isset( $recipe->servings ) ? absint( $recipe->servings ) : 0;
$difficulty      = isset( $recipe->difficulty ) ? $recipe->difficulty : '';
$cuisine         = isset( $recipe->cuisine ) ? $recipe->cuisine : '';
$diet_badges     = isset( $recipe->diet_badges ) && is_array( $recipe->diet_badges ) ? $recipe->diet_badges : array();
$categories      = isset( $recipe->categories ) && is_array( $recipe->categories ) ? $recipe->categories : array();
$like_count      = isset( $recipe->like_count ) ? absint( $recipe->like_count ) : 0;
$is_liked        = isset( $recipe->is_liked ) ? (bool) $recipe->is_liked : false;
$is_favorited    = isset( $recipe->is_favorited ) ? (bool) $recipe->is_favorited : false;

// Video data
$video_url       = isset( $recipe->video_url ) ? $recipe->video_url : '';
$video_type      = isset( $recipe->video_type ) ? $recipe->video_type : '';
$video_id        = isset( $recipe->video_id ) ? $recipe->video_id : '';
$video_enabled   = ! empty( $view_config['video']['enabled'] );
$video_playback  = isset( $view_config['video']['playbackMode'] ) ? $view_config['video']['playbackMode'] : 'hover';
$show_play_icon  = isset( $view_config['video']['showPlayIcon'] ) ? (bool) $view_config['video']['showPlayIcon'] : true;
$has_video       = $video_enabled && $video_url && $video_type;

// View config helpers
$show_image      = isset( $view_config['show_image'] ) ? (bool) $view_config['show_image'] : true;
$show_rating     = isset( $view_config['show_rating'] ) ? (bool) $view_config['show_rating'] : true;
// Ratings are the Reviews module's data: with it off, no badge.
$show_rating     = $show_rating && plugixa_recipe_app_enabled( 'reviews' );
$show_meta       = isset( $view_config['show_meta'] ) ? (bool) $view_config['show_meta'] : true;
$show_difficulty = isset( $view_config['show_difficulty'] ) ? (bool) $view_config['show_difficulty'] : true;
$show_diet       = isset( $view_config['show_diet'] ) ? (bool) $view_config['show_diet'] : true;
$show_cuisine    = isset( $view_config['show_cuisine'] ) ? (bool) $view_config['show_cuisine'] : true;
$show_excerpt    = isset( $view_config['show_excerpt'] ) ? (bool) $view_config['show_excerpt'] : true;

$is_list         = isset( $layout ) && $layout === 'list';
$card_classes    = 'pxr-custom-card';
$card_classes   .= $is_list ? ' pxr-custom-card--list' : ' pxr:flex pxr:flex-col';

// Difficulty badge color map
$difficulty_colors = array(
    'easy'   => 'pxr-custom-badge--easy',
    'medium' => 'pxr-custom-badge--medium',
    'hard'   => 'pxr-custom-badge--hard',
);
$difficulty_class = isset( $difficulty_colors[ strtolower( $difficulty ) ] ) ? $difficulty_colors[ strtolower( $difficulty ) ] : '';

/**
 * Helper: format minutes into human-readable time.
 */
if ( ! function_exists( 'plugixa_recipe_format_time' ) ) {
    function plugixa_recipe_format_time( $minutes ) {
        if ( $minutes < 60 ) {
            /* translators: %d: number of minutes */
            return sprintf( _n( '%d min', '%d mins', $minutes, 'plugixa-recipe' ), $minutes );
        }
        $hours = floor( $minutes / 60 );
        $mins  = $minutes % 60;
        if ( $mins === 0 ) {
            /* translators: %d: number of hours */
            return sprintf( _n( '%d hr', '%d hrs', $hours, 'plugixa-recipe' ), $hours );
        }
        /* translators: 1: number of hours, 2: number of minutes */
        return sprintf( __( '%1$d hr %2$d min', 'plugixa-recipe' ), $hours, $mins );
    }
}
?>

<article class="<?php echo esc_attr( $card_classes ); ?>"
         data-recipe-id="<?php echo esc_attr( $recipe_id ); ?>"
         aria-label="<?php echo esc_attr( $recipe_title ); ?>">

    <?php if ( $show_image && $image_url ) : ?>
        <div class="pxr-custom-card__image-wrapper <?php echo $is_list ? 'pxr-custom-card__image-wrapper--list' : ''; ?>">
            <a href="<?php echo esc_url( $permalink ); ?>"
               class="pxr-custom-card__image-link pxr:block pxr:overflow-hidden pxr:relative"
               tabindex="-1"
               aria-hidden="true">
                <?php $plugixa_recipe_lazy = ! empty( get_option( 'plugixa_recipe_settings', [] )['enable_lazy_load'] ); ?>
                <img src="<?php echo esc_url( $image_url ); ?>"
                     alt="<?php echo esc_attr( $image_alt ); ?>"
                     class="pxr-custom-card__image pxr:w-full pxr:h-full pxr:object-cover pxr:transition-transform pxr:duration-300"
                     <?php echo $plugixa_recipe_lazy ? 'loading="lazy"' : ''; ?> />

                <?php if ( $has_video && $video_type === 'self-hosted' ) : ?>
                    <div class="pxr-custom-card__video-container"
                         data-pxr-video-playback="<?php echo esc_attr( $video_playback ); ?>"
                         aria-hidden="true">
                        <video class="pxr-custom-card__video"
                               preload="none"
                               muted
                               loop
                               playsinline
                               poster="<?php echo esc_url( $image_url ); ?>">
                            <source src="<?php echo esc_url( $video_url ); ?>"
                                    type="video/<?php echo esc_attr( pathinfo( wp_parse_url( $video_url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) ); ?>">
                        </video>
                    </div>
                <?php endif; ?>
            </a>

            <?php if ( $has_video && $show_play_icon ) : ?>
                <span class="pxr-custom-card__play-icon <?php echo $video_playback === 'icon-only' ? 'pxr-custom-card__play-icon--badge' : ''; ?>"
                      aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="pxr-custom-card__play-svg">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                </span>
            <?php endif; ?>

            <?php if ( $show_rating && $rating > 0 ) : ?>
                <span class="pxr-custom-overlay-badge pxr-custom-overlay-badge--rating">
                    <svg class="pxr:w-3.5 pxr:h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                    </svg>
                    <?php echo esc_html( number_format( $rating, 1 ) ); ?>
                </span>
            <?php endif; ?>

            <div class="pxr-custom-overlay-actions">
                <button type="button"
                        class="pxr-custom-overlay-btn pxr-custom-overlay-btn--like<?php echo $is_liked ? ' --active' : ''; ?>"
                        data-pxr-like="<?php echo esc_attr( $recipe_id ); ?>"
                        data-pxr-tooltip="like"
                        aria-label="<?php echo $is_liked ? esc_attr__( 'Unlike this recipe', 'plugixa-recipe' ) : esc_attr__( 'Like this recipe', 'plugixa-recipe' ); ?>"
                        aria-pressed="<?php echo $is_liked ? 'true' : 'false'; ?>">
                    <svg class="pxr:w-5 pxr:h-5" viewBox="0 0 24 24" fill="<?php echo $is_liked ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                </button>
                <?php if ( plugixa_recipe_app_enabled( 'favorites' ) ) : // The save button posts to the Favorites module's route. ?>
                <button type="button"
                        class="pxr-custom-overlay-btn pxr-custom-overlay-btn--save<?php echo $is_favorited ? ' --active' : ''; ?>"
                        data-pxr-card-favorite="<?php echo esc_attr( $recipe_id ); ?>"
                        data-pxr-tooltip="save"
                        aria-label="<?php echo $is_favorited ? esc_attr__( 'Remove from saved', 'plugixa-recipe' ) : esc_attr__( 'Save this recipe', 'plugixa-recipe' ); ?>"
                        aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>">
                    <svg class="pxr:w-5 pxr:h-5" viewBox="0 0 24 24" fill="<?php echo $is_favorited ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>

            <?php if ( $show_difficulty && $difficulty ) : ?>
                <span class="pxr-custom-badge pxr-custom-badge--difficulty <?php echo esc_attr( $difficulty_class ); ?> pxr:absolute pxr:bottom-3 pxr:left-3 pxr:text-xs pxr:font-semibold pxr:px-2 pxr:py-1 pxr:rounded-full pxr:z-10">
                    <?php echo esc_html( $difficulty ); ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $is_list ) : ?>
        <!-- ═══ LIST-SPECIFIC CONTENT LAYOUT ═══ -->
        <div class="pxr-custom-card__content pxr-custom-card__content--list">

            <!-- Top section: title, rating, meta, cuisine, excerpt -->
            <div class="pxr-custom-card__content-top">

                <!-- Title -->
                <h3 class="pxr-custom-card__title pxr-custom-card__title--list pxr:m-0 pxr:leading-snug">
                    <a href="<?php echo esc_url( $permalink ); ?>"
                       class="pxr-custom-card__title-link pxr:no-underline pxr:transition-colors pxr:duration-200">
                        <?php echo esc_html( $recipe_title ); ?>
                    </a>
                </h3>

                <?php if ( $show_rating && $rating > 0 ) : ?>
                    <div class="pxr-custom-card__rating pxr:flex pxr:items-center pxr:gap-1"
                         role="img"
                         aria-label="<?php
                            /* translators: 1: average rating out of 5, 2: number of reviews. */
                            echo esc_attr( sprintf( __( 'Rated %1$s out of 5 from %2$s reviews', 'plugixa-recipe' ), number_format( $rating, 1 ), $rating_count ) );
                         ?>">
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
                                     viewBox="0 0 20 20"
                                     fill="currentColor"
                                     aria-hidden="true">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            <?php endfor; ?>
                        </div>
                        <span class="pxr-custom-card__rating-count pxr:text-sm pxr:opacity-70">
                            (<?php echo esc_html( $rating_count ); ?>)
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ( $show_meta && ( $prep_time || $cook_time || $servings ) ) : ?>
                    <div class="pxr-custom-card__meta pxr-custom-card__meta--list">
                        <?php if ( $prep_time ) : ?>
                            <span class="pxr-custom-card__meta-item pxr:flex pxr:items-center pxr:gap-1"
                                  title="<?php esc_attr_e( 'Prep Time', 'plugixa-recipe' ); ?>">
                                <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span>
                                    <?php echo esc_html( plugixa_recipe_format_time( $prep_time ) ); ?>
                                </span>
                            </span>
                        <?php endif; ?>

                        <?php if ( $prep_time && $cook_time ) : ?>
                            <span class="pxr-custom-card__meta-dot" aria-hidden="true">&middot;</span>
                        <?php endif; ?>

                        <?php if ( $cook_time ) : ?>
                            <span class="pxr-custom-card__meta-item pxr:flex pxr:items-center pxr:gap-1"
                                  title="<?php esc_attr_e( 'Cook Time', 'plugixa-recipe' ); ?>">
                                <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/>
                                </svg>
                                <span>
                                    <?php echo esc_html( plugixa_recipe_format_time( $cook_time ) ); ?>
                                </span>
                            </span>
                        <?php endif; ?>

                        <?php if ( ( $prep_time || $cook_time ) && $servings ) : ?>
                            <span class="pxr-custom-card__meta-dot" aria-hidden="true">&middot;</span>
                        <?php endif; ?>

                        <?php if ( $servings ) : ?>
                            <span class="pxr-custom-card__meta-item pxr:flex pxr:items-center pxr:gap-1"
                                  title="<?php esc_attr_e( 'Servings', 'plugixa-recipe' ); ?>">
                                <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span>
                                    <?php
                                    /* translators: %d: number of servings. */
                                    echo esc_html( sprintf( _n( '%d serving', '%d servings', $servings, 'plugixa-recipe' ), $servings ) );
                                    ?>
                                </span>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( $show_cuisine && $cuisine ) : ?>
                    <div class="pxr-custom-card__cuisine pxr:text-sm">
                        <span class="pxr-custom-tag pxr:inline-flex pxr:items-center pxr:gap-1 pxr:opacity-70">
                            <svg class="pxr:w-3.5 pxr:h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span><?php echo esc_html( $cuisine ); ?></span>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ( $show_excerpt && $excerpt ) : ?>
                    <div class="pxr-custom-card__excerpt pxr-custom-card__excerpt--list">
                        <?php echo wp_kses_post( $excerpt ); ?>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Bottom row: diet badges left, CTA right -->
            <div class="pxr-custom-card__bottom-row">
                <?php if ( $show_diet && ! empty( $diet_badges ) ) : ?>
                    <div class="pxr-custom-card__diets pxr:flex pxr:flex-wrap pxr:gap-1.5">
                        <?php foreach ( $diet_badges as $badge ) : ?>
                            <span class="pxr-custom-badge pxr-custom-badge--diet pxr:text-xs pxr:px-2 pxr:py-0.5 pxr:rounded-full pxr:font-medium">
                                <?php echo esc_html( $badge ); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div></div>
                <?php endif; ?>

                <a href="<?php echo esc_url( $permalink ); ?>"
                   class="pxr-custom-card__cta">
                    <?php esc_html_e( 'View Recipe', 'plugixa-recipe' ); ?>
                    <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </a>
            </div>

            <?php if ( $total_time ) : ?>
            <?php endif; ?>

        </div>

    <?php else : ?>
        <!-- ═══ DEFAULT CONTENT (grid / masonry / slider) ═══ -->
        <div class="pxr-custom-card__content pxr:flex pxr:flex-col pxr:flex-grow pxr:p-4 pxr:gap-2">

            <h3 class="pxr-custom-card__title pxr:m-0 pxr:leading-snug">
                <a href="<?php echo esc_url( $permalink ); ?>"
                   class="pxr-custom-card__title-link pxr:no-underline pxr:transition-colors pxr:duration-200">
                    <?php echo esc_html( $recipe_title ); ?>
                </a>
            </h3>

            <?php if ( $show_rating && $rating > 0 ) : ?>
                <div class="pxr-custom-card__rating pxr:flex pxr:items-center pxr:gap-1"
                     role="img"
                     aria-label="<?php
                        /* translators: 1: average rating out of 5, 2: number of reviews. */
                        echo esc_attr( sprintf( __( 'Rated %1$s out of 5 from %2$s reviews', 'plugixa-recipe' ), number_format( $rating, 1 ), $rating_count ) );
                     ?>">
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
                                 viewBox="0 0 20 20"
                                 fill="currentColor"
                                 aria-hidden="true">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                    </div>
                    <span class="pxr-custom-card__rating-count pxr:text-sm pxr:opacity-70">
                        (<?php echo esc_html( $rating_count ); ?>)
                    </span>
                </div>
            <?php endif; ?>

            <?php if ( $show_meta && ( $prep_time || $cook_time || $servings ) ) : ?>
                <div class="pxr-custom-card__meta pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-3 pxr:text-sm">
                    <?php if ( $prep_time ) : ?>
                        <span class="pxr-custom-card__meta-item pxr:flex pxr:items-center pxr:gap-1"
                              title="<?php esc_attr_e( 'Prep Time', 'plugixa-recipe' ); ?>">
                            <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span>
                                <?php echo esc_html( plugixa_recipe_format_time( $prep_time ) ); ?>
                            </span>
                        </span>
                    <?php endif; ?>
                    <?php if ( $cook_time ) : ?>
                        <span class="pxr-custom-card__meta-item pxr:flex pxr:items-center pxr:gap-1"
                              title="<?php esc_attr_e( 'Cook Time', 'plugixa-recipe' ); ?>">
                            <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/>
                            </svg>
                            <span>
                                <?php echo esc_html( plugixa_recipe_format_time( $cook_time ) ); ?>
                            </span>
                        </span>
                    <?php endif; ?>
                    <?php if ( $servings ) : ?>
                        <span class="pxr-custom-card__meta-item pxr:flex pxr:items-center pxr:gap-1"
                              title="<?php esc_attr_e( 'Servings', 'plugixa-recipe' ); ?>">
                            <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>
                                <?php
                                /* translators: %d: number of servings. */
                                echo esc_html( sprintf( _n( '%d serving', '%d servings', $servings, 'plugixa-recipe' ), $servings ) );
                                ?>
                            </span>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $show_diet && ! empty( $diet_badges ) ) : ?>
                <div class="pxr-custom-card__diets pxr:flex pxr:flex-wrap pxr:gap-1.5">
                    <?php foreach ( $diet_badges as $badge ) : ?>
                        <span class="pxr-custom-badge pxr-custom-badge--diet pxr:text-xs pxr:px-2 pxr:py-0.5 pxr:rounded-full pxr:font-medium">
                            <?php echo esc_html( $badge ); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $show_cuisine && $cuisine ) : ?>
                <div class="pxr-custom-card__cuisine pxr:text-sm">
                    <span class="pxr-custom-tag pxr:inline-flex pxr:items-center pxr:gap-1 pxr:opacity-70">
                        <svg class="pxr:w-3.5 pxr:h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span><?php echo esc_html( $cuisine ); ?></span>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ( $show_excerpt && $excerpt ) : ?>
                <div class="pxr-custom-card__excerpt pxr:text-sm pxr:leading-relaxed pxr:opacity-80 pxr:line-clamp-2">
                    <?php echo wp_kses_post( $excerpt ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $total_time ) : ?>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</article>
