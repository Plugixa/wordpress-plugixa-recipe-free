<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Frontend;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the single-recipe render. The content reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the per-user favourite lookup and the view_count increment are deliberately uncached (see the comment at the increment). Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed file-wide.

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $plugixa_recipe_single_recipe_data is the documented hand-off global read by templates/single-recipe.php. "pxr" is the plugin's established public prefix (CSS classes, --pxr-* custom properties, plugixa_recipe_* transients) and is unique to it; the sniff only objects because it is under its 4-character minimum.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use PlugixaRecipe\RenderedRecipes;

/**
 * RecipeSingle Class - Handles the single recipe frontend page.
 *
 * Registers a rewrite rule for /recipe/{id}/{slug} that renders a full
 * single recipe page using the theme's header/footer and templates/single-recipe.php.
 */
class RecipeSingle {

    /**
     * Initialize single recipe page hooks.
     */
    public function init(): void {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('template_include', [$this, 'handle_single_recipe']);
    }

    /**
     * Register the single recipe rewrite rule.
     */
    public function register_rewrite_rules(): void {
        // The base is configurable (Settings > Display). RecipeArchive owns the flush:
        // its stored signature includes this slug, so changing the setting re-flushes
        // every rule, this one included.
        add_rewrite_rule(
            '^' . preg_quote( plugixa_recipe_url_slug( 'recipe' ), '#' ) . '/(\d+)(?:/([^/]+))?/?$',
            'index.php?plugixa_recipe_id=$matches[1]',
            'top'
        );
    }

    /**
     * Register custom query variables.
     */
    public function register_query_vars(array $vars): array {
        $vars[] = 'plugixa_recipe_id';
        return $vars;
    }

