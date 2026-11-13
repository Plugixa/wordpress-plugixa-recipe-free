<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the shortcode renderers. The listing and its child rows go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user like/favourite lookups and the small view/shortcode config reads are deliberately uncached. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` listing queries report "found 1 replacement, expected 23". The values are passed in one pre-built positional array (see $args above the call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The reads behind [plugixa_recipe] / [plugixa_recipes]: the published listing, the child
 * rows each card draws, the ingredient names for the ingredient filter, the per-user
 * like/favourite state and the saved shortcode's config.
 *
 * PUBLIC reads only: `status = 'published'` is a hard literal and the column list is the
 * public allow-list written out. This class writes nothing, so it never flushes. It is
 * deliberately separate from ShortcodeRepository, which serves the admin REST routes.
 */
final class ListingRepository extends Repository {

    /** Everything cached here hangs off a recipe; a recipe write invalidates it. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * The saved shortcode's row (its JSON `config` column only), or null. Uncached: one
     * small read per render.
     *
     * @return object|null
     */
    public function saved_config(int $id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT config FROM %i WHERE id = %d',
            $this->table('shortcodes'),
            $id
        ));
    }

    /**
     * Slugs of the given term ids, for a filter the admin builder saved as ids.
     *
     * @param string $taxonomy One of 'categories', 'cuisines', 'courses' - the caller
     *                         allow-lists it. It is the table AND the cache entity.
     * @param int[]  $ids
     * @return mixed The cached get_col() result.
     */
    public function slugs_for_ids(string $taxonomy, array $ids) {
        global $wpdb;

        // $taxonomy is one of the three names allow-listed by the caller, so the table name
        // is internal; it still binds as an identifier with %i. The id list binds as ONE
        // %s via FIND_IN_SET, keeping the query a constant literal for any id count.
        $table   = $this->table($taxonomy);
        $id_list = implode(',', $ids);

        return $this->remember_as(
            $taxonomy,
            'resolve_slugs|' . wp_json_encode($ids),
            function () use ($wpdb, $table, $id_list) {
                return $wpdb->get_col($wpdb->prepare('SELECT slug FROM %i WHERE FIND_IN_SET(id, %s)', $table, $id_list));
            }
        );
    }

    /**
     * One page of published recipes matching the listing's filters, public columns only.
     *
     * Every optional filter is a gate placeholder: bind 0 and its clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled.
     *
     * The three taxonomy filters are gated EXISTS subqueries rather than optional
     * INNER JOINs. Two reasons: a JOIN cannot be gated away by a placeholder (it is
     * structure, not a predicate), and EXISTS selects the same row set without the
     * row multiplication a JOIN causes - which is what DISTINCT was undoing. The slug
     * list binds as ONE %s via FIND_IN_SET instead of a variable-length IN (...), so
     * the SQL stays a constant literal no matter how many slugs arrive. Slugs are
     * sanitize_title() output, so they can never contain the comma FIND_IN_SET splits on.
     *
     * A direction keyword cannot be bound, so ASC/DESC is two literal queries; "unlimited"
     * binds a limit larger than any possible row count rather than adding a third and
     * fourth literal.
     *
     * The queries sort by r.id last as a deterministic tiebreaker: seeded and imported
     * recipes routinely share a created_at/published_at to the second, and a sort with
     * ties may return any permutation, so paged output could repeat one recipe and skip
     * another. Sorting by the primary key last makes paging stable.
     *
     * @param int      $featured      1 to keep featured recipes only, else 0.
     * @param float    $min_rating    0.0 for no minimum.
     * @param string   $difficulty    '' for any.
     * @param mixed    $lang          '' when the site is not multilingual.
     * @param string[] $cat_slugs
     * @param string[] $cuisine_slugs
     * @param string[] $course_slugs
     * @param mixed    $orderby       A recipes column from the caller's allow-list.
     * @param string   $order         'ASC' or 'DESC'.
     * @param int      $limit         PHP_INT_MAX for unlimited.
     * @param string   $search        A visitor's search words, '' for none. Matched against
     *                                the title and the description.
     * @return mixed The list of rows (ARRAY_A) - cached, except for a search.
     */
    public function published(int $featured, float $min_rating, string $difficulty, $lang, array $cat_slugs, array $cuisine_slugs, array $course_slugs, $orderby, string $order, int $limit, int $offset, string $search = '') {
        global $wpdb;

        $like = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        $cat_list     = implode(',', $cat_slugs);
        $cuisine_list = implode(',', $cuisine_slugs);
        $course_list  = implode(',', $course_slugs);

        // Bound values for the shared WHERE block, in placeholder order. The %i table
        // identifiers are interleaved at the call site because %i and %s/%d share one
        // positional argument list.
        $args = [
            $this->table('recipes'),
            $featured,
            $min_rating !== 0.0 ? 1 : 0, $min_rating,
            $difficulty !== '' ? 1 : 0, $difficulty,
            $lang !== '' ? 1 : 0, $lang,
            $cat_slugs ? 1 : 0, $this->table('recipe_categories'), $this->table('categories'), $cat_list,
            $cuisine_slugs ? 1 : 0, $this->table('recipe_cuisines'), $this->table('cuisines'), $cuisine_list,
            $course_slugs ? 1 : 0, $this->table('recipe_courses'), $this->table('courses'), $course_list,
            '' !== $search ? 1 : 0, $like, $like,
            $orderby,
            $limit,
            $offset,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - it must NOT embed the SQL text, which now lives inline in prepare().
        // It captures every input that changes the result: categories/cuisines/courses,
        // featured, min_rating, difficulty, language, orderby/order, limit/offset.
        $cache_key = 'shortcode|' . md5(wp_json_encode($args) . '|' . $order);

        // The column list below is Columns::recipe_public('r') written out as a literal, so
        // the string handed to prepare() is a constant in both arms. SqlLiteralTest asserts
        // the two stay identical.
        $read = function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results(
                        $wpdb->prepare(
                    'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                        r.description, r.excerpt, r.notes, r.tips, r.image_id,
                        r.gallery, r.tags, r.equipment, r.video_url, r.servings,
                        r.servings_unit, r.yield_amount, r.yield_unit, r.prep_time, r.cook_time,
                        r.rest_time, r.total_time, r.difficulty, r.cost, r.season,
                        r.is_featured, r.is_editor_pick, r.display_order, r.source, r.source_url,
                        r.author_name, r.view_count, r.rating_average, r.rating_count, r.like_count,
                        r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free, r.is_keto,
                        r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free, r.is_halal,
                        r.is_kosher, r.published_at, r.created_at
                      FROM %i r
                      WHERE r.status = \'published\'
                        AND ( %d = 0 OR r.is_featured = 1 )
                        AND ( %d = 0 OR r.rating_average >= %f )
                        AND ( %d = 0 OR r.difficulty = %s )
                        AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                                 WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu INNER JOIN %i cu ON rcu.cuisine_id = cu.id
                                                 WHERE rcu.recipe_id = r.id AND FIND_IN_SET(cu.slug, %s)) )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco INNER JOIN %i co ON rco.course_id = co.id
                                                 WHERE rco.recipe_id = r.id AND FIND_IN_SET(co.slug, %s)) )
                        AND ( %d = 0 OR r.title LIKE %s OR r.description LIKE %s )
                      ORDER BY r.%i ASC, r.id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.notes, r.tips, r.image_id,
                    r.gallery, r.tags, r.equipment, r.video_url, r.servings,
                    r.servings_unit, r.yield_amount, r.yield_unit, r.prep_time, r.cook_time,
                    r.rest_time, r.total_time, r.difficulty, r.cost, r.season,
                    r.is_featured, r.is_editor_pick, r.display_order, r.source, r.source_url,
                    r.author_name, r.view_count, r.rating_average, r.rating_count, r.like_count,
                    r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free, r.is_keto,
                    r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free, r.is_halal,
                    r.is_kosher, r.published_at, r.created_at
                  FROM %i r
                  WHERE r.status = \'published\'
                    AND ( %d = 0 OR r.is_featured = 1 )
                    AND ( %d = 0 OR r.rating_average >= %f )
                    AND ( %d = 0 OR r.difficulty = %s )
                    AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                             WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu INNER JOIN %i cu ON rcu.cuisine_id = cu.id
                                             WHERE rcu.recipe_id = r.id AND FIND_IN_SET(cu.slug, %s)) )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco INNER JOIN %i co ON rco.course_id = co.id
                                             WHERE rco.recipe_id = r.id AND FIND_IN_SET(co.slug, %s)) )
                    AND ( %d = 0 OR r.title LIKE %s OR r.description LIKE %s )
                  ORDER BY r.%i DESC, r.id DESC
                  LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        };

        // A search is not cached: its key would be whatever a visitor typed, which is an
        // unbounded number of entries for results nobody asks for twice.
        return '' !== $search ? $read() : $this->remember($cache_key, $read);
    }

    /**
     * Lower-cased ingredient names per recipe, one comma-joined row each, for the
     * ingredient filter (single query, not N+1).
     *
     * @param int[] $recipe_ids
     * @return mixed The cached list of rows (recipe_id, names).
     */
    public function ingredient_names(array $recipe_ids) {
        global $wpdb;

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
        // IN (...), which keeps the query a constant literal for any result size.
        $id_list = implode(',', $recipe_ids);

        // Read-through object cache; key = the recipe ids in the result set.
        return $this->remember('ingredient_filter|' . wp_json_encode($recipe_ids), function () use ($wpdb, $id_list) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT recipe_id, GROUP_CONCAT(DISTINCT LOWER(name) SEPARATOR \',\') as names
                         FROM %i WHERE FIND_IN_SET(recipe_id, %s) GROUP BY recipe_id',
                $this->table('ingredients'),
                $id_list
            )) ?: [];
        });
    }

    /**
     * A recipe's categories — child rows cached under the owning recipe.
     *
     * The key is shared with the widget cards' category read on purpose.
     *
     * @return mixed
     */
    public function card_categories(int $recipe_id) {
        global $wpdb;

        return $this->remember('card_categories|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                 INNER JOIN %i rc ON c.id = rc.category_id
                 WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC, c.id ASC',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * A recipe's cuisines — child rows cached under the owning recipe.
     *
     * @return mixed
     */
    public function card_cuisines(int $recipe_id) {
        global $wpdb;

        return $this->remember('card_cuisines|' . $recipe_id, function () use ($wpdb, $recipe_id) {
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
     * A recipe's nutrition (single row) — child row cached under the owning recipe.
     *
     * @return mixed
     */
    public function card_nutrition(int $recipe_id) {
        global $wpdb;
        $nutrition_table = $this->table('nutrition');

        return $this->remember('card_nutrition|' . $recipe_id, function () use ($wpdb, $nutrition_table, $recipe_id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT id, recipe_id, serving_size, calories, total_fat,
                    saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                    dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                    calcium, iron, potassium, source
                  FROM %i
                  WHERE recipe_id = %d',
                $nutrition_table,
                $recipe_id
            ), ARRAY_A) ?: [];
        });
    }

    /** Whether this user liked the recipe (per-user state: not cached). */
    public function has_liked(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('likes'), $user_id, $recipe_id
        ));
    }

    /** Whether this user favourited the recipe (per-user state: not cached). */
    public function has_favorited(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('favorites'), $user_id, $recipe_id
        ));
    }
}
