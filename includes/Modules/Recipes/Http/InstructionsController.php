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
use PlugixaRecipe\Modules\Recipes\Services\InstructionService;

/**
 * Instructions REST Controller
 *
 * Routes, permission checks and the request/response mapping - nothing else. What an
 * instruction list is belongs to InstructionService; every query belongs to
 * InstructionRepository. The routes hang off a recipe (/recipes/{id}/instructions), hence the
 * 'recipes' rest_base.
 */
class InstructionsController extends RestController {

    private InstructionService $instructions;

    public function __construct(InstructionService $instructions) {
        parent::__construct();
        $this->rest_base    = 'recipes';
        $this->instructions = $instructions;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/instructions', [
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/instructions/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get all instructions for a recipe.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        $instructions = $this->instructions->list((int) $request->get_param('id'));

        if (is_wp_error($instructions)) {
            return $instructions;
        }

        return $this->success($instructions);
    }

    /**
     * Replace all instructions for a recipe (delete existing + insert new).
     */
    public function replace_items($request): WP_REST_Response|WP_Error {
        $params   = (array) $request->get_json_params();
        $replaced = $this->instructions->replace((int) $request->get_param('id'), $params['instructions'] ?? []);

        if (is_wp_error($replaced)) {
            return $replaced;
        }

        // The repository has already flushed, so this is read fresh, not from cache.
        return $this->get_items($request);
    }

    /**
     * Reorder instructions for a recipe.
     */
    public function reorder($request): WP_REST_Response|WP_Error {
        $params    = (array) $request->get_json_params();
        $reordered = $this->instructions->reorder((int) $request->get_param('id'), $params['items'] ?? []);

        if (is_wp_error($reordered)) {
            return $reordered;
        }

        return $this->success(null, __('Instructions reordered.', 'plugixa-recipe'));
    }
}
