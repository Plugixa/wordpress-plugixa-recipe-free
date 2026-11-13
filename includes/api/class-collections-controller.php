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
 * Collections REST Controller (collections, cookbooks, meal plans, favorites)
 */
class CollectionsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'collections';
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

        // Collection recipes management
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/recipes', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_collection_recipes'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'add_recipe_to_collection'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/recipes/(?P<recipe_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'remove_recipe_from_collection'],
                // Edit, not delete: removes a pivot row in collection_recipes, not an entity.
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/recipes/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder_recipes'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get collections list.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix  = $wpdb->prefix . 'plugixa_recipe_';
        $filters = $this->get_filters($request);
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // Every optional filter is a gate placeholder: bind 0 and the clause is
        // constant-true, which keeps WHERE literal instead of PHP-assembled.
        $search = !empty($filters['search'])
            ? '%' . $wpdb->esc_like($filters['search']) . '%'
            : '';

        $type       = !empty($filters['type']) ? (string) $filters['type'] : '';
        $status     = !empty($filters['status']) ? (string) $filters['status'] : '';
        $user_id    = !empty($filters['user_id']) ? (int) $filters['user_id'] : 0;
        $visibility = !empty($filters['visibility']) ? (string) $filters['visibility'] : '';

        $allowed_orderby = ['name', 'created_at', 'modified_at', 'display_order', 'type'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound gate/value pairs for the shared WHERE block, in placeholder order. The count
        // query needs only the collections table; the list query joins the pivot too, so each
        // gets its own leading identifiers.
        $filter_gates = [
            $search !== '' ? 1 : 0, $search, $search,
            $type !== '' ? 1 : 0, $type,
            $status !== '' ? 1 : 0, $status,
            $user_id ? 1 : 0, $user_id,
            $visibility !== '' ? 1 : 0, $visibility,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text.
        $total = (int) Cache::remember('collections', 'count|' . md5(wp_json_encode($filter_gates)), function () use ($wpdb, $prefix, $filter_gates) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i col
                  WHERE ( %d = 0 OR (col.name LIKE %s OR col.description LIKE %s) )
                    AND ( %d = 0 OR col.type = %s )
                    AND ( %d = 0 OR col.status = %s )
                    AND ( %d = 0 OR col.user_id = %d )
                    AND ( %d = 0 OR col.visibility = %s )',
                ...array_merge([$prefix . 'collections'], $filter_gates)
            ));
        });

        // Main query with recipe count. The ORDER BY column binds with %i from the
        // $allowed_orderby list above; a direction keyword cannot be bound, so ASC/DESC is
        // two literal queries. The id tiebreaker keeps paging stable on ties.
        $args = array_merge(
            [$prefix . 'collections', $prefix . 'collection_recipes'],
            $filter_gates,
            [$orderby, $pagination['limit'], $pagination['offset']]
        );

        $items = Cache::remember('collections', 'list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT col.*, COUNT(cr.recipe_id) AS recipe_count
                    FROM %i col
                    LEFT JOIN %i cr ON col.id = cr.collection_id
                    WHERE ( %d = 0 OR (col.name LIKE %s OR col.description LIKE %s) )
                      AND ( %d = 0 OR col.type = %s )
                      AND ( %d = 0 OR col.status = %s )
                      AND ( %d = 0 OR col.user_id = %d )
                      AND ( %d = 0 OR col.visibility = %s )
                    GROUP BY col.id
                    ORDER BY col.%i ASC, col.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT col.*, COUNT(cr.recipe_id) AS recipe_count
                FROM %i col
                LEFT JOIN %i cr ON col.id = cr.collection_id
                WHERE ( %d = 0 OR (col.name LIKE %s OR col.description LIKE %s) )
                  AND ( %d = 0 OR col.type = %s )
                  AND ( %d = 0 OR col.status = %s )
                  AND ( %d = 0 OR col.user_id = %d )
                  AND ( %d = 0 OR col.visibility = %s )
                GROUP BY col.id
                ORDER BY col.%i DESC, col.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });

        foreach ($items as &$item) {
            $item['recipe_count'] = (int) $item['recipe_count'];
            $item['is_featured'] = (bool) $item['is_featured'];
            $item['title'] = $item['name'];
            $item['is_public'] = ($item['visibility'] ?? 'public') === 'public';
            $item['status'] = $this->map_status_to_frontend($item['status'] ?? 'published');
            if (!empty($item['user_id'])) {
                $user = get_userdata((int) $item['user_id']);
                $item['author_name'] = $user ? $user->display_name : '';
            }
            if (!empty($item['image_id'])) {
                $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'medium');
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
     * Get single collection with recipes.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        // Read-through object cache; key = collection id.
        $item = Cache::remember('collections', 'item|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'collections', $id),
                ARRAY_A
            );
        });

        if (!$item) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        $item['is_featured'] = (bool) $item['is_featured'];
        $item['title'] = $item['name'];
        $item['is_public'] = ($item['visibility'] ?? 'public') === 'public';
        $item['status'] = $this->map_status_to_frontend($item['status'] ?? 'published');

        if (!empty($item['image_id'])) {
            $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'large');
        }

        // Get recipes in collection — read-through object cache; key = collection id.
        $item['recipes'] = Cache::remember('collections', 'item_recipes|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.image_id, r.prep_time, r.cook_time, r.total_time,
                            r.difficulty, r.rating_average, r.status,
                            cr.display_order, cr.day_number, cr.meal_type, cr.added_at
                    FROM %i r
                    INNER JOIN %i cr ON r.id = cr.recipe_id
                    WHERE cr.collection_id = %d
                    ORDER BY cr.display_order ASC, r.id ASC',
                    $prefix . 'recipes',
                    $prefix . 'collection_recipes',
                    $id
                ),
                ARRAY_A
            ) ?: [];
        });

        foreach ($item['recipes'] as &$recipe) {
            if (!empty($recipe['image_id'])) {
                $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
            }
            $recipe['rating_average'] = (float) $recipe['rating_average'];
        }

        $item['recipe_count'] = count($item['recipes']);

        return $this->success($item);
    }

    /**
     * Create a collection.
     */
    public function create_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();

        $name = sanitize_text_field($params['title'] ?? $params['name'] ?? '');
        if (empty($name)) {
            return $this->error(__('Name is required.', 'plugixa-recipe'));
        }

        $type = sanitize_text_field($params['type'] ?? 'collection');
        $allowed_types = ['collection', 'cookbook', 'meal_plan', 'favorites'];
        if (!in_array($type, $allowed_types)) {
            $type = 'collection';
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);
        $slug = $this->ensure_unique_slug($slug, $prefix . 'collections');

        // Map frontend status (active/inactive) to DB status (published/draft)
        $status = $this->map_status_from_frontend(sanitize_text_field($params['status'] ?? 'active'));

        // Map frontend is_public (bool) to DB visibility (public/private)
        $visibility = sanitize_text_field($params['visibility'] ?? 'public');
        if (isset($params['is_public'])) {
            $visibility = $params['is_public'] ? 'public' : 'private';
        }

        $data = [
            'name'          => $name,
            'slug'          => $slug,
            'description'   => sanitize_textarea_field($params['description'] ?? ''),
            'image_id'      => !empty($params['image_id']) ? (int) $params['image_id'] : null,
            'type'          => $type,
            'status'        => $status,
            'is_featured'   => (int) ($params['is_featured'] ?? 0),
            'display_order' => (int) ($params['display_order'] ?? 0),
            'user_id'       => get_current_user_id(),
            'visibility'    => $visibility,
            'created_at'    => current_time('mysql'),
        ];

        $wpdb->insert($prefix . 'collections', $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create collection.', 'plugixa-recipe'), 500);
        }

        // Content write.
        Cache::flush('collections');

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    /**
     * Update a collection.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'collections', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        // Accept 'title' (frontend) or 'name' (DB)
        if (isset($params['title']) || isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['title'] ?? $params['name']);
        }
        if (isset($params['slug'])) {
            $slug = sanitize_title($params['slug']);
            if ($slug === '') {
                $slug = sanitize_title($params['name'] ?? $existing['name']);
            }
            if ($slug !== $existing['slug']) {
                $slug = $this->ensure_unique_slug($slug, $prefix . 'collections', $id);
            }
            $data['slug'] = $slug;
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['image_id'])) {
            $data['image_id'] = !empty($params['image_id']) ? (int) $params['image_id'] : null;
        }
        if (isset($params['type'])) {
            $allowed_types = ['collection', 'cookbook', 'meal_plan', 'favorites'];
            $data['type'] = in_array($params['type'], $allowed_types) ? $params['type'] : $existing['type'];
        }
        if (isset($params['status'])) {
            $data['status'] = $this->map_status_from_frontend(sanitize_text_field($params['status']));
        }
        if (isset($params['is_featured'])) {
            $data['is_featured'] = (int) $params['is_featured'];
        }
        if (isset($params['display_order'])) {
            $data['display_order'] = (int) $params['display_order'];
        }
        // Accept 'is_public' (frontend bool) or 'visibility' (DB string)
        if (isset($params['is_public'])) {
            $data['visibility'] = $params['is_public'] ? 'public' : 'private';
        } elseif (isset($params['visibility'])) {
            $data['visibility'] = sanitize_text_field($params['visibility']);
        }

        $data['modified_at'] = current_time('mysql');

        $wpdb->update($prefix . 'collections', $data, ['id' => $id]);

        // Content write.
        Cache::flush('collections');

        return $this->get_item($request);
    }

    /**
     * Delete a collection and its pivot entries.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'collections', $id));
        if (!$exists) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->delete($prefix . 'collection_recipes', ['collection_id' => $id]);
        $wpdb->delete($prefix . 'collections', ['id' => $id]);

        // Content write.
        Cache::flush('collections');

        return $this->success(null, __('Collection deleted.', 'plugixa-recipe'));
    }

    /**
     * Get recipes in a collection.
     */
    public function get_collection_recipes($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $collection = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'collections', $id), ARRAY_A);
        if (!$collection) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        // Read-through object cache; key = collection id.
        $recipes = Cache::remember('collections', 'recipes|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT r.*, cr.display_order, cr.day_number, cr.meal_type, cr.added_at
                    FROM %i r
                    INNER JOIN %i cr ON r.id = cr.recipe_id
                    WHERE cr.collection_id = %d
                    ORDER BY cr.display_order ASC, r.id ASC',
                    $prefix . 'recipes',
                    $prefix . 'collection_recipes',
                    $id
                ),
                ARRAY_A
            ) ?: [];
        });

        foreach ($recipes as &$recipe) {
            if (!empty($recipe['image_id'])) {
                $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium');
            }
            $recipe['rating_average'] = (float) $recipe['rating_average'];
        }

        return $this->success($recipes);
    }

    /**
     * Add a recipe to a collection.
     */
    public function add_recipe_to_collection($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $recipe_id = (int) ($params['recipe_id'] ?? 0);

        if (!$recipe_id) {
            return $this->error(__('Recipe ID is required.', 'plugixa-recipe'));
        }

        // Verify both exist
        $collection = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'collections', $id), ARRAY_A);
        if (!$collection) {
            return $this->error(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        $recipe_exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'recipes', $recipe_id));
        if (!$recipe_exists) {
            return $this->error(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Get next display_order
        $max_order = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(display_order) FROM %i WHERE collection_id = %d',
            $prefix . 'collection_recipes',
            $id
        ));

        $insert_data = [
            'collection_id' => $id,
            'recipe_id'     => $recipe_id,
            'display_order' => $max_order + 1,
            'added_at'      => current_time('mysql'),
        ];

        // Meal plan support: day_number and meal_type
        if ($collection['type'] === 'meal_plan') {
            if (isset($params['day_number'])) {
                $insert_data['day_number'] = (int) $params['day_number'];
            }
            if (isset($params['meal_type'])) {
                $insert_data['meal_type'] = sanitize_text_field($params['meal_type']);
            }
        }

        $result = $wpdb->insert($prefix . 'collection_recipes', $insert_data);

        if ($result === false) {
            return $this->error(__('Recipe already exists in this collection or could not be added.', 'plugixa-recipe'));
        }

        // Update collection modified_at
        $wpdb->update($prefix . 'collections', ['modified_at' => current_time('mysql')], ['id' => $id]);

        // Content write.
        Cache::flush('collections');

        return $this->success($insert_data, __('Recipe added to collection.', 'plugixa-recipe'));
    }

    /**
     * Remove a recipe from a collection.
     */
    public function remove_recipe_from_collection($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $recipe_id = (int) $request->get_param('recipe_id');

        $deleted = $wpdb->delete($prefix . 'collection_recipes', [
            'collection_id' => $id,
            'recipe_id'     => $recipe_id,
        ]);

        if (!$deleted) {
            return $this->error(__('Recipe not found in this collection.', 'plugixa-recipe'), 404);
        }

        $wpdb->update($prefix . 'collections', ['modified_at' => current_time('mysql')], ['id' => $id]);

        // Content write.
        Cache::flush('collections');

        return $this->success(null, __('Recipe removed from collection.', 'plugixa-recipe'));
    }

    /**
     * Reorder recipes in a collection.
     */
    public function reorder_recipes($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $items  = $params['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            return $this->error(__('Items are required.', 'plugixa-recipe'));
        }

        foreach ($items as $item) {
            $update = ['display_order' => (int) ($item['display_order'] ?? 0)];
            if (isset($item['day_number'])) {
                $update['day_number'] = (int) $item['day_number'];
            }
            if (isset($item['meal_type'])) {
                $update['meal_type'] = sanitize_text_field($item['meal_type']);
            }
            $wpdb->update($prefix . 'collection_recipes', $update, [
                'collection_id' => $id,
                'recipe_id'     => (int) $item['recipe_id'],
            ]);
        }

        // Content write.
        Cache::flush('collections');

        return $this->success(null, __('Collection recipes reordered.', 'plugixa-recipe'));
    }

    /**
     * Map frontend status (active/inactive) to DB status (published/draft).
     */
    private function map_status_from_frontend(string $status): string {
        return match ($status) {
            'active'  => 'published',
            'inactive' => 'draft',
            default   => $status,
        };
    }

    /**
     * Map DB status (published/draft) to frontend status (active/inactive).
     */
    private function map_status_to_frontend(string $status): string {
        return match ($status) {
            'published' => 'active',
            'draft'     => 'inactive',
            default     => $status,
        };
    }

    private function ensure_unique_slug(string $slug, string $table, int $exclude_id = 0): string {
        global $wpdb;
        $original = $slug;
        $counter  = 1;

        while (true) {
            if ($exclude_id) {
                $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $table, $slug, $exclude_id));
            } else {
                $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, $slug));
            }
            if (!$exists) {
                break;
            }
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
