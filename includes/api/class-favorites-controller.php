<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Favorites REST Controller
 */
class FavoritesController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'favorites';
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
        global $wpdb;
        $prefix  = $wpdb->prefix . 'plugixa_recipe_';
        $user_id = get_current_user_id();
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // Count — read-through object cache; key = user id.
        $total = (int) Cache::remember('favorites', 'count|' . $user_id, function () use ($wpdb, $prefix, $user_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d',
                $prefix . 'favorites',
                $user_id
            ));
        });

        // The sort column lives either on the recipe (r.) or on the favourite (f.), so the
        // alias is part of the ORDER BY and cannot be bound - and nor can the direction
        // keyword. That is four literal variants, with the column itself bound by %i in each.
        // The trailing id keeps paging stable when the sort column has ties.
        $on_recipe = in_array($sort['orderby'], ['title', 'rating_average'], true);
        $orderby   = $on_recipe ? $sort['orderby'] : 'created_at';
        $order     = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Read-through object cache; key = user + sort + pagination (every input that changes the result).
        $cache_key = 'list|' . $user_id . '|' . ($on_recipe ? 'r.' : 'f.') . $orderby . '|' . $order . '|' . $pagination['limit'] . '|' . $pagination['offset'];
        $args = [
            $prefix . 'favorites',
            $prefix . 'recipes',
            $user_id,
            $orderby,
            $pagination['limit'],
            $pagination['offset'],
        ];
        $items = Cache::remember('favorites', $cache_key, function () use ($wpdb, $on_recipe, $order, $args) {
            if ($on_recipe && 'ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                            r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                            r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                            f.created_at AS favorited_at
                    FROM %i f
                    INNER JOIN %i r ON f.recipe_id = r.id
                    WHERE f.user_id = %d
                    ORDER BY r.%i ASC, r.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            if ($on_recipe) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                            r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                            r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                            f.created_at AS favorited_at
                    FROM %i f
                    INNER JOIN %i r ON f.recipe_id = r.id
                    WHERE f.user_id = %d
                    ORDER BY r.%i DESC, r.id DESC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                            r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                            r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                            f.created_at AS favorited_at
                    FROM %i f
                    INNER JOIN %i r ON f.recipe_id = r.id
                    WHERE f.user_id = %d
                    ORDER BY f.%i ASC, f.recipe_id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.id, r.title, r.slug, r.description, r.image_id, r.prep_time, r.cook_time,
                        r.total_time, r.difficulty, r.rating_average, r.rating_count, r.status,
                        r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        f.created_at AS favorited_at
                FROM %i f
                INNER JOIN %i r ON f.recipe_id = r.id
                WHERE f.user_id = %d
                ORDER BY f.%i DESC, f.recipe_id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });

        foreach ($items as &$item) {
            if (!empty($item['image_id'])) {
                $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'medium');
                $item['image_thumbnail'] = wp_get_attachment_image_url((int) $item['image_id'], 'thumbnail');
            }
            $item['rating_average'] = (float) $item['rating_average'];
            $item['rating_count'] = (int) $item['rating_count'];
            $item['is_favorited'] = true;
        }

        return $this->success([
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ]);
    }

    /**
     * Toggle favorite status for a recipe (add/remove).
     */
    public function toggle_favorite($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $user_id = get_current_user_id();
        $recipe_id = (int) $request->get_param('recipe_id');

        // Verify recipe exists
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Check if already favorited
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $prefix . 'favorites',
            $user_id, $recipe_id
        ));

        if ($existing) {
            // Remove favorite
            $wpdb->delete($prefix . 'favorites', [
                'user_id'   => $user_id,
                'recipe_id' => $recipe_id,
            ]);

            // Content write: favourite status is surfaced on cached recipe reads too.
            Cache::flush('favorites', 'recipes');

            return $this->success([
                'recipe_id'    => $recipe_id,
                'is_favorited' => false,
            ], __('Recipe removed from favorites.', 'plugixa-recipe'));
        } else {
            // Add favorite
            $wpdb->insert($prefix . 'favorites', [
                'user_id'    => $user_id,
                'recipe_id'  => $recipe_id,
                'created_at' => current_time('mysql'),
            ]);

            // Content write: favourite status is surfaced on cached recipe reads too.
            Cache::flush('favorites', 'recipes');

            return $this->success([
                'recipe_id'    => $recipe_id,
                'is_favorited' => true,
            ], __('Recipe added to favorites.', 'plugixa-recipe'));
        }
    }

    /**
     * Check if a recipe is favorited by current user.
     */
    public function check_favorite($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $user_id = get_current_user_id();
        $recipe_id = (int) $request->get_param('recipe_id');

        // Read-through object cache; key = user + recipe.
        $is_favorited = (bool) Cache::remember('favorites', 'check|' . $user_id . '|' . $recipe_id, function () use ($wpdb, $prefix, $user_id, $recipe_id) {
            return (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
            $prefix . 'favorites',
                $user_id, $recipe_id
            ));
        });

        return $this->success([
            'recipe_id'    => $recipe_id,
            'is_favorited' => $is_favorited,
        ]);
    }
}
