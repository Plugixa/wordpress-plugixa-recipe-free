<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Collections\Http;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Http\PublicController;
use PlugixaRecipe\Modules\Collections\Services\CollectionReadService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * The public collections list.
 *
 * Split out of the former FrontendApi so each public route lives in the module that owns
 * it - and disappears with that module when the App Manager switches it off.
 *
 * The route and its response mapping - nothing else. The pinned public query belongs to
 * CollectionReadRepository, the display decoration to CollectionReadService.
 */
class FrontendCollectionsController extends PublicController {

    private CollectionReadService $collections;

    public function __construct(CollectionReadService $collections) {
        parent::__construct();
        $this->collections = $collections;
    }

    public function register_routes(): void {
        // Public by design: collections explicitly marked public. Pinned to
        // status='published' AND visibility='public'; the owning user_id is not selected.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/collections', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_collections'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Get public collections.
     */
    public function get_collections($request): WP_REST_Response|WP_Error {
        return $this->success($this->collections->list());
    }
}
