<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Categories / Cuisines List
 *
 * Shared template for [plugixa_categories] and [plugixa_cuisines].
 *
 * @var array  $items      Array of taxonomy objects (id, name, slug, recipe_count, permalink, image_url)
 * @var string $type       'category' or 'cuisine'
 * @var string $style      'list', 'pills', or 'grid'
 * @var bool   $show_count Whether to show recipe count
 * @var string $title      Widget title
 * @var string $unique_id  Unique wrapper ID
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.
?>

<div id="<?php echo esc_attr( $unique_id ); ?>"
     class="pxr-widget pxr-widget-taxonomy pxr-widget-taxonomy--<?php echo esc_attr( $type ); ?> pxr-widget-taxonomy--<?php echo esc_attr( $style ); ?>">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <?php if ( $style === 'list' ) : ?>

        <ul class="pxr-widget-taxonomy__list">
            <?php foreach ( $items as $item ) : ?>
                <li class="pxr-widget-taxonomy__list-item">
                    <a href="<?php echo esc_url( $item->permalink ); ?>"
                       class="pxr-widget-taxonomy__link">
                        <svg class="pxr-widget-taxonomy__chevron" width="6" height="10" viewBox="0 0 6 10" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 1L5 5L1 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="pxr-widget-taxonomy__name"><?php echo esc_html( $item->name ); ?></span>
                        <?php if ( $show_count ) : ?>
                            <span class="pxr-widget-taxonomy__count">(<?php echo absint( $item->recipe_count ); ?>)</span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php elseif ( $style === 'pills' ) : ?>

        <div class="pxr-widget-taxonomy__pills pxr:flex pxr:flex-wrap pxr:gap-2">
            <?php foreach ( $items as $item ) : ?>
                <a href="<?php echo esc_url( $item->permalink ); ?>"
                   class="pxr-widget-taxonomy__pill">
                    <?php echo esc_html( $item->name ); ?>
                    <?php if ( $show_count ) : ?>
                        <span class="pxr-widget-taxonomy__pill-count"><?php echo absint( $item->recipe_count ); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

    <?php elseif ( $style === 'grid' ) : ?>

        <div class="pxr-widget-taxonomy__grid pxr:grid pxr:grid-cols-2 pxr:gap-3">
            <?php foreach ( $items as $item ) : ?>
                <a href="<?php echo esc_url( $item->permalink ); ?>"
                   class="pxr-widget-taxonomy__card pxr:flex pxr:flex-col pxr:items-center pxr:text-center pxr:p-4 pxr:rounded-lg">
                    <?php if ( ! empty( $item->image_url ) ) : ?>
                        <img src="<?php echo esc_url( $item->image_url ); ?>"
                             alt="<?php echo esc_attr( $item->name ); ?>"
                             class="pxr-widget-taxonomy__card-image pxr:w-16 pxr:h-16 pxr:rounded-full pxr:object-cover pxr:mb-2"
                             loading="lazy" />
                    <?php endif; ?>
                    <span class="pxr-widget-taxonomy__card-name"><?php echo esc_html( $item->name ); ?></span>
                    <?php if ( $show_count ) : ?>
                        <span class="pxr-widget-taxonomy__card-count"><?php echo absint( $item->recipe_count ); ?> <?php esc_html_e( 'recipes', 'plugixa-recipe' ); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
