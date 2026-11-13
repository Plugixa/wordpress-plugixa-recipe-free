<?php
/**
 * The time / servings / difficulty / cuisine grid of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Compact Info Grid -->
<div class="pxr-custom-single__info-grid pxr:mb-4">

    <?php if ( $prep_time ) : ?>
        <div class="pxr-custom-single__info-grid-item">
            <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <span class="pxr-custom-single__info-grid-label"><?php esc_html_e( 'Prep', 'plugixa-recipe' ); ?></span>
                <span class="pxr-custom-single__info-grid-value">
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
                <span class="pxr-custom-single__info-grid-value">
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
                <span class="pxr-custom-single__info-grid-value">
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
                <span class="pxr-custom-single__info-grid-value">
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
                <span class="pxr-custom-single__info-grid-value"><?php echo esc_html( $cuisine ); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php include plugixa_recipe_template( 'single/facts.php' ); ?>

</div>
