<?php
/**
 * The reviews and review form of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Reviews Section -->
<?php if ( $show_reviews ) : ?>
    <section class="pxr-custom-single__reviews pxr:mb-8" aria-labelledby="pxr-reviews-heading-<?php echo esc_attr( $unique_id ); ?>">
        <h2 id="pxr-reviews-heading-<?php echo esc_attr( $unique_id ); ?>"
            class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-4 pxr:pb-2 pxr:border-b">
            <?php
            /* translators: %d: number of reviews */
            echo esc_html( sprintf( _n( '%d Review', '%d Reviews', $rating_count, 'plugixa-recipe' ), $rating_count ) );
            ?>
        </h2>

        <?php if ( ! empty( $reviews ) ) : ?>
            <?php
            $per_page_reviews = (int) ( $plugixa_recipe_global_settings['reviews_per_page'] ?? 10 );
            $reviews_page     = array_slice( $reviews, 0, $per_page_reviews );
            $has_more_reviews = count( $reviews ) > $per_page_reviews;
            ?>
            <div class="pxr-custom-single__review-list pxr:space-y-4 pxr:mb-6"
                 data-pxr-review-list>
                <?php foreach ( $reviews_page as $review ) :
                    // Generate avatar initials and color.
                    $review_name   = $review->author_name ?? __( 'Anonymous', 'plugixa-recipe' );
                    $name_parts    = preg_split( '/\s+/', trim( $review_name ) );
                    $initials      = mb_strtoupper( mb_substr( $name_parts[0], 0, 1 ) );
                    if ( count( $name_parts ) > 1 ) {
                        $initials .= mb_strtoupper( mb_substr( end( $name_parts ), 0, 1 ) );
                    }
                    $avatar_colors = [ '#4F46E5', '#0891B2', '#059669', '#D97706', '#DC2626', '#7C3AED', '#DB2777', '#2563EB' ];
                    $avatar_color  = $avatar_colors[ crc32( $review_name ) % count( $avatar_colors ) ];
                    $helpful_count = (int) ( $review->helpful_count ?? 0 );
                ?>
                    <div class="pxr-custom-review pxr:p-4 pxr:rounded-lg pxr:border"
                         data-review-id="<?php echo esc_attr( $review->id ); ?>">
                        <div class="pxr-custom-review__header pxr:flex pxr:items-center pxr:justify-between pxr:mb-2">
                            <div class="pxr:flex pxr:items-center pxr:gap-3">
                                <div class="pxr-custom-review__avatar pxr:w-9 pxr:h-9 pxr:rounded-full pxr:flex pxr:items-center pxr:justify-center pxr:text-xs pxr:font-bold pxr:text-white pxr:shrink-0 pxr:select-none"
                                     style="background-color: <?php echo esc_attr( $avatar_color ); ?>;">
                                    <?php echo esc_html( $initials ); ?>
                                </div>
                                <div>
                                    <span class="pxr-custom-review__author pxr:font-semibold pxr:text-sm pxr:block">
                                        <?php echo esc_html( $review_name ); ?>
                                    </span>
                                    <?php if ( ! empty( $review->created_at ) ) : ?>
                                        <time class="pxr-custom-review__date pxr:text-xs pxr:opacity-60" datetime="<?php echo esc_attr( $review->created_at ); ?>">
                                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->created_at ) ) ); ?>
                                        </time>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ( ! empty( $review->rating ) ) : ?>
                                <div class="pxr-custom-review__rating pxr:flex pxr:gap-0.5">
                                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                        <svg class="pxr-custom-star <?php echo $i <= $review->rating ? 'pxr-custom-star--full' : 'pxr-custom-star--empty'; ?> pxr:w-3.5 pxr:h-3.5"
                                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $review->review_text ) ) : ?>
                            <div class="pxr-custom-review__text pxr:text-sm pxr:leading-relaxed">
                                <?php echo wp_kses_post( $review->review_text ); ?>
                            </div>
                        <?php endif; ?>
                        <?php
                        // Display review images.
                        $review_image_ids = ! empty( $review->images ) ? json_decode( $review->images, true ) : [];
                        if ( ! empty( $review_image_ids ) && is_array( $review_image_ids ) ) : ?>
                            <div class="pxr-custom-review__images pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mt-3">
                                <?php foreach ( $review_image_ids as $att_id ) :
                                    $img_url  = wp_get_attachment_image_url( (int) $att_id, 'thumbnail' );
                                    $full_url = wp_get_attachment_image_url( (int) $att_id, 'large' );
                                    if ( ! $img_url ) continue;
                                ?>
                                    <a href="<?php echo esc_url( $full_url ?: $img_url ); ?>"
                                       target="_blank"
                                       rel="noopener"
                                       class="pxr-custom-review__image-link pxr:block pxr:w-16 pxr:h-16 pxr:rounded-lg pxr:overflow-hidden pxr:border pxr:transition-opacity pxr:hover:opacity-80">
                                        <img src="<?php echo esc_url( $img_url ); ?>"
                                             alt="<?php esc_attr_e( 'Review photo', 'plugixa-recipe' ); ?>"
                                             class="pxr:w-full pxr:h-full pxr:object-cover"
                                             loading="lazy" />
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( ! empty( $plugixa_recipe_global_settings['review_enable_helpful_votes'] ) ) : ?>
                            <div class="pxr-custom-review__actions pxr:flex pxr:items-center pxr:gap-3 pxr:mt-3">
                                <button type="button"
                                        class="pxr-helpful-btn pxr:inline-flex pxr:items-center pxr:gap-1.5 pxr:px-3 pxr:py-1 pxr:rounded-full pxr:border pxr:text-xs pxr:font-medium pxr:cursor-pointer pxr:transition-colors pxr:duration-200 pxr:bg-transparent"
                                        data-pxr-helpful-btn
                                        data-review-id="<?php echo esc_attr( $review->id ); ?>"
                                        data-helpful-count="<?php echo esc_attr( $helpful_count ); ?>"
                                        aria-label="<?php esc_attr_e( 'Mark as helpful', 'plugixa-recipe' ); ?>">
                                    <svg class="pxr:w-3.5 pxr:h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" />
                                    </svg>
                                    <span data-pxr-helpful-label>
                                        <?php
                                        if ( $helpful_count > 0 ) {
                                            /* translators: %d: number of helpful votes. */
                                            printf( esc_html__( 'Helpful (%d)', 'plugixa-recipe' ), absint( $helpful_count ) );
                                        } else {
                                            esc_html_e( 'Helpful', 'plugixa-recipe' );
                                        }
                                        ?>
                                    </span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if ( ! empty( $plugixa_recipe_global_settings['review_enable_admin_reply'] ) && ! empty( $review->admin_reply ) ) : ?>
                            <div class="pxr-custom-review__admin-reply pxr:mt-3 pxr:ml-12 pxr:p-3 pxr:rounded-lg pxr:border-l-4 pxr:text-sm" style="background: var(--pxr-bg-secondary, #f9fafb); border-left-color: var(--pxr-primary, #4F46E5);">
                                <div class="pxr:flex pxr:items-center pxr:gap-2 pxr:mb-1">
                                    <svg class="pxr:w-3.5 pxr:h-3.5 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                    <span class="pxr:font-semibold pxr:text-xs"><?php esc_html_e( 'Admin', 'plugixa-recipe' ); ?></span>
                                    <?php if ( ! empty( $review->admin_reply_at ) ) : ?>
                                        <time class="pxr:text-xs pxr:opacity-60"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->admin_reply_at ) ) ); ?></time>
                                    <?php endif; ?>
                                </div>
                                <div class="pxr:leading-relaxed"><?php echo wp_kses_post( $review->admin_reply ); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( $has_more_reviews ) : ?>
                <div class="pxr-custom-single__review-pagination pxr:text-center pxr:mb-6">
                    <button type="button"
                            class="pxr-load-more-reviews pxr:px-6 pxr:py-2 pxr:rounded-lg pxr:border pxr:text-sm pxr:font-medium pxr:cursor-pointer"
                            data-recipe-id="<?php echo esc_attr( $recipe_id ); ?>"
                            data-page="2"
                            data-total="<?php echo esc_attr( count( $reviews ) ); ?>">
                        <?php esc_html_e( 'Load More Reviews', 'plugixa-recipe' ); ?>
                    </button>
                    <span class="pxr-loading-spinner pxr:ml-2" style="display:none;">
                        <?php esc_html_e( 'Loading…', 'plugixa-recipe' ); ?>
                    </span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( $enable_reviews ) : ?>
            <?php include plugixa_recipe_template( 'review-form.php' ); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
