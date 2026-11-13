<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Latest Recipes
 *
 * Compact list with thumbnail + category badge + title.
 *
 * @var array  $recipes        Array of recipe objects
 * @var bool   $show_thumbnail Show thumbnail image
 * @var bool   $show_category  Show category badge
 * @var bool   $show_date      Show published date
 * @var string $title          Widget title
 * @var string $unique_id      Unique wrapper ID
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.
?>

<div id="<?php echo esc_attr( $unique_id ); ?>" class="pxr-widget pxr-widget-recipes pxr-widget-recipes--latest">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <div class="pxr-widget-recipes__list">
        <?php foreach ( $recipes as $recipe ) : ?>
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

                    <?php if ( $show_date && $recipe->date ) : ?>
                        <time class="pxr-widget-recipe-item__date"
                              datetime="<?php echo esc_attr( $recipe->published_at ?? $recipe->created_at ); ?>">
                            <?php echo esc_html( $recipe->date ); ?>
                        </time>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

</div>
