<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Nutrition\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Nutrition\Services\NutritionService;

/**
 * Nutrition REST Controller (1:1 with recipes, upsert pattern)
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a
 * nutrition record is belongs to NutritionService; every query belongs to
 * NutritionRepository. The routes hang off a recipe (/recipes/{id}/nutrition), hence the
 * 'recipes' rest_base.
 */
class NutritionController extends RestController {

    private NutritionService $nutrition;

    public function __construct(NutritionService $nutrition) {
        parent::__construct();
        $this->rest_base = 'recipes';
        $this->nutrition = $nutrition;
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
        $nutrition = $this->nutrition->get((int) $request->get_param('id'));

        if (is_wp_error($nutrition)) {
            return $nutrition;
        }

        // Null (the recipe has no nutrition row) is a successful, empty answer.
        return $this->success($nutrition);
    }

    /**
     * Upsert nutrition data for a recipe.
     */
    public function upsert_item($request): WP_REST_Response|WP_Error {
        $saved = $this->nutrition->save((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($saved)) {
            return $saved;
        }

        return $this->get_item($request);
    }

    /**
     * Delete nutrition data for a recipe.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->nutrition->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Nutrition data deleted.', 'plugixa-recipe'));
    }
}
