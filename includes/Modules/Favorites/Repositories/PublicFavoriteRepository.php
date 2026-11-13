<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Favorites\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the public routes. Content reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user/per-IP state lookups (favourite, like, helpful vote), the write paths and the view/like counter updates are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the PUBLIC favourite toggle runs.
 *
 * Kept apart from FavoriteRepository because the recipe lookup here is pinned to
 * `status = 'published'` as a hard literal: a logged-in visitor must not be able to favourite,
 * or learn the existence of, a draft. Nothing in this class reads a recipe in any other status.
 */
final class PublicFavoriteRepository extends Repository {

    /** Favourite status is surfaced on cached recipe reads too, so a write invalidates both. */
    protected function entities(): array {
        return ['favorites', 'recipes'];
    }

    /** Is there such a recipe, and is it published? Uncached. */
    public function published_recipe_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND status = \'published\'',
            $this->table('recipes'),
            $recipe_id
        ));
    }

    /** Has the user favourited the recipe? Per-user state, deliberately uncached. */
    public function is_favorited(int $user_id, int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $this->table('favorites'), $user_id, $recipe_id
        ));
    }

    /** Remove the user's favourite for the recipe. */
    public function remove(int $user_id, int $recipe_id): void {
        global $wpdb;

        $wpdb->delete($this->table('favorites'), ['user_id' => $user_id, 'recipe_id' => $recipe_id]);

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
            'user_id' => $user_id, 'recipe_id' => $recipe_id, 'created_at' => $created_at,
        ]);

        // Content write: favourite status is surfaced on cached recipe reads too.
        $this->flush();
    }
}
