<?php
/**
 * The category badges of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<?php if ( ! empty( $categories ) ) : ?>
    <div class="pxr-custom-single__categories pxr:mb-3">
        <?php foreach ( $categories as $recipe_cat ) : ?>
            <span class="pxr-custom-single__category-badge">
                <?php echo esc_html( is_array( $recipe_cat ) ? $recipe_cat['name'] : ( is_object( $recipe_cat ) ? $recipe_cat->name : $recipe_cat ) ); ?>
            </span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
