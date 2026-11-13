<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Enums;
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
        // `equipment` stays the list of names it always was; `equipment_items` adds each
        // item's optional link. A save may send either (see equipment_column()).
        $recipe['equipment_items'] = Equipment::decode($recipe['equipment'] ?? '');
        $recipe['equipment']       = array_column($recipe['equipment_items'], 'name');

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

        // What this status demands (see required_for()), then the fixed choices.
        $unmet = $this->unmet($params, $this->required_for($this->requested_status($params) ?? 'draft'), true);
        if (null !== $unmet) {
            return $unmet;
        }

        $refused = $this->refused_choice($params) ?? $this->refused_elsewhere($params, 0);
        if (null !== $refused) {
            return $refused;
        }

        $title = sanitize_text_field($params['title'] ?? '');

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
            'tags'             => $this->encode_labels($params['tags'] ?? null),
            'equipment'        => $this->equipment_column($params),
            'video_url'        => esc_url_raw($params['video_url'] ?? ''),
            'servings'         => (int) ($params['servings'] ?? 4),
            'servings_unit'    => sanitize_text_field($params['servings_unit'] ?? 'servings'),
            'yield_amount'     => sanitize_text_field($params['yield_amount'] ?? ''),
            'yield_unit'       => sanitize_text_field($params['yield_unit'] ?? ''),
            'prep_time'        => isset($params['prep_time']) ? (int) $params['prep_time'] : null,
            'cook_time'        => isset($params['cook_time']) ? (int) $params['cook_time'] : null,
            'rest_time'        => isset($params['rest_time']) ? (int) $params['rest_time'] : null,
            'difficulty'       => sanitize_text_field($params['difficulty'] ?? 'medium'),
            'cost'             => Enums::cost($params['cost'] ?? 'moderate'),
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
     * The fields a recipe must hold before it may be saved with this status.
     *
     * create() and update() both ask (see unmet() for how an update applies the answer).
     * A title is always required, listed here or not.
     *
     * Field names: title, description, image, category, difficulty, servings, prep_time,
     * cook_time, ingredient, instruction.
     *
     * @param string $status One of STATUSES - the status the recipe will have after the save.
     * @return string[]
     */
    private function required_for(string $status): array {
        // TODO(owner): decide what a draft / pending / published / archived recipe needs.
        // Returning the full list for every status keeps today's create() rule.
        return ['title', 'description', 'image', 'category', 'difficulty', 'servings', 'prep_time', 'cook_time', 'ingredient', 'instruction'];
    }

    /**
     * The first required field the payload leaves empty, as a refusal; null when none is.
     *
     * The order is fixed and decides the message the editor sees: title, description, image,
     * category, difficulty, servings, prep time, cook time, ingredient, instruction. A title
     * is required whatever $required says - the slug is made from it.
     *
     * On create every required field is checked. On update ($all false) only the fields the
     * payload SENDS are: an update changes what it names and leaves the rest alone, so a
     * small edit to a recipe that was imported without an image must still go through. What
     * it may not do is send a required field empty - `ingredients: []` used to be stored,
     * and the recipe lost its ingredient list.
     *
     * @param mixed    $params   The decoded JSON payload.
     * @param string[] $required Field names, from required_for().
     * @param bool     $all      True on create.
     */
    private function unmet($params, array $required, bool $all): ?WP_Error {
        $params     = is_array($params) ? $params : [];
        $required[] = 'title';

        $has = static function (string $key) use ($params): bool {
            return array_key_exists($key, $params);
        };
        $asked = static function (string $field, string $key) use ($required, $all, $has): bool {
            return in_array($field, $required, true) && ($all || $has($key));
        };
        $refuse = static function (string $key, string $message): WP_Error {
            return Failure::make($message, 400, [$key => $message]);
        };

        if ($asked('title', 'title') && '' === sanitize_text_field((string) ($params['title'] ?? ''))) {
            return $refuse('title', __('Title is required.', 'plugixa-recipe'));
        }

        if ($asked('description', 'description') && '' === trim(wp_strip_all_tags(wp_kses_post((string) ($params['description'] ?? ''))))) {
            return $refuse('description', __('Description is required.', 'plugixa-recipe'));
        }

        if ($asked('image', 'image_id') && (int) ($params['image_id'] ?? 0) < 1) {
            return $refuse('image_id', __('Recipe image is required.', 'plugixa-recipe'));
        }

        if ($asked('category', 'category_ids')) {
            $categories = $params['category_ids'] ?? [];
            if (!is_array($categories) || count(array_filter($categories)) < 1) {
                return $refuse('category_ids', __('At least one category is required.', 'plugixa-recipe'));
            }
        }

        if ($asked('difficulty', 'difficulty') && !in_array(sanitize_text_field((string) ($params['difficulty'] ?? '')), Enums::DIFFICULTIES, true)) {
            return $refuse('difficulty', __('Difficulty is required.', 'plugixa-recipe'));
        }

        if ($asked('servings', 'servings') && (int) ($params['servings'] ?? 0) < 1) {
            return $refuse('servings', __('Servings must be at least 1.', 'plugixa-recipe'));
        }

        if ($asked('prep_time', 'prep_time') && (!isset($params['prep_time']) || '' === $params['prep_time'])) {
            return $refuse('prep_time', __('Prep time is required.', 'plugixa-recipe'));
        }

        if ($asked('cook_time', 'cook_time') && (!isset($params['cook_time']) || '' === $params['cook_time'])) {
            return $refuse('cook_time', __('Cook time is required.', 'plugixa-recipe'));
        }

        if ($asked('ingredient', 'ingredients')) {
            $named = false;
            foreach (is_array($params['ingredients'] ?? null) ? $params['ingredients'] : [] as $ing) {
                if (is_array($ing) && '' !== trim((string) ($ing['name'] ?? ''))) {
                    $named = true;
                    break;
                }
            }
            if (!$named) {
                return $refuse('ingredients', __('At least one ingredient is required.', 'plugixa-recipe'));
            }
        }

        if ($asked('instruction', 'instructions')) {
            $written = false;
            foreach (is_array($params['instructions'] ?? null) ? $params['instructions'] : [] as $inst) {
                // Every shape ChildRows::instruction() stores: a bare string, `instruction_text`
                // or `text`. A gate stricter than the writer behind it refuses what would save.
                $step = is_string($inst) ? $inst : (is_array($inst) ? ($inst['instruction_text'] ?? $inst['text'] ?? '') : '');
                if ('' !== trim((string) $step)) {
                    $written = true;
                    break;
                }
            }
            if (!$written) {
                return $refuse('instructions', __('At least one instruction is required.', 'plugixa-recipe'));
            }
        }

        return null;
    }

    /**
     * A refusal from another module, asked before anything is written.
     *
     * A module that stores data of its own with a recipe (through the
     * plugixa_recipe_save_recipe action) had no way to refuse a save: by the time that
     * action fires the recipe is already written. This filter is that way.
     *
     * @param mixed $params The decoded JSON payload.
     * @param int   $id     The recipe being updated; 0 on create.
     */
    private function refused_elsewhere($params, int $id): ?WP_Error {
        /**
         * Filters whether a recipe save may go ahead.
         *
         * Return a WP_Error (see Core\Http\Failure::make(), which carries a per-field
         * `errors` map) to refuse it; return the value received to let it through.
         *
         * @param WP_Error|null       $refusal   Null unless an earlier listener refused.
         * @param array<string,mixed> $params    The request payload, unsanitised.
         * @param int                 $recipe_id 0 when the recipe is being created.
         */
        $refusal = apply_filters('plugixa_recipe_validate_recipe', null, is_array($params) ? $params : [], $id);

        return $refusal instanceof WP_Error ? $refusal : null;
    }

    /**
     * A difficulty, cost or season the payload names that is not one of ours.
     *
     * Refused rather than stored: these are varchars, so an unknown value would be kept and
     * then open in the editor as a choice its own list does not contain. An empty cost or
     * season means "not chosen".
     *
     * @param mixed $params The decoded JSON payload.
     */
    private function refused_choice($params): ?WP_Error {
        if (isset($params['difficulty']) && !in_array(sanitize_text_field((string) $params['difficulty']), Enums::DIFFICULTIES, true)) {
            return Failure::make(__('Invalid difficulty.', 'plugixa-recipe'));
        }

        if (isset($params['cost']) && '' !== (string) $params['cost'] && '' === Enums::cost($params['cost'])) {
            return Failure::make(__('Invalid cost.', 'plugixa-recipe'));
        }

        if (isset($params['season']) && '' !== (string) $params['season'] && !in_array(sanitize_text_field((string) $params['season']), Enums::SEASONS, true)) {
            return Failure::make(__('Invalid season.', 'plugixa-recipe'));
        }

        return null;
    }

    /**
     * The equipment column for a save. `equipment_items` ({name, link_url} each) is the
     * fuller shape and wins when both are sent; `equipment` is the plain list of names.
     *
     * @param mixed $params The decoded JSON payload.
     */
    private function equipment_column($params): ?string {
        return Equipment::encode($params['equipment_items'] ?? $params['equipment'] ?? null);
    }

    /**
     * A list of tags as the row stores it: plain text, no blanks, no repeats. Null when
     * nothing is left.
     *
     * @param mixed $labels Raw input.
     */
    private function encode_labels($labels): ?string {
        if (!is_array($labels)) {
            return null;
        }

        $clean = [];
        foreach ($labels as $label) {
            $label = is_scalar($label) ? sanitize_text_field((string) $label) : '';
            if ('' !== $label && !in_array($label, $clean, true)) {
                $clean[] = $label;
            }
        }

        return $clean ? wp_json_encode($clean) : null;
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

        $unmet = $this->unmet($params, $this->required_for($this->requested_status($params) ?? (string) $existing['status']), false);
        if (null !== $unmet) {
            return $unmet;
        }

        $refused = $this->refused_choice($params) ?? $this->refused_elsewhere($params, $id);
        if (null !== $refused) {
            return $refused;
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

        if (isset($data['cost'])) {
            $data['cost'] = Enums::cost($data['cost']);
        }

        // Plain text, as create() stores it: a meta description holds no markup.
        if (isset($params['seo_description'])) {
            $data['seo_description'] = sanitize_textarea_field($params['seo_description']);
        }

        $html_fields = ['description', 'excerpt', 'notes', 'tips'];
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
            $data['tags'] = $this->encode_labels($params['tags']);
        }
        if (isset($params['equipment']) || isset($params['equipment_items'])) {
            $data['equipment'] = $this->equipment_column($params);
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

        // Save nutrition. Sent empty (null) it is cleared: the label had no way back to "none".
        if (is_array($params) && array_key_exists('nutrition', $params)) {
            if (empty($params['nutrition'])) {
                $this->children->delete_nutrition($id);
            } else {
                $this->save_nutrition($id, $params['nutrition']);
            }
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
     * What the editor offers while an author types: ingredient names, tags and equipment
     * this site's recipes already use, most used first.
     *
     * @return array{ingredients: string[], tags: string[], equipment: string[]}
     */
    public function suggestions(): array {
        $uses = ['tags' => [], 'equipment' => []];

        foreach ($this->recipes->label_lists(1000) as $row) {
            foreach (array_keys($uses) as $column) {
                $labels = json_decode((string) ($row[$column] ?? ''), true);
                foreach (is_array($labels) ? $labels : [] as $label) {
                    // An equipment item may be stored as {name, link_url}.
                    $label = is_array($label) ? ($label['name'] ?? '') : $label;
                    $label = is_scalar($label) ? trim((string) $label) : '';
                    if ('' !== $label) {
                        $uses[$column][$label] = ($uses[$column][$label] ?? 0) + 1;
                    }
                }
            }
        }

        $top = static function (array $counts): array {
            arsort($counts);
            return array_map('strval', array_slice(array_keys($counts), 0, 200));
        };

        return [
            'ingredients' => array_values(array_map('strval', $this->recipes->ingredient_names(500))),
            'tags'        => $top($uses['tags']),
            'equipment'   => $top($uses['equipment']),
        ];
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
            $this->children->insert_ingredient(ChildRows::ingredient($recipe_id, $ing));
        }
    }

    /**
     * Replace a recipe's instructions: clear the stored list, then normalise, sanitise and
     * store each row in turn.
     */
    private function save_instructions(int $recipe_id, array $instructions): void {
        $this->children->clear_instructions($recipe_id);

        // ChildRows accepts every shape the create gate accepts, a bare string included.
        foreach ($instructions as $inst) {
            $this->children->insert_instruction(ChildRows::instruction($recipe_id, $inst));
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
