<?php
/**
 * The recipe's description.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<?php if ( $description ) : ?>
    <div class="pxr-custom-single__description pxr:text-base pxr:leading-relaxed pxr:mb-4 pxr:opacity-80">
        <?php echo wp_kses_post( $description ); ?>
    </div>
<?php endif; ?>
