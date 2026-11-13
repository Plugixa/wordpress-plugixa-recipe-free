<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Repositories\RecipeChildRepository;
use PlugixaRecipe\Modules\Recipes\Repositories\RecipeRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a recipe is in the admin app and what may be done to one.
 *
 * The rules live here rather than in the controller - what a recipe must have before it can
 * be created, how a slug stays unique, which columns a list can be sorted by, which shapes an
 * ingredient or an instruction may arrive in - and so does the order they are checked in,
 * which decides the message the editor sees. It also fires the hooks the premium overlay
 * listens to, each at the same point between the writes as it always has.
 *
 * It holds no SQL: the recipe row, the list and the delete cascades are RecipeRepository's,
 * the pivots and child rows a save rewrites are RecipeChildRepository's.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" on a read is null. The
 * controller maps both onto the response.
 */
final class RecipeService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['title', 'created_at', 'modified_at', 'display_order', 'rating_average', 'view_count', 'total_time', 'published_at'];

    /**
     * Every status a recipe can hold. Compared STRICTLY, on every write.
     *
     * The column is a varchar under a case-insensitive collation, so `status = 'published'`
     * - the predicate every public query pins - also matches a stored 'Published'. Create
     * and update used to store whatever string arrived; with the publish check comparing
     * against the exact word, a differently-cased value would have walked past it and gone
     * live anyway. An unknown status is refused, so the only spelling that can be stored is
     * the one the check looks for.
     */
    private const STATUSES = ['draft', 'pending', 'published', 'archived'];

    /** The status that is live on the site. */
    private const LIVE = 'published';

    /** Bulk actions that set a status, and so decide what is live. */
    private const BULK_STATUS_ACTIONS = ['publish', 'draft', 'archive'];

    private RecipeRepository $recipes;

    private RecipeChildRepository $children;

    public function __construct(RecipeRepository $recipes, RecipeChildRepository $children) {
        $this->recipes  = $recipes;
        $this->children = $children;
    }

    /**
     * Recipes list with filters, pagination, sorting, and pivot joins.
     *
     * @param array<string, mixed>                  $filters    As read by the controller.
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        // Category filter. Three states: no filter, a specific category, or "uncategorized".
        // Each becomes its own gated clause in the repository - the specific-id case a gated
        // EXISTS, the uncategorized case a gated NOT EXISTS (which is what the old
        // LEFT JOIN ... IS NULL expressed). At most one of the two gates is ever 1.
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
        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'created_at';
        $order = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // The filter values, coerced. The repository turns them into the bound list of the
        // shared WHERE block (and escapes the search text for LIKE).
        $criteria = [
            'search'        => !empty($filters['search']) ? $filters['search'] : '',
            'status_gate'   => !empty($filters['status']) ? 1 : 0,
            'status'        => (string) ($filters['status'] ?? ''),
            'category_id'   => $cat_id,
            'uncategorized' => $uncat,
            'cuisine_id'    => $cuisine_id,
            'course_id'     => $course_id,
            'difficulty'    => $difficulty,
            'has_featured'  => $has_featured,
            'featured'      => $featured_val,
            'min_rating'    => $min_rating,
            'diet'          => $diet_args,
        ];

        $total   = $this->recipes->count($criteria);
        $recipes = $this->recipes->page($criteria, $orderby, $order, $pagination['limit'], $pagination['offset']);

        // Enrich each recipe with pivot data and image
        foreach ($recipes as &$recipe) {
            $recipe = $this->enrich_recipe_list_item($recipe);
        }
        unset($recipe);

        return [
            'items' => $recipes,
            'total' => $total,
            'limit' => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /**
     * Single recipe with full relations.
     *
     * @return array<string, mixed>|null Null when there is no such recipe.
     */
    public function get(int $id): ?array {
        // Read-through cached record; the enrichment below (image URLs, taxonomy joins)
        // stays live so a category/cuisine/course rename is never served stale.
        $recipe = $this->recipes->find($id);

        if (null === $recipe) {
            return null;
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
        $recipe['categories'] = $this->recipes->categories($id);

        // Cuisines
        $recipe['cuisines'] = $this->recipes->cuisines($id);

        // Courses
        $recipe['courses'] = $this->recipes->courses($id);

        // Scalar IDs for form binding
        $recipe['category_id']  = !empty($recipe['categories']) ? (int) $recipe['categories'][0]['id'] : 0;
        $recipe['category_ids'] = array_map('intval', array_column($recipe['categories'], 'id'));
        $recipe['cuisine_id']   = !empty($recipe['cuisines'])  ? (int) $recipe['cuisines'][0]['id']  : 0;
        $recipe['course_id']    = !empty($recipe['courses'])   ? (int) $recipe['courses'][0]['id']   : 0;

        // Ingredients (child rows of the recipe -> cached under the 'recipes' entity)
        $recipe['ingredients'] = $this->recipes->ingredients($id);

        // Instructions (child rows of the recipe -> cached under the 'recipes' entity)
        $recipe['instructions'] = $this->recipes->instructions($id);
        foreach ($recipe['instructions'] as &$inst) {
            $inst['text']  = $inst['instruction_text'];
            $inst['title'] = $inst['title'] ?? '';
            if ($inst['image_id']) {
                $inst['image_url'] = wp_get_attachment_image_url((int) $inst['image_id'], 'medium');
            }
        }
        unset($inst);

        // Nutrition (child row of the recipe -> cached under the 'recipes' entity)
        $recipe['nutrition'] = $this->recipes->nutrition($id);

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

        return $recipe;
    }

    /**
     * Create a new recipe.
     *
     * The checks run in this order and the first one to fail decides the message: title,
     * description, image, category, difficulty, servings, prep time, cook time, ingredient,
     * instruction, status.
     *
     * @param mixed $params The decoded JSON payload: raw, untrusted, null when there was none.
     * @return int|WP_Error The new recipe's id.
     */
    public function create($params) {
        $now    = current_time('mysql');

        $title = sanitize_text_field($params['title'] ?? '');
        if (empty($title)) {
            return Failure::make(__('Title is required.', 'plugixa-recipe'));
        }

        $description_check = wp_kses_post($params['description'] ?? '');
        if (empty(trim(wp_strip_all_tags($description_check)))) {
            return Failure::make(__('Description is required.', 'plugixa-recipe'));
        }

        $image_id_check = !empty($params['image_id']) ? (int) $params['image_id'] : 0;
        if ($image_id_check < 1) {
            return Failure::make(__('Recipe image is required.', 'plugixa-recipe'));
        }

        $category_ids_check = $params['category_ids'] ?? [];
        if (empty($category_ids_check) || !is_array($category_ids_check) || count(array_filter($category_ids_check)) < 1) {
            return Failure::make(__('At least one category is required.', 'plugixa-recipe'));
        }

        $difficulty_check = sanitize_text_field($params['difficulty'] ?? '');
        if (empty($difficulty_check) || !in_array($difficulty_check, ['easy', 'medium', 'hard', 'expert'], true)) {
            return Failure::make(__('Difficulty is required.', 'plugixa-recipe'));
        }

        $servings_check = isset($params['servings']) ? (int) $params['servings'] : 0;
        if ($servings_check < 1) {
            return Failure::make(__('Servings must be at least 1.', 'plugixa-recipe'));
        }

        if (!isset($params['prep_time']) || $params['prep_time'] === '' || $params['prep_time'] === null) {
            return Failure::make(__('Prep time is required.', 'plugixa-recipe'));
        }

        if (!isset($params['cook_time']) || $params['cook_time'] === '' || $params['cook_time'] === null) {
            return Failure::make(__('Cook time is required.', 'plugixa-recipe'));
        }

        $ingredients_check = $params['ingredients'] ?? [];
        $has_ingredient = false;
        if (is_array($ingredients_check)) {
            foreach ($ingredients_check as $ing) {
                if (!empty(trim($ing['name'] ?? ''))) { $has_ingredient = true; break; }
            }
        }
        if (!$has_ingredient) {
            return Failure::make(__('At least one ingredient is required.', 'plugixa-recipe'));
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
            return Failure::make(__('At least one instruction is required.', 'plugixa-recipe'));
        }

        $status = $this->requested_status($params) ?? 'draft';
        if (!in_array($status, self::STATUSES, true)) {
            return Failure::make(__('Invalid status.', 'plugixa-recipe'));
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
            'status'           => $status,
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

        if ($data['status'] === self::LIVE && empty($data['published_at'])) {
            $data['published_at'] = $now;
        }

        // Ensure unique slug
        $data['slug'] = $this->ensure_unique_slug($data['slug']);

        $recipe_id = $this->recipes->insert($data);

        if (!$recipe_id) {
            return Failure::make(__('Failed to create recipe.', 'plugixa-recipe'), 500);
        }

        // Sync pivot tables
        $this->children->sync_categories($recipe_id, $params['category_ids'] ?? []);
        $this->children->sync_cuisines($recipe_id, $params['cuisine_ids'] ?? []);
        $this->children->sync_courses($recipe_id, $params['course_ids'] ?? []);

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
        // Must run BEFORE the controller's get_item() so the response is read fresh, not
        // from cache - and AFTER the action above, whose listeners write rows of their own.
        $this->recipes->created();

        return $recipe_id;
    }

    /**
     * Change the fields present in $params; fields that are absent are left alone.
     *
     * @param mixed $params The decoded JSON payload: raw, untrusted, null when there was none.
     * @return true|WP_Error
     */
    public function update(int $id, $params) {
        $now    = current_time('mysql');

        $existing = $this->recipes->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        $text_fields = ['title', 'slug', 'difficulty', 'cost', 'season', 'source', 'source_url', 'author_name', 'servings_unit', 'yield_amount', 'yield_unit', 'video_url', 'language_code', 'translation_group', 'seo_title', 'seo_keywords'];
        foreach ($text_fields as $field) {
            if (isset($params[$field])) {
                if (in_array($field, ['source_url', 'video_url'])) {
                    $data[$field] = esc_url_raw($params[$field]);
                } else {
                    $data[$field] = sanitize_text_field($params[$field]);
                }
            }
        }

        // Not one of the text fields above: a status is refused unless it is one of ours
        // (see STATUSES for why the exact spelling matters).
        $status = $this->requested_status($params);
        if (null !== $status) {
            if (!in_array($status, self::STATUSES, true)) {
                return Failure::make(__('Invalid status.', 'plugixa-recipe'));
            }
            $data['status'] = $status;
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
        if (isset($data['status']) && $data['status'] === self::LIVE && $existing['status'] !== self::LIVE) {
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
            $data['slug'] = $this->ensure_unique_slug($data['slug'], $id);
        }

        $data['modified_at'] = $now;
        $data['modified_by'] = get_current_user_id();

        $this->recipes->update($id, $data);

        // Sync pivot tables
        if (isset($params['category_ids'])) {
            $this->children->sync_categories($id, $params['category_ids']);
        }
        if (isset($params['cuisine_ids'])) {
            $this->children->sync_cuisines($id, $params['cuisine_ids']);
        }
        if (isset($params['course_ids'])) {
            $this->children->sync_courses($id, $params['course_ids']);
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

        /** This filter is documented in create(). */
        do_action('plugixa_recipe_save_recipe', $id, $params);

        // Recipe + pivot rows changed -> also invalidates category/cuisine/course counts.
        // Must run BEFORE the controller's get_item() so the response is read fresh, not
        // from cache - and AFTER the action above, whose listeners write rows of their own.
        $this->recipes->updated();

        return true;
    }

    /**
     * Delete a recipe and all associated data.
     *
     * @return true|WP_Error
     */
    public function delete(int $id) {
        if (!$this->recipes->exists($id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Everything keyed to the recipe goes first (shopping-list items are detached, not
        // deleted); the recipe row itself is still there when the action below fires.
        $this->recipes->delete_cascade($id);

        /**
         * Fires before a recipe row is deleted, while its id is still valid.
         *
         * The premium overlay hooks this to clean up the rows it owns in its own tables.
         * The free build has none, so nothing is hooked.
         *
         * @param int $id
         */
        do_action('plugixa_recipe_delete_recipe', $id);

        // The recipe row, then the invalidation.
        $this->recipes->delete($id);

        return true;
    }

    /**
     * The status a payload names: null when it names none (absent, blank, or not text).
     *
     * ONE reading, used by create(), update(), set_status() and by changes_publication()
     * below. The permission check and the writer must agree on what a payload asks for, or
     * a value one of them reads differently is a way round the check.
     *
     * @param mixed $params The decoded JSON payload.
     */
    private function requested_status($params): ?string {
        if (!is_array($params) || !isset($params['status'])) {
            return null;
        }

        $status = sanitize_text_field($params['status']);

        return '' === $status ? null : $status;
    }

    /**
     * Whether a save would change WHICH recipes are live: publish one that is not, or take
     * down one that is.
     *
     * The controller asks this before the save and requires the publish capability when the
     * answer is yes. Moving between draft, pending and archived is not a publication change,
     * so an edit-level user can still write a draft and submit it for review; nor is saving
     * a published recipe with its status unchanged, which the recipe form does on every save.
     *
     * @param int   $id     The recipe being saved, 0 for one being created.
     * @param mixed $params The decoded JSON payload: raw, untrusted, null when there was none.
     */
    public function changes_publication(int $id, $params): bool {
        $to = $this->requested_status($params);
        if (null === $to) {
            return false;
        }

        $from = '';
        if ($id > 0) {
            $existing = $this->recipes->find_uncached($id);
            if (null === $existing) {
                // Nothing to publish or take down; the handler answers 404.
                return false;
            }
            $from = (string) $existing['status'];
        }

        // The stored side is compared the way the database compares it (see STATUSES).
        return (0 === strcasecmp(self::LIVE, $from)) !== (self::LIVE === $to);
    }

    /**
     * Whether a bulk action sets a status. The three that do - publish, draft and archive -
     * decide what is live for every recipe selected, so they need the publish capability;
     * feature and unfeature do not.
     *
     * @param mixed $params The decoded JSON payload: ids and action.
     */
    public function bulk_changes_publication($params): bool {
        $action = is_array($params) ? sanitize_text_field($params['action'] ?? '') : '';

        return in_array($action, self::BULK_STATUS_ACTIONS, true);
    }

    /**
     * Update recipe status.
     *
     * @param mixed $params The decoded JSON payload.
     * @return array{id: int, status: string}|WP_Error
     */
    public function set_status(int $id, $params) {
        $status = (string) $this->requested_status($params);

        if (!in_array($status, self::STATUSES, true)) {
            return Failure::make(__('Invalid status.', 'plugixa-recipe'));
        }

        $existing = $this->recipes->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $data = [
            'status'      => $status,
            'modified_at' => current_time('mysql'),
            'modified_by' => get_current_user_id(),
        ];

        if ($status === self::LIVE && $existing['status'] !== self::LIVE) {
            $data['published_at'] = current_time('mysql');
        }

        $this->recipes->update_status($id, $data);

        return ['id' => $id, 'status' => $status];
    }

    /**
     * Reorder recipes - their own order, or their order inside one category.
     *
     * @param mixed $params The decoded JSON payload: items (a list of {id, display_order})
     *                      and, for a per-category order, category_id.
     * @return true|WP_Error
     */
    public function reorder($params) {
        $items  = $params['items'] ?? [];
        $category_id = $params['category_id'] ?? null;

        if (empty($items) || !is_array($items)) {
            return Failure::make(__('Items are required.', 'plugixa-recipe'));
        }

        $this->recipes->reorder($items, $category_id);

        return true;
    }

    /**
     * Bulk delete recipes.
     *
     * @param mixed $params The decoded JSON payload: ids.
     * @return int|bool|WP_Error Rows removed from the recipes table.
     */
    public function delete_many($params) {
        $ids    = $params['ids'] ?? [];
        $ids    = array_map('intval', is_array($ids) ? $ids : []);

        if (empty($ids)) {
            return Failure::make(__('No IDs provided.', 'plugixa-recipe'));
        }

        // Delete related data for all IDs. The free list is RecipeRepository::child_tables(),
        // kept beside the single delete's list so the two stay in step.
        $tables = $this->recipes->child_tables();

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

        return $this->recipes->delete_many_cascade($tables, $id_list);
    }

    /**
     * Bulk action (publish, draft, archive, feature, unfeature).
     *
     * @param mixed $params The decoded JSON payload: ids and action.
     * @return string|WP_Error The action that was applied.
     */
    public function bulk_action($params) {
        $ids    = $params['ids'] ?? [];
        $ids    = array_map('intval', is_array($ids) ? $ids : []);
        $action = sanitize_text_field($params['action'] ?? '');

        if (empty($ids) || empty($action)) {
            return Failure::make(__('IDs and action are required.', 'plugixa-recipe'));
        }

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
        // IN (...), so each statement stays a constant literal. $ids is already
        // array_map('intval', ...) above.
        $id_list = implode(',', $ids);
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        if (!$this->recipes->bulk_action($action, $id_list, $now, $user_id)) {
            return Failure::make(__('Invalid action.', 'plugixa-recipe'));
        }

        return $action;
    }

    /**
     * Duplicate a recipe with all related data.
     *
     * @return int|WP_Error The new (duplicated) recipe's id.
     */
    public function duplicate(int $id) {
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $recipe = $this->recipes->find_uncached($id);
        if (null === $recipe) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Remove auto-generated / unique fields
        unset($recipe['id'], $recipe['total_time'], $recipe['view_count'], $recipe['rating_average'], $recipe['rating_count'], $recipe['seo_score'], $recipe['published_at']);

        $recipe['title']       = $recipe['title'] . ' (Copy)';
        $recipe['slug']        = $this->ensure_unique_slug(sanitize_title($recipe['title']));
        $recipe['status']      = 'draft';
        $recipe['is_featured'] = 0;
        $recipe['created_at']  = $now;
        $recipe['created_by']  = $user_id;
        $recipe['modified_at'] = null;
        $recipe['modified_by'] = null;

        $new_id = $this->recipes->insert($recipe);

        if (!$new_id) {
            return Failure::make(__('Failed to duplicate recipe.', 'plugixa-recipe'), 500);
        }

        // Copy the pivots, ingredients, instructions and nutrition.
        $this->children->copy($new_id, $id);

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
        // Must run BEFORE the controller's get_item() so the response is read fresh, not
        // from cache - and AFTER the action above, whose listeners copy rows of their own.
        $this->recipes->duplicated();

        return $new_id;
    }

    // --- Helper methods ---

    /**
     * Enrich a recipe list item with categories, cuisines, courses, image URL.
     */
    private function enrich_recipe_list_item(array $recipe): array {
        $id = (int) $recipe['id'];

        // Image
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium');
            $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
        }

        // Categories
        $recipe['categories'] = $this->recipes->list_categories($id);

        // Cuisines
        $recipe['cuisines'] = $this->recipes->list_cuisines($id);

        // Courses
        $recipe['courses'] = $this->recipes->list_courses($id);

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

    /**
     * Replace a recipe's ingredients: clear the stored list, then sanitise and store each
     * row in turn - the same order the writes have always run in.
     */
    private function save_ingredients(int $recipe_id, array $ingredients): void {
        $this->children->clear_ingredients($recipe_id);

        foreach ($ingredients as $ing) {
            $this->children->insert_ingredient([
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

    /**
     * Replace a recipe's instructions: clear the stored list, then normalise, sanitise and
     * store each row in turn.
     */
    private function save_instructions(int $recipe_id, array $instructions): void {
        $this->children->clear_instructions($recipe_id);

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

            $this->children->insert_instruction([
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

    /**
     * Store a recipe's nutrition row: an update when it has one, an insert when it does not.
     */
    private function save_nutrition(int $recipe_id, array $nutrition): void {
        $existing = $this->children->nutrition_id($recipe_id);

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
            $this->children->update_nutrition($recipe_id, $data);
        } else {
            $this->children->insert_nutrition($data);
        }
    }

    /** The slug itself, or the first of slug-2, slug-3, ... that no other recipe uses. */
    private function ensure_unique_slug(string $slug, int $exclude_id = 0): string {
        $original = $slug;
        $counter  = 1;

        while ($this->recipes->slug_taken($slug, $exclude_id)) {
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
