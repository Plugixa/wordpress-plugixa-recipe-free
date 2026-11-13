<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Submissions\Http;

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
use PlugixaRecipe\Modules\Submissions\Services\SubmissionService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * The public recipe submission form endpoint.
 *
 * Split out of the former FrontendApi so each public route lives in the module that owns
 * it - and disappears with that module when the App Manager switches it off.
 *
 * The route, the reading of the request, the captcha, the honeypot's decoy answer and the
 * two upload blocks - nothing else. The rules (who may submit, how often, what a valid
 * recipe is, the status it is stored in) belong to SubmissionService; every query belongs
 * to SubmissionRepository.
 */
class SubmissionsController extends PublicController {

    private SubmissionService $submissions;

    public function __construct(SubmissionService $submissions) {
        parent::__construct();
        $this->submissions = $submissions;
    }

    public function register_routes(): void {
        // Public by design: the frontend submission form. Off unless an administrator
        // enables submissions, and can additionally require login. Guarded by Turnstile,
        // a honeypot field and a per-IP hourly cap; submissions land in the configured
        // status (pending by default) and are never published without review.
        // No args schema, for the same reason as /reviews above: the submission form posts
        // multipart/form-data (recipe image plus gallery images), and submit_recipe() validates
        // every field itself -- required fields, length caps, enum clamps and numeric ranges --
        // so both the JSON and the multipart encodings go through one set of rules.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/submit-recipe', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'submit_recipe'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Submit a recipe from the frontend form.
     *
     * The order below decides which error a visitor sees and is not to be rearranged:
     * the login gate, the captcha, the honeypot, the rate limit with the title and
     * description, the required image, the remaining fields and the insert, the two
     * uploads, then the child rows / action / notification / cache step.
     */
    public function submit_recipe($request): WP_REST_Response|WP_Error {
        // No "submissions enabled?" check here: this route is registered only while the
        // Submissions module is on, so a disabled site answers with WordPress's own 404.

        // Configurable login gate.
        $settings = $this->submissions->settings_for_visitor();
        if (is_wp_error($settings)) {
            return $settings;
        }

        // Detect content type — form sends FormData (multipart) for image upload.
        $content_type = $request->get_content_type();
        $is_multipart = $content_type && strpos($content_type['value'] ?? '', 'multipart') !== false;

        // A request larger than PHP's post_max_size arrives with NO fields and no files at
        // all - PHP discards the body - so the visitor was told "Recipe title is required"
        // about a form they had filled in. Photos are what make a request that large.
        if ($is_multipart && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && absint(wp_unslash($_SERVER['CONTENT_LENGTH'])) > 0) {
            return $this->error(__('The photos are too large to upload together. Use smaller images and try again.', 'plugixa-recipe'), 413);
        }

        $get_param = function (string $key, $default = '') use ($is_multipart, $request) {
            if ($is_multipart) {
                // $_POST is slashed by WP core; unslash ONCE here so the multipart branch yields the
                // same raw value the JSON branch does. The value is returned raw-but-unslashed on
                // purpose: each caller below applies the sanitizer that fits that field's type
                // (sanitize_text_field / sanitize_textarea_field / wp_kses_post / esc_url_raw / (int) /
                // json_decode). A blanket sanitizer here would corrupt the JSON payloads
                // (ingredients / instructions / nutrition) and the array-valued fields.
                // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field by every caller; see above.
                return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
                // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }
            return $request->get_param($key) ?? $default;
        };

        // Same read, but guaranteed to hand back a string. Every scalar sanitizer
        // (sanitize_text_field, sanitize_textarea_field, sanitize_email, esc_url_raw,
        // wp_kses_post) raises a TypeError on an array in PHP 8, so a payload like
        // {"title": []} would 500 this public endpoint. Use $get_text for any field that
        // is sanitized as text; keep $get_param for the ones that are legitimately arrays
        // or JSON (categories, ingredients, instructions, nutrition).
        $get_text = function (string $key, $default = '') use ($get_param): string {
            return self::scalar_string($get_param($key, $default));
        };

        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        // The hourly limit is read BEFORE the captcha as well as after it: a failed captcha
        // uses up an attempt, and without this check nothing would ever stop the next one
        // from reaching Cloudflare.
        $limited = $this->submissions->rate_limited($settings, $ip);
        if (null !== $limited) {
            return $limited;
        }

        // Turnstile validation.
        if (!empty($settings['turnstile_form_enabled']) && !empty($settings['turnstile_secret_key'])) {
            $captcha = $this->verify_turnstile(
                (string) $get_param('turnstile_token'),
                (string) $settings['turnstile_secret_key']
            );
            if (is_wp_error($captcha)) {
                $this->submissions->count_attempt($ip);
                return $captcha;
            }
        }

        // Honeypot bot detection.
        $honeypot = $get_param('website_url', '');
        if (!empty($honeypot)) {
            // The decoy answers exactly as a real submission would, message included.
            return $this->success(['id' => 0, 'status' => 'pending'], $this->submissions->success_message($settings));
        }

        // Rate limiting (per IP, per hour), then the two required text fields. The service
        // applies the sanitizers; it is handed plain strings and nothing else.
        $accepted = $this->submissions->admit($settings, $ip, $get_text('title'), $get_text('description'));
        if (is_wp_error($accepted)) {
            return $accepted;
        }

        // Validate recipe image is provided.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- 'error' is PHP-generated upload metadata compared against a constant; the empty() guards precede it.
        // The limit a visitor is told is the one that applies: the setting, or the
        // server's own when that is smaller.
        $max_bytes = min(((int) ($settings['max_upload_size'] ?? 5)) * MB_IN_BYTES, (int) wp_max_upload_size());
        $too_large = sprintf(
            /* translators: %s: the largest image a visitor may upload, e.g. "5 MB". */
            __('The recipe image is too large. The limit is %s.', 'plugixa-recipe'),
            size_format($max_bytes)
        );
        // PHP refused the file for its size before this code ran: it arrives with no size.
        if (!empty($_FILES['recipe_image']) && in_array($_FILES['recipe_image']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->error($too_large);
        }
        if (empty($_FILES['recipe_image']) || empty($_FILES['recipe_image']['size']) || $_FILES['recipe_image']['error'] !== UPLOAD_ERR_OK) {
            return $this->error(__('Recipe image is required.', 'plugixa-recipe'));
        }
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated

        // The required image is judged HERE, before anything is written. It used to be
        // checked after the recipe was stored and "skipped" when it failed: a photo over
        // the limit (any phone photo) left a recipe with no image, and the visitor was
        // told it had been submitted successfully.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The whole upload entry is handed to Uploader::store(), which sanitizes the name and re-checks the type itself; the members are validated below against the MIME allow-list and max_upload_size.
        $image_file = $_FILES['recipe_image'];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ($image_file['size'] > $max_bytes) {
            return $this->error($too_large);
        }
        $image_mime = wp_check_filetype_and_ext($image_file['tmp_name'], $image_file['name']);
        if (empty($image_mime['type']) || !in_array($image_mime['type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $this->error(__('The recipe image must be a JPEG, PNG or WebP file.', 'plugixa-recipe'));
        }

        // Validate at least one category (the service's first check in store()).
        $categories_input = $is_multipart
            ? array_map('intval', (array) wp_unslash($_POST['categories'] ?? []))
            : ($request->get_param('categories') ?? []);

        // Everything else the recipe row is built from: the service validates it in its own
        // fixed order (categories, ingredients, instructions, slug, numbers, text lengths) and
        // inserts the row. Text is read through $get_text, so every value a scalar sanitizer
        // will see is already a string; $get_param stays for the array / JSON / numeric ones.
        // No `status` is read here or anywhere: the stored status comes from the settings.
        $submission = $this->submissions->store($settings, $accepted, [
            'categories'     => $categories_input,
            'ingredients'    => $get_param('ingredients', ''),
            'instructions'   => $get_param('instructions', ''),
            'difficulty'     => $get_text('difficulty', 'medium'),
            'cost'           => $get_text('cost'),
            'season'         => $get_text('season', 'all'),
            'servings'       => $get_param('servings', ''),
            'prep_time'      => $get_param('prep_time', ''),
            'cook_time'      => $get_param('cook_time', ''),
            'rest_time'      => $get_param('rest_time'),
            'excerpt'        => $get_text('excerpt'),
            'notes'          => $get_text('notes'),
            'tips'           => $get_text('tips'),
            'tags'           => $get_text('tags', ''),
            'equipment'      => $get_text('equipment', ''),
            'servings_unit'  => $get_text('servings_unit'),
            'video_url'      => $get_text('video_url'),
            'author_name'    => $get_text('author_name'),
            'source_name'    => $get_text('source_name'),
            'source_url'     => $get_text('source_url'),
            'is_vegetarian'  => $get_param('is_vegetarian', false),
            'is_vegan'       => $get_param('is_vegan', false),
            'is_gluten_free' => $get_param('is_gluten_free', false),
            'is_dairy_free'  => $get_param('is_dairy_free', false),
            'is_keto'        => $get_param('is_keto', false),
            'is_low_carb'    => $get_param('is_low_carb', false),
            'is_nut_free'    => $get_param('is_nut_free', false),
            'is_paleo'       => $get_param('is_paleo', false),
            'is_sugar_free'  => $get_param('is_sugar_free', false),
            'is_halal'       => $get_param('is_halal', false),
            'is_kosher'      => $get_param('is_kosher', false),
        ], sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')));
        if (is_wp_error($submission)) {
            return $submission;
        }
        $this->submissions->count_attempt($ip);

        $recipe_id = $submission['id'];

        // The recipe image: its type and size were checked before the insert. Like the
        // gallery below, the browser's name and type are not passed on - the name is
        // sanitised and the type is the MIME derived from the file.
        $attachment_id = Uploader::store([
            'name'     => sanitize_file_name($image_file['name']),
            'type'     => $image_mime['type'],
            'tmp_name' => $image_file['tmp_name'],
            'error'    => $image_file['error'],
            'size'     => $image_file['size'],
        ]);
        if (!is_wp_error($attachment_id)) {
            $this->submissions->attach_image($recipe_id, $attachment_id);
        }

        // Gallery images upload (multiple files, max 10).
        if (!empty($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size      = ((int) ($settings['max_upload_size'] ?? 5)) * 1024 * 1024;
            $gallery_ids   = [];
            $max_gallery   = 10;

            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Scoped to this upload loop. tmp_name/error/size are PHP-generated upload metadata that must reach Uploader::store() byte-for-byte; sanitizing tmp_name would break the upload. They are validated instead: error === UPLOAD_ERR_OK, size against max_upload_size, count against a cap, and the real MIME via wp_check_filetype_and_ext() against an allow-list. name IS sanitized (sanitize_file_name) and type IS replaced by the derived MIME, so neither is suppressed here.
            $file_count = min(count($_FILES['gallery_images']['name']), $max_gallery);
            for ($g = 0; $g < $file_count; $g++) {
                if ($_FILES['gallery_images']['error'][$g] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['gallery_images']['size'][$g] > $max_size) continue;

                // Server-side MIME verification.
                $gallery_mime = wp_check_filetype_and_ext(
                    $_FILES['gallery_images']['tmp_name'][$g],
                    $_FILES['gallery_images']['name'][$g]
                );
                if (empty($gallery_mime['type']) || !in_array($gallery_mime['type'], $allowed_types, true)) continue;

                // Restructure into single-file format for Uploader::store(). The browser-supplied
                // name and type are never trusted: the name is sanitised for the filesystem, and the
                // type is replaced by the MIME wp_check_filetype_and_ext() just derived from the
                // file's own contents and checked against $allowed_types.
                $att_id = Uploader::store([
                    'name'     => sanitize_file_name($_FILES['gallery_images']['name'][$g]),
                    'type'     => $gallery_mime['type'],
                    'tmp_name' => $_FILES['gallery_images']['tmp_name'][$g],
                    'error'    => $_FILES['gallery_images']['error'][$g],
                    'size'     => $_FILES['gallery_images']['size'][$g],
                ]);
                if (!is_wp_error($att_id)) {
                    $gallery_ids[] = $att_id;
                }
            }
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

            if (!empty($gallery_ids)) {
                $this->submissions->attach_gallery($recipe_id, $gallery_ids);
            }
        }

        // Step photos: one file per step that has one, sent as step_images[<n>] where <n> is
        // the number the step's `image_index` names. At most 8, so that with the featured
        // image and ten gallery images the request stays under PHP's default of 20 files.
        $step_image_ids = [];
        if (!empty($_FILES['step_images']) && is_array($_FILES['step_images']['name'] ?? null)) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size      = ((int) ($settings['max_upload_size'] ?? 5)) * 1024 * 1024;
            $max_steps     = 8;

            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Scoped to this upload loop, exactly as the gallery loop above: tmp_name/error/size are PHP-generated upload metadata that must reach Uploader::store() byte-for-byte and are validated instead (error === UPLOAD_ERR_OK, size against max_upload_size, count against a cap, real MIME via wp_check_filetype_and_ext() against an allow-list). name IS sanitized and type IS replaced by the derived MIME. The array keys are cast to int.
            foreach (array_slice(array_keys($_FILES['step_images']['name']), 0, $max_steps) as $step_key) {
                $step_index = (int) $step_key;
                if (($_FILES['step_images']['error'][$step_key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if (($_FILES['step_images']['size'][$step_key] ?? 0) > $max_size) continue;

                // Server-side MIME verification.
                $step_mime = wp_check_filetype_and_ext(
                    $_FILES['step_images']['tmp_name'][$step_key],
                    $_FILES['step_images']['name'][$step_key]
                );
                if (empty($step_mime['type']) || !in_array($step_mime['type'], $allowed_types, true)) continue;

                $att_id = Uploader::store([
                    'name'     => sanitize_file_name($_FILES['step_images']['name'][$step_key]),
                    'type'     => $step_mime['type'],
                    'tmp_name' => $_FILES['step_images']['tmp_name'][$step_key],
                    'error'    => $_FILES['step_images']['error'][$step_key],
                    'size'     => $_FILES['step_images']['size'][$step_key],
                ]);
                if (!is_wp_error($att_id)) {
                    $step_image_ids[$step_index] = (int) $att_id;
                }
            }
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        }

        // Save categories (array of IDs → junction table, with FK validation).
        $categories = $is_multipart
            ? array_map('intval', (array) wp_unslash($_POST['categories'] ?? []))
            : ($request->get_param('categories') ?? []);

        // The child rows (taxonomy pivots, ingredients, instructions, nutrition), then the
        // `plugixa_recipe_frontend_submission_saved` action, the notification email and, last
        // of all, the one cache invalidation. The payload is read again here, after the
        // uploads, exactly as it always was. $get_param goes down with it because it is the
        // action's second argument: the premium overlay reads its own form fields through it.
        $this->submissions->complete($submission, [
            'categories'   => $categories,
            'cuisine_id'   => $get_param('cuisine_id', 0),
            'course_id'    => $get_param('course_id', 0),
            'ingredients'  => $get_param('ingredients', ''),
            'instructions' => $get_param('instructions', ''),
            'nutrition'    => $get_param('nutrition', ''),
            'step_images'  => $step_image_ids,
        ], $get_param);

        return $this->success([
            'id'     => $recipe_id,
            'status' => $submission['status'],
        ], $this->submissions->success_message($settings));
    }
}
