<?php
/**
 * The servings adjuster of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up ($servings_input_prefix: the id prefix of the number input, unique per place it is drawn).
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Servings Adjuster (moved into hero right column) -->
<?php if ( $servings ) : ?>
    <div class="pxr-custom-single__servings-adjuster pxr:flex pxr:items-center pxr:gap-3 pxr:mb-5 pxr:py-3 pxr:px-4 pxr:rounded-lg pxr:border"
         data-pxr-servings-adjuster
         data-pxr-original-servings="<?php echo esc_attr( $servings ); ?>">
        <label for="<?php echo esc_attr( ( $servings_input_prefix ?? 'pxr-servings-input-' ) . $unique_id ); ?>" class="pxr:font-medium pxr:text-sm">
            <?php esc_html_e( 'Adjust Servings:', 'plugixa-recipe' ); ?>
        </label>
        <button type="button"
                class="pxr-custom-servings-btn pxr:w-8 pxr:h-8 pxr:rounded-full pxr:border pxr:flex pxr:items-center pxr:justify-center pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                data-pxr-servings-decrease
                aria-label="<?php esc_attr_e( 'Decrease servings', 'plugixa-recipe' ); ?>">
            <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
            </svg>
        </button>
        <input type="number"
               id="<?php echo esc_attr( ( $servings_input_prefix ?? 'pxr-servings-input-' ) . $unique_id ); ?>"
               class="pxr-custom-servings-input pxr:w-16 pxr:text-center pxr:py-1 pxr:px-2 pxr:border pxr:rounded-md pxr:font-semibold"
               value="<?php echo esc_attr( $servings ); ?>"
               min="1"
               max="100"
               data-pxr-servings-input
               aria-label="<?php esc_attr_e( 'Number of servings', 'plugixa-recipe' ); ?>" />
        <button type="button"
                class="pxr-custom-servings-btn pxr:w-8 pxr:h-8 pxr:rounded-full pxr:border pxr:flex pxr:items-center pxr:justify-center pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                data-pxr-servings-increase
                aria-label="<?php esc_attr_e( 'Increase servings', 'plugixa-recipe' ); ?>">
            <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
        </button>
    </div>
<?php endif; ?>
