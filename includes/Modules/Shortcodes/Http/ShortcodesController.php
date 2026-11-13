<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Shortcodes\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Shortcodes\Services\ShortcodeService;

/**
 * Shortcodes REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What a saved
 * shortcode is belongs to ShortcodeService; every query belongs to ShortcodeRepository.
 */
class ShortcodesController extends RestController {

    private ShortcodeService $shortcodes;

    public function __construct(ShortcodeService $shortcodes) {
        parent::__construct();
        $this->rest_base  = 'shortcodes';
        $this->shortcodes = $shortcodes;
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
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->shortcodes->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->shortcodes->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Shortcode not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        $id = $this->shortcodes->create((array) $request->get_json_params());

        if (is_wp_error($id)) {
            return $id;
        }

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->shortcodes->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->shortcodes->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Shortcode deleted.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $deleted = $this->shortcodes->delete_many($params['ids'] ?? []);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(['deleted' => $deleted], __('Shortcodes deleted.', 'plugixa-recipe'));
    }

    public function duplicate_item($request): WP_REST_Response|WP_Error {
        $new_id = $this->shortcodes->duplicate((int) $request->get_param('id'));

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $request->set_param('id', $new_id);
        return $this->get_item($request);
    }
}
