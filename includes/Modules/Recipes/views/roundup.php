<?php
/**
 * A round-up: a numbered list of recipes, each a summary that links to the recipe's page.
 *
 * @var array<int, object> $recipes Presented recipes (Services\RecipePresenter).
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $recipes ) ) {
    return;
}
?>
<ol class="pxr-roundup pxr-recipes-wrapper">
    <?php foreach ( $recipes as $plugixa_recipe_number => $plugixa_recipe_item ) : ?>
        <?php
        $plugixa_recipe_summary = wp_trim_words( wp_strip_all_tags( (string) ( $plugixa_recipe_item->excerpt ?: $plugixa_recipe_item->description ) ), 40 );
        $plugixa_recipe_minutes = absint( $plugixa_recipe_item->total_time ?? 0 );
        ?>
        <li class="pxr-roundup__item">
            <?php if ( ! empty( $plugixa_recipe_item->image_url ) ) : ?>
                <a class="pxr-roundup__image" href="<?php echo esc_url( $plugixa_recipe_item->permalink ); ?>" tabindex="-1" aria-hidden="true">
                    <img src="<?php echo esc_url( $plugixa_recipe_item->image_url ); ?>" alt="" loading="lazy" />
                </a>
            <?php endif; ?>
            <div class="pxr-roundup__body">
                <h3 class="pxr-roundup__title">
                    <span class="pxr-roundup__number"><?php echo esc_html( $plugixa_recipe_number + 1 ); ?>.</span>
                    <a href="<?php echo esc_url( $plugixa_recipe_item->permalink ); ?>"><?php echo esc_html( $plugixa_recipe_item->title ); ?></a>
                </h3>
                <?php if ( $plugixa_recipe_minutes || ! empty( $plugixa_recipe_item->difficulty_label ) ) : ?>
                    <p class="pxr-roundup__meta">
                        <?php
                        echo esc_html( implode( ' · ', array_filter( array(
                            // "4 hr 30 min", as the recipe's own page says it.
                            \PlugixaRecipe\Modules\Recipes\Services\RecipePresenter::duration_label( $plugixa_recipe_minutes * MINUTE_IN_SECONDS ),
                            (string) ( $plugixa_recipe_item->difficulty_label ?? '' ),
                        ) ) ) );
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ( '' !== $plugixa_recipe_summary ) : ?>
                    <p class="pxr-roundup__summary"><?php echo esc_html( $plugixa_recipe_summary ); ?></p>
                <?php endif; ?>
                <a class="pxr-jump__link" href="<?php echo esc_url( $plugixa_recipe_item->permalink ); ?>"
                   aria-label="<?php
                       /* translators: %s: recipe title. */
                       echo esc_attr( sprintf( __( 'Get the recipe: %s', 'plugixa-recipe' ), $plugixa_recipe_item->title ) );
                   ?>">
                    <?php esc_html_e( 'Get the recipe', 'plugixa-recipe' ); ?>
                </a>
            </div>
        </li>
    <?php endforeach; ?>
</ol>
