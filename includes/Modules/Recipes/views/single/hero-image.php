<?php
/**
 * The recipe photo with its Pin link.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<div class="pxr-custom-single__hero-image-wrap">
    <img src="<?php echo esc_url( $image_url ); ?>"
         alt="<?php echo esc_attr( $image_alt ); ?>"
         class="pxr-custom-single__hero-img" />
    <?php if ( $show_share && $share_url ) : ?>
        <?php // A link, not Pinterest's script: nothing is loaded from Pinterest until the visitor presses it. ?>
        <a class="pxr-custom-single__pin"
           href="https://www.pinterest.com/pin/create/button/?url=<?php echo rawurlencode( $share_url ); ?>&amp;media=<?php echo rawurlencode( $image_url ); ?>&amp;description=<?php echo rawurlencode( $recipe_title ); ?>"
           target="_blank" rel="noopener noreferrer nofollow">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 0a12 12 0 00-4.373 23.17c-.1-.937-.19-2.38.04-3.406l1.315-5.572s-.336-.672-.336-1.664c0-1.558.904-2.721 2.028-2.721.956 0 1.418.718 1.418 1.579 0 .962-.613 2.4-.928 3.732-.264 1.114.559 2.022 1.658 2.022 1.99 0 3.52-2.098 3.52-5.126 0-2.68-1.926-4.555-4.677-4.555-3.186 0-5.056 2.39-5.056 4.86 0 .962.37 1.994.833 2.555a.335.335 0 01.078.321c-.085.354-.274 1.114-.311 1.27-.049.205-.162.248-.374.149-1.398-.651-2.272-2.695-2.272-4.337 0-3.531 2.566-6.774 7.397-6.774 3.883 0 6.901 2.767 6.901 6.465 0 3.858-2.432 6.963-5.808 6.963-1.134 0-2.2-.59-2.565-1.286l-.698 2.66c-.253.973-.936 2.193-1.393 2.937A12 12 0 1012 0z"/>
            </svg>
            <?php esc_html_e( 'Pin', 'plugixa-recipe' ); ?>
            <span class="pxr-custom-sr-only"><?php esc_html_e( 'this recipe on Pinterest (opens in a new tab)', 'plugixa-recipe' ); ?></span>
        </a>
    <?php endif; ?>
</div>
