<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ShoppingLists\Http;

use PlugixaRecipe\Core\Http\PublicController;
use PlugixaRecipe\Modules\ShoppingLists\Services\ShoppingListService;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * /frontend/shopping-list - a signed-in visitor's own list, kept across their devices.
 *
 * The list on the page lives in the browser and works with nobody logged in; this is
 * where a logged-in visitor's copy is kept. Both methods require a login and take no list
 * id and no user id: the list is always the caller's, so there is no other list to name.
 */
class FrontendShoppingListController extends PublicController {

    private ShoppingListService $lists;

    public function __construct(ShoppingListService $lists) {
        parent::__construct();
        $this->lists = $lists;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/shopping-list', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'read'],
                'permission_callback' => [$this, 'check_login'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'replace_my_list'],
                'permission_callback' => [$this, 'check_login'],
            ],
        ]);
    }

    public function check_login(): bool {
        return is_user_logged_in();
    }

    public function read(): WP_REST_Response {
        return $this->personal($this->lists->personal_items());
    }

    /**
     * @param \WP_REST_Request $request
     */
    public function replace_my_list($request): WP_REST_Response {
        $items = $request->get_param('items');

        return $this->personal($this->lists->replace_personal_items(is_array($items) ? $items : []));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function personal(array $items): WP_REST_Response {
        $response = new WP_REST_Response(['success' => true, 'data' => $items]);
        // One person's list: never to be held by a shared cache.
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }
}
