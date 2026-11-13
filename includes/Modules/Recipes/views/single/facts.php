<?php
/**
 * Three facts the info grid of a single recipe did not show: rest time, yield and course.
 *
 * Included inside the grid by single-recipe.php, in both of its layouts.
 *
 * @var object $recipe The presented recipe (Services\RecipePresenter).
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

$plugixa_recipe_facts = array();

$plugixa_recipe_rest = isset( $recipe->rest_time ) ? absint( $recipe->rest_time ) : 0;
if ( $plugixa_recipe_rest ) {
    $plugixa_recipe_facts[] = array( __( 'Rest', 'plugixa-recipe' ), plugixa_recipe_format_time( $plugixa_recipe_rest ) );
}
if ( ! empty( $recipe->yield ) ) {
    $plugixa_recipe_facts[] = array( __( 'Makes', 'plugixa-recipe' ), $recipe->yield );
}
if ( ! empty( $recipe->courses ) && is_array( $recipe->courses ) ) {
    $plugixa_recipe_facts[] = array( __( 'Course', 'plugixa-recipe' ), implode( ', ', array_column( $recipe->courses, 'name' ) ) );
}

foreach ( $plugixa_recipe_facts as $plugixa_recipe_fact ) :
    ?>
    <div class="pxr-custom-single__info-grid-item">
        <svg class="pxr-custom-single__info-grid-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <div>
            <span class="pxr-custom-single__info-grid-label"><?php echo esc_html( $plugixa_recipe_fact[0] ); ?></span>
            <span class="pxr-custom-single__info-grid-value"><?php echo esc_html( $plugixa_recipe_fact[1] ); ?></span>
        </div>
    </div>
    <?php
endforeach;
