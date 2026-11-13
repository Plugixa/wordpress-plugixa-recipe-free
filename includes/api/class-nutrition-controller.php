<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Nutrition REST Controller (1:1 with recipes, upsert pattern)
 *
 * Nutrition is a child row of a recipe and is always read through one, so it has no
 * cache entity of its own: reads are cached under 'recipes' and every write flushes
 * 'recipes'.
 */
class NutritionController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'recipes';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/nutrition', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'upsert_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);
    }

    /**
     * Get nutrition data for a recipe.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Read-through cached under the owning recipe; key captures the recipe id
        // (the only input to this read). The numeric casting below stays outside.
        $nutrition = Cache::remember('recipes', "item:{$recipe_id}:nutrition", function () use ($wpdb, $prefix, $recipe_id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE recipe_id = %d', $prefix . 'nutrition', $recipe_id),
                ARRAY_A
            );
        });

        if (!$nutrition) {
            return $this->success(null);
        }

        // Cast numeric fields
        $numeric_fields = ['calories', 'total_fat', 'saturated_fat', 'trans_fat', 'cholesterol', 'sodium', 'total_carbohydrate', 'dietary_fiber', 'total_sugars', 'protein', 'vitamin_a', 'vitamin_c', 'calcium', 'iron', 'potassium'];
        foreach ($numeric_fields as $field) {
            if ($nutrition[$field] !== null) {
                $nutrition[$field] = (float) $nutrition[$field];
            }
        }

        return $this->success($nutrition);
    }

    /**
     * Upsert nutrition data for a recipe.
     */
    public function upsert_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
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

        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE recipe_id = %d', $prefix . 'nutrition', $recipe_id));

        if ($existing) {
            $wpdb->update($prefix . 'nutrition', $data, ['recipe_id' => $recipe_id]);
        } else {
            $wpdb->insert($prefix . 'nutrition', $data);
        }

        // Update recipe modified timestamp
        $wpdb->update($prefix . 'recipes', [
            'modified_at' => current_time('mysql'),
            'modified_by' => get_current_user_id(),
        ], ['id' => $recipe_id]);

        // Nutrition child row + the recipe row changed.
        // Must run BEFORE get_item() below so the response is read fresh, not from cache.
        Cache::flush('recipes');

        return $this->get_item($request);
    }

    /**
     * Delete nutrition data for a recipe.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->delete($prefix . 'nutrition', ['recipe_id' => $recipe_id]);

        // Nutrition child row removed.
        Cache::flush('recipes');

        return $this->success(null, __('Nutrition data deleted.', 'plugixa-recipe'));
    }
}
