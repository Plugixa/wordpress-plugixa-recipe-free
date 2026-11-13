<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Favorites\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Favorites\Services\FavoriteService;

/**
 * Favorites REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. Every route acts
 * on the CALLER's own favourites (keyed on get_current_user_id()). What a favourite is belongs
 * to FavoriteService; every query belongs to FavoriteRepository.
 */
class FavoritesController extends RestController {

    private FavoriteService $favorites;

    public function __construct(FavoriteService $favorites) {
        parent::__construct();
        $this->rest_base = 'favorites';
        $this->favorites = $favorites;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args'                => $this->get_collection_params(),
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'toggle_favorite'],
                // Edit, not delete: a toggle that only ever removes the row belonging to the CALLER (keyed on get_current_user_id()).
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/check/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'check_favorite'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);
    }

    /**
     * Get current user's favorite recipes.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->favorites->list(
            get_current_user_id(),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    /**
     * Toggle favorite status for a recipe (add/remove).
     */
    public function toggle_favorite($request): WP_REST_Response|WP_Error {
        $recipe_id    = (int) $request->get_param('recipe_id');
        $is_favorited = $this->favorites->toggle(get_current_user_id(), $recipe_id);

        if (is_wp_error($is_favorited)) {
            return $is_favorited;
        }

        if (!$is_favorited) {
            return $this->success([
                'recipe_id'    => $recipe_id,
                'is_favorited' => false,
            ], __('Recipe removed from favorites.', 'plugixa-recipe'));
        }

        return $this->success([
            'recipe_id'    => $recipe_id,
            'is_favorited' => true,
        ], __('Recipe added to favorites.', 'plugixa-recipe'));
    }

    /**
     * Check if a recipe is favorited by current user.
     */
    public function check_favorite($request): WP_REST_Response|WP_Error {
        $recipe_id = (int) $request->get_param('recipe_id');

        return $this->success([
            'recipe_id'    => $recipe_id,
            'is_favorited' => $this->favorites->is_favorited(get_current_user_id(), $recipe_id),
        ]);
    }
}
