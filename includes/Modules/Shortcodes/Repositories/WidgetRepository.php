<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the eight widget shortcodes. Every list read goes through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; only the per-user like/favourite lookups and the two recipe counts (which the widget keeps in a transient) are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so every `...$args` call site reports "found 1 replacement, expected N". The queries here pass their placeholder values in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff: NotPrepared and InterpolatedNotPrepared both stay enabled.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The reads behind the eight widget shortcodes: term lists with their published-recipe
 * counts, the compact latest/popular lists, related recipes, the recipe of the day, the
 * search box's option lists, the published-recipe count and the card child rows.
 *
 * PUBLIC reads only: `status = 'published'` is a hard literal everywhere and the recipe
 * column lists are the card allow-list written out. This class writes nothing, so it never
 * flushes.
 */
final class WidgetRepository extends Repository {

    /**
     * Most reads here hang off a recipe. The term reads name their own entity through
     * remember_as() - see terms_with_counts() and the search option lists.
     */
    protected function entities(): array {
        return ['recipes'];
    }

    // ---------------------------------------------------------------------------------
    // Card child rows (related recipes, recipe of the day)
    // ---------------------------------------------------------------------------------

    /**
     * A recipe's categories — child rows cached under the owning recipe.
     *
     * The key is shared with the listing cards' category read on purpose.
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

    /** Whether this user liked the recipe (per-user state: not cached). */
    public function has_liked(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d', $this->table('likes'), $user_id, $recipe_id
        ));
    }

    /** Whether this user favourited the recipe (per-user state: not cached). */
    public function has_favorited(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d', $this->table('favorites'), $user_id, $recipe_id
        ));
    }

    // ---------------------------------------------------------------------------------
    // [plugixa_categories] / [plugixa_cuisines]
    // ---------------------------------------------------------------------------------

    /**
     * Categories or cuisines, each with its count of PUBLISHED recipes.
     *
     * Every optional filter is a gate placeholder: bind 0 and its clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled. The exclude
     * list binds as ONE %s via FIND_IN_SET rather than a variable-length NOT IN (...),
     * so the query stays a constant literal for any number of excluded ids.
     *
     * @param string $type        'category' or 'cuisine'.
     * @param int[]  $exclude_ids Term ids to leave out.
     * @param mixed  $lang        '' when the site is not multilingual.
     * @param string $orderby     Column from the caller's allow-list ('recipe_count' is the SELECT alias).
     * @param string $order_alias 't.' when $orderby is a column of the term table, '' for the alias.
     * @param string $order       'ASC' or 'DESC'.
     * @return mixed The cached list of row objects.
     */
    public function terms_with_counts(string $type, array $exclude_ids, $lang, string $orderby, string $order_alias, string $order, int $limit) {
        global $wpdb;

        $table        = $type === 'category' ? 'categories' : 'cuisines';
        $pivot_table  = $type === 'category' ? 'recipe_categories' : 'recipe_cuisines';
        $pivot_fk     = $type === 'category' ? 'category_id' : 'cuisine_id';
        $exclude_list = implode(',', $exclude_ids);

        // Bound values in placeholder order. %i identifiers and %s/%d values share one
        // positional list, so the table and column identifiers sit inline.
        $args = [
            $this->table($table),
            $this->table($pivot_table),
            $pivot_fk,
            $this->table('recipes'),
            $exclude_ids ? 1 : 0, $exclude_list,
            $lang !== '' ? 1 : 0, $lang,
            $orderby,
            $limit,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - never the SQL text. It captures exclude/limit/orderby/order/language.
        //
        // recipe_count is PUBLISHED recipes only: COUNT(DISTINCT r.id), where r joins only
        // when the recipe is published. It was COUNT(DISTINCT p.recipe_id), which counted
        // every pivot row whatever its recipe's status - drafts, pending submissions,
        // archived recipes, even pivot rows whose recipe no longer exists - on a public
        // widget. Because the count reads recipes.status, the key depends on 'recipes' too:
        // publishing a draft writes no term or pivot row. `v2` marks the change of query
        // shape, so no key written by the old query can be read back.
        $entity = $type === 'category' ? 'categories' : 'cuisines';
        return $this->remember_as([ $entity, 'recipes' ], 'widget_taxonomy|v2|' . md5(wp_json_encode($args) . '|' . $order_alias . '|' . $order), function () use ($wpdb, $order_alias, $order, $args) {
            // ORDER BY varies in two ways that cannot be bound: the direction keyword, and
            // whether the column is qualified with the t. alias ('count' sorts by the
            // recipe_count SELECT alias, which has none). That is four literal queries -
            // the column itself still binds with %i from the caller's allow-list.
            $qualified = 't.' === $order_alias;

            if ($qualified && 'ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                           COUNT(DISTINCT r.id) as recipe_count
                    FROM %i t
                    LEFT JOIN %i p ON t.id = p.%i
                    LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                    WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                      AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                    GROUP BY t.id
                    ORDER BY t.%i ASC, t.id ASC
                    LIMIT %d',
                    ...$args
                )) ?: [];
            }

            if ($qualified) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                           COUNT(DISTINCT r.id) as recipe_count
                    FROM %i t
                    LEFT JOIN %i p ON t.id = p.%i
                    LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                    WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                      AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                    GROUP BY t.id
                    ORDER BY t.%i DESC, t.id DESC
                    LIMIT %d',
                    ...$args
                )) ?: [];
            }

            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                           COUNT(DISTINCT r.id) as recipe_count
                    FROM %i t
                    LEFT JOIN %i p ON t.id = p.%i
                    LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                    WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                      AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                    GROUP BY t.id
                    ORDER BY %i ASC, t.id ASC
                    LIMIT %d',
                    ...$args
                )) ?: [];
            }

            return $wpdb->get_results($wpdb->prepare(
                'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                       COUNT(DISTINCT r.id) as recipe_count
                FROM %i t
                LEFT JOIN %i p ON t.id = p.%i
                LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                  AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                GROUP BY t.id
                ORDER BY %i DESC, t.id DESC
                LIMIT %d',
                ...$args
            )) ?: [];
        });
    }

    // ---------------------------------------------------------------------------------
    // [plugixa_latest_recipes] / [plugixa_popular_recipes]
    // ---------------------------------------------------------------------------------

    /**
     * Published recipes for the compact list widgets, a handful of columns each.
     *
     * Gated EXISTS instead of an optional INNER JOIN (a join is structure and cannot be
     * gated away by a placeholder), and the slug list binds as ONE %s via FIND_IN_SET
     * instead of a variable-length IN (...). Both keep the query a constant literal.
     * Slugs are sanitize_title() output, so they never contain FIND_IN_SET's comma.
     *
     * @param string[] $cat_slugs Category slugs to filter by; empty for no filter.
     * @param mixed    $lang      '' when the site is not multilingual.
     * @param mixed    $orderby   A recipes column from the caller's allow-list.
     * @param string   $order     'ASC' or 'DESC'.
     * @return mixed The cached list of row objects.
     */
    public function compact_recipes(array $cat_slugs, $lang, $orderby, string $order, int $limit) {
        global $wpdb;

        $cat_list = implode(',', $cat_slugs);

        $args = [
            $this->table('recipes'),
            $cat_slugs ? 1 : 0, $this->table('recipe_categories'), $this->table('categories'), $cat_list,
            $lang !== '' ? 1 : 0, $lang,
            $orderby,
            $limit,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - never the SQL text. Captures the category filter, language,
        // orderby/order and limit.
        return $this->remember('widget_compact|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            // A direction keyword cannot be bound, so ASC/DESC is two literal queries. r.id
            // is a deterministic tiebreaker: seeded recipes routinely share a published_at,
            // and a tied sort may return any permutation.
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.image_id, r.published_at, r.created_at,
                           r.rating_average, r.rating_count, r.view_count, r.difficulty,
                           r.prep_time, r.cook_time
                    FROM %i r
                    WHERE r.status = \'published\'
                      AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                               WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                      AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                    ORDER BY r.%i ASC, r.id ASC
                    LIMIT %d',
                    ...$args
                )) ?: [];
            }

            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.id, r.title, r.image_id, r.published_at, r.created_at,
                       r.rating_average, r.rating_count, r.view_count, r.difficulty,
                       r.prep_time, r.cook_time
                FROM %i r
                WHERE r.status = \'published\'
                  AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                           WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                  AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                ORDER BY r.%i DESC, r.id DESC
                LIMIT %d',
                ...$args
            )) ?: [];
        });
    }

    /**
     * Every category of the given recipes, in display order - the caller keeps the first
     * per recipe. Batch-loaded; read-through object cache; key = recipe ids.
     *
     * @param array $recipe_ids Ids exactly as the list query returned them (the key digests them).
     * @return mixed The cached list of row objects.
     */
    public function categories_of(array $recipe_ids) {
        global $wpdb;

        $id_list = implode(',', $recipe_ids);

        return $this->remember('widget_compact_categories|' . wp_json_encode($recipe_ids), function () use ($wpdb, $id_list) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT rc.recipe_id, cat.name, cat.slug
                 FROM %i rc
                 INNER JOIN %i cat ON rc.category_id = cat.id
                 WHERE FIND_IN_SET(rc.recipe_id, %s)
                 ORDER BY rc.display_order ASC, cat.id ASC',
                $this->table('recipe_categories'),
                $this->table('categories'),
                $id_list
            )) ?: [];
        });
    }

    // ---------------------------------------------------------------------------------
    // [plugixa_related_recipes]
    // ---------------------------------------------------------------------------------

    /**
     * Category ids of one recipe. Read-through object cache; child rows cached under the
     * owning recipe.
     *
     * @return mixed
     */
    public function category_ids_of(int $recipe_id) {
        global $wpdb;

        return $this->remember('related_cat_ids|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return $wpdb->get_col($wpdb->prepare(
                'SELECT category_id FROM %i WHERE recipe_id = %d',
                $this->table('recipe_categories'),
                $recipe_id
            )) ?: [];
        });
    }

    /**
     * Cuisine ids of one recipe. Read-through object cache; child rows cached under the
     * owning recipe.
     *
     * @return mixed
     */
    public function cuisine_ids_of(int $recipe_id) {
        global $wpdb;

        return $this->remember('related_cuisine_ids|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return $wpdb->get_col($wpdb->prepare(
                'SELECT cuisine_id FROM %i WHERE recipe_id = %d',
                $this->table('recipe_cuisines'),
                $recipe_id
            )) ?: [];
        });
    }

    /**
     * Published recipes sharing a category or a cuisine with $recipe_id, best rated first.
     *
     * The old form built a UNION of one or two subqueries and joined it. Both branches
     * are now gated EXISTS clauses in a single literal query, and each id list binds as
     * ONE %s via FIND_IN_SET instead of a variable-length IN (...).
     *
     * NOTE the gate polarity. Everywhere else in this codebase an optional filter is
     * `( %d = 0 OR <clause> )` - constant-TRUE when disabled, because those filters AND
     * together. These two branches OR together (a UNION is a union), so a disabled
     * branch must be constant-FALSE instead: `( %d = 1 AND <clause> )`. Using the
     * constant-true form here would make `TRUE OR ...` match every published recipe.
     * The caller returns early when both id lists are empty, which guarantees at least
     * one gate is 1.
     *
     * @param array $cat_ids     Category ids of the recipe, as category_ids_of() returned them.
     * @param array $cuisine_ids Cuisine ids of the recipe, as cuisine_ids_of() returned them.
     * @return mixed The cached list of rows (ARRAY_A).
     */
    public function related_recipes(int $recipe_id, array $cat_ids, array $cuisine_ids, int $limit) {
        global $wpdb;

        $cat_list = implode(',', array_map('absint', $cat_ids));
        $cui_list = implode(',', array_map('absint', $cuisine_ids));

        $args = [
            $this->table('recipes'),
            $recipe_id,
            $cat_ids ? 1 : 0, $this->table('recipe_categories'), $cat_list,
            $cuisine_ids ? 1 : 0, $this->table('recipe_cuisines'), $cui_list,
            $limit,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - never the SQL text. Captures the related category / cuisine ids,
        // the excluded recipe and the limit. The ORDER BY is entirely literal here, so
        // there is no identifier to bind.
        return $this->remember('widget_related|' . md5(wp_json_encode($args)), function () use ($wpdb, $args) {
            // Column list = Columns::recipe_card('r'), written out so the string handed to
            // prepare() is a constant literal; SqlLiteralTest pins the two together.
            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                    r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                    r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                    r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                    r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                    r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                    r.is_halal, r.is_kosher, r.published_at, r.created_at
                FROM %i r
                WHERE r.status = \'published\' AND r.id != %d
                  AND (
                        ( %d = 1 AND EXISTS (SELECT 1 FROM %i rc WHERE rc.recipe_id = r.id AND FIND_IN_SET(rc.category_id, %s)) )
                     OR ( %d = 1 AND EXISTS (SELECT 1 FROM %i rcu WHERE rcu.recipe_id = r.id AND FIND_IN_SET(rcu.cuisine_id, %s)) )
                  )
                ORDER BY r.rating_average DESC, r.created_at DESC, r.id DESC
                LIMIT %d',
                ...$args
            ), ARRAY_A) ?: [];
        });
    }

    // ---------------------------------------------------------------------------------
    // [plugixa_recipe_of_day]
    // ---------------------------------------------------------------------------------

    /**
     * The recipe a previous pick named, if it still exists and is published — read-through
     * object cache.
     *
     * @param mixed $recipe_id The stored pick, exactly as the transient returned it.
     * @return mixed The row (ARRAY_A) or null.
     */
    public function published_pick($recipe_id) {
        global $wpdb;
        $recipes_table = $this->table('recipes');

        return $this->remember('widget_of_day|' . (int) $recipe_id, function () use ($wpdb, $recipes_table, $recipe_id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                        r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                        r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                        r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                        r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                        r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                        r.is_halal, r.is_kosher, r.published_at, r.created_at
                      FROM %i r
                      WHERE r.id = %d AND r.status = \'published\'',
                $recipes_table,
                $recipe_id
            ), ARRAY_A);
        });
    }

    /**
     * One featured published recipe, chosen by the daily seed. Read-through object cache;
     * key = the daily seed (the only input).
     *
     * @return mixed The row (ARRAY_A) or null.
     */
    public function featured_pick(int $daily_seed) {
        global $wpdb;
        $recipes_table = $this->table('recipes');

        return $this->remember('widget_of_day_featured|' . $daily_seed, function () use ($wpdb, $recipes_table, $daily_seed) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                    r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                    r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                    r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                    r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                    r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                    r.is_halal, r.is_kosher, r.published_at, r.created_at
                  FROM %i r
                  WHERE r.status = \'published\' AND r.is_featured = 1
                  ORDER BY RAND(%d)
                  LIMIT 1',
                $recipes_table,
                $daily_seed
            ), ARRAY_A);
        });
    }

    /**
     * Any published recipe, chosen by the daily seed - the fallback when none is featured.
     *
     * @return mixed The row (ARRAY_A) or null.
     */
    public function any_pick(int $daily_seed) {
        global $wpdb;
        $recipes_table = $this->table('recipes');

        return $this->remember('widget_of_day_any|' . $daily_seed, function () use ($wpdb, $recipes_table, $daily_seed) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                        r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                        r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                        r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                        r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                        r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                        r.is_halal, r.is_kosher, r.published_at, r.created_at
                      FROM %i r
                      WHERE r.status = \'published\'
                      ORDER BY RAND(%d)
                      LIMIT 1',
                $recipes_table,
                $daily_seed
            ), ARRAY_A);
        });
    }

    // ---------------------------------------------------------------------------------
    // [plugixa_recipe_search]
    // ---------------------------------------------------------------------------------

    /**
     * Every category, for the expanded search box. Read-through object cache; the query
     * takes no inputs.
     *
     * @return mixed
     */
    public function search_categories() {
        global $wpdb;

        return $this->remember_as('categories', 'widget_search_options', function () use ($wpdb) {
            return $wpdb->get_results($wpdb->prepare('SELECT id, name, slug FROM %i ORDER BY display_order ASC, id ASC', $this->table('categories'))) ?: [];
        });
    }

    /**
     * Every cuisine, for the expanded search box. Read-through object cache; the query
     * takes no inputs.
     *
     * @return mixed
     */
    public function search_cuisines() {
        global $wpdb;

        return $this->remember_as('cuisines', 'widget_search_options', function () use ($wpdb) {
            return $wpdb->get_results($wpdb->prepare('SELECT id, name, slug FROM %i ORDER BY display_order ASC, id ASC', $this->table('cuisines'))) ?: [];
        });
    }

    // ---------------------------------------------------------------------------------
    // [plugixa_recipe_count]
    // ---------------------------------------------------------------------------------

    /** Published recipes in one category, by slug. Uncached here: the widget keeps it in a transient. */
    public function count_published_in_category(string $category): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i r
                     WHERE r.status = \'published\'
                       AND EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                    WHERE rc.recipe_id = r.id AND c.slug = %s)',
            $this->table('recipes'),
            $this->table('recipe_categories'),
            $this->table('categories'),
            $category
        ));
    }

    /** All published recipes. Uncached here: the widget keeps it in a transient. */
    public function count_published(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = \'published\'', $this->table('recipes'))
        );
    }
}
