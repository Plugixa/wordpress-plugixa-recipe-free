<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Favorites\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the ADMIN favourites routes run: a user's own favourites list, the check for
 * one recipe, and the toggle's lookups and writes.
 *
 * The recipe lookup here accepts a recipe in ANY status - an editor can favourite a draft.
 * The public toggle's lookup is pinned to published recipes and lives in
 * PublicFavoriteRepository, so the two can never be confused for one another.
 */
final class FavoriteRepository extends Repository {

    /** Favourite status is surfaced on cached recipe reads too, so a write invalidates both. */
    protected function entities(): array {
        return ['favorites', 'recipes'];
    }

    /** How many recipes the user has favourited. */
    public function count_for_user(int $user_id): int {
        global $wpdb;

        // Count — read-through object cache; key = user id.
        return (int) $this->remember('count|' . $user_id, function () use ($wpdb, $user_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d',
                $this->table('favorites'),
                $user_id
            ));
        });
    }

    /**
     * One page of the user's favourite recipes, each row with its favorited_at.
     *
     * The sort column lives either on the recipe (r.) or on the favourite (f.), so the
     * alias is part of the ORDER BY and cannot be bound - and nor can the direction
     * keyword. That is four literal variants, with the column itself bound by %i in each.
     * The trailing id keeps paging stable when the sort column has ties.
     *
     * @param bool   $on_recipe True when $orderby is a column of the recipe, false for the favourite.
     * @param string $orderby   A column from the service's allow-list.
     * @param string $order     'ASC' or 'DESC'
     * @return array<int, array<string, mixed>>
     */
    public function page_for_user(int $user_id, bool $on_recipe, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        // Read-through object cache; key = user + sort + pagination (every input that changes the result).
        $cache_key = 'list|' . $user_id . '|' . ($on_recipe ? 'r.' : 'f.') . $orderby . '|' . $order . '|' . $limit . '|' . $offset;
        $args = [
            $this->table('favorites'),
            $this->table('recipes'),
            $user_id,
            $orderby,
            $limit,
            $offset,
        ];

        return (array) $this->remember($cache_key, function () use ($wpdb, $on_recipe, $order, $args) {
            if ($on_recipe && 'ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                            r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                            r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                            f.created_at AS favorited_at
                    FROM %i f
                    INNER JOIN %i r ON f.recipe_id = r.id
                    WHERE f.user_id = %d
                    ORDER BY r.%i ASC, r.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            if ($on_recipe) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                            r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                            r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                            f.created_at AS favorited_at
                    FROM %i f
                    INNER JOIN %i r ON f.recipe_id = r.id
                    WHERE f.user_id = %d
                    ORDER BY r.%i DESC, r.id DESC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                            r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                            r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                            f.created_at AS favorited_at
                    FROM %i f
                    INNER JOIN %i r ON f.recipe_id = r.id
                    WHERE f.user_id = %d
                    ORDER BY f.%i ASC, f.recipe_id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                        r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                        r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        f.created_at AS favorited_at
                FROM %i f
                INNER JOIN %i r ON f.recipe_id = r.id
                WHERE f.user_id = %d
                ORDER BY f.%i DESC, f.recipe_id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });
    }

    /** Is there such a recipe, in any status? Uncached. */
    public function recipe_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('recipes'), $recipe_id));
    }

    /** Has the user favourited the recipe? Straight from the database, for the toggle. */
    public function is_favorited_uncached(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('favorites'),
            $user_id, $recipe_id
        ));
    }

    /** Has the user favourited the recipe? Read through the cache, for the check route. */
    public function is_favorited(int $user_id, int $recipe_id): bool {
        global $wpdb;

        // Read-through object cache; key = user + recipe.
        return (bool) $this->remember('check|' . $user_id . '|' . $recipe_id, function () use ($wpdb, $user_id, $recipe_id) {
            return (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
                $this->table('favorites'),
                $user_id, $recipe_id
            ));
        });
    }

    /** Remove the user's favourite for the recipe. */
    public function remove(int $user_id, int $recipe_id): void {
        global $wpdb;

        $wpdb->delete($this->table('favorites'), [
            'user_id'   => $user_id,
            'recipe_id' => $recipe_id,
        ]);

        // Content write: favourite status is surfaced on cached recipe reads too.
        $this->flush();
    }

    /**
     * Record the recipe as one of the user's favourites.
     *
     * @param string $created_at MySQL datetime, in the site's timezone.
     */
    public function add(int $user_id, int $recipe_id, string $created_at): void {
        global $wpdb;

        $wpdb->insert($this->table('favorites'), [
            'user_id'    => $user_id,
            'recipe_id'  => $recipe_id,
            'created_at' => $created_at,
        ]);

        // Content write: favourite status is surfaced on cached recipe reads too.
        $this->flush();
    }
}
