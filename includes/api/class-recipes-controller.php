<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the admin recipe CRUD. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write, bulk and duplicate paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$filter_args` / `...$args` list queries report the wrong count. Those values are passed in one pre-built positional array (the shared WHERE block's placeholder order IS that array's order) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Recipes REST Controller
 */
class RecipesController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'recipes';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update_status'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-action', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_action'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'duplicate_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get recipes list with filters, pagination, sorting, and pivot joins.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix  = $wpdb->prefix . 'plugixa_recipe_';
        $filters = $this->get_filters($request);
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // Search filter
        $search = !empty($filters['search'])
            ? '%' . $wpdb->esc_like($filters['search']) . '%'
            : '';

        // Category filter. Three states: no filter, a specific category, or "uncategorized".
        // Each becomes its own gated clause below - the specific-id case a gated EXISTS, the
        // uncategorized case a gated NOT EXISTS (which is what the old LEFT JOIN ... IS NULL
        // expressed). At most one of the two gates is ever 1.
        $cat_id   = 0;
        $uncat    = 0;
        if (!empty($filters['category_id'])) {
            if ($filters['category_id'] === 'uncategorized' || $filters['category_id'] === '0') {
                $uncat = 1;
            } else {
                $cat_id = (int) $filters['category_id'];
            }
        }

        $cuisine_id = !empty($filters['cuisine_id']) ? (int) $filters['cuisine_id'] : 0;
        $course_id  = !empty($filters['course_id']) ? (int) $filters['course_id'] : 0;
        $difficulty = !empty($filters['difficulty']) ? (string) $filters['difficulty'] : '';

        // is_featured is a tri-state: absent = no filter, else match the given 0/1 exactly.
        $has_featured = isset($filters['is_featured']) && $filters['is_featured'] !== '';
        $featured_val = $has_featured ? (int) $filters['is_featured'] : 0;

        $min_rating = !empty($filters['min_rating']) ? (float) $filters['min_rating'] : 0.0;

        // Diet flags, each also tri-state (a 0 means "show only recipes NOT flagged").
        $diet_flags = ['is_vegetarian', 'is_vegan', 'is_gluten_free', 'is_dairy_free', 'is_keto', 'is_low_carb'];
        $diet_args  = [];
        foreach ($diet_flags as $flag) {
            $set = isset($filters[$flag]) && $filters[$flag] !== '';
            $diet_args[] = $set ? 1 : 0;
            $diet_args[] = $set ? (int) $filters[$flag] : 0;
        }

        // Allowed sort columns
        $allowed_orderby = ['title', 'created_at', 'modified_at', 'display_order', 'rating_average', 'view_count', 'total_time', 'published_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound values for the shared WHERE block, in placeholder order. %i identifiers and
        // %s/%d values share one positional list, so table names sit inline.
        $filter_args = array_merge([
            $prefix . 'recipes',
            $search !== '' ? 1 : 0, $search, $search, $search,
            !empty($filters['status']) ? 1 : 0, (string) ($filters['status'] ?? ''),
            $cat_id ? 1 : 0, $prefix . 'recipe_categories', $cat_id,
            $uncat, $prefix . 'recipe_categories',
            $cuisine_id ? 1 : 0, $prefix . 'recipe_cuisines', $cuisine_id,
            $course_id ? 1 : 0, $prefix . 'recipe_courses', $course_id,
            $difficulty !== '' ? 1 : 0, $difficulty,
            $has_featured ? 1 : 0, $featured_val,
            $min_rating > 0 ? 1 : 0, $min_rating,
        ], $diet_args);

        // Count query. Cache key is a stable digest of the bound values - it must NOT embed
        // the SQL text, which now lives inline in prepare().
        // The WHERE block below is repeated verbatim in the two ORDER BY variants further
        // down. It used to be a private class constant concatenated in at each call site,
        // which is more compact and cannot drift - but WordPress.org's review reads the
        // argument to prepare(), and a constant is not a literal to that reading. So the
        // block is written out three times on purpose; if you change one, change all three,
        // and keep the placeholder order in step with the $filter_args the callers build.
        //
        // Every filter is a gate placeholder: bind 0 and the clause is constant-true, so the
        // SQL text never depends on which filters the request set. The two taxonomy filters
        // and the "uncategorized" case are gated EXISTS / NOT EXISTS subqueries rather than
        // optional JOINs, because a JOIN is structure and cannot be gated away by a
        // placeholder (and EXISTS needs no DISTINCT to undo a JOIN's row multiplication).
        $total = (int) Cache::remember('recipes', 'count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i r WHERE 1=1
        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s OR r.author_name LIKE %s) )
        AND ( %d = 0 OR r.status = %s )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
        AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i rc_n WHERE rc_n.recipe_id = r.id) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco_f WHERE rco_f.recipe_id = r.id AND rco_f.course_id = %d) )
        AND ( %d = 0 OR r.difficulty = %s )
        AND ( %d = 0 OR r.is_featured = %d )
        AND ( %d = 0 OR r.rating_average >= %f )
        AND ( %d = 0 OR r.is_vegetarian = %d )
        AND ( %d = 0 OR r.is_vegan = %d )
        AND ( %d = 0 OR r.is_gluten_free = %d )
        AND ( %d = 0 OR r.is_dairy_free = %d )
        AND ( %d = 0 OR r.is_keto = %d )
        AND ( %d = 0 OR r.is_low_carb = %d )',
                ...$filter_args
            ));
        });

        // Main query. ORDER BY column bound with the %i identifier placeholder (WP 6.2+)
        // from the $allowed_orderby list above; a direction keyword cannot be bound, so
        // ASC/DESC is two literal queries. r.id is a deterministic tiebreaker so paging
        // stays stable when the sort column has ties.
        $args = array_merge($filter_args, [$orderby, $pagination['limit'], $pagination['offset']]);

        $recipes = Cache::remember('recipes', 'items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.* FROM %i r WHERE 1=1
        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s OR r.author_name LIKE %s) )
        AND ( %d = 0 OR r.status = %s )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
        AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i rc_n WHERE rc_n.recipe_id = r.id) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco_f WHERE rco_f.recipe_id = r.id AND rco_f.course_id = %d) )
        AND ( %d = 0 OR r.difficulty = %s )
        AND ( %d = 0 OR r.is_featured = %d )
        AND ( %d = 0 OR r.rating_average >= %f )
        AND ( %d = 0 OR r.is_vegetarian = %d )
        AND ( %d = 0 OR r.is_vegan = %d )
        AND ( %d = 0 OR r.is_gluten_free = %d )
        AND ( %d = 0 OR r.is_dairy_free = %d )
        AND ( %d = 0 OR r.is_keto = %d )
        AND ( %d = 0 OR r.is_low_carb = %d )
                      ORDER BY r.%i ASC, r.id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.* FROM %i r WHERE 1=1
        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s OR r.author_name LIKE %s) )
        AND ( %d = 0 OR r.status = %s )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
        AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i rc_n WHERE rc_n.recipe_id = r.id) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco_f WHERE rco_f.recipe_id = r.id AND rco_f.course_id = %d) )
        AND ( %d = 0 OR r.difficulty = %s )
        AND ( %d = 0 OR r.is_featured = %d )
        AND ( %d = 0 OR r.rating_average >= %f )
        AND ( %d = 0 OR r.is_vegetarian = %d )
        AND ( %d = 0 OR r.is_vegan = %d )
        AND ( %d = 0 OR r.is_gluten_free = %d )
        AND ( %d = 0 OR r.is_dairy_free = %d )
        AND ( %d = 0 OR r.is_keto = %d )
        AND ( %d = 0 OR r.is_low_carb = %d )
                      ORDER BY r.%i DESC, r.id DESC
                      LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A);
        });

        // Enrich each recipe with pivot data and image
        foreach ($recipes as &$recipe) {
            $recipe = $this->enrich_recipe_list_item($recipe);
        }

        return $this->success([
            'items' => $recipes,
            'total' => $total,
            'limit' => $pagination['limit'],
            'offset' => $pagination['offset'],
        ]);
    }

    /**
     * Get single recipe with full relations.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        // Read-through cached record; the enrichment below (image URLs, taxonomy joins)
        // stays live so a category/cuisine/course rename is never served stale.
        $recipe = Cache::remember('recipes', "item:{$id}", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'recipes', $id),
                ARRAY_A
            );
        });

        if (!$recipe) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Image
        if ($recipe['image_id']) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'large');
            $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
        }

        // Gallery
        if (!empty($recipe['gallery'])) {
            $gallery_ids = json_decode($recipe['gallery'], true);
            $recipe['gallery_urls'] = [];
            $recipe['gallery_ids']  = [];
            if (is_array($gallery_ids)) {
                $recipe['gallery_ids'] = array_map('intval', $gallery_ids);
                foreach ($gallery_ids as $gid) {
                    $recipe['gallery_urls'][] = [
                        'id'        => (int) $gid,
                        'url'       => wp_get_attachment_image_url((int) $gid, 'large'),
                        'thumbnail' => wp_get_attachment_image_url((int) $gid, 'thumbnail'),
                    ];
                }
            }
        }

        // Decode JSON array fields
        $recipe['tags'] = !empty($recipe['tags']) ? json_decode($recipe['tags'], true) : [];
        $recipe['equipment'] = !empty($recipe['equipment']) ? json_decode($recipe['equipment'], true) : [];

        // Categories
        $recipe['categories'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.* FROM %i c
                INNER JOIN %i rc ON c.id = rc.category_id
                WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC, c.id ASC',
                $prefix . 'categories',
                $prefix . 'recipe_categories',
                $id
            ),
            ARRAY_A
        );

        // Cuisines
        $recipe['cuisines'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT cu.* FROM %i cu
                INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE rcu.recipe_id = %d ORDER BY rcu.display_order ASC, cu.id ASC',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines',
                $id
            ),
            ARRAY_A
        );

        // Courses
        $recipe['courses'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT co.* FROM %i co
                INNER JOIN %i rco ON co.id = rco.course_id
                WHERE rco.recipe_id = %d',
                $prefix . 'courses',
                $prefix . 'recipe_courses',
                $id
            ),
            ARRAY_A
        );

        // Scalar IDs for form binding
        $recipe['category_id']  = !empty($recipe['categories']) ? (int) $recipe['categories'][0]['id'] : 0;
        $recipe['category_ids'] = array_map('intval', array_column($recipe['categories'], 'id'));
        $recipe['cuisine_id']   = !empty($recipe['cuisines'])  ? (int) $recipe['cuisines'][0]['id']  : 0;
        $recipe['course_id']    = !empty($recipe['courses'])   ? (int) $recipe['courses'][0]['id']   : 0;

        // Ingredients (child rows of the recipe -> cached under the 'recipes' entity)
        $recipe['ingredients'] = Cache::remember('recipes', "item:{$id}:ingredients", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
                    $prefix . 'ingredients',
                    $id
                ),
                ARRAY_A
            );
        });

        // Instructions (child rows of the recipe -> cached under the 'recipes' entity)
        $recipe['instructions'] = Cache::remember('recipes', "item:{$id}:instructions", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
                    $prefix . 'instructions',
                    $id
                ),
                ARRAY_A
            );
        });
        foreach ($recipe['instructions'] as &$inst) {
            $inst['text']  = $inst['instruction_text'];
            $inst['title'] = $inst['title'] ?? '';
            if ($inst['image_id']) {
                $inst['image_url'] = wp_get_attachment_image_url((int) $inst['image_id'], 'medium');
            }
        }

        // Nutrition (child row of the recipe -> cached under the 'recipes' entity)
        $recipe['nutrition'] = Cache::remember('recipes', "item:{$id}:nutrition", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE recipe_id = %d', $prefix . 'nutrition', $id),
                ARRAY_A
            );
        });

        /**
         * Filter the recipe returned to the admin app.
         *
         * The premium overlay attaches the data belonging to its own features (the
         * recipe's custom-field values). The free build ships none of that machinery, so
         * nothing is attached and the editor simply renders no extra tabs.
         *
         * @param array<string,mixed> $recipe
         * @param int                 $id
         */
        $recipe = apply_filters('plugixa_recipe_prepare_recipe_admin', $recipe, $id);

        return $this->success($recipe);
    }

    /**
     * Create a new recipe.
     */
    public function create_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $now    = current_time('mysql');

        $title = sanitize_text_field($params['title'] ?? '');
        if (empty($title)) {
            return $this->error(__('Title is required.', 'plugixa-recipe'));
        }

        $description_check = wp_kses_post($params['description'] ?? '');
        if (empty(trim(wp_strip_all_tags($description_check)))) {
            return $this->error(__('Description is required.', 'plugixa-recipe'));
        }

        $image_id_check = !empty($params['image_id']) ? (int) $params['image_id'] : 0;
        if ($image_id_check < 1) {
            return $this->error(__('Recipe image is required.', 'plugixa-recipe'));
        }

        $category_ids_check = $params['category_ids'] ?? [];
        if (empty($category_ids_check) || !is_array($category_ids_check) || count(array_filter($category_ids_check)) < 1) {
            return $this->error(__('At least one category is required.', 'plugixa-recipe'));
        }

        $difficulty_check = sanitize_text_field($params['difficulty'] ?? '');
        if (empty($difficulty_check) || !in_array($difficulty_check, ['easy', 'medium', 'hard', 'expert'], true)) {
            return $this->error(__('Difficulty is required.', 'plugixa-recipe'));
        }

        $servings_check = isset($params['servings']) ? (int) $params['servings'] : 0;
        if ($servings_check < 1) {
            return $this->error(__('Servings must be at least 1.', 'plugixa-recipe'));
        }

        if (!isset($params['prep_time']) || $params['prep_time'] === '' || $params['prep_time'] === null) {
            return $this->error(__('Prep time is required.', 'plugixa-recipe'));
        }

        if (!isset($params['cook_time']) || $params['cook_time'] === '' || $params['cook_time'] === null) {
            return $this->error(__('Cook time is required.', 'plugixa-recipe'));
        }

        $ingredients_check = $params['ingredients'] ?? [];
        $has_ingredient = false;
        if (is_array($ingredients_check)) {
            foreach ($ingredients_check as $ing) {
                if (!empty(trim($ing['name'] ?? ''))) { $has_ingredient = true; break; }
            }
        }
        if (!$has_ingredient) {
            return $this->error(__('At least one ingredient is required.', 'plugixa-recipe'));
        }

        $instructions_check = $params['instructions'] ?? [];
        $has_instruction = false;
        if (is_array($instructions_check)) {
            foreach ($instructions_check as $inst) {
                // Accept every shape the write path below accepts (see the insert's
                // 'instruction_text' => $inst['instruction_text'] ?? $inst['text']). Checking
                // only one key made this gate stricter than the writer behind it, so a payload
                // using the column's own name was rejected as "no instructions" even though it
                // would have saved correctly.
                $text = is_string($inst) ? $inst : ($inst['instruction_text'] ?? $inst['text'] ?? '');
                if ('' !== trim($text)) { $has_instruction = true; break; }
            }
        }
        if (!$has_instruction) {
            return $this->error(__('At least one instruction is required.', 'plugixa-recipe'));
        }

        $data = [
            'title'            => $title,
            'slug'             => sanitize_title($params['slug'] ?? '') ?: sanitize_title($title),
            'description'      => wp_kses_post($params['description'] ?? ''),
            'excerpt'          => wp_kses_post($params['excerpt'] ?? ''),
            'notes'            => wp_kses_post($params['notes'] ?? ''),
            'tips'             => wp_kses_post($params['tips'] ?? ''),
            'image_id'         => !empty($params['image_id']) ? (int) $params['image_id'] : null,
            'gallery'          => !empty($params['gallery']) ? wp_json_encode($params['gallery']) : null,
            'tags'             => !empty($params['tags']) ? wp_json_encode($params['tags']) : null,
            'equipment'        => !empty($params['equipment']) ? wp_json_encode($params['equipment']) : null,
            'video_url'        => esc_url_raw($params['video_url'] ?? ''),
            'servings'         => (int) ($params['servings'] ?? 4),
            'servings_unit'    => sanitize_text_field($params['servings_unit'] ?? 'servings'),
            'yield_amount'     => sanitize_text_field($params['yield_amount'] ?? ''),
            'yield_unit'       => sanitize_text_field($params['yield_unit'] ?? ''),
            'prep_time'        => isset($params['prep_time']) ? (int) $params['prep_time'] : null,
            'cook_time'        => isset($params['cook_time']) ? (int) $params['cook_time'] : null,
            'rest_time'        => isset($params['rest_time']) ? (int) $params['rest_time'] : null,
            'difficulty'       => sanitize_text_field($params['difficulty'] ?? 'medium'),
            'cost'             => sanitize_text_field($params['cost'] ?? 'moderate'),
            'season'           => sanitize_text_field($params['season'] ?? ''),
            'status'           => sanitize_text_field($params['status'] ?? 'draft'),
            'is_featured'      => (int) ($params['is_featured'] ?? 0),
            'is_editor_pick'   => (int) ($params['is_editor_pick'] ?? 0),
            'display_order'    => (int) ($params['display_order'] ?? 0),
            'source'           => sanitize_text_field($params['source'] ?? ''),
            'source_url'       => esc_url_raw($params['source_url'] ?? ''),
            'author_name'      => sanitize_text_field($params['author_name'] ?? ''),
            'is_vegetarian'    => (int) ($params['is_vegetarian'] ?? 0),
            'is_vegan'         => (int) ($params['is_vegan'] ?? 0),
            'is_gluten_free'   => (int) ($params['is_gluten_free'] ?? 0),
            'is_dairy_free'    => (int) ($params['is_dairy_free'] ?? 0),
            'is_keto'          => (int) ($params['is_keto'] ?? 0),
            'is_low_carb'      => (int) ($params['is_low_carb'] ?? 0),
            'is_nut_free'      => (int) ($params['is_nut_free'] ?? 0),
            'is_paleo'         => (int) ($params['is_paleo'] ?? 0),
            'is_sugar_free'    => (int) ($params['is_sugar_free'] ?? 0),
            'is_halal'         => (int) ($params['is_halal'] ?? 0),
            'is_kosher'        => (int) ($params['is_kosher'] ?? 0),
            'language_code'    => sanitize_text_field($params['language_code'] ?? ''),
            'translation_group' => sanitize_text_field($params['translation_group'] ?? ''),
            'seo_title'        => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : null,
            'seo_description'  => isset($params['seo_description']) ? sanitize_textarea_field($params['seo_description']) : null,
            'seo_keywords'     => isset($params['seo_keywords']) ? sanitize_text_field($params['seo_keywords']) : null,
            'created_at'       => $now,
            'created_by'       => get_current_user_id(),
        ];

        if ($data['status'] === 'published' && empty($data['published_at'])) {
            $data['published_at'] = $now;
        }

        // Ensure unique slug
        $data['slug'] = $this->ensure_unique_slug($data['slug'], $prefix . 'recipes');

        $wpdb->insert($prefix . 'recipes', $data);
        $recipe_id = $wpdb->insert_id;

        if (!$recipe_id) {
            return $this->error(__('Failed to create recipe.', 'plugixa-recipe'), 500);
        }

        // Sync pivot tables
        $this->sync_categories($recipe_id, $params['category_ids'] ?? []);
        $this->sync_cuisines($recipe_id, $params['cuisine_ids'] ?? []);
        $this->sync_courses($recipe_id, $params['course_ids'] ?? []);

        // Save ingredients
        if (!empty($params['ingredients'])) {
            $this->save_ingredients($recipe_id, $params['ingredients']);
        }

        // Save instructions
        if (!empty($params['instructions'])) {
            $this->save_instructions($recipe_id, $params['instructions']);
        }

        // Save nutrition
        if (!empty($params['nutrition'])) {
            $this->save_nutrition($recipe_id, $params['nutrition']);
        }

        /**
         * Fires after a recipe is created or updated, with the full request payload.
         *
         * The premium overlay hooks this to persist its own per-recipe data (custom-field
         * values) and to run the SEO analysis when that is switched on. The free build has
         * nothing hooked here, so it neither stores nor analyses any of it.
         *
         * @param int                 $recipe_id
         * @param array<string,mixed> $params
         */
        do_action('plugixa_recipe_save_recipe', $recipe_id, $params);

        // Recipe + pivot rows changed -> also invalidates category/cuisine/course counts.
        // Must run BEFORE get_item() below so the response is read fresh, not from cache.
        Cache::flush('recipes', 'categories', 'cuisines', 'courses');

        // Return the full recipe
        $request->set_param('id', $recipe_id);
        return $this->get_item($request);
    }

    /**
     * Update a recipe.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id     = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $now    = current_time('mysql');

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'recipes', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        $text_fields = ['title', 'slug', 'difficulty', 'cost', 'season', 'status', 'source', 'source_url', 'author_name', 'servings_unit', 'yield_amount', 'yield_unit', 'video_url', 'language_code', 'translation_group', 'seo_title', 'seo_keywords'];
        foreach ($text_fields as $field) {
            if (isset($params[$field])) {
                if (in_array($field, ['source_url', 'video_url'])) {
                    $data[$field] = esc_url_raw($params[$field]);
                } else {
                    $data[$field] = sanitize_text_field($params[$field]);
                }
            }
        }

        $html_fields = ['description', 'excerpt', 'notes', 'tips', 'seo_description'];
        foreach ($html_fields as $field) {
            if (isset($params[$field])) {
                $data[$field] = wp_kses_post($params[$field]);
            }
        }

        $int_fields = ['image_id', 'servings', 'prep_time', 'cook_time', 'rest_time', 'is_featured', 'is_editor_pick', 'display_order', 'is_vegetarian', 'is_vegan', 'is_gluten_free', 'is_dairy_free', 'is_keto', 'is_low_carb', 'is_nut_free', 'is_paleo', 'is_sugar_free', 'is_halal', 'is_kosher'];
        foreach ($int_fields as $field) {
            if (isset($params[$field])) {
                $data[$field] = $params[$field] !== null && $params[$field] !== '' ? (int) $params[$field] : null;
            }
        }

        if (isset($params['gallery'])) {
            $data['gallery'] = !empty($params['gallery']) ? wp_json_encode($params['gallery']) : null;
        }
        if (isset($params['tags'])) {
            $data['tags'] = !empty($params['tags']) ? wp_json_encode($params['tags']) : null;
        }
        if (isset($params['equipment'])) {
            $data['equipment'] = !empty($params['equipment']) ? wp_json_encode($params['equipment']) : null;
        }

        // Handle published_at on status change
        if (isset($data['status']) && $data['status'] === 'published' && $existing['status'] !== 'published') {
            $data['published_at'] = $now;
        }

        // Normalize slug: always slug-sanitized, falling back to the (new or
        // existing) title when cleared so a recipe never loses its permalink.
        if (isset($data['slug'])) {
            $data['slug'] = sanitize_title($data['slug']);
            if ($data['slug'] === '') {
                $data['slug'] = sanitize_title($data['title'] ?? $existing['title']);
            }
        }

        // Ensure unique slug if changed
        if (isset($data['slug']) && $data['slug'] !== $existing['slug']) {
            $data['slug'] = $this->ensure_unique_slug($data['slug'], $prefix . 'recipes', $id);
        }

        $data['modified_at'] = $now;
        $data['modified_by'] = get_current_user_id();

        $wpdb->update($prefix . 'recipes', $data, ['id' => $id]);

        // Sync pivot tables
        if (isset($params['category_ids'])) {
            $this->sync_categories($id, $params['category_ids']);
        }
        if (isset($params['cuisine_ids'])) {
            $this->sync_cuisines($id, $params['cuisine_ids']);
        }
        if (isset($params['course_ids'])) {
            $this->sync_courses($id, $params['course_ids']);
        }

        // Save ingredients
        if (isset($params['ingredients'])) {
            $this->save_ingredients($id, $params['ingredients']);
        }

        // Save instructions
        if (isset($params['instructions'])) {
            $this->save_instructions($id, $params['instructions']);
        }

        // Save nutrition
        if (isset($params['nutrition'])) {
            $this->save_nutrition($id, $params['nutrition']);
        }

        /** This filter is documented in create_item(). */
        do_action('plugixa_recipe_save_recipe', $id, $params);

        // Recipe + pivot rows changed -> also invalidates category/cuisine/course counts.
        // Must run BEFORE get_item() below so the response is read fresh, not from cache.
        Cache::flush('recipes', 'categories', 'cuisines', 'courses');

        return $this->get_item($request);
    }

    /**
     * Delete a recipe and all associated data.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Delete related data. Every free table keyed by recipe_id belongs here AND in
        // bulk_delete(): `likes` was missing from both until 1.0.3. DataIntegrityTest reads
        // the recipe-keyed tables from the schema, so the next one fails a test instead.
        $wpdb->delete($prefix . 'recipe_categories', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'recipe_cuisines', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'recipe_courses', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'ingredients', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'instructions', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'nutrition', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'reviews', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'favorites', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'likes', ['recipe_id' => $id]);
        $wpdb->delete($prefix . 'collection_recipes', ['recipe_id' => $id]);

        // Shopping-list items belong to the shopper, not the recipe: detach, never delete.
        // The list reads the recipe through a LEFT JOIN, so the item stays and only loses its
        // recipe title. Left dangling instead, the id could show ANOTHER recipe's title on a
        // server that hands a deleted id out again (InnoDB before MySQL 8, after a restart).
        $wpdb->update($prefix . 'shopping_list_items', ['recipe_id' => null], ['recipe_id' => $id]);

        /**
         * Fires before a recipe row is deleted, while its id is still valid.
         *
         * The premium overlay hooks this to clean up the rows it owns in its own tables.
         * The free build has none, so nothing is hooked.
         *
         * @param int $id
         */
        do_action('plugixa_recipe_delete_recipe', $id);

        $wpdb->delete($prefix . 'recipes', ['id' => $id]);

        // Recipe + pivot rows gone (category/cuisine/course counts change), the recipe's
        // reviews, favorites and collection links were removed above, and any shopping-list
        // item that pointed at it was detached.
        Cache::flush('recipes', 'categories', 'cuisines', 'courses', 'reviews', 'favorites', 'collections', 'shopping_lists');

        return $this->success(null, __('Recipe deleted.', 'plugixa-recipe'));
    }

    /**
     * Update recipe status.
     */
    public function update_status($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $status = sanitize_text_field($params['status'] ?? '');

        $allowed = ['draft', 'pending', 'published', 'archived'];
        if (!in_array($status, $allowed)) {
            return $this->error(__('Invalid status.', 'plugixa-recipe'));
        }

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'recipes', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $data = [
            'status'      => $status,
            'modified_at' => current_time('mysql'),
            'modified_by' => get_current_user_id(),
        ];

        if ($status === 'published' && $existing['status'] !== 'published') {
            $data['published_at'] = current_time('mysql');
        }

        $wpdb->update($prefix . 'recipes', $data, ['id' => $id]);

        // Status/published_at only; no pivot row changes, so the ADMIN taxonomy counts stand.
        // The PUBLIC counts do move - the widgets and public collections count published
        // recipes only - and this flush reaches them because they are cached against
        // 'recipes' as well as their own entity (see Cache::remember()).
        Cache::flush('recipes');

        return $this->success(['id' => $id, 'status' => $status], __('Status updated.', 'plugixa-recipe'));
    }

    /**
     * Reorder recipes.
     */
    public function reorder($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $items  = $params['items'] ?? [];
        $category_id = $params['category_id'] ?? null;

        if (empty($items) || !is_array($items)) {
            return $this->error(__('Items are required.', 'plugixa-recipe'));
        }

        if ($category_id) {
            // Per-category ordering via pivot table
            foreach ($items as $item) {
                $wpdb->update(
                    $prefix . 'recipe_categories',
                    ['display_order' => (int) $item['display_order']],
                    ['recipe_id' => (int) $item['id'], 'category_id' => (int) $category_id]
                );
            }
        } else {
            foreach ($items as $item) {
                $wpdb->update(
                    $prefix . 'recipes',
                    ['display_order' => (int) $item['display_order']],
                    ['id' => (int) $item['id']]
                );
            }
        }

        // Ordering changed (recipe display_order, or the per-category pivot order).
        Cache::flush('recipes');

        return $this->success(null, __('Order updated.', 'plugixa-recipe'));
    }

    /**
     * Bulk delete recipes.
     */
    public function bulk_delete($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids    = array_map('intval', $params['ids'] ?? []);

        if (empty($ids)) {
            return $this->error(__('No IDs provided.', 'plugixa-recipe'));
        }

        // Delete related data for all IDs. Keep this list in step with delete_item()'s.
        $tables = ['recipe_categories', 'recipe_cuisines', 'recipe_courses', 'ingredients', 'instructions', 'nutrition', 'reviews', 'favorites', 'likes', 'collection_recipes'];

        /**
         * Filter the child tables cascaded when recipes are deleted.
         *
         * Every table here is keyed by recipe_id. The premium overlay appends the tables
         * it owns; the free build has none.
         *
         * @param string[] $tables Table names, without the plugin prefix.
         */
        $tables = apply_filters('plugixa_recipe_recipe_child_tables', $tables);

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
        // IN (...), so each statement stays a constant literal for any selection size.
        // $ids is already array_map('intval', ...) at the top of this method.
        $id_list = implode(',', $ids);

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE FIND_IN_SET(recipe_id, %s)',
                $prefix . $table,
                $id_list
            ));
        }

        // Detach shopping-list items rather than delete them - see delete_item().
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET recipe_id = NULL WHERE FIND_IN_SET(recipe_id, %s)',
            $prefix . 'shopping_list_items',
            $id_list
        ));

        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s)',
            $prefix . 'recipes',
            $id_list
        ));

        // Recipes + pivot rows gone (category/cuisine/course counts change), the deleted
        // recipes' reviews, favorites and collection links were removed above, and their
        // shopping-list items were detached.
        Cache::flush('recipes', 'categories', 'cuisines', 'courses', 'reviews', 'favorites', 'collections', 'shopping_lists');

        return $this->success(['deleted' => $deleted], __('Recipes deleted.', 'plugixa-recipe'));
    }

    /**
     * Bulk action (publish, draft, feature, unfeature).
     */
    public function bulk_action($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids    = array_map('intval', $params['ids'] ?? []);
        $action = sanitize_text_field($params['action'] ?? '');

        if (empty($ids) || empty($action)) {
            return $this->error(__('IDs and action are required.', 'plugixa-recipe'));
        }

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
        // IN (...), so each statement below stays a constant literal. $ids is already
        // array_map('intval', ...) above.
        $id_list = implode(',', $ids);
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        switch ($action) {
            case 'publish':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET status = %s, published_at = COALESCE(published_at, %s), modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $prefix . 'recipes', 'published', $now, $now, $user_id, $id_list
                ));
                break;

            case 'draft':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET status = %s, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $prefix . 'recipes', 'draft', $now, $user_id, $id_list
                ));
                break;

            case 'feature':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET is_featured = 1, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $prefix . 'recipes', $now, $user_id, $id_list
                ));
                break;

            case 'unfeature':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET is_featured = 0, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $prefix . 'recipes', $now, $user_id, $id_list
                ));
                break;

            default:
                return $this->error(__('Invalid action.', 'plugixa-recipe'));
        }

        // Status / is_featured only; pivot rows (and therefore taxonomy counts) are untouched.
        Cache::flush('recipes');

        /* translators: %s: the bulk action that was applied (e.g. publish, draft, feature). */
        return $this->success(null, sprintf(__('Bulk %s completed.', 'plugixa-recipe'), $action));
    }

    /**
     * Duplicate a recipe with all related data.
     */
    public function duplicate_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $recipe = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'recipes', $id), ARRAY_A);
        if (!$recipe) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Remove auto-generated / unique fields
        unset($recipe['id'], $recipe['total_time'], $recipe['view_count'], $recipe['rating_average'], $recipe['rating_count'], $recipe['seo_score'], $recipe['published_at']);

        $recipe['title']       = $recipe['title'] . ' (Copy)';
        $recipe['slug']        = $this->ensure_unique_slug(sanitize_title($recipe['title']), $prefix . 'recipes');
        $recipe['status']      = 'draft';
        $recipe['is_featured'] = 0;
        $recipe['created_at']  = $now;
        $recipe['created_by']  = $user_id;
        $recipe['modified_at'] = null;
        $recipe['modified_by'] = null;

        $wpdb->insert($prefix . 'recipes', $recipe);
        $new_id = $wpdb->insert_id;

        if (!$new_id) {
            return $this->error(__('Failed to duplicate recipe.', 'plugixa-recipe'), 500);
        }

        // Copy categories pivot
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, category_id, display_order)
            SELECT %d, category_id, display_order FROM %i WHERE recipe_id = %d',
            $prefix . 'recipe_categories',
            $new_id,
            $prefix . 'recipe_categories',
            $id
        ));

        // Copy cuisines pivot
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, cuisine_id, display_order)
            SELECT %d, cuisine_id, display_order FROM %i WHERE recipe_id = %d',
            $prefix . 'recipe_cuisines',
            $new_id,
            $prefix . 'recipe_cuisines',
            $id
        ));

        // Copy courses pivot
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, course_id)
            SELECT %d, course_id FROM %i WHERE recipe_id = %d',
            $prefix . 'recipe_courses',
            $new_id,
            $prefix . 'recipe_courses',
            $id
        ));

        // Copy ingredients
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, quantity, unit, name, notes, link_url)
            SELECT %d, group_name, group_order, sort_order, quantity, unit, name, notes, link_url FROM %i WHERE recipe_id = %d',
            $prefix . 'ingredients',
            $new_id,
            $prefix . 'ingredients',
            $id
        ));

        // Copy instructions
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, title, instruction_text, image_id, video_url, duration)
            SELECT %d, group_name, group_order, sort_order, title, instruction_text, image_id, video_url, duration FROM %i WHERE recipe_id = %d',
            $prefix . 'instructions',
            $new_id,
            $prefix . 'instructions',
            $id
        ));

        // Copy nutrition
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source)
            SELECT %d, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source FROM %i WHERE recipe_id = %d',
            $prefix . 'nutrition',
            $new_id,
            $prefix . 'nutrition',
            $id
        ));

        /**
         * Fires after a recipe has been duplicated, once its children have been copied.
         *
         * The premium overlay hooks this to copy the rows it owns in its own tables.
         *
         * @param int $new_id The new (duplicated) recipe id.
         * @param int $id     The recipe it was copied from.
         */
        do_action('plugixa_recipe_duplicate_recipe', $new_id, $id);

        // New recipe + copied pivot rows -> also invalidates category/cuisine/course counts.
        // Must run BEFORE get_item() below so the response is read fresh, not from cache.
        Cache::flush('recipes', 'categories', 'cuisines', 'courses');

        $request->set_param('id', $new_id);
        return $this->get_item($request);
    }

    // --- Helper methods ---

    /**
     * Enrich a recipe list item with categories, cuisines, courses, image URL.
     */
    private function enrich_recipe_list_item(array $recipe): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $recipe['id'];

        // Image
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium');
            $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
        }

        // Categories
        $recipe['categories'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                INNER JOIN %i rc ON c.id = rc.category_id
                WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC, c.id ASC',
                $prefix . 'categories',
                $prefix . 'recipe_categories',
                $id
            ),
            ARRAY_A
        );

        // Cuisines
        $recipe['cuisines'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE rcu.recipe_id = %d ORDER BY rcu.display_order ASC, cu.id ASC',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines',
                $id
            ),
            ARRAY_A
        );

        // Courses
        $recipe['courses'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT co.id, co.name, co.slug FROM %i co
                INNER JOIN %i rco ON co.id = rco.course_id
                WHERE rco.recipe_id = %d',
                $prefix . 'courses',
                $prefix . 'recipe_courses',
                $id
            ),
            ARRAY_A
        );

        // Flattened names and IDs for list display and form binding
        $recipe['category_name'] = !empty($recipe['categories'])
            ? implode(', ', array_column($recipe['categories'], 'name'))
            : null;
        $recipe['cuisine_name']  = $recipe['cuisines'][0]['name'] ?? null;
        $recipe['course_name']   = $recipe['courses'][0]['name'] ?? null;
        $recipe['category_id']   = !empty($recipe['categories']) ? (int) $recipe['categories'][0]['id'] : 0;
        $recipe['category_ids']  = array_map('intval', array_column($recipe['categories'], 'id'));
        $recipe['cuisine_id']    = !empty($recipe['cuisines'])  ? (int) $recipe['cuisines'][0]['id']  : 0;
        $recipe['course_id']     = !empty($recipe['courses'])   ? (int) $recipe['courses'][0]['id']   : 0;

        // Frontend expects average_rating, DB has rating_average
        $recipe['average_rating'] = (float) ($recipe['rating_average'] ?? 0);

        return $recipe;
    }

    private function sync_categories(int $recipe_id, array $category_ids): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $wpdb->delete($prefix . 'recipe_categories', ['recipe_id' => $recipe_id]);
        foreach ($category_ids as $i => $cid) {
            $wpdb->insert($prefix . 'recipe_categories', [
                'recipe_id'     => $recipe_id,
                'category_id'   => (int) $cid,
                'display_order' => $i,
            ]);
        }
    }

    private function sync_cuisines(int $recipe_id, array $cuisine_ids): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $wpdb->delete($prefix . 'recipe_cuisines', ['recipe_id' => $recipe_id]);
        foreach ($cuisine_ids as $i => $cid) {
            $wpdb->insert($prefix . 'recipe_cuisines', [
                'recipe_id'     => $recipe_id,
                'cuisine_id'    => (int) $cid,
                'display_order' => $i,
            ]);
        }
    }

    private function sync_courses(int $recipe_id, array $course_ids): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $wpdb->delete($prefix . 'recipe_courses', ['recipe_id' => $recipe_id]);
        foreach ($course_ids as $cid) {
            $wpdb->insert($prefix . 'recipe_courses', [
                'recipe_id' => $recipe_id,
                'course_id' => (int) $cid,
            ]);
        }
    }

    private function save_ingredients(int $recipe_id, array $ingredients): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $wpdb->delete($prefix . 'ingredients', ['recipe_id' => $recipe_id]);

        foreach ($ingredients as $ing) {
            $wpdb->insert($prefix . 'ingredients', [
                'recipe_id'   => $recipe_id,
                'group_name'  => sanitize_text_field($ing['group_name'] ?? ''),
                'group_order' => (int) ($ing['group_order'] ?? 0),
                'sort_order'  => (int) ($ing['sort_order'] ?? 0),
                'quantity'    => sanitize_text_field($ing['quantity'] ?? ''),
                'unit'        => sanitize_text_field($ing['unit'] ?? ''),
                'name'        => sanitize_text_field($ing['name'] ?? ''),
                'notes'       => sanitize_text_field($ing['notes'] ?? ''),
                'link_url'    => esc_url_raw($ing['link_url'] ?? ''),
            ]);
        }
    }

    private function save_instructions(int $recipe_id, array $instructions): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $wpdb->delete($prefix . 'instructions', ['recipe_id' => $recipe_id]);

        foreach ($instructions as $inst) {
            // The validation gate accepts a bare string as well as an array (see the
            // is_string() branch beside "At least one instruction is required"), so the
            // writer has to accept the same shape. It did not: $inst['instruction_text']
            // on a string is null under isset() semantics, so a string payload inserted a
            // row with an EMPTY instruction_text and still returned 200 - the step text was
            // silently discarded. Normalise here so the gate and the writer agree.
            if (is_string($inst)) {
                $inst = ['instruction_text' => $inst];
            }

            $wpdb->insert($prefix . 'instructions', [
                'recipe_id'        => $recipe_id,
                'group_name'       => sanitize_text_field($inst['group_name'] ?? ''),
                'group_order'      => (int) ($inst['group_order'] ?? 0),
                'sort_order'       => (int) ($inst['sort_order'] ?? 0),
                'title'            => sanitize_text_field($inst['title'] ?? ''),
                'instruction_text' => wp_kses_post($inst['instruction_text'] ?? $inst['text'] ?? ''),
                'image_id'         => !empty($inst['image_id']) ? (int) $inst['image_id'] : null,
                'video_url'        => esc_url_raw($inst['video_url'] ?? ''),
                'duration'         => isset($inst['duration']) ? (int) $inst['duration'] : null,
            ]);
        }
    }

    private function save_nutrition(int $recipe_id, array $nutrition): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE recipe_id = %d', $prefix . 'nutrition', $recipe_id));

        $data = [
            'recipe_id'          => $recipe_id,
            'serving_size'       => sanitize_text_field($nutrition['serving_size'] ?? ''),
            'calories'           => isset($nutrition['calories']) ? (float) $nutrition['calories'] : null,
            'total_fat'          => isset($nutrition['total_fat']) ? (float) $nutrition['total_fat'] : null,
            'saturated_fat'      => isset($nutrition['saturated_fat']) ? (float) $nutrition['saturated_fat'] : null,
            'trans_fat'          => isset($nutrition['trans_fat']) ? (float) $nutrition['trans_fat'] : null,
            'cholesterol'        => isset($nutrition['cholesterol']) ? (float) $nutrition['cholesterol'] : null,
            'sodium'             => isset($nutrition['sodium']) ? (float) $nutrition['sodium'] : null,
            'total_carbohydrate' => isset($nutrition['total_carbohydrate']) ? (float) $nutrition['total_carbohydrate'] : null,
            'dietary_fiber'      => isset($nutrition['dietary_fiber']) ? (float) $nutrition['dietary_fiber'] : null,
            'total_sugars'       => isset($nutrition['total_sugars']) ? (float) $nutrition['total_sugars'] : null,
            'protein'            => isset($nutrition['protein']) ? (float) $nutrition['protein'] : null,
            'vitamin_a'          => isset($nutrition['vitamin_a']) ? (float) $nutrition['vitamin_a'] : null,
            'vitamin_c'          => isset($nutrition['vitamin_c']) ? (float) $nutrition['vitamin_c'] : null,
            'calcium'            => isset($nutrition['calcium']) ? (float) $nutrition['calcium'] : null,
            'iron'               => isset($nutrition['iron']) ? (float) $nutrition['iron'] : null,
            'potassium'          => isset($nutrition['potassium']) ? (float) $nutrition['potassium'] : null,
            'source'             => sanitize_text_field($nutrition['source'] ?? 'manual'),
        ];

        if ($existing) {
            $wpdb->update($prefix . 'nutrition', $data, ['recipe_id' => $recipe_id]);
        } else {
            $wpdb->insert($prefix . 'nutrition', $data);
        }
    }

    private function ensure_unique_slug(string $slug, string $table, int $exclude_id = 0): string {
        global $wpdb;
        $original = $slug;
        $counter  = 1;

        while (true) {
            if ($exclude_id) {
                $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $table, $slug, $exclude_id));
            } else {
                $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, $slug));
            }
            if (!$exists) {
                break;
            }
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
