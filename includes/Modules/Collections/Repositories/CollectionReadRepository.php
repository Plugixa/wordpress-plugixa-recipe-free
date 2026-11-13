<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Collections\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the public routes. Content reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user/per-IP state lookups (favourite, like, helpful vote), the write paths and the view/like counter updates are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The PUBLIC read of `collections`: published and public rows only, public columns only.
 *
 * Kept apart from CollectionRepository on purpose. That class filters status and visibility
 * through bound gates for the admin list; here both are hard literals that no caller can
 * switch off. This class writes nothing.
 */
final class CollectionReadRepository extends Repository {

    /** The public list counts published recipes, so it is cached under both. */
    protected function entities(): array {
        return ['collections', 'recipes'];
    }

    /**
     * Collections explicitly marked public, each with its count of published recipes.
     * Pinned to published status AND public visibility; the owning user_id is not selected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function public_list(): array {
        global $wpdb;

        // Read-through object cache; the query takes no inputs. `|v2` marks the query SHAPE:
        // recipe_count changed meaning (below), and the key digests inputs, not SQL, so no
        // key written by the old query can be read back. Keyed on 'recipes' as well, because
        // the count now reads recipes.status and publishing a recipe writes no collection row.
        return (array) $this->remember_as(['collections', 'recipes'], 'frontend_public|v2', function () use ($wpdb) {
            // The col. prefix is load-bearing here: the JOINs put three tables in scope.
            // This list must stay identical to Columns::collection_public('col').
            //
            // recipe_count counts links to PUBLISHED recipes, through the joined recipe row.
            // It was COUNT(cr.recipe_id), which counted every link row - drafts, pending
            // recipes and links to deleted recipes included - on an anonymous endpoint. It is
            // deliberately COUNT(r.id), not COUNT(DISTINCT r.id): a meal plan can hold the
            // same recipe on several days, and those entries have always each counted.
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT col.id, col.name, col.slug, col.description, col.image_id,
                        col.type, col.is_featured, col.display_order, col.created_at,
                        COUNT(r.id) AS recipe_count
                FROM %i col
                LEFT JOIN %i cr ON col.id = cr.collection_id
                LEFT JOIN %i r ON r.id = cr.recipe_id AND r.status = \'published\'
                WHERE col.status = \'published\' AND col.visibility = \'public\'
                GROUP BY col.id
                ORDER BY col.display_order ASC, col.id ASC',
                    $this->table('collections'),
                    $this->table('collection_recipes'),
                    $this->table('recipes')
                ),
                ARRAY_A
            ) ?: [];
        });
    }
}
