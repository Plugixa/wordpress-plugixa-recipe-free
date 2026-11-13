<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator\Http;

use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\NutritionCalculator\Services\FoodMatcher;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * /nutrition-calculator - food matches for the recipe editor.
 *
 * Both routes are for someone who can edit recipes: one asks the food database on their
 * behalf (it spends the site's API quota), the other writes to the site's list of
 * remembered foods. Neither touches a recipe.
 */
class NutritionCalculatorController extends RestController {

    private FoodMatcher $matcher;

    public function __construct(FoodMatcher $matcher) {
        parent::__construct();
        $this->rest_base = 'nutrition-calculator';
        $this->matcher   = $matcher;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/match', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'match'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/remember', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'remember'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function match($request) {
        $names = $request->get_param('ingredients');
        $found = $this->matcher->match(is_array($names) ? $names : []);

        if (is_wp_error($found)) {
            return $found;
        }

        return $this->success($found);
    }

    /**
     * @param WP_REST_Request $request
     */
    public function remember($request): WP_REST_Response {
        $confirmed = $request->get_param('matches');

        return $this->success(['stored' => $this->matcher->remember(is_array($confirmed) ? $confirmed : [])]);
    }
}