    /**
     * Handle the single recipe page via template_include.
     */
    public function handle_single_recipe(string $template): string {
        $recipe_id = get_query_var('plugixa_recipe_id');
        if (empty($recipe_id)) {
            return $template;
        }

        $recipe_id = (int) $recipe_id;
        $recipe = $this->build_recipe_object($recipe_id);

        if (!$recipe) {
            return $template;
        }

        // The page's subject is this one recipe: register it so the JSON-LD builder
        // describes it. Before the registry existed the builder gated on "was a shortcode
        // used", which is false here, so single-recipe pages carried no structured data at
        // all while unrelated pages carried too much.
        RenderedRecipes::add([$recipe_id]);

        // Prevent 404
        global $wp_query;
        $wp_query->is_404 = false;
        status_header(200);

        // Set page title
        add_filter('document_title_parts', function ($parts) use ($recipe) {
            $parts['title'] = $recipe->title;
            return $parts;
        });

        // Fix body classes for block theme compatibility.
        add_filter( 'body_class', function ( $classes ) {
            $classes = array_diff( $classes, [ 'home', 'blog' ] );
            $classes[] = 'wp-singular';
            $classes[] = 'pxr-single-recipe';
            return $classes;
        } );

        // Enqueue frontend CSS
        $css_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Enqueue frontend JS
        $js_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipes.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipes.js',
                [],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            wp_localize_script('plugixa-recipe-frontend', 'plugixaRecipeConfig', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'restUrl'    => esc_url_raw(rest_url('plugixa-recipe/v1/')),
                'nonce'      => wp_create_nonce('plugixa_recipe_frontend'),
                'restNonce'  => wp_create_nonce('wp_rest'),
                'isLoggedIn'     => is_user_logged_in(),
                'loginUrl'       => wp_login_url(get_permalink()),
                'reviewsPerPage'      => (int) ( ( (array) get_option( 'plugixa_recipe_settings', [] ) )['reviews_per_page'] ?? 10 ),
                'reviewHelpfulEnabled' => ! empty( ( (array) get_option( 'plugixa_recipe_settings', [] ) )['review_enable_helpful_votes'] ),
                'i18n'       => [
                    'tooltip' => [
                        'like'       => __('Like this recipe', 'plugixa-recipe'),
                        'likeActive' => __('Unlike this recipe', 'plugixa-recipe'),
                        'save'       => __('Save to favorites', 'plugixa-recipe'),
                        'saveActive' => __('Remove from saved', 'plugixa-recipe'),
                        'loginLike'  => __('Log in to like this recipe', 'plugixa-recipe'),
                        'loginSave'  => __('Log in to save this recipe', 'plugixa-recipe'),
                        'loginLink'  => __('Log in', 'plugixa-recipe'),
                    ],
                ],
            ]);
        }

        // GLightbox for gallery lightbox (conditional).
        $recipe_arr = (array) $recipe;
        if ( ! empty( $recipe_arr['gallery_urls'] ) ) {
            wp_enqueue_style(
                'glightbox',
                PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/glightbox/glightbox.min.css',
                [],
                '3.3.0'
            );
            wp_enqueue_script(
                'glightbox',
                PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/glightbox/glightbox.min.js',
                [],
                '3.3.0',
                true
            );
        }

        // Build template data
        $shortcodes = new Shortcodes();
        $view_config = $this->get_default_view_config();
        $css_vars_style = $shortcodes->build_css_vars($view_config);
        $unique_id = 'pxr-single-' . $recipe_id;

        // Use a wrapper template that calls get_header/get_footer
        $GLOBALS['plugixa_recipe_single_recipe_data'] = [
            'recipe'        => $recipe,
            'view_config'   => $view_config,
            'css_vars_style' => $css_vars_style,
            'unique_id'     => $unique_id,
        ];

        // Return our wrapper template
        return PLUGIXA_RECIPE_PATH . 'templates/single-recipe-wrapper.php';
    }

    /**
     * Build the full recipe object that the single-recipe.php template expects.
     */
    private function build_recipe_object(int $id): ?object {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // Read-through object cache; key = recipe id.
        //
        // The column list is Columns::recipe_public('') written out as a literal, so the
        // string handed to prepare() is a constant, PLUS one extra column: `status`.
        // Frontend\Seo reads status off this same render struct to decide whether to emit a
        // noindex robots tag, and this result feeds the template rather than a REST
        // response, so that column never reaches a client. SqlLiteralTest knows about this
        // one documented extra; adding another needs a change there too.
        $recipes_table = $prefix . 'recipes';
        $recipe = Cache::remember('recipes', 'single|' . $id, function () use ($wpdb, $recipes_table, $id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT id, language_code, translation_group, title, slug,
                    description, excerpt, notes, tips, image_id,
                    gallery, tags, equipment, video_url, servings,
                    servings_unit, yield_amount, yield_unit, prep_time, cook_time,
                    rest_time, total_time, difficulty, cost, season,
                    is_featured, is_editor_pick, display_order, source, source_url,
                    author_name, view_count, rating_average, rating_count, like_count,
                    is_vegetarian, is_vegan, is_gluten_free, is_dairy_free, is_keto,
                    is_low_carb, is_nut_free, is_paleo, is_sugar_free, is_halal,
                    is_kosher, published_at, created_at, status
                  FROM %i
                  WHERE id = %d AND status = \'published\'',
                $recipes_table,
                $id
            ), ARRAY_A);
        });

        if (!$recipe) {
            return null;
        }

        $recipe_id = (int) $recipe['id'];

        // Image
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'large') ?: '';
            $recipe['image_alt'] = get_post_meta((int) $recipe['image_id'], '_wp_attachment_image_alt', true) ?: $recipe['title'];
        } else {
            $recipe['image_url'] = '';
            $recipe['image_alt'] = $recipe['title'];
        }

        // Author
        if (!empty($recipe['author_id'])) {
            $user = get_userdata((int) $recipe['author_id']);
            $recipe['author_name'] = $user ? $user->display_name : '';
        } else {
            $recipe['author_name'] = '';
        }

        // Published date
        $recipe['published_date'] = !empty($recipe['published_at'])
            ? wp_date(get_option('date_format'), strtotime($recipe['published_at']))
            : (!empty($recipe['created_at']) ? wp_date(get_option('date_format'), strtotime($recipe['created_at'])) : '');

        // Rating
        $recipe['rating'] = (float) ($recipe['rating_average'] ?? 0);
        $recipe['rating_count'] = (int) ($recipe['rating_count'] ?? 0);

        // Categories — child rows cached under the owning recipe.
        $recipe['categories'] = Cache::remember('recipes', 'single_categories|' . $recipe_id, function () use ($wpdb, $prefix, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                 INNER JOIN %i rc ON c.id = rc.category_id
                 WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC',
                $prefix . 'categories',
                $prefix . 'recipe_categories',
                $recipe_id
            ), ARRAY_A) ?: [];
        });

        // Cuisines — child rows cached under the owning recipe.
        $cuisines = Cache::remember('recipes', 'single_cuisines|' . $recipe_id, function () use ($wpdb, $prefix, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                 INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                 WHERE rcu.recipe_id = %d',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines',
                $recipe_id
            ), ARRAY_A) ?: [];
        });
        $recipe['cuisines'] = $cuisines;
        $recipe['cuisine'] = !empty($cuisines) ? $cuisines[0]['name'] : '';

        // Ingredient groups (grouped by group_name/group_order) — child rows cached under the recipe.
        $ingredients_table = $prefix . 'ingredients';
        $raw_ingredients = Cache::remember('recipes', 'single_ingredients|' . $recipe_id, function () use ($wpdb, $ingredients_table, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    quantity, unit, name, notes, link_url
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $ingredients_table,
                $recipe_id
            ), ARRAY_A) ?: [];
        });
        $recipe['ingredient_groups'] = $this->group_ingredients($raw_ingredients);

        // Instruction groups (grouped by group_name/group_order) — child rows cached under the recipe.
        $instructions_table = $prefix . 'instructions';
        $raw_instructions = Cache::remember('recipes', 'single_instructions|' . $recipe_id, function () use ($wpdb, $instructions_table, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    title, instruction_text, image_id, video_url, duration
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $instructions_table,
                $recipe_id
            ), ARRAY_A) ?: [];
        });
        $recipe['instruction_groups'] = $this->group_instructions($raw_instructions);

        // Nutrition — child row cached under the owning recipe.
        $nutrition_table = $prefix . 'nutrition';
        $nutrition_row = Cache::remember('recipes', 'single_nutrition|' . $recipe_id, function () use ($wpdb, $nutrition_table, $recipe_id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT id, recipe_id, serving_size, calories, total_fat,
                    saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                    dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                    calcium, iron, potassium, source
                  FROM %i
                  WHERE recipe_id = %d',
                $nutrition_table,
                $recipe_id
            ), ARRAY_A);
        });
        $recipe['nutrition'] = $nutrition_row ? (object) $nutrition_row : null;

        // Reviews (approved only, sorted by settings).
        $plugixa_recipe_settings = (array) get_option( 'plugixa_recipe_settings', [] );
        $sort_key = $plugixa_recipe_settings['reviews_default_sort'] ?? 'newest';

        // ORDER BY is not one identifier here - each option is a different column set with
        // its own directions, and a direction keyword cannot be bound at all. So the sort
        // is a match over five *literal* queries rather than an assembled fragment: the
        // string handed to prepare() is a constant in every arm. $sort_key only chooses
        // which arm runs; it never reaches the SQL. Any unknown value falls to 'newest'.
        $reviews_table = $prefix . 'reviews';
        $recipe['reviews'] = Cache::remember('reviews', 'single|' . $recipe_id . '|' . $sort_key, function () use ($wpdb, $reviews_table, $recipe_id, $sort_key) {
            // The column list is Columns::review_public() written out as a literal, so the
            // string handed to prepare() is a constant. It must stay identical to that
            // allowlist; SqlLiteralTest compares them. There is no LIMIT here (the whole
            // approved set renders), which is why these arms carry no id tiebreaker while
            // their paged twin in Api\FrontendApi does.
            $rows = match ( $sort_key ) {
                'oldest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY created_at ASC',
                    $reviews_table,
                    $recipe_id
                )),
                'highest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY rating DESC, created_at DESC',
                    $reviews_table,
                    $recipe_id
                )),
                'lowest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY rating ASC, created_at DESC',
                    $reviews_table,
                    $recipe_id
                )),
                'helpful' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY helpful_count DESC, created_at DESC',
                    $reviews_table,
                    $recipe_id
                )),
                default => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY created_at DESC',
                    $reviews_table,
                    $recipe_id
                )),
            };

            return $rows ?: [];
        });

        /** This filter is documented in Api\FrontendApi::get_recipe(). */
        $recipe = apply_filters('plugixa_recipe_prepare_recipe_frontend', $recipe, $recipe_id);

        // Diet badges
        $recipe['diet_badges'] = [];
        if (!empty($recipe['is_vegetarian'])) $recipe['diet_badges'][] = __('Vegetarian', 'plugixa-recipe');
        if (!empty($recipe['is_vegan'])) $recipe['diet_badges'][] = __('Vegan', 'plugixa-recipe');
        if (!empty($recipe['is_gluten_free'])) $recipe['diet_badges'][] = __('Gluten-Free', 'plugixa-recipe');
        if (!empty($recipe['is_dairy_free'])) $recipe['diet_badges'][] = __('Dairy-Free', 'plugixa-recipe');
        if (!empty($recipe['is_keto'])) $recipe['diet_badges'][] = __('Keto', 'plugixa-recipe');
        if (!empty($recipe['is_low_carb'])) $recipe['diet_badges'][] = __('Low-Carb', 'plugixa-recipe');

        // Gallery images (decode JSON IDs → URL objects).
        $recipe['gallery_urls'] = [];
        if ( ! empty( $recipe['gallery'] ) ) {
            $gallery_ids = json_decode( $recipe['gallery'], true );
            if ( is_array( $gallery_ids ) ) {
                foreach ( $gallery_ids as $gid ) {
                    $gid       = (int) $gid;
                    $large_url = wp_get_attachment_image_url( $gid, 'large' );
                    if ( ! $large_url ) {
                        continue;
                    }
                    $recipe['gallery_urls'][] = (object) [
                        'id'        => $gid,
                        'url'       => $large_url,
                        'full'      => wp_get_attachment_image_url( $gid, 'full' ) ?: $large_url,
                        'thumbnail' => wp_get_attachment_image_url( $gid, 'thumbnail' ) ?: $large_url,
                        'alt'       => get_post_meta( $gid, '_wp_attachment_image_alt', true ) ?: ( $recipe['title'] ?? '' ),
                    ];
                }
            }
        }

        // Video embed (oEmbed for YouTube/Vimeo, <video> fallback for self-hosted).
        $recipe['video_embed'] = '';
        if ( ! empty( $recipe['video_url'] ) ) {
            $video_ext = strtolower( pathinfo( wp_parse_url( $recipe['video_url'], PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
            if ( in_array( $video_ext, [ 'mp4', 'webm', 'ogg' ], true ) ) {
                $recipe['video_embed'] = sprintf(
                    '<video controls preload="metadata" playsinline><source src="%s" type="video/%s">%s</video>',
                    esc_url( $recipe['video_url'] ),
                    esc_attr( $video_ext ),
                    esc_html__( 'Your browser does not support the video tag.', 'plugixa-recipe' )
                );
            } else {
                $oembed = wp_oembed_get( $recipe['video_url'], [ 'width' => 1200 ] );
                if ( $oembed ) {
                    $recipe['video_embed'] = $oembed;
                }
            }
        }

        // URLs
        $slug = sanitize_title($recipe['title'] ?? '');
        $recipe['permalink'] = plugixa_recipe_permalink((int) $recipe_id, $slug);
        $recipe['print_url'] = home_url("/recipe-print/{$recipe_id}/?auto=1");

        // Favorite status (per-user state: not cached).
        $recipe['is_favorited'] = false;
        if (is_user_logged_in()) {
            $recipe['is_favorited'] = (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
                $prefix . 'favorites',
                get_current_user_id(),
                $recipe_id
            ));
        }

        // Increment view count.
        // Counter increment only: deliberately does NOT flush the cache — this runs on every
        // render, so flushing here would invalidate the whole cache on every page view. Cached
        // lists therefore carry eventually-consistent view counts.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET view_count = view_count + 1 WHERE id = %d',
            $prefix . 'recipes',
            $recipe_id
        ));

        return (object) $recipe;
    }

    /**
     * Group flat ingredient rows into grouped structure with objects.
     *
     * Returns array of objects: { name: string, items: [ { amount, unit, name, text, notes } ] }
     */
    private function group_ingredients(array $rows): array {
        $groups = [];
        $current_group = null;

        foreach ($rows as $row) {
            $group_name = $row['group_name'] ?? '';

            if ($current_group === null || $current_group->name !== $group_name) {
                $current_group = (object) [
                    'name'  => $group_name,
                    'items' => [],
                ];
                $groups[] = $current_group;
            }

            $current_group->items[] = (object) [
                'amount' => $row['quantity'] ?? '',
                'unit'   => $row['unit'] ?? '',
                'name'   => $row['name'] ?? '',
                'text'   => trim(($row['quantity'] ?? '') . ' ' . ($row['unit'] ?? '') . ' ' . ($row['name'] ?? '')),
                'notes'  => $row['notes'] ?? '',
            ];
        }

        return $groups;
    }

    /**
     * Group flat instruction rows into grouped structure with objects.
     *
     * Returns array of objects: { name: string, steps: [ { text, image_url, tip } ] }
     */
    private function group_instructions(array $rows): array {
        $groups = [];
        $current_group = null;

        foreach ($rows as $row) {
            $group_name = $row['group_name'] ?? '';

            if ($current_group === null || $current_group->name !== $group_name) {
                $current_group = (object) [
                    'name'  => $group_name,
                    'steps' => [],
                ];
                $groups[] = $current_group;
            }

            $image_url = '';
            if (!empty($row['image_id'])) {
                $image_url = wp_get_attachment_image_url((int) $row['image_id'], 'medium') ?: '';
            }

            $current_group->steps[] = (object) [
                'text'      => $row['instruction_text'] ?? '',
                'image_url' => $image_url,
                'tip'       => $row['tip'] ?? '',
            ];
        }

        return $groups;
    }

    /**
     * Get default view config from the database.
     */
    private function get_default_view_config(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_views';

        $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM %i WHERE is_default = 1 LIMIT 1', $table));
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM %i ORDER BY id ASC LIMIT 1', $table));
        }

        if ($row && !empty($row->config)) {
            $config = json_decode($row->config, true);
            return is_array($config) ? $config : [];
        }

        return [];
    }

}
