<?php
/**
 * The recipe's image gallery.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Gallery Section -->
<?php if ( $show_gallery && ! empty( $gallery_urls ) ) : ?>
    <section class="pxr-custom-single__gallery pxr:mb-8"
             aria-labelledby="pxr-gallery-heading-<?php echo esc_attr( $unique_id ); ?>">
        <h2 id="pxr-gallery-heading-<?php echo esc_attr( $unique_id ); ?>"
            class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
            <?php esc_html_e( 'Images', 'plugixa-recipe' ); ?>
        </h2>
        <div class="pxr-custom-single__gallery-grid" data-pxr-gallery>
            <?php foreach ( $gallery_urls as $img ) : ?>
                <a href="<?php echo esc_url( $img->full ); ?>"
                   class="pxr-custom-single__gallery-item glightbox"
                   data-gallery="pxr-recipe-gallery"
                   data-glightbox="title: <?php echo esc_attr( $img->alt ); ?>">
                    <img src="<?php echo esc_url( $img->url ); ?>"
                         alt="<?php echo esc_attr( $img->alt ); ?>"
                         class="pxr-custom-single__gallery-img"
                         loading="lazy" />
                    <div class="pxr-custom-single__gallery-overlay" aria-hidden="true">
                        <svg class="pxr:w-6 pxr:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/>
                        </svg>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
