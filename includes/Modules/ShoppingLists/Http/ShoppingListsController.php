<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\ShoppingLists\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\ShoppingLists\Services\ShoppingListService;

/**
 * Shopping Lists REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a shopping
 * list is belongs to ShoppingListService; every query belongs to ShoppingListRepository.
 */
class ShoppingListsController extends RestController {

    private ShoppingListService $lists;

    public function __construct(ShoppingListService $lists) {
        parent::__construct();
        $this->rest_base = 'shopping-lists';
        $this->lists     = $lists;
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

        // List items
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/items', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'add_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/items/(?P<item_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_list_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_list_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // Generate from recipe
        register_rest_route($this->namespace, '/' . $this->rest_base . '/from-recipe/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_from_recipe'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        // Generate from meal plan
        register_rest_route($this->namespace, '/' . $this->rest_base . '/from-meal-plan/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_from_meal_plan'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get shopping lists for current user.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->lists->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    /**
     * Get single shopping list with items.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->lists->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    /**
     * Create a shopping list.
     */
    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->lists->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    /**
     * Update a shopping list.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->lists->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    /**
     * Delete a shopping list with items.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->lists->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Shopping list deleted.', 'plugixa-recipe'));
    }

    /**
     * Add item to shopping list.
     */
    public function add_item($request): WP_REST_Response|WP_Error {
        $new_item = $this->lists->add_item((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($new_item)) {
            return $new_item;
        }

        return $this->success($new_item, __('Item added.', 'plugixa-recipe'));
    }

    /**
     * Update a shopping list item.
     */
    public function update_list_item($request): WP_REST_Response|WP_Error {
        $updated = $this->lists->update_item(
            (int) $request->get_param('id'),
            (int) $request->get_param('item_id'),
            (array) $request->get_json_params()
        );

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->success($updated);
    }

    /**
     * Delete a shopping list item.
     */
    public function delete_list_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->lists->delete_item((int) $request->get_param('id'), (int) $request->get_param('item_id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Item deleted.', 'plugixa-recipe'));
    }

    /**
     * Create shopping list from a recipe's ingredients.
     */
    public function create_from_recipe($request): WP_REST_Response|WP_Error {
        $list_id = $this->lists->create_from_recipe((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($list_id)) {
            return $list_id;
        }

        $request->set_param('id', $list_id);
        return $this->get_item($request);
    }

    /**
     * Create shopping list from all recipes in a meal plan collection.
     */
    public function create_from_meal_plan($request): WP_REST_Response|WP_Error {
        $list_id = $this->lists->create_from_meal_plan((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($list_id)) {
            return $list_id;
        }

        $request->set_param('id', $list_id);
        return $this->get_item($request);
    }
}
