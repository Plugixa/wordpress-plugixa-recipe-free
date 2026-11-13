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
 * Ingredients REST Controller
 *
 * Ingredients are child rows of a recipe and are always read through one, so they
 * have no cache entity of their own: reads are cached under 'recipes' and every
 * write flushes 'recipes'.
 */
class IngredientsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'recipes';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/ingredients', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'replace_items'],
                // Edit, not delete: rewrites the recipe's own child rows -- that is what saving a recipe is.
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/ingredients/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get all ingredients for a recipe.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');

        // Verify recipe exists
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Read-through cached under the owning recipe; key captures the recipe id
        // (the only input to this read).
        $ingredients = Cache::remember('recipes', "item:{$recipe_id}:ingredients", function () use ($wpdb, $prefix, $recipe_id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
                    $prefix . 'ingredients',
                    $recipe_id
                ),
                ARRAY_A
            );
        });

        return $this->success($ingredients);
    }

    /**
     * Replace all ingredients for a recipe (delete existing + insert new).
     */
    public function replace_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $ingredients = $params['ingredients'] ?? [];

        // Verify recipe exists
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Delete all existing ingredients
        $wpdb->delete($prefix . 'ingredients', ['recipe_id' => $recipe_id]);

        // Insert new ingredients
        foreach ($ingredients as $ing) {
            $wpdb->insert($prefix . 'ingredients', [
                'recipe_id'   => $recipe_id,
                'group_name'  => sanitize_text_field($ing['group_name'] ?? ''),
                'group_order' => (int) ($ing['group_order'] ?? 0),
                'sort_order'  => (int) ($ing['sort_order'] ?? 0),
                'quantity'    => sanitize_text_field($ing['quantity'] ?? ''),
                'unit'        => sanitize_text_field($ing['unit'] ?? ''),
                'name'        => sanitize_text_field($ing['name'] ?? ''),
                'notes'       => sanitize_text_field($ing['notes'] ?? ''),
                'link_url'    => esc_url_raw($ing['link_url'] ?? ''),
            ]);
        }

        // Update recipe modified timestamp
        $wpdb->update($prefix . 'recipes', [
            'modified_at' => current_time('mysql'),
            'modified_by' => get_current_user_id(),
        ], ['id' => $recipe_id]);

        // Ingredient child rows + the recipe row changed.
        // Must run BEFORE get_items() below so the response is read fresh, not from cache.
        Cache::flush('recipes');

        return $this->get_items($request);
    }

    /**
     * Reorder ingredients for a recipe.
     */
    public function reorder($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $items  = $params['items'] ?? [];

        // Verify recipe exists
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        if (empty($items) || !is_array($items)) {
            return $this->error(__('Items are required.', 'plugixa-recipe'));
        }

        foreach ($items as $item) {
            $update = ['sort_order' => (int) ($item['sort_order'] ?? 0)];
            if (isset($item['group_order'])) {
                $update['group_order'] = (int) $item['group_order'];
            }
            if (isset($item['group_name'])) {
                $update['group_name'] = sanitize_text_field($item['group_name']);
            }
            $wpdb->update($prefix . 'ingredients', $update, [
                'id'        => (int) $item['id'],
                'recipe_id' => $recipe_id,
            ]);
        }

        // Ingredient child rows changed.
        Cache::flush('recipes');

        return $this->success(null, __('Ingredients reordered.', 'plugixa-recipe'));
    }
}
