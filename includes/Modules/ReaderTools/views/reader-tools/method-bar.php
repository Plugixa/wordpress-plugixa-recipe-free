<?php
/**
 * The "Cook mode" button, under the Instructions heading.
 *
 * Hidden until cook-mode.js shows it.
 *
 * @var string $unique_id The page's id prefix.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pxr-tools pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-3 pxr:mb-4" data-pxr-method-tools hidden>
    <button type="button" class="pxr-tools__btn pxr-tools__btn--primary" data-pxr-cook-open
            aria-haspopup="dialog" aria-controls="pxr-cook-<?php echo esc_attr( $unique_id ); ?>">
        <?php esc_html_e( 'Cook mode', 'plugixa-recipe' ); ?>
    </button>
    <span class="pxr-tools__hint pxr:text-sm pxr:opacity-70">
        <?php esc_html_e( 'One step at a time, with the screen kept on.', 'plugixa-recipe' ); ?>
    </span>
</div>
