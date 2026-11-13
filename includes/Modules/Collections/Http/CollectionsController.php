<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Collections\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Collections\Services\CollectionService;

/**
 * Collections REST Controller (collections, cookbooks, meal plans, favorites)
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a
 * collection is belongs to CollectionService; every query belongs to CollectionRepository.
 */
class CollectionsController extends RestController {

    private CollectionService $collections;

    public function __construct(CollectionService $collections) {
        parent::__construct();
        $this->rest_base   = 'collections';
        $this->collections = $collections;
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
                'permission_callback' => [$this, 'check_edit_permission'],
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
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // Collection recipes management
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/recipes', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_collection_recipes'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'add_recipe_to_collection'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/recipes/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'remove_recipe_from_collection'],
                // Edit, not delete: removes a pivot row in collection_recipes, not an entity.
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/recipes/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder_recipes'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get collections list.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->collections->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    /**
     * Get single collection with recipes.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->collections->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    /**
     * Create a collection.
     */
    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->collections->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    /**
     * Update a collection.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->collections->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    /**
     * Delete a collection and its pivot entries.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->collections->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Collection deleted.', 'plugixa-recipe'));
    }

    /**
     * Get recipes in a collection.
     */
    public function get_collection_recipes($request): WP_REST_Response|WP_Error {
        $recipes = $this->collections->recipes((int) $request->get_param('id'));

        if (null === $recipes) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($recipes);
    }

    /**
     * Add a recipe to a collection.
     */
    public function add_recipe_to_collection($request): WP_REST_Response|WP_Error {
        $added = $this->collections->add_recipe((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($added)) {
            return $added;
        }

        return $this->success($added, __('Recipe added to collection.', 'plugixa-recipe'));
    }

    /**
     * Remove a recipe from a collection.
     */
    public function remove_recipe_from_collection($request): WP_REST_Response|WP_Error {
        $removed = $this->collections->remove_recipe(
            (int) $request->get_param('id'),
            (int) $request->get_param('recipe_id')
        );

        if (is_wp_error($removed)) {
            return $removed;
        }

        return $this->success(null, __('Recipe removed from collection.', 'plugixa-recipe'));
    }

    /**
     * Reorder recipes in a collection.
     */
    public function reorder_recipes($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $ordered = $this->collections->reorder_recipes((int) $request->get_param('id'), $params['items'] ?? []);

        if (is_wp_error($ordered)) {
            return $ordered;
        }

        return $this->success(null, __('Collection recipes reordered.', 'plugixa-recipe'));
    }
}
