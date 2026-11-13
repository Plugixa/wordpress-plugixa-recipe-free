<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Recipe Search
 *
 * Search form with optional category/cuisine filters.
 *
 * @var string $placeholder Search input placeholder
 * @var string $style       'simple' or 'expanded'
 * @var string $action      Form action URL (empty = current page)
 * @var string $title       Widget title
 * @var array  $categories  Category objects for expanded mode
 * @var array  $cuisines    Cuisine objects for expanded mode
 * @var string $unique_id   Unique wrapper ID
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$current_search   = isset( $_GET['plugixa_recipe_search'] ) ? sanitize_text_field( wp_unslash( $_GET['plugixa_recipe_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter re-populating the public search form; no state change.
$current_category = isset( $_GET['plugixa_recipe_category'] ) ? sanitize_text_field( wp_unslash( $_GET['plugixa_recipe_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter re-populating the public search form; no state change.
$current_cuisine  = isset( $_GET['plugixa_recipe_cuisine'] ) ? sanitize_text_field( wp_unslash( $_GET['plugixa_recipe_cuisine'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter re-populating the public search form; no state change.
?>

<div id="<?php echo esc_attr( $unique_id ); ?>"
     class="pxr-widget pxr-widget-search pxr-widget-search--<?php echo esc_attr( $style ); ?>">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <form class="pxr-widget-search__form" action="<?php echo $action ? esc_url( $action ) : ''; ?>" method="get" role="search">
        <div class="pxr-widget-search__input-wrap">
            <svg class="pxr-widget-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text"
                   name="plugixa_recipe_search"
                   value="<?php echo esc_attr( $current_search ); ?>"
                   placeholder="<?php echo esc_attr( $placeholder ); ?>"
                   class="pxr-widget-search__input"
                   aria-label="<?php esc_attr_e( 'Search recipes', 'plugixa-recipe' ); ?>" />
        </div>

        <?php if ( $style === 'expanded' ) : ?>
            <div class="pxr-widget-search__filters pxr:flex pxr:gap-2 pxr:mt-2">
                <?php if ( ! empty( $categories ) ) : ?>
                    <select name="plugixa_recipe_category" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by category', 'plugixa-recipe' ); ?>">
                        <option value=""><?php esc_html_e( 'All Categories', 'plugixa-recipe' ); ?></option>
                        <?php foreach ( $categories as $recipe_cat ) : ?>
                            <option value="<?php echo esc_attr( $recipe_cat->slug ); ?>"
                                <?php selected( $current_category, $recipe_cat->slug ); ?>>
                                <?php echo esc_html( $recipe_cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ( ! empty( $cuisines ) ) : ?>
                    <select name="plugixa_recipe_cuisine" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by cuisine', 'plugixa-recipe' ); ?>">
                        <option value=""><?php esc_html_e( 'All Cuisines', 'plugixa-recipe' ); ?></option>
                        <?php foreach ( $cuisines as $cuisine ) : ?>
                            <option value="<?php echo esc_attr( $cuisine->slug ); ?>"
                                <?php selected( $current_cuisine, $cuisine->slug ); ?>>
                                <?php echo esc_html( $cuisine->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <button type="submit" class="pxr-widget-search__submit">
            <?php esc_html_e( 'Search', 'plugixa-recipe' ); ?>
        </button>
    </form>

</div>
