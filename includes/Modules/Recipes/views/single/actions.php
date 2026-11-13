<?php
/**
 * Print, save and share buttons of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up ($share_with_pinterest: false drops Pinterest from the share menu, for a recipe drawn without its photo).
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Action Buttons -->
<div class="pxr-custom-single__actions pxr:flex pxr:flex-wrap pxr:gap-3 pxr:mb-5">

    <?php if ( $show_print ) : ?>
        <a href="<?php echo esc_url( $print_url ); ?>"
           class="pxr-custom-btn pxr-custom-btn--print pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:no-underline"
           target="_blank"
           rel="noopener noreferrer"
           aria-label="<?php esc_attr_e( 'Print this recipe', 'plugixa-recipe' ); ?>">
            <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            <?php esc_html_e( 'Print', 'plugixa-recipe' ); ?>
        </a>
    <?php endif; ?>

    <?php if ( $show_favorite ) : ?>
        <button type="button"
                class="pxr-custom-btn pxr-custom-btn--favorite pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:cursor-pointer <?php echo $is_favorited ? 'pxr-custom-btn--favorited' : ''; ?>"
                data-pxr-favorite="<?php echo esc_attr( $recipe_id ); ?>"
                aria-label="<?php echo $is_favorited ? esc_attr__( 'Remove from favorites', 'plugixa-recipe' ) : esc_attr__( 'Add to favorites', 'plugixa-recipe' ); ?>"
                aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>">
            <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="<?php echo $is_favorited ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
            <span class="pxr-custom-btn__label">
                <?php echo $is_favorited ? esc_html__( 'Saved', 'plugixa-recipe' ) : esc_html__( 'Save', 'plugixa-recipe' ); ?>
            </span>
        </button>
    <?php endif; ?>

    <?php if ( $show_share ) : ?>
        <div class="pxr-custom-share pxr:relative pxr:inline-flex">
            <button type="button"
                    class="pxr-custom-btn pxr-custom-btn--share pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:rounded-lg pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:cursor-pointer"
                    data-pxr-share-toggle
                    aria-expanded="false"
                    aria-haspopup="true"
                    aria-label="<?php esc_attr_e( 'Share recipe', 'plugixa-recipe' ); ?>">
                <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                </svg>
                <?php esc_html_e( 'Share', 'plugixa-recipe' ); ?>
            </button>
            <div class="pxr-custom-share__dropdown pxr:absolute pxr:top-full pxr:left-0 pxr:mt-2 pxr:py-2 pxr:rounded-lg pxr:shadow-lg pxr:border pxr:z-20 pxr:min-w-[160px] pxr:hidden"
                 data-pxr-share-menu
                 role="menu">
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>"
                   class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                   target="_blank"
                   rel="noopener noreferrer"
                   role="menuitem">
                    <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                    </svg>
                    <?php esc_html_e( 'Facebook', 'plugixa-recipe' ); ?>
                </a>
                <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $share_url ); ?>&text=<?php echo rawurlencode( $recipe_title ); ?>"
                   class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                   target="_blank"
                   rel="noopener noreferrer"
                   role="menuitem">
                    <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                    </svg>
                    <?php esc_html_e( 'X', 'plugixa-recipe' ); ?>
                </a>
                <?php if ( $share_with_pinterest ?? true ) : ?>
                <a href="https://pinterest.com/pin/create/button/?url=<?php echo rawurlencode( $share_url ); ?>&media=<?php echo rawurlencode( $image_url ); ?>&description=<?php echo rawurlencode( $recipe_title ); ?>"
                   class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                   target="_blank"
                   rel="noopener noreferrer"
                   role="menuitem">
                    <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 0a12 12 0 00-4.373 23.17c-.1-.937-.19-2.38.04-3.406l1.315-5.572s-.336-.672-.336-1.664c0-1.558.904-2.721 2.028-2.721.956 0 1.418.718 1.418 1.579 0 .962-.613 2.4-.928 3.732-.264 1.114.559 2.023 1.659 2.023 1.99 0 3.52-2.098 3.52-5.124 0-2.68-1.926-4.554-4.676-4.554-3.184 0-5.053 2.388-5.053 4.857 0 .962.37 1.993.833 2.554a.335.335 0 01.077.32l-.31 1.264c-.05.2-.162.243-.374.146-1.395-.649-2.267-2.687-2.267-4.325 0-3.523 2.56-6.76 7.381-6.76 3.876 0 6.887 2.762 6.887 6.453 0 3.851-2.428 6.953-5.798 6.953-1.132 0-2.196-.588-2.56-1.283l-.697 2.654c-.252.973-.933 2.192-1.389 2.936A12 12 0 1012 0z"/>
                    </svg>
                    <?php esc_html_e( 'Pinterest', 'plugixa-recipe' ); ?>
                </a>
                <?php endif; ?>
                <a href="mailto:?subject=<?php echo rawurlencode( $recipe_title ); ?>&body=<?php echo rawurlencode( $share_url ); ?>"
                   class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:no-underline pxr:transition-colors pxr:duration-200"
                   role="menuitem">
                    <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <?php esc_html_e( 'Email', 'plugixa-recipe' ); ?>
                </a>
                <button type="button"
                        class="pxr-custom-share__link pxr:flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:text-sm pxr:w-full pxr:text-left pxr:border-0 pxr:bg-transparent pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                        data-pxr-copy-link="<?php echo esc_attr( $share_url ); ?>"
                        role="menuitem">
                    <svg class="pxr:w-4 pxr:h-4 pxr:flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <?php esc_html_e( 'Copy Link', 'plugixa-recipe' ); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>

</div>
