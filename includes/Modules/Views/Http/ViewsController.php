<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Views\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Views\Services\ViewService;

/**
 * Views REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a view
 * is belongs to ViewService; every query belongs to ViewRepository.
 */
class ViewsController extends RestController {

    private ViewService $views;

    public function __construct(ViewService $views) {
        parent::__construct();
        $this->rest_base = 'views';
        $this->views     = $views;
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'duplicate_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/set-default', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'set_default'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/default-config', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_default_config'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->views->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->views->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('View not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->views->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->views->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->views->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('View deleted.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $deleted = $this->views->delete_many($params['ids'] ?? []);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(['deleted' => $deleted], __('Views deleted.', 'plugixa-recipe'));
    }

    public function duplicate_item($request): WP_REST_Response|WP_Error {
        $new_id = $this->views->duplicate((int) $request->get_param('id'));

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $request->set_param('id', $new_id);
        return $this->get_item($request);
    }

    public function set_default($request): WP_REST_Response|WP_Error {
        $id  = (int) $request->get_param('id');
        $set = $this->views->set_default($id);

        if (is_wp_error($set)) {
            return $set;
        }

        return $this->success(['id' => $id, 'is_default' => true], __('Default view updated.', 'plugixa-recipe'));
    }

    public function get_default_config($request): WP_REST_Response|WP_Error {
        return $this->success($this->views->default_config());
    }
}
