<?php
/**
 * The nutrition facts of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Nutrition Facts -->
<?php if ( $show_nutrition && $nutrition ) : ?>
    <section class="pxr-custom-single__nutrition pxr:mb-8" aria-labelledby="pxr-nutrition-heading-<?php echo esc_attr( $unique_id ); ?>">
        <h2 id="pxr-nutrition-heading-<?php echo esc_attr( $unique_id ); ?>"
            class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
            <?php esc_html_e( 'Nutrition Facts', 'plugixa-recipe' ); ?>
        </h2>
        <?php include plugixa_recipe_template( 'nutrition-label.php' ); ?>
    </section>
<?php endif; ?>
