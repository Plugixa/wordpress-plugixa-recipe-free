<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the public routes. Content reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the per-user/per-IP state lookups (favourite, like, helpful vote), the write paths and the view/like counter updates are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every $_POST/$_FILES read below happens inside a callback registered with register_rest_route(); WP core dispatches these and enforces the route's permission_callback (cookie-authenticated callers are nonce-checked by the REST infrastructure itself). The submission endpoints are deliberately public (__return_true) because anyone may post a review or submit a recipe -- a nonce would add no protection to an action an anonymous visitor is allowed to perform -- so abuse is instead controlled by Cloudflare Turnstile, a honeypot field and per-IP hourly rate limiting (see the get_transient() rate gates).
// This directive is file-scoped ONLY because the rationale is uniform: this file registers REST
// routes and nothing else. That is the invariant that makes it safe, and RestSecurityTest enforces
// it -- adding an admin-post, admin_action or wp_ajax handler here fails the build, because such a
// handler WOULD need a nonce and this header would silence the sniff that says so. Add it to its
// own file instead. The ValidatedSanitizedInput sniffs are deliberately NOT suppressed file-wide:
// they are scoped to the individual upload blocks so that un-sanitizing a filename still reports.


defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Frontend (Public) REST API
 */
class FrontendApi extends RestController {

