<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `ingredients`, plus the two it needs on its parent `recipes` row: the
 * existence check and the modified stamp.
 *
 * Ingredients are child rows of a recipe and are always read through one, so they
 * have no cache entity of their own: reads are cached under 'recipes' and every
 * write flushes 'recipes'.
 */
final class IngredientRepository extends Repository {

    protected function entities(): array {
        return ['recipes'];
    }

    /** Is there a recipe with this id? Never cached. */
    public function recipe_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('recipes'), $recipe_id));
    }

    /**
     * All ingredients of a recipe, in group then sort order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for_recipe(int $recipe_id): array {
        global $wpdb;

        // Read-through cached under the owning recipe; key captures the recipe id
        // (the only input to this read).
        return (array) $this->remember("item:{$recipe_id}:ingredients", function () use ($wpdb, $recipe_id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
                    $this->table('ingredients'),
                    $recipe_id
                ),
                ARRAY_A
            );
        });
    }

    /**
     * Replace all ingredients of a recipe (delete existing + insert new) and stamp the recipe
     * as modified. One method, one flush: the delete and the re-insert are a single write.
     *
     * @param array<int, array<string, mixed>> $rows        Full rows, recipe_id included, already sanitised.
     * @param string                           $modified_at MySQL datetime for recipes.modified_at.
     * @param int                              $modified_by User id for recipes.modified_by.
     */
    public function replace(int $recipe_id, array $rows, string $modified_at, int $modified_by): void {
        global $wpdb;

        // Delete all existing ingredients
        $wpdb->delete($this->table('ingredients'), ['recipe_id' => $recipe_id]);

        // Insert new ingredients
        foreach ($rows as $row) {
            $wpdb->insert($this->table('ingredients'), $row);
        }

        // Update recipe modified timestamp
        $wpdb->update($this->table('recipes'), [
            'modified_at' => $modified_at,
            'modified_by' => $modified_by,
        ], ['id' => $recipe_id]);

        // Ingredient child rows + the recipe row changed.
        // Must run BEFORE the get_items() re-read in the controller so the response is read fresh, not from cache.
        $this->flush();
    }

    /**
     * Store a new order. Each update is pinned to the recipe as well as the row id, so an id
     * belonging to another recipe changes nothing. One flush for the whole batch.
     *
     * @param array<int, array{id: int, update: array<string, mixed>}> $updates
     */
    public function reorder(int $recipe_id, array $updates): void {
        global $wpdb;

        foreach ($updates as $item) {
            $wpdb->update($this->table('ingredients'), $item['update'], [
                'id'        => $item['id'],
                'recipe_id' => $recipe_id,
            ]);
        }

        // Ingredient child rows changed.
        $this->flush();
    }
}
