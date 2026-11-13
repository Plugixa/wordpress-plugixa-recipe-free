<?php
/**
 * What the author wrote around the method: equipment, notes, tips, the source and tags.
 *
 * All five were stored by the editor and drawn nowhere. Included by single-recipe.php
 * below the instructions.
 *
 * @var object $recipe    The presented recipe (Services\RecipePresenter).
 * @var string $unique_id The page's id prefix.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

$plugixa_recipe_equipment = isset( $recipe->equipment_items ) && is_array( $recipe->equipment_items ) ? $recipe->equipment_items : array();
$plugixa_recipe_notes     = isset( $recipe->notes ) ? trim( (string) $recipe->notes ) : '';
$plugixa_recipe_tips      = isset( $recipe->tips ) ? trim( (string) $recipe->tips ) : '';
$plugixa_recipe_source    = isset( $recipe->source ) ? trim( (string) $recipe->source ) : '';
$plugixa_recipe_source_to = isset( $recipe->source_url ) ? trim( (string) $recipe->source_url ) : '';
$plugixa_recipe_tags      = isset( $recipe->tag_list ) && is_array( $recipe->tag_list ) ? $recipe->tag_list : array();
?>

<?php if ( $plugixa_recipe_equipment ) : ?>
    <section class="pxr-custom-single__equipment pxr:mb-8" aria-labelledby="pxr-equipment-heading-<?php echo esc_attr( $unique_id ); ?>">
        <h2 id="pxr-equipment-heading-<?php echo esc_attr( $unique_id ); ?>"
            class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
            <?php esc_html_e( 'Equipment', 'plugixa-recipe' ); ?>
        </h2>
        <ul class="pxr-custom-single__equipment-list pxr:list-none pxr:p-0 pxr:m-0 pxr:flex pxr:flex-wrap pxr:gap-2" role="list">
            <?php foreach ( $plugixa_recipe_equipment as $plugixa_recipe_tool ) : ?>
                <li class="pxr-custom-single__equipment-item pxr:text-sm pxr:px-3 pxr:py-1 pxr:rounded-full pxr:border">
                    <?php if ( ! empty( $plugixa_recipe_tool->link_url ) ) : ?>
                        <a href="<?php echo esc_url( $plugixa_recipe_tool->link_url ); ?>"
                           <?php if ( plugixa_recipe_link_rel( $plugixa_recipe_tool->link_url ) ) : ?>
                               rel="<?php echo esc_attr( plugixa_recipe_link_rel( $plugixa_recipe_tool->link_url ) ); ?>" target="_blank"
                           <?php endif; ?>><?php echo esc_html( $plugixa_recipe_tool->name ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $plugixa_recipe_tool->name ); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ( '' !== $plugixa_recipe_notes || '' !== $plugixa_recipe_tips ) : ?>
    <section class="pxr-custom-single__notes pxr:mb-8">
        <?php if ( '' !== $plugixa_recipe_notes ) : ?>
            <div class="pxr-custom-single__note pxr:p-5 pxr:rounded-lg pxr:border pxr:mb-4">
                <h2 class="pxr-custom-single__section-title pxr:text-lg pxr:font-bold pxr:mb-2">
                    <?php esc_html_e( 'Notes', 'plugixa-recipe' ); ?>
                </h2>
                <div class="pxr-custom-single__note-text pxr:leading-relaxed">
                    <?php echo wp_kses_post( wpautop( $plugixa_recipe_notes ) ); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ( '' !== $plugixa_recipe_tips ) : ?>
            <div class="pxr-custom-single__note pxr-custom-single__note--tips pxr:p-5 pxr:rounded-lg pxr:border">
                <h2 class="pxr-custom-single__section-title pxr:text-lg pxr:font-bold pxr:mb-2">
                    <?php esc_html_e( 'Tips', 'plugixa-recipe' ); ?>
                </h2>
                <div class="pxr-custom-single__note-text pxr:leading-relaxed">
                    <?php echo wp_kses_post( wpautop( $plugixa_recipe_tips ) ); ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ( '' !== $plugixa_recipe_source || '' !== $plugixa_recipe_source_to || $plugixa_recipe_tags ) : ?>
    <footer class="pxr-custom-single__credits pxr:mb-8 pxr:text-sm">
        <?php if ( '' !== $plugixa_recipe_source || '' !== $plugixa_recipe_source_to ) : ?>
            <p class="pxr-custom-single__source pxr:m-0 pxr:mb-3">
                <span class="pxr:opacity-70"><?php esc_html_e( 'Source:', 'plugixa-recipe' ); ?></span>
                <?php if ( '' !== $plugixa_recipe_source_to ) : ?>
                    <a href="<?php echo esc_url( $plugixa_recipe_source_to ); ?>"
                       <?php if ( plugixa_recipe_link_rel( $plugixa_recipe_source_to ) ) : ?>
                           rel="<?php echo esc_attr( plugixa_recipe_link_rel( $plugixa_recipe_source_to ) ); ?>" target="_blank"
                       <?php endif; ?>><?php echo esc_html( '' !== $plugixa_recipe_source ? $plugixa_recipe_source : wp_parse_url( $plugixa_recipe_source_to, PHP_URL_HOST ) ); ?></a>
                <?php else : ?>
                    <?php echo esc_html( $plugixa_recipe_source ); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ( $plugixa_recipe_tags ) : ?>
            <ul class="pxr-custom-single__tags pxr:list-none pxr:p-0 pxr:m-0 pxr:flex pxr:flex-wrap pxr:gap-2"
                aria-label="<?php esc_attr_e( 'Tags', 'plugixa-recipe' ); ?>">
                <?php foreach ( $plugixa_recipe_tags as $plugixa_recipe_tag ) : ?>
                    <li class="pxr-custom-single__tag pxr:px-3 pxr:py-1 pxr:rounded-full pxr:border"><?php echo esc_html( $plugixa_recipe_tag ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </footer>
<?php endif; ?>
