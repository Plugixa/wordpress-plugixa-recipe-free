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
use PlugixaRecipe\Modules\Recipes\Services\RecipeService;

/**
 * Recipes REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a recipe
 * is, and the hooks a save, a duplicate and a delete fire, belong to RecipeService; every
 * query belongs to RecipeRepository and RecipeChildRepository.
 */
class RecipesController extends RestController {

    private RecipeService $recipes;

    public function __construct(RecipeService $recipes) {
        parent::__construct();
        $this->rest_base = 'recipes';
        $this->entity_type = 'recipes';
        $this->recipes   = $recipes;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_create_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_save_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update_status'],
                'permission_callback' => [$this, 'check_save_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-action', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_action'],
                'permission_callback' => [$this, 'check_bulk_action_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/suggestions', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_suggestions'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'duplicate_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Create a recipe: the edit capability, and the publish capability too when the payload
     * creates it already published.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_create_permission($request) {
        // Id 0, never one read from the request: create_item() ignores an `id` in the body,
        // so reading one here would let a payload borrow a published recipe's status to
        // answer "nothing changes" and then create a new recipe that is live.
        return $this->edit_and_publish_when($request, $this->recipes->changes_publication(0, $request->get_json_params()));
    }

    /**
     * Save a recipe or set its status: the edit capability, and the publish capability too
     * when the payload would publish the recipe or take it down.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_save_permission($request) {
        // The id and the body are read with the SAME expressions update_item() and
        // update_status() use. That matters: get_param() prefers a body `id` over the one in
        // the URL, so a check that read the URL would judge one recipe while the handler
        // wrote another.
        return $this->edit_and_publish_when(
            $request,
            $this->recipes->changes_publication((int) $request->get_param('id'), $request->get_json_params())
        );
    }

    /**
     * Bulk action: the edit capability, and the publish capability too for the three actions
     * that set a status (publish, draft, archive).
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_bulk_action_permission($request) {
        return $this->edit_and_publish_when($request, $this->recipes->bulk_changes_publication($request->get_json_params()));
    }

    /**
     * The edit check, then the publish check when the request decides what is live.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    private function edit_and_publish_when($request, bool $changes_publication) {
        $edit = $this->check_edit_permission($request);
        if (true !== $edit || !$changes_publication) {
            return $edit;
        }

        return $this->check_publish_permission($request);
    }

    /**
     * Get recipes list with filters, pagination, sorting, and pivot joins.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->recipes->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    /**
     * Get single recipe with full relations.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        $recipe = $this->recipes->get((int) $request->get_param('id'));

        if (null === $recipe) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($recipe);
    }

    /**
     * Create a new recipe.
     */
    public function create_item($request): WP_REST_Response|WP_Error {
        $recipe_id = $this->recipes->create($request->get_json_params());

        if (is_wp_error($recipe_id)) {
            return $recipe_id;
        }

        // Return the full recipe
        $request->set_param('id', $recipe_id);
        return $this->get_item($request);
    }

    /**
     * Update a recipe.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->recipes->update((int) $request->get_param('id'), $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    /**
     * Delete a recipe and all associated data.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->recipes->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Recipe deleted.', 'plugixa-recipe'));
    }

    /**
     * Update recipe status.
     */
    public function update_status($request): WP_REST_Response|WP_Error {
        $result = $this->recipes->set_status((int) $request->get_param('id'), $request->get_json_params());

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success($result, __('Status updated.', 'plugixa-recipe'));
    }

    /**
     * Reorder recipes.
     */
    public function reorder($request): WP_REST_Response|WP_Error {
        $ordered = $this->recipes->reorder($request->get_json_params());

        if (is_wp_error($ordered)) {
            return $ordered;
        }

        return $this->success(null, __('Order updated.', 'plugixa-recipe'));
    }

    /**
     * Bulk delete recipes.
     */
    public function bulk_delete($request): WP_REST_Response|WP_Error {
        $deleted = $this->recipes->delete_many($request->get_json_params());

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(['deleted' => $deleted], __('Recipes deleted.', 'plugixa-recipe'));
    }

    /**
     * Bulk action (publish, draft, archive, feature, unfeature).
     */
    public function bulk_action($request): WP_REST_Response|WP_Error {
        $action = $this->recipes->bulk_action($request->get_json_params());

        if (is_wp_error($action)) {
            return $action;
        }

        /* translators: %s: the bulk action that was applied (e.g. publish, draft, feature). */
        return $this->success(null, sprintf(__('Bulk %s completed.', 'plugixa-recipe'), $action));
    }

    /**
     * Ingredient names, tags and equipment already in use, for the editor's suggestions.
     */
    public function get_suggestions($request): WP_REST_Response|WP_Error {
        return $this->success($this->recipes->suggestions());
    }

    /**
     * Duplicate a recipe with all related data.
     */
    public function duplicate_item($request): WP_REST_Response|WP_Error {
        $new_id = $this->recipes->duplicate((int) $request->get_param('id'));

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $request->set_param('id', $new_id);
        return $this->get_item($request);
    }
}
