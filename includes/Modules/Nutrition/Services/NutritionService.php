<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Nutrition\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Nutrition\Repositories\NutritionRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a recipe's nutrition record is and what may be done to it.
 *
 * The rules live here rather than in the controller: nutrition only exists for a recipe that
 * exists, an empty value is stored as NULL rather than 0, and numeric columns leave as floats.
 * It holds no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown. "The recipe has no nutrition row" is
 * null - which is a normal answer here, not a 404; only a missing RECIPE is refused.
 */
final class NutritionService {

    /** Columns returned as floats (or null when not set). */
    private const NUMERIC_FIELDS = ['calories', 'total_fat', 'saturated_fat', 'trans_fat', 'cholesterol', 'sodium', 'total_carbohydrate', 'dietary_fiber', 'total_sugars', 'protein', 'vitamin_a', 'vitamin_c', 'calcium', 'iron', 'potassium'];

    private NutritionRepository $nutrition;

    public function __construct(NutritionRepository $nutrition) {
        $this->nutrition = $nutrition;
    }

    /**
     * Nutrition data for a recipe.
     *
     * @return array<string, mixed>|null|WP_Error Null when the recipe has no nutrition row;
     *                                            a 404 failure when there is no such recipe.
     */
    public function get(int $recipe_id) {
        if (!$this->nutrition->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $nutrition = $this->nutrition->find($recipe_id);

        if (!$nutrition) {
            return null;
        }

        // Cast numeric fields
        foreach (self::NUMERIC_FIELDS as $field) {
            if ($nutrition[$field] !== null) {
                $nutrition[$field] = (float) $nutrition[$field];
            }
        }

        return $nutrition;
    }

    /**
     * Upsert nutrition data for a recipe. Every column is written on every save: a field
     * that is absent or '' is stored as NULL, and `source` defaults to 'manual'.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return true|WP_Error
     */
    public function save(int $recipe_id, array $params) {
        if (!$this->nutrition->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $data = [
            'recipe_id'          => $recipe_id,
            'serving_size'       => sanitize_text_field($params['serving_size'] ?? ''),
            'calories'           => isset($params['calories']) && $params['calories'] !== '' ? (float) $params['calories'] : null,
            'total_fat'          => isset($params['total_fat']) && $params['total_fat'] !== '' ? (float) $params['total_fat'] : null,
            'saturated_fat'      => isset($params['saturated_fat']) && $params['saturated_fat'] !== '' ? (float) $params['saturated_fat'] : null,
            'trans_fat'          => isset($params['trans_fat']) && $params['trans_fat'] !== '' ? (float) $params['trans_fat'] : null,
            'cholesterol'        => isset($params['cholesterol']) && $params['cholesterol'] !== '' ? (float) $params['cholesterol'] : null,
            'sodium'             => isset($params['sodium']) && $params['sodium'] !== '' ? (float) $params['sodium'] : null,
            'total_carbohydrate' => isset($params['total_carbohydrate']) && $params['total_carbohydrate'] !== '' ? (float) $params['total_carbohydrate'] : null,
            'dietary_fiber'      => isset($params['dietary_fiber']) && $params['dietary_fiber'] !== '' ? (float) $params['dietary_fiber'] : null,
            'total_sugars'       => isset($params['total_sugars']) && $params['total_sugars'] !== '' ? (float) $params['total_sugars'] : null,
            'protein'            => isset($params['protein']) && $params['protein'] !== '' ? (float) $params['protein'] : null,
            'vitamin_a'          => isset($params['vitamin_a']) && $params['vitamin_a'] !== '' ? (float) $params['vitamin_a'] : null,
            'vitamin_c'          => isset($params['vitamin_c']) && $params['vitamin_c'] !== '' ? (float) $params['vitamin_c'] : null,
            'calcium'            => isset($params['calcium']) && $params['calcium'] !== '' ? (float) $params['calcium'] : null,
            'iron'               => isset($params['iron']) && $params['iron'] !== '' ? (float) $params['iron'] : null,
            'potassium'          => isset($params['potassium']) && $params['potassium'] !== '' ? (float) $params['potassium'] : null,
            'source'             => sanitize_text_field($params['source'] ?? 'manual'),
        ];

        $this->nutrition->upsert($recipe_id, $data, current_time('mysql'), get_current_user_id());

        return true;
    }

    /**
     * Delete nutrition data for a recipe.
     *
     * @return true|WP_Error
     */
    public function delete(int $recipe_id) {
        if (!$this->nutrition->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $this->nutrition->delete($recipe_id);

        return true;
    }
}
