<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` / `...$filter_args` list queries report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Cuisines REST Controller
 */
class CuisinesController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'cuisines';
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reorder'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix  = $wpdb->prefix . 'plugixa_recipe_';
        $filters = $this->get_filters($request);
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // Every optional filter is a gate placeholder: bind 0 and the clause is
        // constant-true, which keeps WHERE literal instead of PHP-assembled.
        $search = !empty($filters['search']) ? '%' . $wpdb->esc_like($filters['search']) . '%' : '';
        $lang   = !empty($filters['language_code']) ? (string) $filters['language_code'] : '';

        $allowed_orderby = ['name', 'display_order'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'display_order';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound values for the shared WHERE block, in placeholder order. %i identifiers
        // and %s/%d values share one positional list, so the table name sits inline.
        $filter_args = [
            $prefix . 'cuisines',
            $search !== '' ? 1 : 0, $search, $search,
            $lang !== '' ? 1 : 0, $lang,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text.
        $total = (int) Cache::remember('cuisines', 'count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i cu
                  WHERE ( %d = 0 OR (cu.name LIKE %s OR cu.description LIKE %s) )
                    AND ( %d = 0 OR cu.language_code = %s )',
                ...$filter_args
            ));
        });

        // Main query with recipe_count. The ORDER BY column binds with the %i identifier
        // placeholder (WP 6.2+) from the $allowed_orderby list above; a direction keyword
        // cannot be bound at all, so ASC/DESC is two literal queries. The id tiebreaker
        // keeps paging stable when the sort column has ties.
        $args = array_merge(
            [$prefix . 'cuisines', $prefix . 'recipe_cuisines'],
            array_slice($filter_args, 1),
            [$orderby, $pagination['limit'], $pagination['offset']]
        );

        // Cache key: stable digest of the query shape and bound values, not the SQL text.
        $items = Cache::remember('cuisines', 'items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT cu.*, COUNT(rcu.recipe_id) AS recipe_count
                    FROM %i cu
                    LEFT JOIN %i rcu ON cu.id = rcu.cuisine_id
                    WHERE ( %d = 0 OR (cu.name LIKE %s OR cu.description LIKE %s) )
                      AND ( %d = 0 OR cu.language_code = %s )
                    GROUP BY cu.id
                    ORDER BY cu.%i ASC, cu.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.*, COUNT(rcu.recipe_id) AS recipe_count
                FROM %i cu
                LEFT JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE ( %d = 0 OR (cu.name LIKE %s OR cu.description LIKE %s) )
                      AND ( %d = 0 OR cu.language_code = %s )
                GROUP BY cu.id
                ORDER BY cu.%i DESC, cu.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A);
        });

        foreach ($items as &$item) {
            $item['recipe_count'] = (int) $item['recipe_count'];
            if (!empty($item['image_id'])) {
                $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'thumbnail');
            }
        }

        return $this->success([
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ]);
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $item = Cache::remember('cuisines', "item:{$id}", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'cuisines', $id),
                ARRAY_A
            );
        });

        if (!$item) {
            return $this->error(__('Cuisine not found.', 'plugixa-recipe'), 404);
        }

        $item['recipe_count'] = (int) Cache::remember('cuisines', "item-recipe-count:{$id}", function () use ($wpdb, $prefix, $id) {
            return (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE cuisine_id = %d', $prefix . 'recipe_cuisines', $id)
            );
        });

        if (!empty($item['image_id'])) {
            $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'medium');
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();

        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return $this->error(__('Name is required.', 'plugixa-recipe'));
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);
        $slug = $this->ensure_unique_slug($slug, $prefix . 'cuisines');

        $data = [
            'name'              => $name,
            'slug'              => $slug,
            'description'       => sanitize_textarea_field($params['description'] ?? ''),
            'image_id'          => !empty($params['image_id']) ? (int) $params['image_id'] : null,
            'display_order'     => (int) ($params['display_order'] ?? 0),
            'language_code'     => sanitize_text_field($params['language_code'] ?? ''),
            'translation_group' => sanitize_text_field($params['translation_group'] ?? ''),
        ];

        $wpdb->insert($prefix . 'cuisines', $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create cuisine.', 'plugixa-recipe'), 500);
        }

        Cache::flush('cuisines', 'recipes');

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'cuisines', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Cuisine not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['slug'])) {
            $slug = sanitize_title($params['slug']);
            if ($slug === '') {
                $slug = sanitize_title($params['name'] ?? $existing['name']);
            }
            if ($slug !== $existing['slug']) {
                $slug = $this->ensure_unique_slug($slug, $prefix . 'cuisines', $id);
            }
            $data['slug'] = $slug;
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['image_id'])) {
            $data['image_id'] = !empty($params['image_id']) ? (int) $params['image_id'] : null;
        }
        if (isset($params['display_order'])) {
            $data['display_order'] = (int) $params['display_order'];
        }
        if (isset($params['language_code'])) {
            $data['language_code'] = sanitize_text_field($params['language_code']);
        }
        if (isset($params['translation_group'])) {
            $data['translation_group'] = sanitize_text_field($params['translation_group']);
        }

        if (empty($data)) {
            return $this->error(__('No fields to update.', 'plugixa-recipe'));
        }

        $wpdb->update($prefix . 'cuisines', $data, ['id' => $id]);

        Cache::flush('cuisines', 'recipes');

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'cuisines', $id));
        if (!$exists) {
            return $this->error(__('Cuisine not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->delete($prefix . 'recipe_cuisines', ['cuisine_id' => $id]);
        $wpdb->delete($prefix . 'cuisines', ['id' => $id]);

        Cache::flush('cuisines', 'recipes');

        return $this->success(null, __('Cuisine deleted.', 'plugixa-recipe'));
    }

    public function reorder($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $items  = $params['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            return $this->error(__('Items are required.', 'plugixa-recipe'));
        }

        foreach ($items as $item) {
            $wpdb->update(
                $prefix . 'cuisines',
                ['display_order' => (int) $item['display_order']],
                ['id' => (int) $item['id']]
            );
        }

        Cache::flush('cuisines', 'recipes');

        return $this->success(null, __('Order updated.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids    = array_map('intval', $params['ids'] ?? []);

        if (empty($ids)) {
            return $this->error(__('No IDs provided.', 'plugixa-recipe'));
        }

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so each statement stays a constant literal. $ids is already intval-mapped above.
        $id_list = implode(',', $ids);
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE FIND_IN_SET(cuisine_id, %s)', $prefix . 'recipe_cuisines', $id_list));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE FIND_IN_SET(id, %s)', $prefix . 'cuisines', $id_list));

        Cache::flush('cuisines', 'recipes');

        return $this->success(null, __('Cuisines deleted.', 'plugixa-recipe'));
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
