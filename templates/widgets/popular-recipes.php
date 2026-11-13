<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Popular Recipes
 *
 * Compact list of most viewed or top-rated recipes.
 *
 * @var array  $recipes        Array of recipe objects
 * @var bool   $show_thumbnail Show thumbnail image
 * @var bool   $show_category  Show category badge
 * @var bool   $show_rating    Show star rating
 * @var bool   $show_views     Show view count
 * @var string $title          Widget title
 * @var string $unique_id      Unique wrapper ID
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

if ( ! function_exists( 'plugixa_recipe_format_number' ) ) {
    function plugixa_recipe_format_number( $num ) {
        if ( $num >= 1000000 ) {
            return round( $num / 1000000, 1 ) . 'M';
        }
        if ( $num >= 1000 ) {
            return round( $num / 1000, 1 ) . 'K';
        }
        return number_format_i18n( $num );
    }
}
?>

<div id="<?php echo esc_attr( $unique_id ); ?>" class="pxr-widget pxr-widget-recipes pxr-widget-recipes--popular">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <div class="pxr-widget-recipes__list">
        <?php foreach ( $recipes as $index => $recipe ) : ?>
            <article class="pxr-widget-recipe-item pxr:flex pxr:gap-3 pxr:items-start">
                <?php if ( $show_thumbnail && $recipe->image_url ) : ?>
                    <a href="<?php echo esc_url( $recipe->permalink ); ?>"
                       class="pxr-widget-recipe-item__thumb"
                       tabindex="-1" aria-hidden="true">
                        <img src="<?php echo esc_url( $recipe->image_url ); ?>"
                             alt="<?php echo esc_attr( $recipe->title ); ?>"
                             class="pxr-widget-recipe-item__image"
                             loading="lazy" />
                    </a>
                <?php endif; ?>

                <div class="pxr-widget-recipe-item__content">
                    <?php if ( $show_category && $recipe->category_name ) : ?>
                        <span class="pxr-widget-recipe-item__category">
                            <?php echo esc_html( $recipe->category_name ); ?>
                        </span>
                    <?php endif; ?>

                    <h4 class="pxr-widget-recipe-item__title">
                        <a href="<?php echo esc_url( $recipe->permalink ); ?>">
                            <?php echo esc_html( $recipe->title ); ?>
                        </a>
                    </h4>

                    <div class="pxr-widget-recipe-item__meta pxr:flex pxr:items-center pxr:gap-3">
                        <?php if ( $show_rating && $recipe->rating > 0 ) : ?>
                            <span class="pxr-widget-recipe-item__rating pxr:flex pxr:items-center pxr:gap-1">
                                <svg width="14" height="14" viewBox="0 0 20 20" fill="var(--pxr-star-color, #f59e0b)">
                                    <path d="M10 1l2.39 4.84 5.34.78-3.87 3.77.91 5.33L10 13.28l-4.77 2.51.91-5.33L2.27 6.69l5.34-.78L10 1z"/>
                                </svg>
                                <?php echo number_format( floatval( $recipe->rating ), 1 ); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ( $show_views && $recipe->view_count > 0 ) : ?>
                            <span class="pxr-widget-recipe-item__views pxr:flex pxr:items-center pxr:gap-1">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <?php echo esc_html( plugixa_recipe_format_number( (int) $recipe->view_count ) ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

</div>
