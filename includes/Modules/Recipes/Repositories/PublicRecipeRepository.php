<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the public routes. Content reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user/per-IP state lookups (favourite, like, helpful vote), the write paths and the view/like counter updates are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the PUBLIC recipe routes run: the published list, one published recipe with
 * its child rows, the view counter and a logged-in visitor's like.
 *
 * Kept apart from RecipeRepository on purpose. That one filters status through a bound gate
 * for the admin list and selects every column; here `status = 'published'` is a hard literal
 * no caller can switch off, and every recipe SELECT is a written-out public column list that
 * never names submission_ip, modified_by, modified_at or any seo_* column. Nothing in this
 * repository reads a recipe in any other status, and no status is ever gated in it - the
 * gates below are content filters (category, search, diet flags) only.
 *
 * Nothing here flushes. The only writes are a like row and the view / like counters, and
 * those run straight off a rendered page: the increment_* / decrement_* methods deliberately
 * leave the cache alone, so cached lists carry eventually-consistent counts.
 */
final class PublicRecipeRepository extends Repository {

    /** Everything read here is cached under the owning recipe. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * Bound values for the shared WHERE block, in placeholder order. %i identifiers and
     * %s/%d values share one positional argument list, so the table names sit inline.
     *
     * Every optional filter is a gate placeholder: bind 0 and its clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled. The two
     * taxonomy filters are gated EXISTS subqueries rather than optional INNER JOINs -
     * a JOIN is structure and cannot be gated away by a placeholder, and EXISTS
     * selects the same rows without the multiplication DISTINCT was there to undo.
     *
     * Diet flags: six independent gates. The column names are literal in the SQL below
     * (they come from a hard-coded list, never from the request); only the 1/0 gate
     * travels as a bound value.
     *
     * @param array<string, mixed> $criteria As coerced by the controller: category_id,
     *                                       cuisine_id, search (raw text or ''), is_featured,
     *                                       min_rating, difficulty and the six diet gates.
     * @return array<int, mixed>
     */
    private function filter_args(array $criteria): array {
        global $wpdb;

        $category_id = $criteria['category_id'];
        $cuisine_id  = $criteria['cuisine_id'];

        $search     = $criteria['search'];
        $search     = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';
        $featured   = $criteria['is_featured'];
        $min_rating = $criteria['min_rating'];
        $difficulty = $criteria['difficulty'];

        return [
            $this->table('recipes'),
            $category_id ? 1 : 0, $this->table('recipe_categories'), $category_id,
            $cuisine_id ? 1 : 0, $this->table('recipe_cuisines'), $cuisine_id,
            $search !== '' ? 1 : 0, $search, $search,
            $featured,
            $min_rating > 0 ? 1 : 0, $min_rating,
            $difficulty !== '' ? 1 : 0, $difficulty,
            $criteria['is_vegetarian'],
            $criteria['is_vegan'],
            $criteria['is_gluten_free'],
            $criteria['is_dairy_free'],
            $criteria['is_keto'],
            $criteria['is_low_carb'],
        ];
    }

