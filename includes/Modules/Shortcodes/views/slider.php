<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Slider Layout Template
 *
 * Renders recipes in a Swiper 12.x carousel with configurable navigation,
 * pagination, effects, keyboard nav, lazy loading, and responsive slides.
 *
 * Available variables:
 * @var array  $recipes          Array of recipe objects
 * @var array  $view_config      Decoded JSON view configuration
 * @var array  $shortcode_config Shortcode configuration
 * @var string $css_vars_style   Inline CSS with --pxr-* variables
 * @var string $unique_id        Unique shortcode instance ID
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

// Swiper configuration from view_config.
$autoplay        = isset( $view_config['autoplay'] ) ? (bool) $view_config['autoplay'] : false;
$autoplay_delay  = isset( $view_config['autoplay_delay'] ) ? absint( $view_config['autoplay_delay'] ) : 5000;
$loop            = isset( $view_config['loop'] ) ? (bool) $view_config['loop'] : true;
$show_arrows     = isset( $view_config['show_arrows'] ) ? (bool) $view_config['show_arrows'] : true;
$show_dots       = isset( $view_config['show_dots'] ) ? (bool) $view_config['show_dots'] : true;
$speed           = isset( $view_config['speed'] ) ? absint( $view_config['speed'] ) : 400;
$slides_desktop  = isset( $view_config['slides_per_view_desktop'] ) ? absint( $view_config['slides_per_view_desktop'] ) : 3;
$slides_tablet   = isset( $view_config['slides_per_view_tablet'] ) ? absint( $view_config['slides_per_view_tablet'] ) : 2;
$slides_mobile   = isset( $view_config['slides_per_view_mobile'] ) ? absint( $view_config['slides_per_view_mobile'] ) : 1;
$space_between   = isset( $view_config['space_between'] ) ? absint( $view_config['space_between'] ) : 24;
$pause_on_hover  = isset( $view_config['pause_on_hover'] ) ? (bool) $view_config['pause_on_hover'] : true;

// Swiper 12 module settings.
$effect           = $view_config['effect'] ?? 'slide';
$pagination_style = $view_config['pagination_style'] ?? 'bullets';
$keyboard         = isset( $view_config['keyboard'] ) ? (bool) $view_config['keyboard'] : true;
$free_mode        = isset( $view_config['free_mode'] ) ? (bool) $view_config['free_mode'] : false;
$lazy             = isset( $view_config['lazy'] ) ? (bool) $view_config['lazy'] : true;

// Pagination visibility: hide if style is 'none' or show_dots is false.
$show_pagination = $show_dots && $pagination_style !== 'none';

$slider_id = $unique_id . '-slider';
?>

<div class="pxr-custom-container pxr-custom-slider-container pxr:mx-auto pxr:relative"
     data-pxr-layout="slider"
     aria-label="<?php esc_attr_e( 'Recipe carousel', 'plugixa-recipe' ); ?>"
     aria-roledescription="carousel">

    <?php if ( ! empty( $recipes ) ) : ?>

        <div id="<?php echo esc_attr( $slider_id ); ?>"
             class="swiper pxr-custom-slider"
             data-pxr-autoplay="<?php echo esc_attr( $autoplay ? 'true' : 'false' ); ?>"
             data-pxr-autoplay-delay="<?php echo esc_attr( $autoplay_delay ); ?>"
             data-pxr-loop="<?php echo esc_attr( $loop ? 'true' : 'false' ); ?>"
             data-pxr-speed="<?php echo esc_attr( $speed ); ?>"
             data-pxr-space-between="<?php echo esc_attr( $space_between ); ?>"
             data-pxr-slides-desktop="<?php echo esc_attr( $slides_desktop ); ?>"
             data-pxr-slides-tablet="<?php echo esc_attr( $slides_tablet ); ?>"
             data-pxr-slides-mobile="<?php echo esc_attr( $slides_mobile ); ?>"
             data-pxr-pause-on-hover="<?php echo esc_attr( $pause_on_hover ? 'true' : 'false' ); ?>"
             data-pxr-arrows="<?php echo esc_attr( $show_arrows ? 'true' : 'false' ); ?>"
             data-pxr-dots="<?php echo esc_attr( $show_pagination ? 'true' : 'false' ); ?>"
             data-pxr-effect="<?php echo esc_attr( $effect ); ?>"
             data-pxr-pagination-style="<?php echo esc_attr( $pagination_style ); ?>"
             data-pxr-keyboard="<?php echo esc_attr( $keyboard ? 'true' : 'false' ); ?>"
             data-pxr-free-mode="<?php echo esc_attr( $free_mode ? 'true' : 'false' ); ?>"
             data-pxr-lazy="<?php echo esc_attr( $lazy ? 'true' : 'false' ); ?>">

            <div class="swiper-wrapper">
                <?php foreach ( $recipes as $index => $recipe ) : ?>
                    <div class="swiper-slide pxr-custom-slider__slide"
                         role="group"
                         aria-roledescription="slide"
                         aria-label="<?php
                             /* translators: 1: slide number, 2: total slides */
                             echo esc_attr( sprintf( __( 'Slide %1$d of %2$d', 'plugixa-recipe' ), $index + 1, count( $recipes ) ) );
                         ?>">
                        <div class="pxr-custom-slider__card pxr:rounded-lg pxr:overflow-hidden pxr:shadow-sm pxr:h-full">
                            <?php
                            $layout = 'slider';
                            include plugixa_recipe_template( 'recipe-card.php' );
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( $show_arrows ) : ?>
                <!-- Swiper Navigation (built-in module) -->
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
            <?php endif; ?>

            <?php if ( $show_pagination ) : ?>
                <!-- Swiper Pagination (built-in module) -->
                <div class="swiper-pagination"></div>
            <?php endif; ?>

        </div>

    <?php else : ?>
        <p class="pxr-custom-empty pxr:text-center pxr:py-12 pxr:opacity-60">
            <?php esc_html_e( 'No recipes found.', 'plugixa-recipe' ); ?>
        </p>
    <?php endif; ?>

</div>
