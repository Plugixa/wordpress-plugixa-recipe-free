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
use PlugixaRecipe\Modules\Recipes\Services\UnitService;

/**
 * Units REST Controller
 *
 * Routes, permission checks and the request/response mapping - nothing else. Listing and
 * conversion rules belong to UnitService; every query belongs to UnitRepository.
 */
class UnitsController extends RestController {

    private UnitService $units;

    public function __construct(UnitService $units) {
        parent::__construct();
        $this->rest_base = 'units';
        $this->units     = $units;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/convert', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'convert'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get all available units, optionally filtered by system or type.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->units->list(
            $request->get_param('system'),
            $request->get_param('type')
        ));
    }

    /**
     * Convert a quantity from one unit to another.
     */
    public function convert($request): WP_REST_Response|WP_Error {
        $converted = $this->units->convert((array) $request->get_json_params());

        if (is_wp_error($converted)) {
            return $converted;
        }

        return $this->success($converted);
    }
}
