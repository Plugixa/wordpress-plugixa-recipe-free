<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Favorites\Http;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Http\PublicController;
use PlugixaRecipe\Modules\Favorites\Services\FavoriteService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * The logged-in favourite toggle.
 *
 * Split out of the former FrontendApi so each public route lives in the module that owns
 * it - and disappears with that module when the App Manager switches it off.
 *
 * The route, its login check and the response mapping - nothing else. The rule (only a
 * published recipe can be favourited) is FavoriteService's; the queries are
 * PublicFavoriteRepository's.
 */
class FrontendFavoritesController extends PublicController {

    private FavoriteService $favorites;

    public function __construct(FavoriteService $favorites) {
        parent::__construct();
        $this->favorites = $favorites;
    }

    public function register_routes(): void {
        // Toggle favorite (requires login)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/favorites/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'toggle_favorite'],
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ],
        ]);
    }

    /**
     * Toggle favorite (requires login, public route).
     */
    public function toggle_favorite($request): WP_REST_Response|WP_Error {
        $is_favorited = $this->favorites->toggle_published(get_current_user_id(), (int) $request->get_param('recipe_id'));

        if (is_wp_error($is_favorited)) {
            return $is_favorited;
        }

        if (!$is_favorited) {
            return $this->success(['is_favorited' => false], __('Removed from favorites.', 'plugixa-recipe'));
        }

        return $this->success(['is_favorited' => true], __('Added to favorites.', 'plugixa-recipe'));
    }
}
