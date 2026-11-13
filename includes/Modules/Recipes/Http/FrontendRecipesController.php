<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Http;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Http\PublicController;
use PlugixaRecipe\Modules\Recipes\Services\PublicRecipeService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Public recipe reads and the like toggle.
 *
 * Split out of the former FrontendApi so each public route lives in the module that owns
 * it - and disappears with that module when the App Manager switches it off.
 *
 * Routes, permission callbacks and the request/response mapping - nothing else. What a
 * visitor may read and do belongs to PublicRecipeService; every query, each pinned to
 * published recipes and a public column list, belongs to PublicRecipeRepository.
 */
class FrontendRecipesController extends PublicController {

    private PublicRecipeService $recipes;

    public function __construct(PublicRecipeService $recipes) {
        parent::__construct();
        $this->recipes = $recipes;
    }

    public function register_routes(): void {
        // Public by design: the published recipe list a visitor already sees rendered on
        // the page. Pinned to status='published'; public column list only.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/recipes', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_recipes'],
                'permission_callback' => '__return_true',
                'args'                => $this->get_collection_params(),
            ],
        ]);

        // Public by design: one published recipe, the same content the single-recipe page
        // renders. Pinned to status='published'; public column list only.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/recipes/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_recipe'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'description'       => __('Unique identifier for the recipe.', 'plugixa-recipe'),
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static function ($value) {
                            return is_numeric($value) && (int) $value > 0;
                        },
                    ],
                ],
            ],
        ]);

        // Toggle like (requires login)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/likes/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'toggle_like'],
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ],
        ]);
    }

    /**
     * Get published recipes for frontend display.
     */
    public function get_recipes($request): WP_REST_Response|WP_Error {
        // The request's filters, coerced here; the repository binds each one behind a gate.
        $search = $request->get_param('search');

        $criteria = [
            'category_id' => (int) ($request->get_param('category_id') ?: 0),
            'cuisine_id'  => (int) ($request->get_param('cuisine_id') ?: 0),
            'search'      => $search ? $search : '',
            'is_featured' => $request->get_param('is_featured') ? 1 : 0,
            'min_rating'  => (float) ($request->get_param('min_rating') ?: 0),
            'difficulty'  => (string) ($request->get_param('difficulty') ?: ''),
        ];

        // Diet flags: six independent gates. The column names are literal in the SQL
        // (they come from this hard-coded list, never from the request); only the 1/0 gate
        // travels as a bound value.
        $diet_flags = ['is_vegetarian', 'is_vegan', 'is_gluten_free', 'is_dairy_free', 'is_keto', 'is_low_carb'];
        foreach ($diet_flags as $flag) {
            $criteria[$flag] = $request->get_param($flag) ? 1 : 0;
        }

        return $this->success($this->recipes->list(
            $criteria,
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    /**
     * Get single published recipe with full data.
     */
    public function get_recipe($request): WP_REST_Response|WP_Error {
        $recipe = $this->recipes->get((int) $request->get_param('id'));

        if (null === $recipe) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($recipe);
    }

    /**
     * Toggle like (requires login, public route).
     */
    public function toggle_like($request): WP_REST_Response|WP_Error {
        $like = $this->recipes->toggle_like((int) $request->get_param('recipe_id'));

        if (is_wp_error($like)) {
            return $like;
        }

        return $this->success($like, $like['is_liked']
            ? __('Liked!', 'plugixa-recipe')
            : __('Like removed.', 'plugixa-recipe')
        );
    }
}
