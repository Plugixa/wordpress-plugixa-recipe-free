<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Repositories\IngredientRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a recipe's ingredient list is and what may be done to it.
 *
 * The rules live here rather than in the controller: ingredients only exist for a recipe that
 * exists, a save replaces the whole list, and every stored field is sanitised here. It holds
 * no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown. The controller maps it onto the response.
 */
final class IngredientService {

    private IngredientRepository $ingredients;

    public function __construct(IngredientRepository $ingredients) {
        $this->ingredients = $ingredients;
    }

    /**
     * Get all ingredients for a recipe.
     *
     * @return array<int, array<string, mixed>>|WP_Error A 404 failure when there is no such recipe.
     */
    public function list(int $recipe_id) {
        // Verify recipe exists
        if (!$this->ingredients->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        return $this->ingredients->for_recipe($recipe_id);
    }

    /**
     * Replace all ingredients for a recipe (delete existing + insert new).
     *
     * @param mixed $ingredients Raw payload value: a list of ingredient rows.
     * @return true|WP_Error
     */
    public function replace(int $recipe_id, $ingredients) {
        // Verify recipe exists
        if (!$this->ingredients->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $rows = [];
        foreach ($ingredients as $ing) {
            $rows[] = ChildRows::ingredient($recipe_id, $ing);
        }

        $this->ingredients->replace($recipe_id, $rows, current_time('mysql'), get_current_user_id());

        return true;
    }

    /**
     * Reorder ingredients for a recipe.
     *
     * @param mixed $items Raw payload value: a list of {id, sort_order[, group_order, group_name]}.
     * @return true|WP_Error
     */
    public function reorder(int $recipe_id, $items) {
        // Verify recipe exists
        if (!$this->ingredients->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        if (empty($items) || !is_array($items)) {
            return Failure::make(__('Items are required.', 'plugixa-recipe'));
        }

        $updates = [];
        foreach ($items as $item) {
            $update = ['sort_order' => (int) ($item['sort_order'] ?? 0)];
            if (isset($item['group_order'])) {
                $update['group_order'] = (int) $item['group_order'];
            }
            if (isset($item['group_name'])) {
                $update['group_name'] = sanitize_text_field($item['group_name']);
            }
            $updates[] = [
                'id'     => (int) $item['id'],
                'update' => $update,
            ];
        }

        $this->ingredients->reorder($recipe_id, $updates);

        return true;
    }
}
