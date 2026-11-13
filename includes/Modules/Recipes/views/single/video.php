<?php
/**
 * The recipe's video.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Video Section -->
<?php if ( $show_video && $video_embed ) : ?>
    <section class="pxr-custom-single__video pxr:mb-8"
             aria-labelledby="pxr-video-heading-<?php echo esc_attr( $unique_id ); ?>">
        <h2 id="pxr-video-heading-<?php echo esc_attr( $unique_id ); ?>"
            class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
            <?php esc_html_e( 'Video', 'plugixa-recipe' ); ?>
        </h2>
        <div class="pxr-custom-single__video-wrapper pxr:rounded-xl pxr:overflow-hidden">
            <div class="pxr-custom-single__video-embed">
                <?php
                // oEmbed player markup (YouTube / Vimeo <iframe>), late-escaped through
                // the plugin's central allowlist, which permits the player element and
                // its attributes but no <script>.
                echo wp_kses( $video_embed, plugixa_recipe_allowed_html() );
                ?>
            </div>
        </div>
    </section>
<?php endif; ?>