    /** Cloudflare Turnstile server-side verification endpoint. */
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cloudflare Turnstile is a CAPTCHA service, not asset offloading. Plugin Check's offloading list carries the pattern '(?<!api\.)cloudflare\.com', whose lookbehind exempts only api.cloudflare.com, so every other *.cloudflare.com host matches and Turnstile's challenges.cloudflare.com is caught by a rule aimed at CDN-hosted JS/CSS. The endpoint cannot be self-hosted: Cloudflare requires both the widget script and the siteverify call to go to its own host, and a token verified anywhere else would be meaningless. It is off by default, requires the site owner's own keys, and is disclosed in readme.txt under == External services ==.
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'frontend';
    }

    /**
     * Verify a Cloudflare Turnstile token.
     *
     * Shared by the review form and the recipe submission form, which previously each
     * carried their own copy of this logic.
     *
     * Turnstile returns a pass/fail verdict, not reCAPTCHA v3's 0.0-1.0 score, so there
     * is no threshold to configure and none to get wrong.
     *
     * **This fails CLOSED.** The version this replaced wrapped the verdict check in
     * `if (!is_wp_error($verify))`, so any network failure skipped verification and let
     * the submission through - a captcha that a transport error switches off is not a
     * captcha. A caller that cannot reach Cloudflare now gets a distinct message so the
     * cause is diagnosable rather than looking like a rejected token.
     *
     * @param string $token  The `turnstile_token` supplied by the form.
     * @param string $secret The site owner's Turnstile secret key.
     * @return true|WP_Error True when the token is valid.
     */
    /**
     * Coerce one request value into a plain string before a sanitizer touches it.
     *
     * The submission payload accepts an ingredient or instruction either as a bare string
     * or as a keyed array, so both shapes reach the same code. A malformed third shape -
     * `{"name": []}` - reaches it too, and trim(), sanitize_text_field() and wp_kses_post()
     * all raise a TypeError on an array in PHP 8, which on a public endpoint is a 500 an
     * anonymous caller can trigger at will. Anything that is not scalar becomes ''.
     *
     * This deliberately does NOT sanitize: the caller picks the sanitizer that fits the
     * field (sanitize_text_field for a name, wp_kses_post for instruction text).
     *
     * @param mixed $value Raw value from the decoded request payload.
     */
    private static function scalar_string($value): string {
        return is_scalar($value) ? (string) $value : '';
    }

    private function verify_turnstile(string $token, string $secret) {
        if ('' === $token) {
            return $this->error(__('Captcha verification failed. Please try again.', 'plugixa-recipe'));
        }

        $verify = wp_remote_post(self::TURNSTILE_VERIFY_URL, [
            'timeout' => 10,
            'body'    => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            ],
        ]);

        if (is_wp_error($verify)) {
            return $this->error(
                __('Could not reach the captcha service. Please try again in a moment.', 'plugixa-recipe'),
                503
            );
        }

        $result = json_decode(wp_remote_retrieve_body($verify), true);

        if (!is_array($result) || empty($result['success'])) {
            return $this->error(__('Captcha verification failed. Please try again.', 'plugixa-recipe'));
        }

        return true;
    }

    /**
     * Register the public frontend routes.
     *
     * Every route below that carries `permission_callback => '__return_true'` is public
     * BY DESIGN, and each one states what it exposes and why anonymous access is safe.
     * They fall into two groups:
     *
     * - Reads that return exactly what the rendered page already shows a visitor. They
     *   are all pinned to published/approved rows and select an explicit public column
     *   list (see PlugixaRecipe\Columns) that excludes submitter IPs, reviewer email
     *   addresses, WordPress user IDs and the editorial audit trail.
     * - Writes that an anonymous visitor is deliberately allowed to perform (post a
     *   review, vote a review helpful, submit a recipe). A nonce would add nothing to
     *   an action open to logged-out callers, so abuse is bounded by Turnstile, a
     *   honeypot field, per-IP hourly rate limits, and moderation-before-publish.
     *
     * Routes that gate on identity rather than content (favorites, likes) require a
     * logged-in user instead.
     */
    public function register_routes(): void {
        // Public by design: the published recipe list a visitor already sees rendered on
        // the page. Pinned to status='published'; public column list only.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/recipes', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_recipes'],
                'permission_callback' => '__return_true',
                'args'                => $this->get_collection_params(),
            ],
        ]);

        // Public by design: one published recipe, the same content the single-recipe page
        // renders. Pinned to status='published'; public column list only.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/recipes/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_recipe'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'description'       => __('Unique identifier for the recipe.', 'plugixa-recipe'),
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

        // Public by design: collections explicitly marked public. Pinned to
        // status='published' AND visibility='public'; the owning user_id is not selected.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/collections', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_collections'],
                'permission_callback' => '__return_true',
            ],
        ]);

        // Toggle favorite (requires login)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/favorites/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'toggle_favorite'],
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ],
        ]);

        // Toggle like (requires login)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/likes/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'toggle_like'],
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ],
        ]);

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
     * Get published recipes for frontend display.
     */
    public function get_recipes($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // Every optional filter is a gate placeholder: bind 0 and its clause is
        // constant-true, which keeps WHERE literal instead of PHP-assembled. The two
        // taxonomy filters are gated EXISTS subqueries rather than optional INNER JOINs -
        // a JOIN is structure and cannot be gated away by a placeholder, and EXISTS
        // selects the same rows without the multiplication DISTINCT was there to undo.
        $category_id = (int) ($request->get_param('category_id') ?: 0);
        $cuisine_id  = (int) ($request->get_param('cuisine_id') ?: 0);

        $search     = $request->get_param('search');
        $search     = $search ? '%' . $wpdb->esc_like($search) . '%' : '';
        $featured   = $request->get_param('is_featured') ? 1 : 0;
        $min_rating = (float) ($request->get_param('min_rating') ?: 0);
        $difficulty = (string) ($request->get_param('difficulty') ?: '');

        // Diet flags: six independent gates. The column names are literal in the SQL below
        // (they come from this hard-coded list, never from the request); only the 1/0 gate
        // travels as a bound value.
        $diet_flags = ['is_vegetarian', 'is_vegan', 'is_gluten_free', 'is_dairy_free', 'is_keto', 'is_low_carb'];
        $diet_gates = [];
        foreach ($diet_flags as $flag) {
            $diet_gates[$flag] = $request->get_param($flag) ? 1 : 0;
        }

        $allowed_orderby = ['title', 'created_at', 'rating_average', 'total_time', 'display_order', 'published_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'published_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound values for the shared WHERE block, in placeholder order. %i identifiers and
        // %s/%d values share one positional argument list, so the table names sit inline.
        $filter_args = [
            $prefix . 'recipes',
            $category_id ? 1 : 0, $prefix . 'recipe_categories', $category_id,
            $cuisine_id ? 1 : 0, $prefix . 'recipe_cuisines', $cuisine_id,
            $search !== '' ? 1 : 0, $search, $search,
            $featured,
            $min_rating > 0 ? 1 : 0, $min_rating,
            $difficulty !== '' ? 1 : 0, $difficulty,
            $diet_gates['is_vegetarian'],
            $diet_gates['is_vegan'],
            $diet_gates['is_gluten_free'],
            $diet_gates['is_dairy_free'],
            $diet_gates['is_keto'],
            $diet_gates['is_low_carb'],
        ];

        // Count. Cache key is a stable digest of the bound values - it must NOT embed the
        // SQL text, which now lives inline in prepare().
        $total = (int) Cache::remember('recipes', 'frontend_count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- every placeholder is satisfied by the ...$filter_args spread, which this sniff counts syntactically and cannot see through.
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i r
                  WHERE r.status = \'published\'
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
                    AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s) )
                    AND ( %d = 0 OR r.is_featured = 1 )
                    AND ( %d = 0 OR r.rating_average >= %f )
                    AND ( %d = 0 OR r.difficulty = %s )
                    AND ( %d = 0 OR r.is_vegetarian = 1 )
                    AND ( %d = 0 OR r.is_vegan = 1 )
                    AND ( %d = 0 OR r.is_gluten_free = 1 )
                    AND ( %d = 0 OR r.is_dairy_free = 1 )
                    AND ( %d = 0 OR r.is_keto = 1 )
                    AND ( %d = 0 OR r.is_low_carb = 1 )',
                ...$filter_args
            ));
        });

        // Data. ORDER BY column binds with the %i identifier placeholder (WP 6.2+) from the
        // $allowed_orderby list above; a direction keyword cannot be bound at all, so
        // ASC/DESC is two literal queries.
        //
        // r.id is a deterministic tiebreaker, and it is not optional. Seeded/imported
        // recipes routinely share a published_at to the second, and a sort with ties is
        // free to return any permutation - so paging with LIMIT/OFFSET over tied rows
        // could show one recipe on two pages and skip another entirely. Sorting by the
        // primary key last makes every page stable.
        $args = array_merge($filter_args, [$orderby, $pagination['limit'], $pagination['offset']]);

        $recipes = Cache::remember('recipes', 'frontend_list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- every placeholder is satisfied by the ...$args spread, which this sniff counts syntactically and cannot see through.
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id,
                        r.servings, r.prep_time, r.cook_time, r.total_time, r.difficulty,
                        r.rating_average, r.rating_count, r.is_featured,
                        r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        r.is_keto, r.is_low_carb, r.published_at
                      FROM %i r
                      WHERE r.status = \'published\'
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
                        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s) )
                        AND ( %d = 0 OR r.is_featured = 1 )
                        AND ( %d = 0 OR r.rating_average >= %f )
                        AND ( %d = 0 OR r.difficulty = %s )
                        AND ( %d = 0 OR r.is_vegetarian = 1 )
                        AND ( %d = 0 OR r.is_vegan = 1 )
                        AND ( %d = 0 OR r.is_gluten_free = 1 )
                        AND ( %d = 0 OR r.is_dairy_free = 1 )
                        AND ( %d = 0 OR r.is_keto = 1 )
                        AND ( %d = 0 OR r.is_low_carb = 1 )
                      ORDER BY r.%i ASC, r.id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- every placeholder is satisfied by the spread $args above; the sniff cannot count arguments through the spread operator.
            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.id, r.title, r.slug, r.description, r.image_id,
                    r.servings, r.prep_time, r.cook_time, r.total_time, r.difficulty,
                    r.rating_average, r.rating_count, r.is_featured,
                    r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                    r.is_keto, r.is_low_carb, r.published_at
                  FROM %i r
                  WHERE r.status = \'published\'
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
                    AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s) )
                    AND ( %d = 0 OR r.is_featured = 1 )
                    AND ( %d = 0 OR r.rating_average >= %f )
                    AND ( %d = 0 OR r.difficulty = %s )
                    AND ( %d = 0 OR r.is_vegetarian = 1 )
                    AND ( %d = 0 OR r.is_vegan = 1 )
                    AND ( %d = 0 OR r.is_gluten_free = 1 )
                    AND ( %d = 0 OR r.is_dairy_free = 1 )
                    AND ( %d = 0 OR r.is_keto = 1 )
                    AND ( %d = 0 OR r.is_low_carb = 1 )
                  ORDER BY r.%i DESC, r.id DESC
                  LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });

        foreach ($recipes as &$recipe) {
            $rid = (int) $recipe['id'];

            if (!empty($recipe['image_id'])) {
                $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium_large');
                $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
            }

            $recipe['rating_average'] = (float) $recipe['rating_average'];
            $recipe['rating_count'] = (int) $recipe['rating_count'];

            // Categories — child rows cached under the owning recipe.
            $recipe['categories'] = Cache::remember('recipes', 'recipe_categories|' . $rid, function () use ($wpdb, $prefix, $rid) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT c.id, c.name, c.slug FROM %i c
                    INNER JOIN %i rc ON c.id = rc.category_id
                    WHERE rc.recipe_id = %d',
                    $prefix . 'categories',
                    $prefix . 'recipe_categories',
                    $rid
                ), ARRAY_A) ?: [];
            });

            // Cuisines — child rows cached under the owning recipe.
            $recipe['cuisines'] = Cache::remember('recipes', 'recipe_cuisines|' . $rid, function () use ($wpdb, $prefix, $rid) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT cu.id, cu.name, cu.slug FROM %i cu
                    INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                    WHERE rcu.recipe_id = %d',
                    $prefix . 'cuisines',
                    $prefix . 'recipe_cuisines',
                    $rid
                ), ARRAY_A) ?: [];
            });

            // Check favorite for logged-in user (per-user state: not cached).
            if (is_user_logged_in()) {
                $recipe['is_favorited'] = (bool) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
                    $prefix . 'favorites', get_current_user_id(), $rid
                ));
            }
        }

        return $this->success([
            'items'  => $recipes,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ]);
    }

    /**
     * Get single published recipe with full data.
     */
    public function get_recipe($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $recipes_table = $prefix . 'recipes';

        // Read-through object cache; key = recipe id.
        $recipe = Cache::remember('recipes', 'frontend_single|' . $id, function () use ($wpdb, $recipes_table, $id) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT id, language_code, translation_group, title, slug,
                        description, excerpt, notes, tips, image_id,
                        gallery, tags, equipment, video_url, servings,
                        servings_unit, yield_amount, yield_unit, prep_time, cook_time,
                        rest_time, total_time, difficulty, cost, season,
                        is_featured, is_editor_pick, display_order, source, source_url,
                        author_name, view_count, rating_average, rating_count, like_count,
                        is_vegetarian, is_vegan, is_gluten_free, is_dairy_free, is_keto,
                        is_low_carb, is_nut_free, is_paleo, is_sugar_free, is_halal,
                        is_kosher, published_at, created_at
                      FROM %i
                      WHERE id = %d AND status = \'published\'',
                    $recipes_table,
                    $id
                ),
                ARRAY_A
            );
        });

        if (!$recipe) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Increment view count.
        // Counter increment only: deliberately does NOT flush the cache — this runs on every
        // render, so flushing here would invalidate the whole cache on every page view. Cached
        // lists therefore carry eventually-consistent view counts.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET view_count = view_count + 1 WHERE id = %d',
            $prefix . 'recipes',
            $id
        ));

        // Image
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'large');
            $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
        }

        // Gallery
        if (!empty($recipe['gallery'])) {
            $gallery_ids = json_decode($recipe['gallery'], true);
            $recipe['gallery_urls'] = [];
            if (is_array($gallery_ids)) {
                foreach ($gallery_ids as $gid) {
                    $recipe['gallery_urls'][] = [
                        'id'  => (int) $gid,
                        'url' => wp_get_attachment_image_url((int) $gid, 'large'),
                    ];
                }
            }
        }

        // Categories — child rows cached under the owning recipe.
        $recipe['categories'] = Cache::remember('recipes', 'recipe_categories|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                INNER JOIN %i rc ON c.id = rc.category_id
                WHERE rc.recipe_id = %d',
                $prefix . 'categories',
                $prefix . 'recipe_categories',
                $id
            ), ARRAY_A) ?: [];
        });

        // Cuisines — child rows cached under the owning recipe.
        $recipe['cuisines'] = Cache::remember('recipes', 'recipe_cuisines|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE rcu.recipe_id = %d',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines',
                $id
            ), ARRAY_A) ?: [];
        });

        // Courses — child rows cached under the owning recipe.
        $recipe['courses'] = Cache::remember('recipes', 'recipe_courses|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT co.id, co.name, co.slug FROM %i co
                INNER JOIN %i rco ON co.id = rco.course_id
                WHERE rco.recipe_id = %d',
                $prefix . 'courses',
                $prefix . 'recipe_courses',
                $id
            ), ARRAY_A) ?: [];
        });

        // Ingredients — child rows cached under the owning recipe.
        $ingredients_table = $prefix . 'ingredients';
        $recipe['ingredients'] = Cache::remember('recipes', 'recipe_ingredients|' . $id, function () use ($wpdb, $ingredients_table, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    quantity, unit, name, notes, link_url
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $ingredients_table,
                $id
            ), ARRAY_A) ?: [];
        });

        // Instructions — child rows cached under the owning recipe.
        $instructions_table = $prefix . 'instructions';
        $recipe['instructions'] = Cache::remember('recipes', 'recipe_instructions|' . $id, function () use ($wpdb, $instructions_table, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    title, instruction_text, image_id, video_url, duration
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $instructions_table,
                $id
            ), ARRAY_A) ?: [];
        });
        foreach ($recipe['instructions'] as &$inst) {
            if (!empty($inst['image_id'])) {
                $inst['image_url'] = wp_get_attachment_image_url((int) $inst['image_id'], 'medium');
            }
        }
        unset($inst);

        // Nutrition — child row cached under the owning recipe.
        $nutrition_table = $prefix . 'nutrition';
        $recipe['nutrition'] = Cache::remember('recipes', 'recipe_nutrition|' . $id, function () use ($wpdb, $nutrition_table, $id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT id, recipe_id, serving_size, calories, total_fat,
                    saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                    dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                    calcium, iron, potassium, source
                  FROM %i
                  WHERE recipe_id = %d',
                $nutrition_table,
                $id
            ), ARRAY_A);
        });

        /**
         * Filter the recipe returned to the public frontend.
         *
         * The premium overlay attaches the data belonging to its own features. The free
         * build ships none of that machinery, so nothing is attached.
         *
         * @param array<string,mixed> $recipe
         * @param int                 $id
         */
        $recipe = apply_filters('plugixa_recipe_prepare_recipe_frontend', $recipe, $id);

        // Favorite status (per-user state: not cached).
        if (is_user_logged_in()) {
            $recipe['is_favorited'] = (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
                $prefix . 'favorites', get_current_user_id(), $id
            ));
        }

        $recipe['rating_average'] = (float) $recipe['rating_average'];
        $recipe['rating_count'] = (int) $recipe['rating_count'];

        return $this->success($recipe);
    }

    /**
     * Get approved reviews for a recipe.
     */
    public function get_recipe_reviews($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');
        $pagination = $this->get_pagination($request);

        // Apply settings for default limit and sort order.
        $settings = get_option('plugixa_recipe_settings', []);
        $default_limit = (int) ($settings['reviews_per_page'] ?? 10);
        $limit = (int) ($request->get_param('limit') ?? $default_limit);
        $offset = (int) ($request->get_param('offset') ?? 0);

        $sort = $settings['reviews_default_sort'] ?? 'newest';

        // Read-through object cache; key = recipe id.
        $total = (int) Cache::remember('reviews', 'frontend_count|' . $recipe_id, function () use ($wpdb, $prefix, $recipe_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE recipe_id = %d AND status = \'approved\'',
                $prefix . 'reviews',
                $recipe_id
            ));
        });

        // ORDER BY is not one identifier here - each option is a different column set with
        // its own directions, and a direction keyword cannot be bound at all. So the sort is
        // a match over five *literal* queries rather than an assembled fragment: the string
        // handed to prepare() is a constant in every arm, and $sort only chooses which arm
        // runs. Any unknown value falls to 'newest'. Each arm ends with `id` as a
        // deterministic tiebreaker - without it, reviews sharing a created_at (or a rating,
        // or a helpful_count) could repeat across pages and others be skipped entirely.
        //
        // Read-through object cache; key = recipe id + sort + pagination.
        $reviews_key = 'frontend_list|' . $recipe_id . '|' . $sort . '|' . $limit . '|' . $offset;
        $reviews_table = $prefix . 'reviews';
        $reviews = Cache::remember('reviews', $reviews_key, function () use ($wpdb, $reviews_table, $recipe_id, $sort, $limit, $offset) {
            // The column list is written out rather than assembled, so the string handed to
            // prepare() is a literal in every arm. It must stay identical to
            // PlugixaRecipe\Columns::review_public(), which is the canonical allowlist and
            // is what SqlLiteralTest compares these literals against. author_email,
            // author_ip and user_id are absent on purpose - see that class's docblock.
            $rows = match ($sort) {
                'oldest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY created_at ASC, id ASC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                'highest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY rating DESC, created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                'lowest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY rating ASC, created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                'helpful' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY helpful_count DESC, created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                default => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
            };

            return $rows ?: [];
        });

        // Check which reviews the current user has voted as helpful (per-user/IP state: not cached).
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        foreach ($reviews as &$review) {
            $review['rating'] = (int) $review['rating'];
            $review['is_verified'] = (bool) $review['is_verified'];
            $review['helpful_count'] = (int) $review['helpful_count'];

            // Check if current user/IP voted helpful on this review.
            if ($user_id) {
                $review['is_helpful'] = (bool) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM %i WHERE review_id = %d AND user_id = %d',
                    $prefix . 'review_helpful_votes', $review['id'], $user_id
                ));
            } else {
                $review['is_helpful'] = (bool) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM %i WHERE review_id = %d AND ip_address = %s AND user_id IS NULL',
                    $prefix . 'review_helpful_votes', $review['id'], $ip_address
                ));
            }

            if (!empty($review['images'])) {
                $ids = json_decode($review['images'], true);
                $review['images'] = [];
                if (is_array($ids)) {
                    foreach ($ids as $att_id) {
                        $url = wp_get_attachment_image_url((int) $att_id, 'medium');
                        $full = wp_get_attachment_image_url((int) $att_id, 'full');
                        if ($url) {
                            $review['images'][] = [
                                'id'   => (int) $att_id,
                                'url'  => $url,
                                'full' => $full ?: $url,
                            ];
                        }
                    }
                }
            }
        }

        return $this->success([
            'items'  => $reviews,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ]);
    }

    /**
     * Submit a review (public).
     */
    public function submit_review($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

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
        if (!$recipe_id) {
            return $this->error(__('Recipe ID is required.', 'plugixa-recipe'));
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND status = \'published\'',
            $prefix . 'recipes',
            $recipe_id
        ));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Guest review gate
        $settings = get_option('plugixa_recipe_settings', []);
        if (empty($settings['allow_guest_reviews']) && !is_user_logged_in()) {
            return $this->error(__('You must be logged in to submit a review.', 'plugixa-recipe'), 403);
        }

        // Rate limiting (per IP, per hour) — mirrors submit_recipe.
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        $rate_key = 'plugixa_recipe_review_' . md5($ip);
        $review_submissions = (int) get_transient($rate_key);
        $max_per_hour = min((int) ($settings['api_rate_limit'] ?? 100), 10);
        if ($review_submissions >= $max_per_hour) {
            return $this->error(__('Too many reviews submitted. Please try again later.', 'plugixa-recipe'), 429);
        }
        set_transient($rate_key, $review_submissions + 1, HOUR_IN_SECONDS);

        // scalar_string() first: these sanitizers all TypeError on an array, and the payload
        // is anonymous, so {"author_name": []} would otherwise 500 this public endpoint.
        $author_name = sanitize_text_field(self::scalar_string($params['author_name'] ?? ''));
        if (empty($author_name)) {
            return $this->error(__('Name is required.', 'plugixa-recipe'));
        }

        $author_email = sanitize_email(self::scalar_string($params['author_email'] ?? ''));
        if (empty($author_email) || !is_email($author_email)) {
            return $this->error(__('A valid email address is required.', 'plugixa-recipe'));
        }

        $review_text = wp_kses_post(self::scalar_string($params['review_text'] ?? ''));
        if (empty(trim($review_text)) || mb_strlen(trim($review_text)) < 10) {
            return $this->error(__('Review must be at least 10 characters.', 'plugixa-recipe'));
        }

        // Rating: required by default, optional if require_rating is false
        $require_rating = $settings['require_rating'] ?? true;
        $raw_rating = $params['rating'] ?? null;
        if ($require_rating && (empty($raw_rating) || (int) $raw_rating < 1)) {
            return $this->error(__('Rating is required.', 'plugixa-recipe'));
        }
        $rating = $raw_rating ? max(1, min(5, (int) $raw_rating)) : 0;
        if (!empty($settings['turnstile_reviews_enabled']) && !empty($settings['turnstile_secret_key'])) {
            $captcha = $this->verify_turnstile(
                (string) ($params['turnstile_token'] ?? ''),
                (string) $settings['turnstile_secret_key']
            );
            if (is_wp_error($captcha)) {
                return $captcha;
            }
        }

        $default_status = !empty($settings['review_moderation']) ? 'pending' : 'approved';

        $wpdb->insert($prefix . 'reviews', [
            'recipe_id'    => $recipe_id,
            'user_id'      => is_user_logged_in() ? get_current_user_id() : null,
            'author_name'  => $author_name,
            'author_email' => $author_email,
            'author_ip'    => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'rating'       => $rating,
            'review_text'  => $review_text,
            'status'       => $default_status,
            'is_verified'  => is_user_logged_in() ? 1 : 0,
            'created_at'   => current_time('mysql'),
        ]);

        $review_id = $wpdb->insert_id;

        // Handle review image uploads when enabled.
        if ( $review_id && ! empty( $settings['review_enable_images'] ) && ! empty( $_FILES['review_images'] ) && ! empty( $_FILES['review_images']['name'][0] ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $allowed_types = [ 'image/jpeg', 'image/png', 'image/webp' ];
            $max_size      = ( (int) ( $settings['max_upload_size'] ?? 5 ) ) * 1024 * 1024;
            $max_images    = max( 1, min( 10, (int) ( $settings['review_max_images'] ?? 3 ) ) );
            $image_ids     = [];

            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Scoped to this upload loop. tmp_name/error/size are PHP-generated upload metadata that must reach media_handle_upload() byte-for-byte; sanitizing tmp_name would break the upload. They are validated instead: error === UPLOAD_ERR_OK, size against max_upload_size, count against a cap, and the real MIME via wp_check_filetype_and_ext() against an allow-list. name IS sanitized (sanitize_file_name) and type IS replaced by the derived MIME, so neither is suppressed here.
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
                $_FILES['plugixa_recipe_review_img_temp'] = [
                    'name'     => sanitize_file_name( $_FILES['review_images']['name'][ $i ] ),
                    'type'     => $file_mime['type'],
                    'tmp_name' => $_FILES['review_images']['tmp_name'][ $i ],
                    'error'    => $_FILES['review_images']['error'][ $i ],
                    'size'     => $_FILES['review_images']['size'][ $i ],
                ];
                $att_id = media_handle_upload( 'plugixa_recipe_review_img_temp', 0 );
                if ( ! is_wp_error( $att_id ) ) {
                    $image_ids[] = $att_id;
                }
            }
            unset( $_FILES['plugixa_recipe_review_img_temp'] );
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

            if ( ! empty( $image_ids ) ) {
                $wpdb->update( $prefix . 'reviews', [ 'images' => wp_json_encode( $image_ids ) ], [ 'id' => $review_id ] );
            }
        }

        // Recalculate recipe rating if auto-approved
        if ($default_status === 'approved') {
            $this->recalculate_recipe_rating($recipe_id);
        }

        // Send notification email
        if (!empty($settings['notification_enabled']) && !empty($settings['notification_recipients'])) {
            $recipe_title = $wpdb->get_var($wpdb->prepare('SELECT title FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
            $subject = str_replace('{recipe_title}', $recipe_title, $settings['notification_subject'] ?? 'New review submitted');
            $message = str_replace(
                ['{recipe_title}', '{author_name}', '{rating}'],
                [$recipe_title, $author_name, $rating],
                $settings['notification_message'] ?? ''
            );
            wp_mail($settings['notification_recipients'], $subject, $message);
        }

        // Content write: a review also changes the recipe's rating aggregate shown in recipe lists.
        Cache::flush('reviews', 'recipes');

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
        global $wpdb;
        $prefix    = $wpdb->prefix . 'plugixa_recipe_';
        $review_id = (int) $request->get_param('review_id');

        $review = $wpdb->get_row($wpdb->prepare(
            'SELECT id, helpful_count FROM %i WHERE id = %d AND status = \'approved\'',
            $prefix . 'reviews',
            $review_id
        ));
        if (!$review) {
            return $this->error(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $settings = get_option('plugixa_recipe_settings', []);
        if (empty($settings['review_enable_helpful_votes'])) {
            return $this->error(__('This feature is disabled.', 'plugixa-recipe'), 403);
        }

        $user_id    = is_user_logged_in() ? get_current_user_id() : null;
        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        // Rate limiting (per IP, per hour) — votes are lightweight so the cap is generous.
        $rate_key   = 'plugixa_recipe_helpful_' . md5($ip_address);
        $vote_count = (int) get_transient($rate_key);
        $max_per_hour = min((int) ($settings['api_rate_limit'] ?? 100), 60);
        if ($vote_count >= $max_per_hour) {
            return $this->error(__('Too many requests. Please try again later.', 'plugixa-recipe'), 429);
        }
        set_transient($rate_key, $vote_count + 1, HOUR_IN_SECONDS);

        // Check for existing vote (by user_id if logged in, by IP if guest).
        if ($user_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE review_id = %d AND user_id = %d',
                $prefix . 'review_helpful_votes', $review_id, $user_id
            ));
        } else {
            $existing = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE review_id = %d AND ip_address = %s AND user_id IS NULL',
                $prefix . 'review_helpful_votes', $review_id, $ip_address
            ));
        }

        if ($existing) {
            // Remove vote.
            $wpdb->delete($prefix . 'review_helpful_votes', ['id' => $existing]);
            $new_count = max(0, (int) $review->helpful_count - 1);
            // Counter increment only: deliberately does NOT flush the cache — helpful votes fire
            // straight off a rendered review list, so flushing here would invalidate the whole
            // cache on every vote. Cached lists therefore carry eventually-consistent helpful counts.
            $wpdb->update($prefix . 'reviews', ['helpful_count' => $new_count], ['id' => $review_id]);
            return $this->success(['is_helpful' => false, 'helpful_count' => $new_count]);
        } else {
            // Add vote.
            $wpdb->insert($prefix . 'review_helpful_votes', [
                'review_id'  => $review_id,
                'user_id'    => $user_id,
                'ip_address' => $ip_address,
                'created_at' => current_time('mysql'),
            ]);
            $new_count = (int) $review->helpful_count + 1;
            // Counter increment only: deliberately does NOT flush the cache — helpful votes fire
            // straight off a rendered review list, so flushing here would invalidate the whole
            // cache on every vote. Cached lists therefore carry eventually-consistent helpful counts.
            $wpdb->update($prefix . 'reviews', ['helpful_count' => $new_count], ['id' => $review_id]);
            return $this->success(['is_helpful' => true, 'helpful_count' => $new_count]);
        }
    }

    /**
     * Get public collections.
     */
    public function get_collections($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // Read-through object cache; the query takes no inputs. `|v2` marks the query SHAPE:
        // recipe_count changed meaning (below), and the key digests inputs, not SQL, so no
        // key written by the old query can be read back. Keyed on 'recipes' as well, because
        // the count now reads recipes.status and publishing a recipe writes no collection row.
        $collections_table = $prefix . 'collections';
        $collections = Cache::remember(['collections', 'recipes'], 'frontend_public|v2', function () use ($wpdb, $prefix, $collections_table) {
            // The col. prefix is load-bearing here: the JOINs put three tables in scope.
            // This list must stay identical to Columns::collection_public('col').
            //
            // recipe_count counts links to PUBLISHED recipes, through the joined recipe row.
            // It was COUNT(cr.recipe_id), which counted every link row - drafts, pending
            // recipes and links to deleted recipes included - on an anonymous endpoint. It is
            // deliberately COUNT(r.id), not COUNT(DISTINCT r.id): a meal plan can hold the
            // same recipe on several days, and those entries have always each counted.
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT col.id, col.name, col.slug, col.description, col.image_id,
                        col.type, col.is_featured, col.display_order, col.created_at,
                        COUNT(r.id) AS recipe_count
                FROM %i col
                LEFT JOIN %i cr ON col.id = cr.collection_id
                LEFT JOIN %i r ON r.id = cr.recipe_id AND r.status = \'published\'
                WHERE col.status = \'published\' AND col.visibility = \'public\'
                GROUP BY col.id
                ORDER BY col.display_order ASC, col.id ASC',
                    $collections_table,
                    $prefix . 'collection_recipes',
                    $prefix . 'recipes'
                ),
                ARRAY_A
            ) ?: [];
        });

        foreach ($collections as &$col) {
            $col['recipe_count'] = (int) $col['recipe_count'];
            if (!empty($col['image_id'])) {
                $col['image_url'] = wp_get_attachment_image_url((int) $col['image_id'], 'medium');
            }
        }

        return $this->success($collections);
    }

    /**
     * Toggle favorite (requires login, public route).
     */
    public function toggle_favorite($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $user_id = get_current_user_id();
        $recipe_id = (int) $request->get_param('recipe_id');

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND status = \'published\'',
            $prefix . 'recipes',
            $recipe_id
        ));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $prefix . 'favorites', $user_id, $recipe_id
        ));

        if ($existing) {
            $wpdb->delete($prefix . 'favorites', ['user_id' => $user_id, 'recipe_id' => $recipe_id]);

            // Content write: favourite status is surfaced on cached recipe reads too.
            Cache::flush('favorites', 'recipes');

            return $this->success(['is_favorited' => false], __('Removed from favorites.', 'plugixa-recipe'));
        } else {
            $wpdb->insert($prefix . 'favorites', [
                'user_id' => $user_id, 'recipe_id' => $recipe_id, 'created_at' => current_time('mysql'),
            ]);

            // Content write: favourite status is surfaced on cached recipe reads too.
            Cache::flush('favorites', 'recipes');

            return $this->success(['is_favorited' => true], __('Added to favorites.', 'plugixa-recipe'));
        }
    }

    /**
     * Toggle like (requires login, public route).
     */
    public function toggle_like($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $user_id = get_current_user_id();
        $recipe_id = (int) $request->get_param('recipe_id');

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND status = \'published\'',
            $prefix . 'recipes',
            $recipe_id
        ));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $prefix . 'likes', $user_id, $recipe_id
        ));

        if ($existing) {
            $wpdb->delete($prefix . 'likes', ['user_id' => $user_id, 'recipe_id' => $recipe_id]);
            // Counter increment only: deliberately does NOT flush the cache — likes are fired
            // straight off a rendered recipe card, so flushing here would invalidate the whole
            // cache on a plain page view. Cached lists therefore carry eventually-consistent
            // like counts (the live count is returned in this response for the UI to apply).
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET like_count = GREATEST(like_count - 1, 0) WHERE id = %d',
                $prefix . 'recipes',
                $recipe_id
            ));
        } else {
            $wpdb->insert($prefix . 'likes', [
                'user_id' => $user_id, 'recipe_id' => $recipe_id, 'created_at' => current_time('mysql'),
            ]);
            // Counter increment only: deliberately does NOT flush the cache — likes are fired
            // straight off a rendered recipe card, so flushing here would invalidate the whole
            // cache on a plain page view. Cached lists therefore carry eventually-consistent
            // like counts (the live count is returned in this response for the UI to apply).
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET like_count = like_count + 1 WHERE id = %d',
                $prefix . 'recipes',
                $recipe_id
            ));
        }

        $like_count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT like_count FROM %i WHERE id = %d',
            $prefix . 'recipes',
            $recipe_id
        ));

        $is_liked = !$existing;

        return $this->success([
            'is_liked'   => $is_liked,
            'like_count' => $like_count,
        ], $is_liked
            ? __('Liked!', 'plugixa-recipe')
            : __('Like removed.', 'plugixa-recipe')
        );
    }

    /**
     * Submit a recipe from the frontend form.
     */
    public function submit_recipe($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix   = $wpdb->prefix . 'plugixa_recipe_';
        $settings = get_option('plugixa_recipe_settings', []);

        if (empty($settings['enable_submissions'])) {
            return $this->error(__('Recipe submissions are not enabled.', 'plugixa-recipe'));
        }

        // Configurable login gate.
        if (!empty($settings['submission_require_login']) && !is_user_logged_in()) {
            return $this->error(__('You must be logged in to submit a recipe.', 'plugixa-recipe'), 401);
        }

        // Detect content type — form sends FormData (multipart) for image upload.
        $content_type = $request->get_content_type();
        $is_multipart = $content_type && strpos($content_type['value'] ?? '', 'multipart') !== false;

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

        // Turnstile validation.
        if (!empty($settings['turnstile_form_enabled']) && !empty($settings['turnstile_secret_key'])) {
            $captcha = $this->verify_turnstile(
                (string) $get_param('turnstile_token'),
                (string) $settings['turnstile_secret_key']
            );
            if (is_wp_error($captcha)) {
                return $captcha;
            }
        }

        // Honeypot bot detection.
        $honeypot = $get_param('website_url', '');
        if (!empty($honeypot)) {
            return $this->success(['id' => 0, 'status' => 'pending'], __('Thank you! Your recipe has been submitted for review.', 'plugixa-recipe'));
        }

        // Rate limiting (per IP, per hour).
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        $rate_key = 'plugixa_recipe_submit_' . md5($ip);
        $submissions = (int) get_transient($rate_key);
        $max_per_hour = min((int) ($settings['api_rate_limit'] ?? 100), 10);
        if ($submissions >= $max_per_hour) {
            return $this->error(__('Too many submissions. Please try again later.', 'plugixa-recipe'), 429);
        }
        set_transient($rate_key, $submissions + 1, HOUR_IN_SECONDS);

        // Validate required fields.
        $title = sanitize_text_field($get_text('title'));
        if (empty($title)) {
            return $this->error(__('Recipe title is required.', 'plugixa-recipe'));
        }
        if (mb_strlen($title) > 200) {
            return $this->error(__('Recipe title must be 200 characters or fewer.', 'plugixa-recipe'));
        }

        $description = wp_kses_post($get_text('description'));
        if (empty($description)) {
            return $this->error(__('Recipe description is required.', 'plugixa-recipe'));
        }
        if (mb_strlen(wp_strip_all_tags($description)) > 5000) {
            return $this->error(__('Recipe description is too long.', 'plugixa-recipe'));
        }

        // Validate recipe image is provided.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- 'error' is PHP-generated upload metadata compared against a constant; the empty() guards precede it.
        if (empty($_FILES['recipe_image']) || empty($_FILES['recipe_image']['size']) || $_FILES['recipe_image']['error'] !== UPLOAD_ERR_OK) {
            return $this->error(__('Recipe image is required.', 'plugixa-recipe'));
        }
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated

        // Validate at least one category.
        $categories_input = $is_multipart
            ? array_map('intval', (array) wp_unslash($_POST['categories'] ?? []))
            : ($request->get_param('categories') ?? []);
        if (empty($categories_input) || !is_array($categories_input) || count(array_filter(array_map('intval', $categories_input))) < 1) {
            return $this->error(__('Please select at least one category.', 'plugixa-recipe'));
        }

        // Pre-validate ingredients: at least one with a name.
        $ingredients_json_raw = $get_param('ingredients', '');
        $ingredients_pre = is_string($ingredients_json_raw) ? json_decode($ingredients_json_raw, true) : $ingredients_json_raw;
        $has_valid_ingredient = false;
        if (!empty($ingredients_pre) && is_array($ingredients_pre)) {
            foreach ($ingredients_pre as $ing) {
                $name = self::scalar_string(is_string($ing) ? $ing : ($ing['name'] ?? ''));
                if (trim($name) !== '') { $has_valid_ingredient = true; break; }
            }
        }
        if (!$has_valid_ingredient) {
            return $this->error(__('At least one ingredient with a name is required.', 'plugixa-recipe'));
        }

        // Pre-validate instructions: at least one with text.
        $instructions_json_raw = $get_param('instructions', '');
        $instructions_pre = is_string($instructions_json_raw) ? json_decode($instructions_json_raw, true) : $instructions_json_raw;
        $has_valid_instruction = false;
        if (!empty($instructions_pre) && is_array($instructions_pre)) {
            foreach ($instructions_pre as $inst) {
                // 'text' is the alias the admin app binds its form to (the read path sets it
                // from instruction_text), so accept either name rather than silently dropping
                // an instruction that used the other one.
                $text = self::scalar_string(is_string($inst) ? $inst : ($inst['instruction_text'] ?? $inst['text'] ?? ''));
                if (trim($text) !== '') { $has_valid_instruction = true; break; }
            }
        }
        if (!$has_valid_instruction) {
            return $this->error(__('At least one instruction step is required.', 'plugixa-recipe'));
        }

        // Generate unique slug.
        $slug = sanitize_title($title);
        $original_slug = $slug;
        $counter = 1;
        while ($wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $prefix . 'recipes', $slug))) {
            $slug = $original_slug . '-' . (++$counter);
        }

        $status = sanitize_text_field($settings['submission_status'] ?? 'pending');

        // Validate enums.
        $difficulty = sanitize_text_field($get_text('difficulty', 'medium'));
        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $difficulty = 'medium';
        }
        $cost = sanitize_text_field($get_text('cost'));
        if (!in_array($cost, ['budget', 'medium', 'expensive', ''], true)) {
            $cost = '';
        }

        // Validate season enum.
        $season = sanitize_text_field($get_text('season', 'all'));
        if (!in_array($season, ['spring', 'summer', 'autumn', 'winter', 'all'], true)) {
            $season = 'all';
        }

        // Validate required numeric fields.
        $servings_raw = $get_param('servings', '');
        if ($servings_raw === '' || (int) $servings_raw < 1) {
            return $this->error(__('Servings is required and must be at least 1.', 'plugixa-recipe'));
        }
        $servings = min(999, max(1, (int) $servings_raw));

        $prep_time_raw = $get_param('prep_time', '');
        if ($prep_time_raw === '') {
            return $this->error(__('Prep time is required.', 'plugixa-recipe'));
        }
        $prep_time = min(9999, max(0, (int) $prep_time_raw));

        $cook_time_raw = $get_param('cook_time', '');
        if ($cook_time_raw === '') {
            return $this->error(__('Cook time is required.', 'plugixa-recipe'));
        }
        $cook_time = min(9999, max(0, (int) $cook_time_raw));

        $rest_time = $get_param('rest_time') !== '' ? min(9999, max(0, (int) $get_param('rest_time'))) : null;

        // Validate text field max-lengths.
        $excerpt = sanitize_textarea_field($get_text('excerpt'));
        if (mb_strlen($excerpt) > 500) {
            return $this->error(__('Excerpt must be 500 characters or fewer.', 'plugixa-recipe'));
        }
        $notes = wp_kses_post($get_text('notes'));
        if (mb_strlen(wp_strip_all_tags($notes)) > 5000) {
            return $this->error(__('Notes field is too long.', 'plugixa-recipe'));
        }
        $tips = wp_kses_post($get_text('tips'));
        if (mb_strlen(wp_strip_all_tags($tips)) > 5000) {
            return $this->error(__('Tips field is too long.', 'plugixa-recipe'));
        }

        // Parse tags & equipment from comma-separated to JSON.
        $tags_raw  = sanitize_text_field($get_text('tags', ''));
        $tags_json = wp_json_encode(array_values(array_filter(array_map('trim', explode(',', $tags_raw)))));

        $equip_raw  = sanitize_text_field($get_text('equipment', ''));
        $equip_json = wp_json_encode(array_values(array_filter(array_map('trim', explode(',', $equip_raw)))));

        // Insert recipe.
        $recipe_data = [
            'title'          => $title,
            'slug'           => $slug,
            'description'    => $description,
            'excerpt'        => $excerpt,
            'notes'          => $notes,
            'tips'           => $tips,
            'servings'       => $servings,
            'servings_unit'  => sanitize_text_field($get_text('servings_unit')),
            'prep_time'      => $prep_time,
            'cook_time'      => $cook_time,
            'rest_time'      => $rest_time,
            'difficulty'     => $difficulty,
            'cost'           => $cost ?: null,
            'season'         => $season,
            'video_url'      => esc_url_raw($get_text('video_url')),
            'author_name'    => sanitize_text_field($get_text('author_name')),
            'source'         => sanitize_text_field($get_text('source_name')) ?: 'form',
            'source_url'     => esc_url_raw($get_text('source_url')),
            'tags'           => $tags_json,
            'equipment'      => $equip_json,
            'is_vegetarian'  => (int) (bool) $get_param('is_vegetarian', false),
            'is_vegan'       => (int) (bool) $get_param('is_vegan', false),
            'is_gluten_free' => (int) (bool) $get_param('is_gluten_free', false),
            'is_dairy_free'  => (int) (bool) $get_param('is_dairy_free', false),
            'is_keto'        => (int) (bool) $get_param('is_keto', false),
            'is_low_carb'    => (int) (bool) $get_param('is_low_carb', false),
            'is_nut_free'    => (int) (bool) $get_param('is_nut_free', false),
            'is_paleo'       => (int) (bool) $get_param('is_paleo', false),
            'is_sugar_free'  => (int) (bool) $get_param('is_sugar_free', false),
            'is_halal'       => (int) (bool) $get_param('is_halal', false),
            'is_kosher'      => (int) (bool) $get_param('is_kosher', false),
            'status'         => $status,
            'created_at'     => current_time('mysql'),
            'created_by'     => is_user_logged_in() ? get_current_user_id() : 0,
            'submission_ip'  => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
        ];

        $wpdb->insert($prefix . 'recipes', $recipe_data);
        $recipe_id = $wpdb->insert_id;

        if (!$recipe_id) {
            return $this->error(__('Failed to submit recipe.', 'plugixa-recipe'), 500);
        }

        // Image upload (with server-side MIME verification).
        if (!empty($_FILES['recipe_image']) && !empty($_FILES['recipe_image']['size'])) {
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The whole upload entry is handed to media_handle_upload(), which sanitizes the name and re-checks the type itself; the members are validated below against the MIME allow-list and max_upload_size.
            $file          = $_FILES['recipe_image'];
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size      = ((int) ($settings['max_upload_size'] ?? 5)) * 1024 * 1024;
            $real_mime     = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);

            if (empty($real_mime['type']) || !in_array($real_mime['type'], $allowed_types, true)) {
                // Non-blocking: skip file with invalid or mismatched MIME type.
            } elseif ($file['size'] > $max_size) {
                // Non-blocking: skip oversized file.
            } else {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';

                $attachment_id = media_handle_upload('recipe_image', 0);
                if (!is_wp_error($attachment_id)) {
                    $wpdb->update($prefix . 'recipes', ['image_id' => $attachment_id], ['id' => $recipe_id]);
                }
            }
        }

        // Gallery images upload (multiple files, max 10).
        if (!empty($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size      = ((int) ($settings['max_upload_size'] ?? 5)) * 1024 * 1024;
            $gallery_ids   = [];
            $max_gallery   = 10;

            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Scoped to this upload loop. tmp_name/error/size are PHP-generated upload metadata that must reach media_handle_upload() byte-for-byte; sanitizing tmp_name would break the upload. They are validated instead: error === UPLOAD_ERR_OK, size against max_upload_size, count against a cap, and the real MIME via wp_check_filetype_and_ext() against an allow-list. name IS sanitized (sanitize_file_name) and type IS replaced by the derived MIME, so neither is suppressed here.
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

                // Restructure into single-file format for media_handle_upload. The browser-supplied
                // name and type are never trusted: the name is sanitised for the filesystem, and the
                // type is replaced by the MIME wp_check_filetype_and_ext() just derived from the
                // file's own contents and checked against $allowed_types.
                $_FILES['plugixa_recipe_gallery_temp'] = [
                    'name'     => sanitize_file_name($_FILES['gallery_images']['name'][$g]),
                    'type'     => $gallery_mime['type'],
                    'tmp_name' => $_FILES['gallery_images']['tmp_name'][$g],
                    'error'    => $_FILES['gallery_images']['error'][$g],
                    'size'     => $_FILES['gallery_images']['size'][$g],
                ];
                $att_id = media_handle_upload('plugixa_recipe_gallery_temp', 0);
                if (!is_wp_error($att_id)) {
                    $gallery_ids[] = $att_id;
                }
            }
            unset($_FILES['plugixa_recipe_gallery_temp']);
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

            if (!empty($gallery_ids)) {
                $wpdb->update($prefix . 'recipes', ['gallery' => wp_json_encode($gallery_ids)], ['id' => $recipe_id]);
            }
        }

        // Save categories (array of IDs → junction table, with FK validation).
        $categories = $is_multipart
            ? array_map('intval', (array) wp_unslash($_POST['categories'] ?? []))
            : ($request->get_param('categories') ?? []);
        if (!empty($categories) && is_array($categories)) {
            $cat_ids = array_filter(array_map('intval', $categories));
            if (!empty($cat_ids)) {
                // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
                // IN (...), which keeps the query a constant literal for any id count.
                $valid_cats = $wpdb->get_col($wpdb->prepare(
                    'SELECT id FROM %i WHERE FIND_IN_SET(id, %s)',
                    $prefix . 'categories',
                    implode(',', $cat_ids)
                ));
                foreach ($valid_cats as $i => $cat_id) {
                    $wpdb->insert($prefix . 'recipe_categories', [
                        'recipe_id'     => $recipe_id,
                        'category_id'   => (int) $cat_id,
                        'display_order' => $i,
                    ]);
                }
            }
        }

        // Save cuisine (single ID → junction table, with FK validation).
        $cuisine_id = (int) $get_param('cuisine_id', 0);
        if ($cuisine_id > 0) {
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'cuisines', $cuisine_id));
            if ($exists) {
                $wpdb->insert($prefix . 'recipe_cuisines', [
                    'recipe_id'  => $recipe_id,
                    'cuisine_id' => $cuisine_id,
                ]);
            }
        }

        // Save course (single ID → junction table, with FK validation).
        $course_id = (int) $get_param('course_id', 0);
        if ($course_id > 0) {
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'courses', $course_id));
            if ($exists) {
                $wpdb->insert($prefix . 'recipe_courses', [
                    'recipe_id' => $recipe_id,
                    'course_id' => $course_id,
                ]);
            }
        }

        // Insert ingredients (structured: quantity + unit + name + notes, max 100).
        $ingredients_json = $get_param('ingredients', '');
        $ingredients      = is_string($ingredients_json) ? json_decode($ingredients_json, true) : $ingredients_json;
        if (!empty($ingredients) && is_array($ingredients)) {
            $ingredients = array_slice($ingredients, 0, 100);
            foreach ($ingredients as $i => $ing) {
                // Sanitize BOTH shapes. The bare-string form used to skip the sanitizer
                // entirely and reach $wpdb->insert() raw, from a public anonymous endpoint -
                // only the keyed-array form was cleaned.
                $name = sanitize_text_field(self::scalar_string(is_string($ing) ? $ing : ($ing['name'] ?? '')));
                if (empty($name)) continue;
                $wpdb->insert($prefix . 'ingredients', [
                    'recipe_id'   => $recipe_id,
                    'name'        => $name,
                    'quantity'    => sanitize_text_field(self::scalar_string($ing['quantity'] ?? '')),
                    'unit'        => sanitize_text_field(self::scalar_string($ing['unit'] ?? '')),
                    'notes'       => sanitize_text_field(self::scalar_string($ing['notes'] ?? '')),
                    'sort_order'  => $i,
                    'group_order' => 0,
                ]);
            }
        }

        // Insert instructions (with optional title, max 50).
        $instructions_json = $get_param('instructions', '');
        $instructions      = is_string($instructions_json) ? json_decode($instructions_json, true) : $instructions_json;
        if (!empty($instructions) && is_array($instructions)) {
            $instructions = array_slice($instructions, 0, 50);
            foreach ($instructions as $i => $inst) {
                // 'text' is the alias the admin app binds its form to (the read path sets it
                // from instruction_text), so accept either name rather than silently dropping
                // an instruction that used the other one.
                $text = self::scalar_string(is_string($inst) ? $inst : ($inst['instruction_text'] ?? $inst['text'] ?? ''));
                if (empty($text)) continue;
                $wpdb->insert($prefix . 'instructions', [
                    'recipe_id'        => $recipe_id,
                    'title'            => sanitize_text_field(self::scalar_string($inst['title'] ?? '')),
                    'instruction_text' => wp_kses_post($text),
                    'sort_order'       => $i,
                    'group_order'      => 0,
                ]);
            }
        }

        // Insert nutrition data (if any fields provided, with range clamping).
        $nutrition_json = $get_param('nutrition', '');
        $nutrition      = is_string($nutrition_json) ? json_decode($nutrition_json, true) : $nutrition_json;
        if (!empty($nutrition) && is_array($nutrition)) {
            $nf_names       = ['serving_size', 'calories', 'total_fat', 'saturated_fat', 'trans_fat',
                'cholesterol', 'sodium', 'total_carbohydrate', 'dietary_fiber', 'total_sugars',
                'protein', 'vitamin_a', 'vitamin_c', 'calcium', 'iron', 'potassium'];
            $nutrition_data = ['recipe_id' => $recipe_id];
            $has_values     = false;
            foreach ($nf_names as $nf) {
                $val = $nutrition[$nf] ?? null;
                if ($nf === 'serving_size') {
                    $nutrition_data[$nf] = sanitize_text_field(self::scalar_string($val ?? ''));
                    if (!empty($val)) $has_values = true;
                } else {
                    $numeric_val = ($val !== '' && $val !== null) ? (float) $val : null;
                    if ($numeric_val !== null) {
                        $numeric_val = max(0, min(99999.99, $numeric_val));
                        $has_values = true;
                    }
                    $nutrition_data[$nf] = $numeric_val;
                }
            }
            if ($has_values) {
                $wpdb->insert($prefix . 'nutrition', $nutrition_data);
            }
        }

        /**
         * Fires after a recipe submitted through the public form has been stored.
         *
         * The premium overlay hooks this to persist the extra data its own form fields
         * collect. $get_param reads one sanitized request value: fn(string $key, $default).
         *
         * @param int      $recipe_id
         * @param callable $get_param
         */
        do_action( 'plugixa_recipe_frontend_submission_saved', $recipe_id, $get_param );

        // Email notification.
        if (class_exists('\\PlugixaRecipe\\Email\\EmailNotifier')) {
            \PlugixaRecipe\Email\EmailNotifier::send_new_submission_notification([
                'title'       => $title,
                'author_name' => $recipe_data['author_name'],
                'description' => $description,
                'difficulty'  => $difficulty,
                'servings'    => $recipe_data['servings'],
            ]);
        }

        // Content write: the new recipe (plus its taxonomy pivots, which feed the recipe counts
        // shown in the category/cuisine/course widgets).
        Cache::flush('recipes', 'categories', 'cuisines', 'courses');

        return $this->success([
            'id'     => $recipe_id,
            'status' => $status,
        ], __('Thank you! Your recipe has been submitted for review.', 'plugixa-recipe'));
    }

    /**
     * Recalculate average rating for a recipe.
     */
    private function recalculate_recipe_rating(int $recipe_id): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $stats = $wpdb->get_row($wpdb->prepare(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS count
            FROM %i
            WHERE recipe_id = %d AND status = \'approved\'',
            $prefix . 'reviews',
            $recipe_id
        ));

        $wpdb->update($prefix . 'recipes', [
            'rating_average' => $stats->avg_rating ? round((float) $stats->avg_rating, 2) : 0,
            'rating_count'   => (int) $stats->count,
        ], ['id' => $recipe_id]);
    }
}
