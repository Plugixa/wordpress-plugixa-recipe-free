<?php
/**
 * The ingredient list of a single recipe.
 *
 * Included by single-recipe.php, which draws it in one of two places (beside the image, or
 * in the single column when there is none), so it reads that template's variables:
 *
 * @var array  $ingredient_groups       Groups of items (Services\RecipePresenter).
 * @var string $ingredients_block_class Extra class for the wrapper; optional.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.

if ( empty( $ingredient_groups ) ) {
    return;
}
?>
<div class="pxr-custom-single__ingredients <?php echo esc_attr( $ingredients_block_class ?? '' ); ?>">
    <div class="pxr-custom-single__section pxr:p-5 pxr:rounded-lg pxr:border">
        <h2 class="pxr-custom-single__section-title pxr:text-lg pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
            <?php esc_html_e( 'Ingredients', 'plugixa-recipe' ); ?>
        </h2>
        <?php
        /**
         * Fires under the Ingredients heading of a single recipe: scaling, units, copy.
         *
         * @since 1.2.0
         *
         * @param object $recipe    The presented recipe.
         * @param string $unique_id The page's id prefix.
         */
        do_action( 'plugixa_recipe_single_ingredient_tools', $recipe, $unique_id );
        ?>
        <?php foreach ( $ingredient_groups as $group ) : ?>
            <?php if ( ! empty( $group->name ) ) : ?>
                <h3 class="pxr-custom-single__group-title pxr:text-sm pxr:font-semibold pxr:uppercase pxr:tracking-wide pxr:mt-4 pxr:mb-2 pxr:opacity-70" data-pxr-ingredient-group>
                    <?php echo esc_html( $group->name ); ?>
                </h3>
            <?php endif; ?>
            <?php if ( ! empty( $group->items ) && is_array( $group->items ) ) : ?>
                <ul class="pxr-custom-single__ingredient-list pxr:list-none pxr:p-0 pxr:m-0 pxr:space-y-2" role="list">
                    <?php foreach ( $group->items as $ingredient ) : ?>
                        <?php
                        $ingredient_name = $ingredient->name ?? $ingredient->text ?? '';
                        $ingredient_link = $ingredient->link_url ?? '';
                        ?>
                        <li class="pxr-custom-single__ingredient pxr:flex pxr:items-start pxr:gap-2">
                            <label class="pxr:flex pxr:items-start pxr:gap-2 pxr:cursor-pointer pxr:w-full">
                                <input type="checkbox"
                                       class="pxr-custom-checkbox pxr:mt-1 pxr:flex-shrink-0 pxr:w-4 pxr:h-4 pxr:rounded pxr:border"
                                       data-pxr-ingredient-check
                                       aria-label="<?php echo esc_attr( $ingredient->text ?? '' ); ?>" />
                                <span class="pxr-custom-single__ingredient-text pxr:text-sm pxr:leading-relaxed"
                                      data-pxr-amount="<?php echo esc_attr( $ingredient->amount ?? '' ); ?>"
                                      data-pxr-ingredient-name="<?php echo esc_attr( $ingredient_name ); ?>"
                                      <?php if ( isset( $ingredient->amount_value ) ) : ?>
                                          data-pxr-amount-value="<?php echo esc_attr( $ingredient->amount_value ); ?>"
                                      <?php endif; ?>
                                      <?php if ( isset( $ingredient->amount_max ) ) : ?>
                                          data-pxr-amount-max="<?php echo esc_attr( $ingredient->amount_max ); ?>"
                                      <?php endif; ?>
                                      <?php if ( ! empty( $ingredient->unit_type ) ) : ?>
                                          data-pxr-unit-type="<?php echo esc_attr( $ingredient->unit_type ); ?>"
                                          data-pxr-unit-system="<?php echo esc_attr( $ingredient->unit_system ); ?>"
                                          data-pxr-unit-factor="<?php echo esc_attr( $ingredient->unit_factor ); ?>"
                                      <?php endif; ?>
                                      data-pxr-unit="<?php echo esc_attr( $ingredient->unit ?? '' ); ?>">
                                    <?php if ( ! empty( $ingredient->amount ) ) : ?>
                                        <strong class="pxr-custom-single__ingredient-amount" data-pxr-ingredient-amount>
                                            <?php echo esc_html( $ingredient->amount ); ?>
                                        </strong>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->unit ) ) : ?>
                                        <span class="pxr-custom-single__ingredient-unit" data-pxr-ingredient-unit>
                                            <?php echo esc_html( $ingredient->unit ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $ingredient_link ) : ?>
                                        <a class="pxr-custom-single__ingredient-link"
                                           href="<?php echo esc_url( $ingredient_link ); ?>"
                                           <?php if ( plugixa_recipe_link_rel( $ingredient_link ) ) : ?>
                                               rel="<?php echo esc_attr( plugixa_recipe_link_rel( $ingredient_link ) ); ?>" target="_blank"
                                           <?php endif; ?>><?php echo esc_html( $ingredient_name ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $ingredient_name ); ?>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->notes ) ) : ?>
                                        <em class="pxr:opacity-60">(<?php echo esc_html( $ingredient->notes ); ?>)</em>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->is_optional ) ) : ?>
                                        <span class="pxr-custom-single__ingredient-optional"><?php esc_html_e( 'optional', 'plugixa-recipe' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->substitution ) ) : ?>
                                        <span class="pxr-custom-single__ingredient-sub">
                                            <?php
                                            /* translators: %s: an ingredient that can be used instead. */
                                            echo esc_html( sprintf( __( 'Or use: %s', 'plugixa-recipe' ), $ingredient->substitution ) );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
