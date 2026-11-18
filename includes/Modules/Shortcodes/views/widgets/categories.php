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
                       class="pxr-widget-taxonomy__link<?php echo ! empty( $item->is_current ) ? ' pxr-widget-taxonomy__link--current' : ''; ?>"
                       <?php echo ! empty( $item->is_current ) ? 'aria-current="page"' : ''; ?>>
                        <svg class="pxr-widget-taxonomy__chevron" width="6" height="10" viewBox="0 0 6 10" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
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

        <div class="pxr-widget-taxonomy__pills">
            <?php foreach ( $items as $item ) : ?>
                <a href="<?php echo esc_url( $item->permalink ); ?>"
                   class="pxr-widget-taxonomy__pill<?php echo ! empty( $item->is_current ) ? ' pxr-widget-taxonomy__pill--current' : ''; ?>"
                   <?php echo ! empty( $item->is_current ) ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html( $item->name ); ?>
                    <?php if ( $show_count ) : ?>
                        <span class="pxr-widget-taxonomy__pill-count"><?php echo absint( $item->recipe_count ); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

    <?php elseif ( $style === 'grid' ) : ?>

        <div class="pxr-widget-taxonomy__grid">
            <?php foreach ( $items as $item ) : ?>
                <a href="<?php echo esc_url( $item->permalink ); ?>"
                   class="pxr-widget-taxonomy__card<?php echo ! empty( $item->is_current ) ? ' pxr-widget-taxonomy__card--current' : ''; ?>"
                   <?php echo ! empty( $item->is_current ) ? 'aria-current="page"' : ''; ?>>
                    <?php if ( ! empty( $item->image_url ) ) : ?>
                        <img src="<?php echo esc_url( $item->image_url ); ?>"
                             alt=""
                             class="pxr-widget-taxonomy__card-image"
                             loading="lazy" />
                    <?php else : ?>
                        <?php // No picture: the term's first letter stands in, so the cards keep one height. ?>
                        <span class="pxr-widget-taxonomy__card-image pxr-widget-taxonomy__card-image--letter" aria-hidden="true"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( (string) $item->name, 0, 1 ) ) : strtoupper( substr( (string) $item->name, 0, 1 ) ) ); ?></span>
                    <?php endif; ?>
                    <span class="pxr-widget-taxonomy__card-name"><?php echo esc_html( $item->name ); ?></span>
                    <?php if ( $show_count ) : ?>
                        <span class="pxr-widget-taxonomy__card-count">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %d: number of recipes. */
                                    _n( '%d recipe', '%d recipes', absint( $item->recipe_count ), 'plugixa-recipe' ),
                                    absint( $item->recipe_count )
                                )
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
