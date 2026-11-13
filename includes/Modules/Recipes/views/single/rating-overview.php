<?php
/**
 * The rating summary, diet badges and share icons of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Rating Overview Section -->
<section class="pxr-custom-single__rating-overview pxr:mb-8">
    <div class="pxr-custom-single__rating-overview-box pxr:rounded-xl pxr:border pxr:overflow-hidden">

        <?php if ( $total_reviews_for_bars > 0 ) : ?>
            <div class="pxr-custom-single__rating-overview-body pxr:p-6 pxr:flex pxr:flex-col pxr:sm:flex-row pxr:gap-6 pxr:items-start">

                <!-- Left: Average Score -->
                <div class="pxr-custom-single__rating-summary pxr:flex pxr:flex-col pxr:items-center pxr:text-center pxr:min-w-[140px] pxr:flex-shrink-0">
                    <span class="pxr-custom-single__rating-number pxr:text-5xl pxr:font-bold pxr:leading-none" style="color: var(--pxr-title-color, #1a1a2e);">
                        <?php echo esc_html( number_format( $rating, 1 ) ); ?>
                    </span>
                    <span class="pxr:text-sm pxr:opacity-60 pxr:mt-1" style="color: var(--pxr-text-color, #374151);">
                        <?php
                        /* translators: %s: max rating */
                        echo esc_html( sprintf( __( '/ %s', 'plugixa-recipe' ), '5' ) );
                        ?>
                    </span>
                    <div class="pxr:flex pxr:gap-1 pxr:mt-2">
                        <?php for ( $i = 1; $i <= 5; $i++ ) :
                            $star_class = 'pxr-custom-star--empty';
                            if ( $i <= floor( $rating ) ) {
                                $star_class = 'pxr-custom-star--full';
                            } elseif ( $i - $rating < 1 && $i - $rating > 0 ) {
                                $star_class = 'pxr-custom-star--half';
                            }
                        ?>
                            <svg class="pxr-custom-star <?php echo esc_attr( $star_class ); ?> pxr:w-5 pxr:h-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                    </div>
                    <span class="pxr:text-sm pxr:mt-2 pxr:opacity-60" style="color: var(--pxr-text-color, #374151);">
                        <?php
                        /* translators: %d: number of ratings */
                        echo esc_html( sprintf( _n( '%d rating', '%d ratings', $total_reviews_for_bars, 'plugixa-recipe' ), $total_reviews_for_bars ) );
                        ?>
                    </span>
                </div>

                <!-- Right: Distribution Bars -->
                <div class="pxr-custom-single__rating-bars pxr:flex-1 pxr:w-full pxr:space-y-2">
                    <?php for ( $star = 5; $star >= 1; $star-- ) :
                        $count   = $rating_distribution[ $star ];
                        $percent = $total_reviews_for_bars > 0 ? round( ( $count / $total_reviews_for_bars ) * 100 ) : 0;
                    ?>
                        <div class="pxr-custom-single__rating-bar-row pxr:flex pxr:items-center pxr:gap-3">
                            <!-- Star label -->
                            <div class="pxr-custom-single__rating-bar-label pxr:flex pxr:items-center pxr:gap-1 pxr:min-w-[90px] pxr:flex-shrink-0">
                                <?php for ( $star_index = 1; $star_index <= 5; $star_index++ ) : ?>
                                    <svg class="pxr-custom-star <?php echo $star_index <= $star ? 'pxr-custom-star--full' : 'pxr-custom-star--empty'; ?> pxr:w-3.5 pxr:h-3.5"
                                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                <?php endfor; ?>
                            </div>
                            <!-- Progress bar -->
                            <div class="pxr-custom-single__rating-bar-track pxr:flex-1 pxr:h-2.5 pxr:rounded-full pxr:overflow-hidden">
                                <div class="pxr-custom-single__rating-bar-fill pxr:h-full pxr:rounded-full pxr:transition-all pxr:duration-500"
                                     style="width: <?php echo esc_attr( $percent ); ?>%;"
                                     role="progressbar"
                                     aria-valuenow="<?php echo esc_attr( $count ); ?>"
                                     aria-valuemin="0"
                                     aria-valuemax="<?php echo esc_attr( $total_reviews_for_bars ); ?>"
                                     aria-label="<?php echo esc_attr( sprintf( '%d star: %d reviews', $star, $count ) ); ?>">
                                </div>
                            </div>
                            <!-- Count -->
                            <span class="pxr-custom-single__rating-bar-count pxr:text-sm pxr:font-medium pxr:min-w-[24px] pxr:text-right pxr:opacity-70" style="color: var(--pxr-text-color, #374151);">
                                <?php echo esc_html( $count ); ?>
                            </span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php else : ?>
            <div class="pxr:p-6 pxr:text-center">
                <p class="pxr:text-base pxr:opacity-60" style="color: var(--pxr-text-color, #374151);">
                    <?php esc_html_e( 'No ratings yet', 'plugixa-recipe' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Footer Row: Diet Badges + Social Share -->
        <div class="pxr-custom-single__rating-footer pxr:flex pxr:flex-wrap pxr:items-center pxr:justify-between pxr:gap-4 pxr:px-6 pxr:py-4 pxr:border-t pxr:min-w-0 pxr:max-w-full">
            <!-- Diet Badges (left) -->
            <div class="pxr-custom-single__rating-footer-tags pxr:flex pxr:flex-wrap pxr:gap-2 pxr:min-w-0 pxr:max-w-full">
                <?php if ( ! empty( $diet_badges ) ) : ?>
                    <?php foreach ( $diet_badges as $badge ) : ?>
                        <span class="pxr-custom-badge pxr-custom-badge--diet pxr:text-sm pxr:px-3 pxr:py-1 pxr:rounded-full pxr:font-medium">
                            <?php echo esc_html( $badge ); ?>
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <!-- Social Share Icons (right) -->
            <div class="pxr-custom-single__rating-social pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-2 pxr:max-w-full">
                <!-- Facebook -->
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>"
                   class="pxr-custom-single__rating-social-link"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?php esc_attr_e( 'Share on Facebook', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                    </svg>
                </a>
                <!-- X (Twitter) -->
                <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $share_url ); ?>&text=<?php echo rawurlencode( $recipe_title ); ?>"
                   class="pxr-custom-single__rating-social-link"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?php esc_attr_e( 'Share on X', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                    </svg>
                </a>
                <!-- LinkedIn -->
                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo rawurlencode( $share_url ); ?>"
                   class="pxr-custom-single__rating-social-link"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?php esc_attr_e( 'Share on LinkedIn', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                    </svg>
                </a>
                <!-- WhatsApp -->
                <a href="https://api.whatsapp.com/send?text=<?php echo rawurlencode( $recipe_title . ' ' . $share_url ); ?>"
                   class="pxr-custom-single__rating-social-link"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?php esc_attr_e( 'Share on WhatsApp', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                </a>
                <!-- Pinterest -->
                <a href="https://pinterest.com/pin/create/button/?url=<?php echo rawurlencode( $share_url ); ?>&media=<?php echo rawurlencode( $image_url ); ?>&description=<?php echo rawurlencode( $recipe_title ); ?>"
                   class="pxr-custom-single__rating-social-link"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?php esc_attr_e( 'Share on Pinterest', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-4 pxr:h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 0a12 12 0 00-4.373 23.17c-.1-.937-.19-2.38.04-3.406l1.315-5.572s-.336-.672-.336-1.664c0-1.558.904-2.721 2.028-2.721.956 0 1.418.718 1.418 1.579 0 .962-.613 2.4-.928 3.732-.264 1.114.559 2.023 1.659 2.023 1.99 0 3.52-2.098 3.52-5.124 0-2.68-1.926-4.554-4.676-4.554-3.184 0-5.053 2.388-5.053 4.857 0 .962.37 1.993.833 2.554a.335.335 0 01.077.32l-.31 1.264c-.05.2-.162.243-.374.146-1.395-.649-2.267-2.687-2.267-4.325 0-3.523 2.56-6.76 7.381-6.76 3.876 0 6.887 2.762 6.887 6.453 0 3.851-2.428 6.953-5.798 6.953-1.132 0-2.196-.588-2.56-1.283l-.697 2.654c-.252.973-.933 2.192-1.389 2.936A12 12 0 1012 0z"/>
                    </svg>
                </a>
                <!-- Email -->
                <a href="mailto:?subject=<?php echo rawurlencode( $recipe_title ); ?>&body=<?php echo rawurlencode( $share_url ); ?>"
                   class="pxr-custom-single__rating-social-link"
                   aria-label="<?php esc_attr_e( 'Share via Email', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</section>
