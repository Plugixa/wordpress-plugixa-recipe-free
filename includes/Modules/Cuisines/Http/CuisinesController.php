<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Cuisines\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Cuisines\Services\CuisineService;

/**
 * Cuisines REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a cuisine
 * is belongs to CuisineService; every query belongs to CuisineRepository.
 */
class CuisinesController extends RestController {

    private CuisineService $cuisines;

    public function __construct(CuisineService $cuisines) {
        parent::__construct();
        $this->rest_base = 'cuisines';
        $this->cuisines  = $cuisines;
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
        return $this->success($this->cuisines->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->cuisines->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Cuisine not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->cuisines->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->cuisines->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->cuisines->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Cuisine deleted.', 'plugixa-recipe'));
    }

    public function reorder($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $ordered = $this->cuisines->reorder($params['items'] ?? []);

        if (is_wp_error($ordered)) {
            return $ordered;
        }

        return $this->success(null, __('Order updated.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $deleted = $this->cuisines->delete_many($params['ids'] ?? []);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Cuisines deleted.', 'plugixa-recipe'));
    }
}
