<?php
/**
 * "Add to shopping list" under a recipe's Ingredients heading.
 *
 * Hidden until shopping-list.js shows it: the list is in the browser, so without the
 * script the button could do nothing.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pxr-tools pxr-add-to-list pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-3 pxr:mb-4" data-pxr-add-to-list hidden>
    <button type="button" class="pxr-tools__btn" data-pxr-add-to-list-button
            title="<?php esc_attr_e( 'Tick what you already have; the rest is added.', 'plugixa-recipe' ); ?>">
        <?php esc_html_e( 'Add to shopping list', 'plugixa-recipe' ); ?>
    </button>
    <span class="pxr-tools__status" role="status" aria-live="polite" data-pxr-add-to-list-status></span>
    <a class="pxr-add-to-list__link" data-pxr-add-to-list-link hidden></a>
</div>
