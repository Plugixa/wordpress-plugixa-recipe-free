<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the single-recipe render. The content reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user favourite lookup and the view_count increment are deliberately uncached (see the comment at the increment). Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the single recipe PAGE runs (Frontend\SinglePage): the published recipe, its
 * child rows, its approved reviews, the visitor's favourite, the view counter and the
 * default view's config.
 *
 * The page's own repository on purpose. Its reads look like PublicRecipeRepository's but are
 * not the same reads: they carry their own cache keys (`single|{id}`, `single_categories|{id}`
 * ...), the recipe SELECT carries one extra column, and the category list is ordered. Merging
 * them would change what a visitor's page is cached under.
 *
 * `status = 'published'` and `status = 'approved'` are hard literals here and nothing gates
 * them. Nothing in this repository flushes: its one write is the view counter, which runs on
 * every page view.
 */
final class SinglePageRepository extends Repository {

    /** Everything but the review list is cached under the owning recipe. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * One published recipe, as the page template needs it.
     *
     * @return array<string, mixed>|null Null when there is no such recipe, or it is not published.
     */
    public function find_published(int $id) {
        global $wpdb;

        // Read-through object cache; key = recipe id.
        //
        // The column list is Columns::recipe_public('') written out as a literal, so the
        // string handed to prepare() is a constant, PLUS one extra column: `status`.
        // Frontend\MetaTags reads status off this same render struct to decide whether to emit a
        // noindex robots tag, and this result feeds the template rather than a REST
        // response, so that column never reaches a client. SqlLiteralTest knows about this
        // one documented extra; adding another needs a change there too.
        $recipes_table = $this->table('recipes');
        return $this->remember('single|' . $id, function () use ($wpdb, $recipes_table, $id) {
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
    }

    /**
     * Categories — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(int $recipe_id) {
        global $wpdb;

        return $this->remember('single_categories|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                 INNER JOIN %i rc ON c.id = rc.category_id
                 WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Cuisines — child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cuisines(int $recipe_id) {
        global $wpdb;

        return $this->remember('single_cuisines|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                 INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                 WHERE rcu.recipe_id = %d',
                $this->table('cuisines'),
                $this->table('recipe_cuisines'),
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * One published recipe picked at random, or null when there is none. Never cached:
     * a cached "random" recipe is the same recipe for everyone.
     *
     * @return array{id:string, slug:string}|null
     */
    public function random_published(): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, slug FROM %i WHERE status = \'published\' ORDER BY RAND() LIMIT 1',
            $this->table('recipes')
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * The site's units, for Services\UnitResolver. Cached under `units`, so adding or
     * correcting a unit reaches the recipe pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function units() {
        global $wpdb;

        return $this->remember_as('units', 'single_units', function () use ($wpdb) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT name, abbreviation, `system`, `type`, conversion_factor FROM %i ORDER BY id ASC',
                $this->table('units')
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Courses (Main, Dessert...) - child rows cached under the owning recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function courses(int $recipe_id) {
        global $wpdb;

        return $this->remember('single_courses|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT co.id, co.name, co.slug FROM %i co
                 INNER JOIN %i rco ON co.id = rco.course_id
                 WHERE rco.recipe_id = %d
                 ORDER BY co.display_order ASC, co.id ASC',
                $this->table('courses'),
                $this->table('recipe_courses'),
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Ingredient rows, in group then sort order — child rows cached under the recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ingredients(int $recipe_id) {
        global $wpdb;

        $ingredients_table = $this->table('ingredients');
        return $this->remember('single_ingredients|' . $recipe_id, function () use ($wpdb, $ingredients_table, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    quantity, unit, name, notes, link_url, amount, amount_max, is_optional, substitution
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $ingredients_table,
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Instruction rows, in group then sort order — child rows cached under the recipe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function instructions(int $recipe_id) {
        global $wpdb;

        $instructions_table = $this->table('instructions');
        return $this->remember('single_instructions|' . $recipe_id, function () use ($wpdb, $instructions_table, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT id, recipe_id, group_name, group_order, sort_order,
                    title, instruction_text, image_id, video_url, duration, timer_seconds, tip
                  FROM %i
                  WHERE recipe_id = %d
                  ORDER BY group_order ASC, sort_order ASC',
                $instructions_table,
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Nutrition — child row cached under the owning recipe.
     *
     * @return array<string, mixed>|null
     */
    public function nutrition(int $recipe_id) {
        global $wpdb;

        $nutrition_table = $this->table('nutrition');
        return $this->remember('single_nutrition|' . $recipe_id, function () use ($wpdb, $nutrition_table, $recipe_id) {
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
    }

    /**
     * A recipe's approved reviews, the whole set, in the configured order. Cached under
     * 'reviews', not under the recipe.
     *
     * ORDER BY is not one identifier here - each option is a different column set with
     * its own directions, and a direction keyword cannot be bound at all. So the sort
     * is a match over five *literal* queries rather than an assembled fragment: the
     * string handed to prepare() is a constant in every arm. $sort_key only chooses
     * which arm runs; it never reaches the SQL. Any unknown value falls to 'newest'.
     *
     * @param mixed $sort_key The `reviews_default_sort` setting, as stored.
     * @return array<int, object>
     */
    public function approved_reviews(int $recipe_id, $sort_key) {
        global $wpdb;

        $reviews_table = $this->table('reviews');
        return $this->remember_as('reviews', 'single|' . $recipe_id . '|' . $sort_key, function () use ($wpdb, $reviews_table, $recipe_id, $sort_key) {
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
    }

    /** Has this user favourited the recipe? Per-user state: not cached. */
    public function is_favorited(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('favorites'),
            $user_id,
            $recipe_id
        ));
    }

    /**
     * Count one more view of a recipe.
     *
     * Counter increment only: deliberately does NOT flush the cache — this runs on every
     * render, so flushing here would invalidate the whole cache on every page view. Cached
     * lists therefore carry eventually-consistent view counts.
     */
    public function increment_view_count(int $recipe_id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET view_count = view_count + 1 WHERE id = %d',
            $this->table('recipes'),
            $recipe_id
        ));
    }

    /**
     * The view the page is styled from: the default one, or failing that the oldest. Uncached.
     *
     * @return object|null A row carrying `config` (JSON), or null when no view exists.
     */
    public function default_view_row() {
        global $wpdb;
        $table = $this->table('views');

        $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM %i WHERE is_default = 1 LIMIT 1', $table));
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM %i ORDER BY id ASC LIMIT 1', $table));
        }

        return $row;
    }
}
