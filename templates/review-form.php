<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Review Form Template
 *
 * Star rating selector, author fields, review textarea, and AJAX submission.
 * Optionally includes Cloudflare Turnstile verification.
 *
 * Available variables:
 * @var int    $recipe_id       Recipe ID being reviewed
 * @var array  $view_config     Decoded JSON view configuration
 * @var string $unique_id       Unique instance ID
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$recipe_id          = isset( $recipe_id ) ? absint( $recipe_id ) : ( isset( $recipe->id ) ? absint( $recipe->id ) : 0 );
$plugixa_recipe_settings       = get_option( 'plugixa_recipe_settings', [] );
$turnstile_enabled  = ! empty( $plugixa_recipe_settings['turnstile_reviews_enabled'] );
$turnstile_site_key = ! empty( $plugixa_recipe_settings['turnstile_site_key'] ) ? esc_attr( $plugixa_recipe_settings['turnstile_site_key'] ) : '';
$allow_guests       = ! empty( $plugixa_recipe_settings['allow_guest_reviews'] );
$require_rating        = $plugixa_recipe_settings['require_rating'] ?? true;
$review_enable_images  = ! empty( $plugixa_recipe_settings['review_enable_images'] );
$review_max_images     = (int) ( $plugixa_recipe_settings['review_max_images'] ?? 3 );
$form_unique_id        = isset( $unique_id ) ? $unique_id : 'pxr-' . wp_rand( 1000, 9999 );
$form_id            = 'pxr-review-form-' . $form_unique_id;
$nonce              = wp_create_nonce( 'wp_rest' );
$rest_url           = esc_url( rest_url( 'plugixa-recipe/v1/frontend/reviews' ) );

// Check if user is logged in for pre-filling.
$review_author = wp_get_current_user();
$is_logged_in = is_user_logged_in();
$user_name    = $is_logged_in ? $review_author->display_name : '';
$review_author_email   = $is_logged_in ? $review_author->user_email : '';

// Guest review gate: if guests not allowed and user not logged in, show login prompt.
if ( ! $allow_guests && ! $is_logged_in ) {
    echo '<div class="pxr-custom-review-form-wrapper pxr:mt-6">';
    echo '<p class="pxr:text-sm pxr:text-center pxr:py-4 pxr:opacity-70">';
    printf(
        /* translators: 1: opening anchor tag for the login link, 2: closing anchor tag. */
        esc_html__( 'Please %1$slog in%2$s to leave a review.', 'plugixa-recipe' ),
        '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="pxr:underline pxr:font-medium">',
        '</a>'
    );
    echo '</p></div>';
    return;
}
?>