    /**
     * Published recipes matching the filters.
     *
     * @param array<string, mixed> $criteria See filter_args().
     */
    public function count_published(array $criteria): int {
        global $wpdb;

        $filter_args = $this->filter_args($criteria);

        // Count. Cache key is a stable digest of the bound values - it must NOT embed the
        // SQL text, which now lives inline in prepare().
        return (int) $this->remember('frontend_count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
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
    }

    /**
     * One page of published recipes, card columns only.
     *
     * Data. ORDER BY column binds with the %i identifier placeholder (WP 6.2+) from the
     * service's allow-list; a direction keyword cannot be bound at all, so
     * ASC/DESC is two literal queries.
     *
     * r.id is a deterministic tiebreaker, and it is not optional. Seeded/imported
     * recipes routinely share a published_at to the second, and a sort with ties is
     * free to return any permutation - so paging with LIMIT/OFFSET over tied rows
     * could show one recipe on two pages and skip another entirely. Sorting by the
     * primary key last makes every page stable.
     *
     * @param array<string, mixed> $criteria See filter_args().
     * @param string               $orderby  A column from the service's allow-list.
     * @param string               $order    'ASC' or 'DESC'
     * @param int                  $limit
     * @param int                  $offset
     * @return array<int, array<string, mixed>>
     */
    public function published_page(array $criteria, $orderby, string $order, $limit, $offset): array {
        global $wpdb;

        $filter_args = $this->filter_args($criteria);

        $args = array_merge($filter_args, [$orderby, $limit, $offset]);

        return (array) $this->remember('frontend_list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
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
    }

    /**
     * A LISTED recipe's categories — child rows cached under the owning recipe.
     *
     * The same statement and the same cache entry as categories() below (the key is
     * `recipe_categories|{id}` either way); kept as the list's own read, as it was.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listed_categories(int $rid) {
        global $wpdb;

        return $this->remember('recipe_categories|' . $rid, function () use ($wpdb, $rid) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                    INNER JOIN %i rc ON c.id = rc.category_id
                    WHERE rc.recipe_id = %d',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $rid
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * A LISTED recipe's cuisines — child rows cached under the owning recipe.
     *
     * The same statement and the same cache entry as cuisines() below (the key is
     * `recipe_cuisines|{id}` either way); kept as the list's own read, as it was.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listed_cuisines(int $rid) {
        global $wpdb;

        return $this->remember('recipe_cuisines|' . $rid, function () use ($wpdb, $rid) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                    INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                    WHERE rcu.recipe_id = %d',
                $this->table('cuisines'),
                $this->table('recipe_cuisines'),
                $rid
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * One published recipe, public columns only. Read-through object cache; key = recipe id.
     *
     * @return array<string, mixed>|null Null when there is no such recipe, or it is not published.
     */
    public function find_published(int $id): ?array {
        global $wpdb;

        $recipes_table = $this->table('recipes');

        $recipe = $this->remember('frontend_single|' . $id, function () use ($wpdb, $recipes_table, $id) {
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

        return $recipe ?: null;
    }

    /**
     * Count one more view of a recipe.
     *
     * Counter increment only: deliberately does NOT flush the cache — this runs on every
     * render, so flushing here would invalidate the whole cache on every page view. Cached
     * lists therefore carry eventually-consistent view counts.
     */
    public function increment_view_count(int $id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET view_count = view_count + 1 WHERE id = %d',
            $this->table('recipes'),
            $id
        ));
    }

    /**
     * Categories — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(int $id) {
        global $wpdb;

        return $this->remember('recipe_categories|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                INNER JOIN %i rc ON c.id = rc.category_id
                WHERE rc.recipe_id = %d',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Cuisines — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cuisines(int $id) {
        global $wpdb;

        return $this->remember('recipe_cuisines|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE rcu.recipe_id = %d',
                $this->table('cuisines'),
                $this->table('recipe_cuisines'),
                $id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Courses — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function courses(int $id) {
        global $wpdb;

        return $this->remember('recipe_courses|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT co.id, co.name, co.slug FROM %i co
                INNER JOIN %i rco ON co.id = rco.course_id
                WHERE rco.recipe_id = %d',
                $this->table('courses'),
                $this->table('recipe_courses'),
                $id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Ingredients — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ingredients(int $id) {
        global $wpdb;

        $ingredients_table = $this->table('ingredients');

        return $this->remember('recipe_ingredients|' . $id, function () use ($wpdb, $ingredients_table, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    quantity, unit, name, notes, link_url, amount, amount_max, is_optional, substitution
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $ingredients_table,
                $id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Instructions — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function instructions(int $id) {
        global $wpdb;

        $instructions_table = $this->table('instructions');

        return $this->remember('recipe_instructions|' . $id, function () use ($wpdb, $instructions_table, $id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    title, instruction_text, image_id, video_url, duration, timer_seconds, tip
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $instructions_table,
                $id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Nutrition — child row cached under the owning recipe.
     *
     * @return array<string, mixed>|null
     */
    public function nutrition(int $id) {
        global $wpdb;

        $nutrition_table = $this->table('nutrition');

        return $this->remember('recipe_nutrition|' . $id, function () use ($wpdb, $nutrition_table, $id) {
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
    }

    /** Has this user favourited the recipe? Per-user state: not cached. */
    public function is_favorited(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('favorites'), $user_id, $recipe_id
        ));
    }

    /** Is there such a recipe, and is it published? Uncached. */
    public function published_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND status = \'published\'',
            $this->table('recipes'),
            $recipe_id
        ));
    }

    /**
     * The id of this user's like on the recipe, if there is one. Per-user state: not cached.
     *
     * @return string|null
     */
    public function like_id(int $user_id, int $recipe_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('likes'), $user_id, $recipe_id
        ));
    }

    /** Retract the caller's own like. The counter is decrement_like_count()'s. */
    public function remove_like(int $user_id, int $recipe_id): void {
        global $wpdb;

        $wpdb->delete($this->table('likes'), ['user_id' => $user_id, 'recipe_id' => $recipe_id]);
    }

    /**
     * Record the caller's like. The counter is increment_like_count()'s.
     *
     * @param array<string, mixed> $like user_id, recipe_id, created_at.
     */
    public function add_like(array $like): void {
        global $wpdb;

        $wpdb->insert($this->table('likes'), $like);
    }

    /**
     * Count one more like on a recipe.
     *
     * Counter increment only: deliberately does NOT flush the cache — likes are fired
     * straight off a rendered recipe card, so flushing here would invalidate the whole
     * cache on a plain page view. Cached lists therefore carry eventually-consistent
     * like counts (the live count is returned in the response for the UI to apply).
     */
    public function increment_like_count(int $recipe_id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET like_count = like_count + 1 WHERE id = %d',
            $this->table('recipes'),
            $recipe_id
        ));
    }

    /**
     * Count one like fewer on a recipe, never below zero.
     *
     * Counter increment only: deliberately does NOT flush the cache — likes are fired
     * straight off a rendered recipe card, so flushing here would invalidate the whole
     * cache on a plain page view. Cached lists therefore carry eventually-consistent
     * like counts (the live count is returned in the response for the UI to apply).
     */
    public function decrement_like_count(int $recipe_id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET like_count = GREATEST(like_count - 1, 0) WHERE id = %d',
            $this->table('recipes'),
            $recipe_id
        ));
    }

    /** A recipe's like count as the database has it right now. Uncached. */
    public function like_count(int $recipe_id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT like_count FROM %i WHERE id = %d',
            $this->table('recipes'),
            $recipe_id
        ));
    }
}
