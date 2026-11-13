<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Nutrition\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `nutrition` (1:1 with recipes), plus the two it needs on its parent
 * `recipes` row: the existence check and the modified stamp.
 *
 * Nutrition is a child row of a recipe and is always read through one, so it has no
 * cache entity of its own: reads are cached under 'recipes' and every write flushes
 * 'recipes'.
 */
final class NutritionRepository extends Repository {

    protected function entities(): array {
        return ['recipes'];
    }

    /** Is there a recipe with this id? Never cached. */
    public function recipe_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('recipes'), $recipe_id));
    }

    /**
     * The nutrition row of a recipe, as stored (numeric columns still strings).
     *
     * @return array<string, mixed>|null Null when the recipe has no nutrition row.
     */
    public function find(int $recipe_id): ?array {
        global $wpdb;

        // Read-through cached under the owning recipe; key captures the recipe id
        // (the only input to this read). The numeric casting stays outside, in the service.
        $nutrition = $this->remember("item:{$recipe_id}:nutrition", function () use ($wpdb, $recipe_id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE recipe_id = %d', $this->table('nutrition'), $recipe_id),
                ARRAY_A
            );
        });

        return $nutrition ?: null;
    }

    /**
     * Write the nutrition row of a recipe - update it when there is one, insert it when there
     * is not - and stamp the recipe as modified.
     *
     * @param array<string, mixed> $data        The full row, recipe_id included.
     * @param string               $modified_at MySQL datetime for recipes.modified_at.
     * @param int                  $modified_by User id for recipes.modified_by.
     */
    public function upsert(int $recipe_id, array $data, string $modified_at, int $modified_by): void {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE recipe_id = %d', $this->table('nutrition'), $recipe_id));

        if ($existing) {
            $wpdb->update($this->table('nutrition'), $data, ['recipe_id' => $recipe_id]);
        } else {
            $wpdb->insert($this->table('nutrition'), $data);
        }

        // Update recipe modified timestamp
        $wpdb->update($this->table('recipes'), [
            'modified_at' => $modified_at,
            'modified_by' => $modified_by,
        ], ['id' => $recipe_id]);

        // Nutrition child row + the recipe row changed.
        // Must run BEFORE the get_item() re-read in the controller so the response is read fresh, not from cache.
        $this->flush();
    }

    /** Remove the nutrition row of a recipe. The recipe itself is untouched. */
    public function delete(int $recipe_id): void {
        global $wpdb;

        $wpdb->delete($this->table('nutrition'), ['recipe_id' => $recipe_id]);

        // Nutrition child row removed.
        $this->flush();
    }
}
