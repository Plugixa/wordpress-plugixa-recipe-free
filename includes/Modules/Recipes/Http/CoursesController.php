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
use PlugixaRecipe\Modules\Recipes\Services\CourseService;

/**
 * Courses REST Controller
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a course
 * is belongs to CourseService; every query belongs to CourseRepository.
 */
class CoursesController extends RestController {

    private CourseService $courses;

    public function __construct(CourseService $courses) {
        parent::__construct();
        $this->rest_base = 'courses';
        $this->entity_type = 'courses';
        $this->courses   = $courses;
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
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->courses->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->courses->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Course not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->courses->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->courses->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->courses->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Course deleted.', 'plugixa-recipe'));
    }

    public function reorder($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $ordered = $this->courses->reorder($params['items'] ?? []);

        if (is_wp_error($ordered)) {
            return $ordered;
        }

        return $this->success(null, __('Order updated.', 'plugixa-recipe'));
    }
}
