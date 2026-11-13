<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Recipes\Services\IngredientService;

/**
 * Ingredients REST Controller
 *
 * Routes, permission checks and the request/response mapping - nothing else. What an
 * ingredient list is belongs to IngredientService; every query belongs to
 * IngredientRepository. The routes hang off a recipe (/recipes/{id}/ingredients), hence the
 * 'recipes' rest_base.
 */
class IngredientsController extends RestController {

    private IngredientService $ingredients;

    public function __construct(IngredientService $ingredients) {
        parent::__construct();
        $this->rest_base   = 'recipes';
        $this->ingredients = $ingredients;
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
        $ingredients = $this->ingredients->list((int) $request->get_param('id'));

        if (is_wp_error($ingredients)) {
            return $ingredients;
        }

        return $this->success($ingredients);
    }

    /**
     * Replace all ingredients for a recipe (delete existing + insert new).
     */
    public function replace_items($request): WP_REST_Response|WP_Error {
        $params   = (array) $request->get_json_params();
        $replaced = $this->ingredients->replace((int) $request->get_param('id'), $params['ingredients'] ?? []);

        if (is_wp_error($replaced)) {
            return $replaced;
        }

        // The repository has already flushed, so this is read fresh, not from cache.
        return $this->get_items($request);
    }

    /**
     * Reorder ingredients for a recipe.
     */
    public function reorder($request): WP_REST_Response|WP_Error {
        $params    = (array) $request->get_json_params();
        $reordered = $this->ingredients->reorder((int) $request->get_param('id'), $params['items'] ?? []);

        if (is_wp_error($reordered)) {
            return $reordered;
        }

        return $this->success(null, __('Ingredients reordered.', 'plugixa-recipe'));
    }
}