<div class="pxr-custom-review-form-wrapper pxr:mt-6" id="<?php echo esc_attr( $form_id . '-wrapper' ); ?>">

    <h3 class="pxr-custom-review-form__title pxr:text-lg pxr:font-semibold pxr:mb-4">
        <?php esc_html_e( 'Leave a Review', 'plugixa-recipe' ); ?>
    </h3>

    <!-- Success / Error Messages -->
    <div class="pxr-custom-review-form__messages pxr:hidden pxr:mb-4"
         data-pxr-review-messages
         role="alert"
         aria-live="polite">
        <div class="pxr-custom-review-form__success pxr:hidden pxr:p-4 pxr:rounded-lg pxr:text-sm pxr:font-medium"
             data-pxr-review-success>
            <?php esc_html_e( 'Thank you! Your review has been submitted successfully.', 'plugixa-recipe' ); ?>
        </div>
        <div class="pxr-custom-review-form__error pxr:hidden pxr:p-4 pxr:rounded-lg pxr:text-sm pxr:font-medium"
             data-pxr-review-error>
        </div>
    </div>

    <form id="<?php echo esc_attr( $form_id ); ?>"
          class="pxr-custom-review-form pxr:space-y-4"
          data-pxr-review-form
          data-pxr-recipe-id="<?php echo esc_attr( $recipe_id ); ?>"
          data-pxr-rest-url="<?php echo esc_attr( $rest_url ); ?>"
          data-pxr-nonce="<?php echo esc_attr( $nonce ); ?>"
          <?php echo $require_rating ? 'data-pxr-rating-required' : ''; ?>
          novalidate>

        <!-- Star Rating Selector -->
        <fieldset class="pxr-custom-review-form__rating-field pxr:border-0 pxr:p-0 pxr:m-0">
            <legend class="pxr-custom-review-form__label pxr:block pxr:text-sm pxr:font-medium pxr:mb-2">
                <?php esc_html_e( 'Your Rating', 'plugixa-recipe' ); ?>
                <?php if ( $require_rating ) : ?>
                    <span class="pxr:text-red-500" aria-hidden="true">*</span>
                <?php endif; ?>
            </legend>
            <div class="pxr-custom-review-form__stars pxr:flex pxr:gap-1"
                 data-pxr-rating-selector
                 role="radiogroup"
                 aria-label="<?php esc_attr_e( 'Select rating', 'plugixa-recipe' ); ?>">
                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                    <button type="button"
                            class="pxr-custom-review-star pxr:w-8 pxr:h-8 pxr:border-0 pxr:bg-transparent pxr:cursor-pointer pxr:p-0 pxr:transition-transform pxr:duration-100 hover:pxr:scale-110"
                            data-pxr-star-value="<?php echo esc_attr( $i ); ?>"
                            role="radio"
                            aria-checked="false"
                            aria-label="<?php
                                /* translators: %d: number of stars */
                                echo esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'plugixa-recipe' ), $i ) );
                            ?>">
                        <svg class="pxr-custom-star pxr-custom-star--empty pxr:w-8 pxr:h-8"
                             viewBox="0 0 20 20"
                             fill="currentColor"
                             aria-hidden="true">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                    </button>
                <?php endfor; ?>
            </div>
            <input type="hidden"
                   name="rating"
                   value=""
                   data-pxr-rating-value
                   required />
        </fieldset>

        <!-- Author Name -->
        <div class="pxr-custom-review-form__field">
            <label for="<?php echo esc_attr( $form_id . '-name' ); ?>"
                   class="pxr-custom-review-form__label pxr:block pxr:text-sm pxr:font-medium pxr:mb-1">
                <?php esc_html_e( 'Your Name', 'plugixa-recipe' ); ?>
                <span class="pxr:text-red-500" aria-hidden="true">*</span>
            </label>
            <input type="text"
                   id="<?php echo esc_attr( $form_id . '-name' ); ?>"
                   name="author_name"
                   value="<?php echo esc_attr( $user_name ); ?>"
                   class="pxr-custom-input pxr:w-full pxr:px-3 pxr:py-2 pxr:border pxr:rounded-lg pxr:text-sm pxr:transition-colors pxr:duration-200"
                   placeholder="<?php esc_attr_e( 'John Doe', 'plugixa-recipe' ); ?>"
                   required
                   autocomplete="name"
                   <?php echo $is_logged_in ? 'readonly' : ''; ?> />
        </div>

        <!-- Author Email -->
        <div class="pxr-custom-review-form__field">
            <label for="<?php echo esc_attr( $form_id . '-email' ); ?>"
                   class="pxr-custom-review-form__label pxr:block pxr:text-sm pxr:font-medium pxr:mb-1">
                <?php esc_html_e( 'Your Email', 'plugixa-recipe' ); ?>
                <span class="pxr:text-red-500" aria-hidden="true">*</span>
            </label>
            <input type="email"
                   id="<?php echo esc_attr( $form_id . '-email' ); ?>"
                   name="author_email"
                   value="<?php echo esc_attr( $review_author_email ); ?>"
                   class="pxr-custom-input pxr:w-full pxr:px-3 pxr:py-2 pxr:border pxr:rounded-lg pxr:text-sm pxr:transition-colors pxr:duration-200"
                   placeholder="<?php esc_attr_e( 'john@example.com', 'plugixa-recipe' ); ?>"
                   required
                   autocomplete="email"
                   <?php echo $is_logged_in ? 'readonly' : ''; ?> />
            <p class="pxr-custom-review-form__hint pxr:text-xs pxr:opacity-60 pxr:mt-1">
                <?php esc_html_e( 'Your email will not be published.', 'plugixa-recipe' ); ?>
            </p>
        </div>

        <!-- Review Text -->
        <div class="pxr-custom-review-form__field">
            <label for="<?php echo esc_attr( $form_id . '-text' ); ?>"
                   class="pxr-custom-review-form__label pxr:block pxr:text-sm pxr:font-medium pxr:mb-1">
                <?php esc_html_e( 'Your Review', 'plugixa-recipe' ); ?>
                <span class="pxr:text-red-500" aria-hidden="true">*</span>
            </label>
            <textarea id="<?php echo esc_attr( $form_id . '-text' ); ?>"
                      name="review_text"
                      class="pxr-custom-textarea pxr:w-full pxr:px-3 pxr:py-2 pxr:border pxr:rounded-lg pxr:text-sm pxr:transition-colors pxr:duration-200 pxr:resize-y"
                      rows="4"
                      placeholder="<?php esc_attr_e( 'Share your experience with this recipe...', 'plugixa-recipe' ); ?>"
                      required
                      minlength="10"
                      maxlength="2000"></textarea>
        </div>

        <?php if ( $review_enable_images ) : ?>
            <!-- Image Upload -->
            <div class="pxr-custom-review-form__field">
                <label class="pxr-custom-review-form__label pxr:block pxr:text-sm pxr:font-medium pxr:mb-1">
                    <?php esc_html_e( 'Photos', 'plugixa-recipe' ); ?>
                    <span class="pxr:text-xs pxr:opacity-60 pxr:font-normal">
                        (<?php
                        /* translators: %d: maximum number of images allowed. */
                        printf( esc_html__( 'max %d', 'plugixa-recipe' ), absint( $review_max_images ) );
                        ?>)
                    </span>
                </label>
                <div class="pxr-review-images-preview pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mb-2" data-pxr-review-images-preview></div>
                <label class="pxr-review-images-trigger pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-4 pxr:py-2 pxr:border pxr:border-dashed pxr:rounded-lg pxr:text-sm pxr:cursor-pointer pxr:transition-colors pxr:duration-200 hover:pxr:border-current"
                       data-pxr-review-images-trigger>
                    <svg class="pxr:w-4 pxr:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <?php esc_html_e( 'Add Photos', 'plugixa-recipe' ); ?>
                    <input type="file"
                           name="review_images[]"
                           accept="image/jpeg,image/png,image/webp"
                           multiple
                           class="pxr:hidden"
                           data-pxr-review-images-input
                           data-max-files="<?php echo esc_attr( $review_max_images ); ?>"
                           data-max-size="<?php echo esc_attr( (int) ( $plugixa_recipe_settings['max_upload_size'] ?? 5 ) ); ?>" />
                </label>
                <p class="pxr-custom-review-form__hint pxr:text-xs pxr:opacity-60 pxr:mt-1">
                    <?php esc_html_e( 'JPG, PNG or WebP. Max 5 MB each.', 'plugixa-recipe' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ( $turnstile_enabled && $turnstile_site_key ) : ?>
            <div class="pxr-custom-captcha-field">
                <div class="cf-turnstile"
                    data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"
                    data-response-field-name="turnstile_token"
                    data-theme="auto"></div>
            </div>
        <?php endif; ?>

        <!-- Submit Button -->
        <div class="pxr-custom-review-form__actions">
            <button type="submit"
                    class="pxr-custom-btn pxr-custom-btn--primary pxr:inline-flex pxr:items-center pxr:gap-2 pxr:px-6 pxr:py-2.5 pxr:rounded-lg pxr:text-sm pxr:font-semibold pxr:border-0 pxr:cursor-pointer pxr:transition-all pxr:duration-200"
                    data-pxr-review-submit>
                <span class="pxr-custom-btn__text"><?php esc_html_e( 'Submit Review', 'plugixa-recipe' ); ?></span>
                <svg class="pxr-custom-btn__spinner pxr:hidden pxr:w-4 pxr:h-4 pxr:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="pxr:opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="pxr:opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </button>
        </div>

    </form>

</div>


<?php
// The review form's behaviour lives in a real enqueued file, never an inline <script>. It is
// enqueued here rather than in RecipeSingle because this template is its only consumer and it
// renders before wp_footer(), so the footer-loaded handle still prints. Enqueuing twice (two
// forms on one page) is a no-op; the script itself initialises every form it finds.
wp_enqueue_script(
    'plugixa-recipe-review-form',
    PLUGIXA_RECIPE_URL . 'assets/frontend/js/review-form.js',
    [],
    PLUGIXA_RECIPE_VERSION,
    true
);
wp_localize_script(
    'plugixa-recipe-review-form',
    'plugixaRecipeReviewL10n',
    [
        'maxImages'      => __( 'Maximum number of images reached.', 'plugixa-recipe' ),
        'remove'         => __( 'Remove', 'plugixa-recipe' ),
        'submitting'     => __( 'Submitting...', 'plugixa-recipe' ),
        'submit'         => __( 'Submit Review', 'plugixa-recipe' ),
        'selectRating'   => __( 'Please select a rating.', 'plugixa-recipe' ),
        'nameRequired'   => __( 'Name is required.', 'plugixa-recipe' ),
        'invalidEmail'   => __( 'Please enter a valid email address.', 'plugixa-recipe' ),
        'reviewTooShort' => __( 'Review must be at least 10 characters.', 'plugixa-recipe' ),
        'genericError'   => __( 'An error occurred. Please try again.', 'plugixa-recipe' ),
        'networkError'   => __( 'Network error. Please check your connection and try again.', 'plugixa-recipe' ),
    ]
);

// Turnstile is enqueued properly (never printed as a raw <script src>), and only when the
// site owner switched it on and supplied their own key.
\PlugixaRecipe\Frontend\Shortcodes::enqueue_turnstile( (bool) $turnstile_enabled, (string) $turnstile_site_key );
?>
