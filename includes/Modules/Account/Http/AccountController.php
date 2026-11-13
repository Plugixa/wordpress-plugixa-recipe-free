<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Account\Http;

use PlugixaRecipe\Core\Http\PublicController;
use PlugixaRecipe\Modules\Account\Services\AccountService;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * /frontend/account/saved - the caller's own saved recipes.
 *
 * The route requires a login and takes NO user parameter: the only id ever used is
 * get_current_user_id(), so there is no other visitor's list to ask for. The answers are
 * personal, so they are sent `no-store` - a shared cache must never hold one.
 */
class AccountController extends PublicController {

    private AccountService $account;

    public function __construct(AccountService $account) {
        parent::__construct();
        $this->account = $account;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/account/saved', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'saved'],
                'permission_callback' => [$this, 'check_login'],
            ],
        ]);

    }

    public function check_login(): bool {
        return is_user_logged_in();
    }

    public function saved(): WP_REST_Response {
        return $this->personal($this->account->saved(get_current_user_id()));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function personal(array $items): WP_REST_Response {
        $response = new WP_REST_Response(['success' => true, 'data' => $items]);
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }
}
