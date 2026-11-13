<?php
/**
 * The recipe's title, at the heading level the page asks for.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<h<?php echo (int) $title_level; ?> class="pxr-custom-single__title pxr:text-3xl pxr:font-bold pxr:mb-3 pxr:leading-tight">
    <?php echo esc_html( $recipe_title ); ?>
</h<?php echo (int) $title_level; ?>>
