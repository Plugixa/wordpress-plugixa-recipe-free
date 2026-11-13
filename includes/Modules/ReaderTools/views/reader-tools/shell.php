<?php
/**
 * Markup the reader tools need once per recipe: the cook-mode dialog and the timer dock.
 *
 * Empty shells. The scripts fill them by cloning the page's own steps and the <template>
 * below, so no label is written in JavaScript and nothing is built from a string.
 *
 * @var object $recipe    The presented recipe.
 * @var string $unique_id The page's id prefix.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<dialog class="pxr-cook" id="pxr-cook-<?php echo esc_attr( $unique_id ); ?>" data-pxr-cook
        aria-labelledby="pxr-cook-title-<?php echo esc_attr( $unique_id ); ?>">
    <div class="pxr-cook__inner">
        <header class="pxr-cook__header">
            <div>
                <p class="pxr-cook__recipe" id="pxr-cook-title-<?php echo esc_attr( $unique_id ); ?>"><?php echo esc_html( $recipe->title ?? '' ); ?></p>
                <p class="pxr-cook__count" data-pxr-cook-count aria-live="polite"></p>
            </div>
            <button type="button" class="pxr-cook__close" data-pxr-cook-close aria-label="<?php esc_attr_e( 'Leave cook mode', 'plugixa-recipe' ); ?>">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </header>

        <div class="pxr-cook__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
             aria-label="<?php esc_attr_e( 'Progress through the recipe', 'plugixa-recipe' ); ?>" data-pxr-cook-progress>
            <span class="pxr-cook__progress-bar" data-pxr-cook-progress-bar></span>
        </div>

        <div class="pxr-cook__body">
            <p class="pxr-cook__group" data-pxr-cook-group hidden></p>
            <div class="pxr-cook__step" data-pxr-cook-step tabindex="-1"></div>
        </div>

        <p class="pxr-cook__notice" data-pxr-cook-awake hidden>
            <?php esc_html_e( 'Your screen will stay on while cook mode is open.', 'plugixa-recipe' ); ?>
        </p>
        <p class="pxr-cook__notice" data-pxr-cook-asleep hidden>
            <?php esc_html_e( 'This browser cannot keep the screen on. Tap it now and then, or raise the screen timeout.', 'plugixa-recipe' ); ?>
        </p>

        <footer class="pxr-cook__footer">
            <button type="button" class="pxr-tools__btn" data-pxr-cook-prev><?php esc_html_e( 'Back', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-tools__btn pxr-tools__btn--primary" data-pxr-cook-next><?php esc_html_e( 'Next', 'plugixa-recipe' ); ?></button>
        </footer>
    </div>
</dialog>

<div class="pxr-timer-dock" data-pxr-timer-dock hidden
     role="region" aria-label="<?php esc_attr_e( 'Timers', 'plugixa-recipe' ); ?>">
    <ul class="pxr-timer-dock__list" data-pxr-timer-list role="list"></ul>
    <span class="pxr-timer-dock__live" role="status" aria-live="assertive" data-pxr-timer-live></span>
</div>

<template data-pxr-timer-template>
    <li class="pxr-timer">
        <span class="pxr-timer__label" data-pxr-timer-label></span>
        <span class="pxr-timer__time" data-pxr-timer-time></span>
        <button type="button" class="pxr-timer__btn" data-pxr-timer-toggle></button>
        <button type="button" class="pxr-timer__btn" data-pxr-timer-remove aria-label="<?php esc_attr_e( 'Remove timer', 'plugixa-recipe' ); ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </li>
</template>
