<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Recipe of the Day
 *
 * Featured recipe card or compact display.
 *
 * @var object $recipe      Enriched recipe object
 * @var string $style       'card' or 'compact'
 * @var bool   $show_rating Show star rating
 * @var bool   $show_time   Show cooking time
 * @var string $title       Widget title
 * @var string $unique_id   Unique wrapper ID
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$recipe_title = isset( $recipe->title ) ? $recipe->title : '';
$permalink    = isset( $recipe->permalink ) ? $recipe->permalink : '#';
$image_url    = isset( $recipe->image_url ) ? $recipe->image_url : '';
$rating       = isset( $recipe->rating ) ? floatval( $recipe->rating ) : 0;
$prep_time    = isset( $recipe->prep_time ) ? absint( $recipe->prep_time ) : 0;
$cook_time    = isset( $recipe->cook_time ) ? absint( $recipe->cook_time ) : 0;
$total_time   = $prep_time + $cook_time;
$difficulty   = isset( $recipe->difficulty ) ? $recipe->difficulty : '';
$categories   = isset( $recipe->categories ) && is_array( $recipe->categories ) ? $recipe->categories : [];

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
     class="pxr-widget pxr-widget-rod pxr-widget-rod--<?php echo esc_attr( $style ); ?>">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <?php if ( $style === 'card' ) : ?>

        <div class="pxr-widget-rod__card">
            <?php if ( $image_url ) : ?>
                <a href="<?php echo esc_url( $permalink ); ?>" class="pxr-widget-rod__image-link">
                    <img src="<?php echo esc_url( $image_url ); ?>"
                         alt="<?php echo esc_attr( $recipe_title ); ?>"
                         class="pxr-widget-rod__image"
                         loading="lazy" />
                    <span class="pxr-widget-rod__ribbon">
                        <?php esc_html_e( 'Today\'s Pick', 'plugixa-recipe' ); ?>
                    </span>
                </a>
            <?php endif; ?>

            <div class="pxr-widget-rod__body">
                <?php if ( ! empty( $categories[0]['name'] ) ) : ?>
                    <span class="pxr-widget-recipe-item__category">
                        <?php echo esc_html( $categories[0]['name'] ); ?>
                    </span>
                <?php endif; ?>

                <h4 class="pxr-widget-rod__title">
                    <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $recipe_title ); ?></a>
                </h4>

                <div class="pxr-widget-rod__meta pxr:flex pxr:items-center pxr:gap-3 pxr:flex-wrap">
                    <?php if ( $show_rating && $rating > 0 ) : ?>
                        <span class="pxr-widget-rod__rating pxr:flex pxr:items-center pxr:gap-1">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="var(--pxr-star-color, #f59e0b)">
                                <path d="M10 1l2.39 4.84 5.34.78-3.87 3.77.91 5.33L10 13.28l-4.77 2.51.91-5.33L2.27 6.69l5.34-.78L10 1z"/>
                            </svg>
                            <?php echo number_format( $rating, 1 ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $show_time && $total_time > 0 ) : ?>
                        <span class="pxr-widget-rod__time pxr:flex pxr:items-center pxr:gap-1">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <?php echo esc_html( plugixa_recipe_format_time( $total_time ) ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $difficulty ) : ?>
                        <span class="pxr-widget-rod__difficulty">
                            <?php echo esc_html( ucfirst( $difficulty ) ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php else : /* compact style */ ?>

        <article class="pxr-widget-recipe-item pxr:flex pxr:gap-3 pxr:items-start">
            <?php if ( $image_url ) : ?>
                <a href="<?php echo esc_url( $permalink ); ?>"
                   class="pxr-widget-recipe-item__thumb"
                   tabindex="-1" aria-hidden="true">
                    <img src="<?php echo esc_url( $image_url ); ?>"
                         alt="<?php echo esc_attr( $recipe_title ); ?>"
                         class="pxr-widget-recipe-item__image"
                         loading="lazy" />
                </a>
            <?php endif; ?>

            <div class="pxr-widget-recipe-item__content">
                <?php if ( ! empty( $categories[0]['name'] ) ) : ?>
                    <span class="pxr-widget-recipe-item__category">
                        <?php echo esc_html( $categories[0]['name'] ); ?>
                    </span>
                <?php endif; ?>

                <h4 class="pxr-widget-recipe-item__title">
                    <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $recipe_title ); ?></a>
                </h4>

                <div class="pxr-widget-recipe-item__meta pxr:flex pxr:items-center pxr:gap-3">
                    <?php if ( $show_rating && $rating > 0 ) : ?>
                        <span class="pxr-widget-recipe-item__rating pxr:flex pxr:items-center pxr:gap-1">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="var(--pxr-star-color, #f59e0b)">
                                <path d="M10 1l2.39 4.84 5.34.78-3.87 3.77.91 5.33L10 13.28l-4.77 2.51.91-5.33L2.27 6.69l5.34-.78L10 1z"/>
                            </svg>
                            <?php echo number_format( $rating, 1 ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $show_time && $total_time > 0 ) : ?>
                        <span class="pxr-widget-recipe-item__time pxr:flex pxr:items-center pxr:gap-1">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <?php echo esc_html( plugixa_recipe_format_time( $total_time ) ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </article>

    <?php endif; ?>

</div>
