<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Reviews\Http;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every $_POST/$_FILES read below happens inside a callback registered with register_rest_route(); WP core dispatches these and enforces the route's permission_callback (cookie-authenticated callers are nonce-checked by the REST infrastructure itself). The submission endpoints are deliberately public (__return_true) because anyone may post a review or submit a recipe -- a nonce would add no protection to an action an anonymous visitor is allowed to perform -- so abuse is instead controlled by Cloudflare Turnstile, a honeypot field and per-IP hourly rate limiting (see the get_transient() rate gates).
// This directive is file-scoped ONLY because the rationale is uniform: this file registers REST
// routes and nothing else. That is the invariant that makes it safe, and RestSecurityTest enforces
// it -- adding an admin-post, admin_action or wp_ajax handler here fails the build, because such a
// handler WOULD need a nonce and this header would silence the sniff that says so. Add it to its
// own file instead. The ValidatedSanitizedInput sniffs are deliberately NOT suppressed file-wide:
// they are scoped to the individual upload blocks so that un-sanitizing a filename still reports.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Http\PublicController;
use PlugixaRecipe\Core\Media\Uploader;
use PlugixaRecipe\Modules\Reviews\Services\PublicReviewService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Public review reads, review submission and helpful votes.
 *
 * Split out of the former FrontendApi so each public route lives in the module that owns
 * it - and disappears with that module when the App Manager switches it off.
 *
 * The routes, the reading of the request, the captcha and the image upload - nothing else.
 * The rules (who may post, how often, what a valid review is) belong to PublicReviewService;
 * the pinned public queries belong to PublicReviewRepository.
 */
class FrontendReviewsController extends PublicController {

    private PublicReviewService $reviews;

    public function __construct(PublicReviewService $reviews) {
        parent::__construct();
        $this->reviews = $reviews;
    }

