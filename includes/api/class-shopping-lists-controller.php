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
 * Shopping Lists REST Controller
 */
class ShoppingListsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'shopping-lists';
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

        // List items
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/items', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'add_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/items/(?P<item_id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_list_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_list_item'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // Generate from recipe
        register_rest_route($this->namespace, '/' . $this->rest_base . '/from-recipe/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_from_recipe'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        // Generate from meal plan
        register_rest_route($this->namespace, '/' . $this->rest_base . '/from-meal-plan/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_from_meal_plan'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get shopping lists for current user.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix  = $wpdb->prefix . 'plugixa_recipe_';
        $filters = $this->get_filters($request);
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);
        $user_id = get_current_user_id();

        // The user filter always applies; the other two are gate placeholders - bind 0 and
        // the clause is constant-true, which keeps WHERE literal instead of PHP-assembled.
        $search = !empty($filters['search'])
            ? '%' . $wpdb->esc_like($filters['search']) . '%'
            : '';
        $status = !empty($filters['status']) ? (string) $filters['status'] : '';

        $allowed_orderby = ['name', 'created_at', 'modified_at', 'status'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound gate/value pairs for the shared WHERE block, in placeholder order.
        $filter_gates = [
            $user_id,
            $search !== '' ? 1 : 0, $search,
            $status !== '' ? 1 : 0, $status,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text.
        $total = (int) Cache::remember('shopping_lists', 'count|' . md5(wp_json_encode($filter_gates)), function () use ($wpdb, $prefix, $filter_gates) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i sl
                  WHERE sl.user_id = %d
                    AND ( %d = 0 OR sl.name LIKE %s )
                    AND ( %d = 0 OR sl.status = %s )',
                ...array_merge([$prefix . 'shopping_lists'], $filter_gates)
            ));
        });

        // Main query with item counts. The ORDER BY column binds with %i from the
        // $allowed_orderby list above; a direction keyword cannot be bound, so ASC/DESC is
        // two literal queries. The id tiebreaker keeps paging stable on ties.
        $args = array_merge(
            [$prefix . 'shopping_lists', $prefix . 'shopping_list_items'],
            $filter_gates,
            [$orderby, $pagination['limit'], $pagination['offset']]
        );

        $items = Cache::remember('shopping_lists', 'list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT sl.*,
                        COUNT(sli.id) AS total_items,
                        SUM(CASE WHEN sli.is_checked = 1 THEN 1 ELSE 0 END) AS checked_items
                    FROM %i sl
                    LEFT JOIN %i sli ON sl.id = sli.shopping_list_id
                    WHERE sl.user_id = %d
                      AND ( %d = 0 OR sl.name LIKE %s )
                      AND ( %d = 0 OR sl.status = %s )
                    GROUP BY sl.id
                    ORDER BY sl.%i ASC, sl.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT sl.*,
                    COUNT(sli.id) AS total_items,
                    SUM(CASE WHEN sli.is_checked = 1 THEN 1 ELSE 0 END) AS checked_items
                FROM %i sl
                LEFT JOIN %i sli ON sl.id = sli.shopping_list_id
                WHERE sl.user_id = %d
                  AND ( %d = 0 OR sl.name LIKE %s )
                  AND ( %d = 0 OR sl.status = %s )
                GROUP BY sl.id
                ORDER BY sl.%i DESC, sl.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });

        foreach ($items as &$item) {
            $item['total_items'] = (int) $item['total_items'];
            $item['checked_items'] = (int) $item['checked_items'];
            $item['title'] = $item['name'];
            if (!empty($item['user_id'])) {
                $user = get_userdata((int) $item['user_id']);
                $item['author_name'] = $user ? $user->display_name : '';
            }
        }

        return $this->success([
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ]);
    }

    /**
     * Get single shopping list with items.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        // Read-through object cache; key = list id.
        $item = Cache::remember('shopping_lists', 'item|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'shopping_lists', $id),
                ARRAY_A
            );
        });

        if (!$item) {
            return $this->error(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        // Get items — read-through object cache; key = list id.
        $item['items'] = Cache::remember('shopping_lists', 'item_rows|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT sli.*, r.title AS recipe_title
                    FROM %i sli
                    LEFT JOIN %i r ON sli.recipe_id = r.id
                    WHERE sli.shopping_list_id = %d
                    ORDER BY sli.sort_order ASC, sli.id ASC',
                    $prefix . 'shopping_list_items',
                    $prefix . 'recipes',
                    $id
                ),
                ARRAY_A
            ) ?: [];
        });

        foreach ($item['items'] as &$li) {
            $li['is_checked'] = (bool) $li['is_checked'];
        }

        return $this->success($item);
    }

    /**
     * Create a shopping list.
     */
    public function create_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();

        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return $this->error(__('Name is required.', 'plugixa-recipe'));
        }

        $data = [
            'user_id'    => get_current_user_id(),
            'name'       => $name,
            'status'     => sanitize_text_field($params['status'] ?? 'active'),
            'created_at' => current_time('mysql'),
        ];

        $wpdb->insert($prefix . 'shopping_lists', $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create shopping list.', 'plugixa-recipe'), 500);
        }

        // Add initial items if provided
        if (!empty($params['items'])) {
            foreach ($params['items'] as $i => $li) {
                $wpdb->insert($prefix . 'shopping_list_items', [
                    'shopping_list_id' => $id,
                    'recipe_id'        => !empty($li['recipe_id']) ? (int) $li['recipe_id'] : null,
                    'ingredient_name'  => sanitize_text_field($li['ingredient_name'] ?? ''),
                    'quantity'         => sanitize_text_field($li['quantity'] ?? ''),
                    'unit'             => sanitize_text_field($li['unit'] ?? ''),
                    'is_checked'       => 0,
                    'sort_order'       => $i,
                    'added_at'         => current_time('mysql'),
                ]);
            }
        }

        // Content write.
        Cache::flush('shopping_lists');

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    /**
     * Update a shopping list.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'shopping_lists', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['status'])) {
            $data['status'] = sanitize_text_field($params['status']);
        }

        $data['modified_at'] = current_time('mysql');

        $wpdb->update($prefix . 'shopping_lists', $data, ['id' => $id]);

        // Content write.
        Cache::flush('shopping_lists');

        return $this->get_item($request);
    }

    /**
     * Delete a shopping list with items.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'shopping_lists', $id));
        if (!$exists) {
            return $this->error(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->delete($prefix . 'shopping_list_items', ['shopping_list_id' => $id]);
        $wpdb->delete($prefix . 'shopping_lists', ['id' => $id]);

        // Content write.
        Cache::flush('shopping_lists');

        return $this->success(null, __('Shopping list deleted.', 'plugixa-recipe'));
    }

    /**
     * Add item to shopping list.
     */
    public function add_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'shopping_lists', $id));
        if (!$exists) {
            return $this->error(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        $ingredient_name = sanitize_text_field($params['ingredient_name'] ?? '');
        if (empty($ingredient_name)) {
            return $this->error(__('Ingredient name is required.', 'plugixa-recipe'));
        }

        $max_order = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(sort_order) FROM %i WHERE shopping_list_id = %d',
            $prefix . 'shopping_list_items',
            $id
        ));

        $wpdb->insert($prefix . 'shopping_list_items', [
            'shopping_list_id' => $id,
            'recipe_id'        => !empty($params['recipe_id']) ? (int) $params['recipe_id'] : null,
            'ingredient_name'  => $ingredient_name,
            'quantity'         => sanitize_text_field($params['quantity'] ?? ''),
            'unit'             => sanitize_text_field($params['unit'] ?? ''),
            'is_checked'       => 0,
            'sort_order'       => $max_order + 1,
            'added_at'         => current_time('mysql'),
        ]);

        $item_id = $wpdb->insert_id;

        $wpdb->update($prefix . 'shopping_lists', ['modified_at' => current_time('mysql')], ['id' => $id]);

        $new_item = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $prefix . 'shopping_list_items',
            $item_id
        ), ARRAY_A);

        // Content write.
        Cache::flush('shopping_lists');

        return $this->success($new_item, __('Item added.', 'plugixa-recipe'));
    }

    /**
     * Update a shopping list item.
     */
    public function update_list_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $item_id = (int) $request->get_param('item_id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND shopping_list_id = %d',
            $prefix . 'shopping_list_items',
            $item_id, $id
        ), ARRAY_A);

        if (!$existing) {
            return $this->error(__('Item not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['ingredient_name'])) {
            $data['ingredient_name'] = sanitize_text_field($params['ingredient_name']);
        }
        if (isset($params['quantity'])) {
            $data['quantity'] = sanitize_text_field($params['quantity']);
        }
        if (isset($params['unit'])) {
            $data['unit'] = sanitize_text_field($params['unit']);
        }
        if (isset($params['is_checked'])) {
            $data['is_checked'] = (int) $params['is_checked'];
        }
        if (isset($params['sort_order'])) {
            $data['sort_order'] = (int) $params['sort_order'];
        }

        if (!empty($data)) {
            $wpdb->update($prefix . 'shopping_list_items', $data, ['id' => $item_id]);
            $wpdb->update($prefix . 'shopping_lists', ['modified_at' => current_time('mysql')], ['id' => $id]);
        }

        $updated = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $prefix . 'shopping_list_items',
            $item_id
        ), ARRAY_A);
        $updated['is_checked'] = (bool) $updated['is_checked'];

        // Content write.
        Cache::flush('shopping_lists');

        return $this->success($updated);
    }

    /**
     * Delete a shopping list item.
     */
    public function delete_list_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $item_id = (int) $request->get_param('item_id');

        $deleted = $wpdb->delete($prefix . 'shopping_list_items', [
            'id'               => $item_id,
            'shopping_list_id' => $id,
        ]);

        if (!$deleted) {
            return $this->error(__('Item not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->update($prefix . 'shopping_lists', ['modified_at' => current_time('mysql')], ['id' => $id]);

        // Content write.
        Cache::flush('shopping_lists');

        return $this->success(null, __('Item deleted.', 'plugixa-recipe'));
    }

    /**
     * Create shopping list from a recipe's ingredients.
     */
    public function create_from_recipe($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $recipe = $wpdb->get_row($wpdb->prepare(
            'SELECT id, title FROM %i WHERE id = %d',
            $prefix . 'recipes',
            $recipe_id
        ), ARRAY_A);

        if (!$recipe) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $ingredients = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
            $prefix . 'ingredients',
            $recipe_id
        ), ARRAY_A);

        if (empty($ingredients)) {
            return $this->error(__('Recipe has no ingredients.', 'plugixa-recipe'));
        }

        /* translators: %s: recipe title the shopping list was generated from. */
        $list_name = sanitize_text_field($params['name'] ?? '') ?: sprintf(__('Shopping List - %s', 'plugixa-recipe'), $recipe['title']);

        $wpdb->insert($prefix . 'shopping_lists', [
            'user_id'    => get_current_user_id(),
            'name'       => $list_name,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ]);

        $list_id = $wpdb->insert_id;

        if (!$list_id) {
            return $this->error(__('Failed to create shopping list.', 'plugixa-recipe'), 500);
        }

        foreach ($ingredients as $i => $ing) {
            $wpdb->insert($prefix . 'shopping_list_items', [
                'shopping_list_id' => $list_id,
                'recipe_id'        => $recipe_id,
                'ingredient_name'  => $ing['name'],
                'quantity'         => $ing['quantity'],
                'unit'             => $ing['unit'],
                'is_checked'       => 0,
                'sort_order'       => $i,
                'added_at'         => current_time('mysql'),
            ]);
        }

        // Content write.
        Cache::flush('shopping_lists');

        $request->set_param('id', $list_id);
        return $this->get_item($request);
    }

    /**
     * Create shopping list from all recipes in a meal plan collection.
     */
    public function create_from_meal_plan($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $collection_id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $collection = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND type = \'meal_plan\'',
            $prefix . 'collections',
            $collection_id
        ), ARRAY_A);

        if (!$collection) {
            return $this->error(__('Meal plan not found.', 'plugixa-recipe'), 404);
        }

        // Get all recipe IDs in the meal plan
        $recipe_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT recipe_id FROM %i WHERE collection_id = %d',
            $prefix . 'collection_recipes',
            $collection_id
        ));

        if (empty($recipe_ids)) {
            return $this->error(__('Meal plan has no recipes.', 'plugixa-recipe'));
        }

        /* translators: %s: collection (meal plan) name the shopping list was generated from. */
        $list_name = sanitize_text_field($params['name'] ?? '') ?: sprintf(__('Shopping List - %s', 'plugixa-recipe'), $collection['name']);

        $wpdb->insert($prefix . 'shopping_lists', [
            'user_id'    => get_current_user_id(),
            'name'       => $list_name,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ]);

        $list_id = $wpdb->insert_id;

        if (!$list_id) {
            return $this->error(__('Failed to create shopping list.', 'plugixa-recipe'), 500);
        }

        // Get all ingredients from all recipes in the meal plan. The id list binds as ONE %s
        // via FIND_IN_SET rather than a variable-length IN (...), keeping the query a
        // constant literal for any meal-plan size.
        $ingredients = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE FIND_IN_SET(recipe_id, %s) ORDER BY recipe_id ASC, group_order ASC, sort_order ASC',
            $prefix . 'ingredients',
            implode(',', array_map('intval', $recipe_ids))
        ), ARRAY_A);

        // Aggregate similar ingredients
        $aggregated = [];
        foreach ($ingredients as $ing) {
            $key = strtolower(trim($ing['name'])) . '|' . strtolower(trim($ing['unit']));
            if (isset($aggregated[$key])) {
                // Add quantities if both are numeric
                if (is_numeric($aggregated[$key]['quantity']) && is_numeric($ing['quantity'])) {
                    $aggregated[$key]['quantity'] = (float) $aggregated[$key]['quantity'] + (float) $ing['quantity'];
                    $aggregated[$key]['quantity'] = (string) $aggregated[$key]['quantity'];
                }
            } else {
                $aggregated[$key] = [
                    'recipe_id'       => (int) $ing['recipe_id'],
                    'ingredient_name' => $ing['name'],
                    'quantity'        => $ing['quantity'],
                    'unit'            => $ing['unit'],
                ];
            }
        }

        $sort = 0;
        foreach ($aggregated as $item) {
            $wpdb->insert($prefix . 'shopping_list_items', [
                'shopping_list_id' => $list_id,
                'recipe_id'        => $item['recipe_id'],
                'ingredient_name'  => $item['ingredient_name'],
                'quantity'         => $item['quantity'],
                'unit'             => $item['unit'],
                'is_checked'       => 0,
                'sort_order'       => $sort++,
                'added_at'         => current_time('mysql'),
            ]);
        }

        // Content write.
        Cache::flush('shopping_lists');

        $request->set_param('id', $list_id);
        return $this->get_item($request);
    }
}
