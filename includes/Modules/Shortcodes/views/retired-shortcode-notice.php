<?php
/**
 * What a retired shortcode shows to someone who manages the plugin, in place of nothing.
 *
 * A page holding [plugixa_recipe_form], [plugixa_shopping_list] or [plugixa_my_submissions]
 * is empty for visitors while no app handles the tag. Without this, the site owner saw the
 * same empty page and nothing told them why (a "Form" page went blank when its app was
 * switched off). Visitors still get nothing.
 *
 * @var string $shortcode_tag The tag on the page.
 * @var string $apps_url      Plugixa Recipe > Apps.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pxr-retired-shortcode pxr:box-border pxr:my-6 pxr:rounded-lg pxr:border pxr:border-amber-300 pxr:bg-amber-50 pxr:px-4 pxr:py-3 pxr:text-sm pxr:leading-relaxed pxr:text-amber-950" role="note">
    <p class="pxr:m-0">
        <strong class="pxr:font-semibold"><?php esc_html_e( 'Only you can see this note.', 'plugixa-recipe' ); ?></strong>
        <?php
        printf(
            /* translators: %s: a shortcode tag, e.g. [plugixa_recipe_form] */
            esc_html__( 'The %s shortcode on this page shows nothing to your visitors: the app it belongs to is switched off, or is not part of this edition of Plugixa Recipe.', 'plugixa-recipe' ),
            '<code class="pxr:rounded pxr:bg-white/70 pxr:px-1">[' . esc_html( $shortcode_tag ) . ']</code>'
        );
        ?>
    </p>
    <p class="pxr:mt-2 pxr:mb-0">
        <a class="pxr:font-semibold pxr:text-amber-900 pxr:underline" href="<?php echo esc_url( $apps_url ); ?>"><?php esc_html_e( 'Open Plugixa Recipe > Apps', 'plugixa-recipe' ); ?></a>
    </p>
</div>
