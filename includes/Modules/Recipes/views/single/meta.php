<?php
/**
 * Author, date and star rating of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Meta Bar -->
<div class="pxr-custom-single__meta pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-4 pxr:mb-4 pxr:text-sm">
    <?php if ( $author_name ) : ?>
        <span class="pxr-custom-single__author pxr:flex pxr:items-center pxr:gap-1">
            <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <span>
                <span><?php echo esc_html( $author_name ); ?></span>
            </span>
        </span>
    <?php endif; ?>

    <?php if ( $published_date ) : ?>
        <span class="pxr-custom-single__date pxr:flex pxr:items-center pxr:gap-1">
            <svg class="pxr:w-4 pxr:h-4 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <time datetime="<?php echo esc_attr( $published_iso ); ?>">
                <?php echo esc_html( $published_date ); ?>
            </time>
        </span>
    <?php endif; ?>

    <?php if ( $rating > 0 && $reviews_app_on ) : ?>
        <span class="pxr-custom-single__rating pxr:flex pxr:items-center pxr:gap-1">
            <div class="pxr-custom-card__stars pxr:flex pxr:gap-0.5">
                <?php for ( $i = 1; $i <= 5; $i++ ) :
                    $fill_class = 'pxr-custom-star--empty';
                    if ( $i <= floor( $rating ) ) {
                        $fill_class = 'pxr-custom-star--full';
                    } elseif ( $i - $rating < 1 && $i - $rating > 0 ) {
                        $fill_class = 'pxr-custom-star--half';
                    }
                ?>
                    <svg class="pxr-custom-star <?php echo esc_attr( $fill_class ); ?> pxr:w-4 pxr:h-4"
                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                    </svg>
                <?php endfor; ?>
            </div>
            <span class="pxr:opacity-70">(<?php echo esc_html( $rating_count ); ?>)</span>
        </span>
    <?php endif; ?>
</div>
