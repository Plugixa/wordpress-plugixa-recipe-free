<?php
/**
 * Scaling presets, the unit switch and "copy" - under the Ingredients heading.
 *
 * Hidden until recipe-tools.js shows it: without the script none of it does anything.
 *
 * @var object $recipe    The presented recipe.
 * @var string $unique_id The page's id prefix.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// Offer the unit switch only when a line of this recipe can be converted.
$plugixa_recipe_convertible = false;
foreach ( (array) ( $recipe->ingredient_groups ?? array() ) as $plugixa_recipe_group ) {
    foreach ( (array) ( $plugixa_recipe_group->items ?? array() ) as $plugixa_recipe_item ) {
        if ( ! empty( $plugixa_recipe_item->unit_type ) && isset( $plugixa_recipe_item->amount_value ) ) {
            $plugixa_recipe_convertible = true;
            break 2;
        }
    }
}
?>
<div class="pxr-tools pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-3 pxr:mb-4" data-pxr-tools hidden>

    <div class="pxr-tools__group" role="group" aria-label="<?php esc_attr_e( 'Scale the recipe', 'plugixa-recipe' ); ?>">
        <?php foreach ( array( '0.5' => '½×', '1' => '1×', '2' => '2×', '3' => '3×' ) as $plugixa_recipe_factor => $plugixa_recipe_label ) : ?>
            <button type="button" class="pxr-tools__btn" data-pxr-scale="<?php echo esc_attr( $plugixa_recipe_factor ); ?>"
                    aria-pressed="<?php echo '1' === (string) $plugixa_recipe_factor ? 'true' : 'false'; ?>">
                <?php echo esc_html( $plugixa_recipe_label ); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php if ( $plugixa_recipe_convertible ) : ?>
        <div class="pxr-tools__group" role="group" aria-label="<?php esc_attr_e( 'Units', 'plugixa-recipe' ); ?>">
            <button type="button" class="pxr-tools__btn" data-pxr-system="original" aria-pressed="true">
                <?php esc_html_e( 'As written', 'plugixa-recipe' ); ?>
            </button>
            <button type="button" class="pxr-tools__btn" data-pxr-system="metric" aria-pressed="false">
                <?php esc_html_e( 'Metric', 'plugixa-recipe' ); ?>
            </button>
            <button type="button" class="pxr-tools__btn" data-pxr-system="us" aria-pressed="false">
                <?php esc_html_e( 'US', 'plugixa-recipe' ); ?>
            </button>
        </div>
    <?php endif; ?>

    <button type="button" class="pxr-tools__btn pxr-tools__btn--plain" data-pxr-copy-ingredients>
        <span data-pxr-copy-label><?php esc_html_e( 'Copy ingredients', 'plugixa-recipe' ); ?></span>
    </button>

    <span class="pxr-tools__status" role="status" aria-live="polite" data-pxr-tools-status></span>
</div>
