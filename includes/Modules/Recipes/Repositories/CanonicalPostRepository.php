<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for one column. The read on the public path goes through the object cache; the writes flush it. Every query is a constant literal with the table bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * `recipes.canonical_post_id`: the post that embeds a recipe whole, if one does.
 *
 * Written when a post is saved (Services\CanonicalPosts), read when the recipe's own page
 * prints its canonical link. The column says which post CLAIMED the recipe; whether that
 * post is still published is asked of WordPress at read time, never assumed from this.
 */
final class CanonicalPostRepository extends Repository {

    protected function entities(): array {
        return ['recipes'];
    }

    /** The post a published recipe is embedded in, or 0. */
    public function post_of(int $recipe_id): int {
        global $wpdb;

        return (int) $this->remember('canonical_post|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT canonical_post_id FROM %i WHERE id = %d AND status = \'published\'',
                $this->table('recipes'),
                $recipe_id
            ));
        });
    }

    /**
     * The recipes that name a post as their canonical. Uncached: asked only while a post
     * is being saved.
     *
     * @return int[]
     */
    public function recipes_of(int $post_id): array {
        global $wpdb;

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i WHERE canonical_post_id = %d ORDER BY id ASC',
            $this->table('recipes'),
            $post_id
        )));
    }

    /**
     * Name a post as a recipe's canonical - unless another post already is. The first post
     * to embed a recipe keeps it; a second one must not move a canonical that search
     * engines have already been given.
     */
    public function claim(int $recipe_id, int $post_id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET canonical_post_id = %d
              WHERE id = %d AND ( canonical_post_id IS NULL OR canonical_post_id = 0 OR canonical_post_id = %d )',
            $this->table('recipes'),
            $post_id,
            $recipe_id,
            $post_id
        ));

        $this->flush();
    }

    /** A post no longer embeds the recipe (or is no longer public): the recipe is its own page again. */
    public function release(int $recipe_id, int $post_id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET canonical_post_id = NULL WHERE id = %d AND canonical_post_id = %d',
            $this->table('recipes'),
            $recipe_id,
            $post_id
        ));

        $this->flush();
    }
}