    public function register_routes(): void {
        // Public by design: approved reviews for a published recipe, as displayed under it.
        // Pinned to status='approved'; author_email and author_ip are not in the select.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/recipes/(?P<id>\d+)/reviews', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_recipe_reviews'],
                'permission_callback' => '__return_true',
                'args'                => $this->get_collection_params(),
            ],
        ]);

        // Public by design: posting a review is an anonymous action by definition (the
        // "allow guest reviews" setting can restrict it to logged-in users). Guarded by
        // Turnstile, a per-IP hourly cap, and optional moderation before publication.
        // No args schema: the form posts multipart/form-data so it can carry review images,
        // and the handler reads those fields through its own unslash-then-sanitize-per-field
        // helper. Declaring them here would validate the JSON copy while the multipart body
        // took a different path, so every field is required, typed, length-capped and
        // sanitized in submit_review() instead -- one place, covering both encodings.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/reviews', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'submit_review'],
                'permission_callback' => '__return_true',
            ],
        ]);

        // Public by design: a helpful vote on an approved review. Writes a single counter,
        // de-duplicated per user id or per IP, behind a per-IP hourly cap, and only while
        // the feature is switched on.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/reviews/(?P<review_id>\d+)/helpful', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'toggle_review_helpful'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'review_id' => [
                        'description'       => __('Unique identifier for the review.', 'plugixa-recipe'),
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static function ($value) {
                            return is_numeric($value) && (int) $value > 0;
                        },
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get approved reviews for a recipe.
     */
    public function get_recipe_reviews($request): WP_REST_Response|WP_Error {
        $recipe_id = (int) $request->get_param('id');
        $pagination = $this->get_pagination($request);

        // The caller's address keys a guest's helpful votes (per-user/IP state: not cached).
        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        return $this->success($this->reviews->approved_for_recipe(
            $recipe_id,
            $request->get_param('limit'),
            $request->get_param('offset'),
            $pagination,
            $ip_address
        ));
    }

    /**
     * Submit a review (public).
     *
     * The order below decides which error a visitor sees and is not to be rearranged:
     * the service's checks (recipe, guest gate, rate limit, fields), then the captcha, then
     * the insert, then the image upload, then the rating / notification / cache step.
     */
    public function submit_review($request): WP_REST_Response|WP_Error {
        // Support both JSON and multipart (FormData) for image uploads.
        $content_type = $request->get_content_type();
        $is_multipart = $content_type && strpos( $content_type['value'] ?? '', 'multipart' ) !== false;

        if ( $is_multipart ) {
            // $_POST is slashed by WP core; unslash so this branch matches the (unslashed) JSON branch.
            // Individual fields are sanitized where they are read below (sanitize_text_field / sanitize_email /
            // wp_kses_post / (int)).
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field at each read below.
            $params = wp_unslash( $_POST );
        } else {
            $params = $request->get_json_params();
        }

        $recipe_id = (int) ($params['recipe_id'] ?? 0);

        // The caller's address keys the service's per-IP hourly rate limit.
        $ip =sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        // scalar_string() first: these sanitizers all TypeError on an array, and the payload
        // is anonymous, so {"author_name": []} would otherwise 500 this public endpoint.
        // The service applies the sanitizers; it is handed plain strings and nothing else.
        $submission = $this->reviews->validate_submission($recipe_id, [
            'author_name'  => self::scalar_string($params['author_name'] ?? ''),
            'author_email' => self::scalar_string($params['author_email'] ?? ''),
            'review_text'  => self::scalar_string($params['review_text'] ?? ''),
            'rating'       => $params['rating'] ?? null,
        ], $ip);
        if (is_wp_error($submission)) {
            return $submission;
        }

        $settings = $submission['settings'];
        if (!empty($settings['turnstile_reviews_enabled']) && !empty($settings['turnstile_secret_key'])) {
            $captcha = $this->verify_turnstile(
                (string) ($params['turnstile_token'] ?? ''),
                (string) $settings['turnstile_secret_key']
            );
            if (is_wp_error($captcha)) {
                $this->reviews->count_attempt($ip);
                return $captcha;
            }
        }

        $review = $this->reviews->store(
            $submission,
            sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''))
        );
        $this->reviews->count_attempt($ip);

        $review_id      = $review['id'];
        $default_status = $review['status'];

        // Handle review image uploads when enabled.
        if ( $review_id && ! empty( $settings['review_enable_images'] ) && ! empty( $_FILES['review_images'] ) && ! empty( $_FILES['review_images']['name'][0] ) ) {
            $allowed_types = [ 'image/jpeg', 'image/png', 'image/webp' ];
            $max_size      = ( (int) ( $settings['max_upload_size'] ?? 5 ) ) * 1024 * 1024;
            $max_images    = max( 1, min( 10, (int) ( $settings['review_max_images'] ?? 3 ) ) );
            $image_ids     = [];

            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Scoped to this upload loop. tmp_name/error/size are PHP-generated upload metadata that must reach Uploader::store() byte-for-byte; sanitizing tmp_name would break the upload. They are validated instead: error === UPLOAD_ERR_OK, size against max_upload_size, count against a cap, and the real MIME via wp_check_filetype_and_ext() against an allow-list. name IS sanitized (sanitize_file_name) and type IS replaced by the derived MIME, so neither is suppressed here.
            $file_count = min( count( $_FILES['review_images']['name'] ), $max_images );
            for ( $i = 0; $i < $file_count; $i++ ) {
                if ( $_FILES['review_images']['error'][ $i ] !== UPLOAD_ERR_OK ) continue;
                if ( $_FILES['review_images']['size'][ $i ] > $max_size ) continue;

                $file_mime = wp_check_filetype_and_ext(
                    $_FILES['review_images']['tmp_name'][ $i ],
                    $_FILES['review_images']['name'][ $i ]
                );
                if ( empty( $file_mime['type'] ) || ! in_array( $file_mime['type'], $allowed_types, true ) ) continue;

                // The browser-supplied name and type are never trusted: the name is sanitised
                // for the filesystem, and the type is replaced by the MIME wp_check_filetype_and_ext()
                // just derived from the file's own contents and checked against $allowed_types.
                $att_id = Uploader::store( [
                    'name'     => sanitize_file_name( $_FILES['review_images']['name'][ $i ] ),
                    'type'     => $file_mime['type'],
                    'tmp_name' => $_FILES['review_images']['tmp_name'][ $i ],
                    'error'    => $_FILES['review_images']['error'][ $i ],
                    'size'     => $_FILES['review_images']['size'][ $i ],
                ] );
                if ( ! is_wp_error( $att_id ) ) {
                    $image_ids[] = $att_id;
                }
            }
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

            if ( ! empty( $image_ids ) ) {
                $this->reviews->attach_images( $review_id, $image_ids );
            }
        }

        // Rating if auto-approved, notification email, and the one cache invalidation.
        $this->reviews->complete($submission, $review);

        return $this->success([
            'id'     => $review_id,
            'status' => $default_status,
        ], $default_status === 'approved'
            ? __('Thank you for your review!', 'plugixa-recipe')
            : __('Thank you! Your review will be published after moderation.', 'plugixa-recipe')
        );
    }

    /**
     * Toggle helpful vote on a review (public).
     */
    public function toggle_review_helpful($request): WP_REST_Response|WP_Error {
        $review_id  = (int) $request->get_param('review_id');
        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        $result = $this->reviews->toggle_helpful($review_id, $ip_address);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success($result);
    }
}
