<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Categories\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Categories\Services\CategoryService;

/**
 * Categories REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a category
 * is belongs to CategoryService; every query belongs to CategoryRepository.
 */
class CategoriesController extends RestController {

    private CategoryService $categories;

    public function __construct(CategoryService $categories) {
        parent::__construct();
        $this->rest_base = 'categories';
        $this->categories  = $categories;
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
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->categories->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->categories->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Category not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->categories->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->categories->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->categories->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Category deleted.', 'plugixa-recipe'));
    }

    public function reorder($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $ordered = $this->categories->reorder($params['items'] ?? []);

        if (is_wp_error($ordered)) {
            return $ordered;
        }

        return $this->success(null, __('Order updated.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $deleted = $this->categories->delete_many($params['ids'] ?? []);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Categories deleted.', 'plugixa-recipe'));
    }
}
