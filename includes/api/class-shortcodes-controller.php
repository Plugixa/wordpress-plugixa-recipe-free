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
 * Shortcodes REST Controller
 */
class ShortcodesController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'shortcodes';
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'duplicate_item'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix  = $wpdb->prefix . 'plugixa_recipe_';
        $filters = $this->get_filters($request);
        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request);

        // The search filter is a gate placeholder: bind 0 and the clause is constant-true,
        // which keeps WHERE literal instead of PHP-assembled.
        $search = !empty($filters['search'])
            ? '%' . $wpdb->esc_like($filters['search']) . '%'
            : '';

        $allowed_orderby = ['name', 'created_at', 'modified_at'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $filter_args = [
            $prefix . 'shortcodes',
            $search !== '' ? 1 : 0, $search, $search,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text.
        $total = (int) Cache::remember('shortcodes', 'count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )',
                ...$filter_args
            ));
        });

        // The ORDER BY column binds with the %i identifier placeholder (WP 6.2+) from the
        // $allowed_orderby list above; a direction keyword cannot be bound, so ASC/DESC is
        // two literal queries. The id tiebreaker keeps paging stable on ties.
        $args = array_merge($filter_args, [$orderby, $pagination['limit'], $pagination['offset']]);

        $items = Cache::remember('shortcodes', 'items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT * FROM %i
                      WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                      ORDER BY %i ASC, id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                  ORDER BY %i DESC, id DESC
                  LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A);
        });

        foreach ($items as &$item) {
            $item['config'] = json_decode($item['config'], true);
            $item['view_id'] = (int) ($item['config']['view_id'] ?? 0);
            if ($item['view_id']) {
                $view_id = $item['view_id'];
                // Cached under the "views" entity: a view write invalidates this name lookup.
                $view = Cache::remember('views', "name:{$view_id}", function () use ($wpdb, $prefix, $view_id) {
                    return $wpdb->get_row($wpdb->prepare(
                        'SELECT name FROM %i WHERE id = %d', $prefix . 'views', $view_id
                    ), ARRAY_A);
                });
                $item['view_name'] = $view ? $view['name'] : '';
            } else {
                $item['view_name'] = '';
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

        $item = Cache::remember('shortcodes', "item:{$id}", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'shortcodes', $id),
                ARRAY_A
            );
        });

        if (!$item) {
            return $this->error(__('Shortcode not found.', 'plugixa-recipe'), 404);
        }

        $item['config'] = json_decode($item['config'], true);

        $item['view_id'] = (int) ($item['config']['view_id'] ?? 0);
        if ($item['view_id']) {
            $view_id = $item['view_id'];
            // Cached under the "views" entity: a view write invalidates this name lookup.
            $view = Cache::remember('views', "name:{$view_id}", function () use ($wpdb, $prefix, $view_id) {
                return $wpdb->get_row($wpdb->prepare(
                    'SELECT name FROM %i WHERE id = %d', $prefix . 'views', $view_id
                ), ARRAY_A);
            });
            $item['view_name'] = $view ? $view['name'] : '';
        } else {
            $item['view_name'] = '';
        }

        return $this->success($item);
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $now = current_time('mysql');

        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return $this->error(__('Name is required.', 'plugixa-recipe'));
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);
        $slug = $this->ensure_unique_slug($slug, $prefix . 'shortcodes');

        $config = $params['config'] ?? [];
        if (isset($params['view_id'])) {
            $config['view_id'] = (int) $params['view_id'];
        }

        $data = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'config'      => wp_json_encode($config),
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ];

        $wpdb->insert($prefix . 'shortcodes', $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create shortcode.', 'plugixa-recipe'), 500);
        }

        Cache::flush('shortcodes');

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'shortcodes', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Shortcode not found.', 'plugixa-recipe'), 404);
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
                $slug = $this->ensure_unique_slug($slug, $prefix . 'shortcodes', $id);
            }
            $data['slug'] = $slug;
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['config'])) {
            $config = $params['config'];
            if (isset($params['view_id'])) {
                $config['view_id'] = (int) $params['view_id'];
            }
            $data['config'] = wp_json_encode($config);
        } elseif (isset($params['view_id'])) {
            $existing_config = json_decode($existing['config'], true) ?: [];
            $existing_config['view_id'] = (int) $params['view_id'];
            $data['config'] = wp_json_encode($existing_config);
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        $wpdb->update($prefix . 'shortcodes', $data, ['id' => $id]);

        Cache::flush('shortcodes');

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'shortcodes', $id));
        if (!$exists) {
            return $this->error(__('Shortcode not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->delete($prefix . 'shortcodes', ['id' => $id]);

        Cache::flush('shortcodes');

        return $this->success(null, __('Shortcode deleted.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids = array_map('intval', $params['ids'] ?? []);

        if (empty($ids)) {
            return $this->error(__('No IDs provided.', 'plugixa-recipe'));
        }

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so the statement stays a constant literal. $ids is already intval-mapped above.
        $id_list = implode(',', $ids);
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s)',
            $prefix . 'shortcodes',
            $id_list
        ));

        Cache::flush('shortcodes');

        return $this->success(['deleted' => $deleted], __('Shortcodes deleted.', 'plugixa-recipe'));
    }

    public function duplicate_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $now = current_time('mysql');

        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'shortcodes', $id), ARRAY_A);
        if (!$item) {
            return $this->error(__('Shortcode not found.', 'plugixa-recipe'), 404);
        }

        $new_name = $item['name'] . ' (Copy)';
        $new_slug = $this->ensure_unique_slug(sanitize_title($new_name), $prefix . 'shortcodes');

        $wpdb->insert($prefix . 'shortcodes', [
            'name'        => $new_name,
            'slug'        => $new_slug,
            'description' => $item['description'],
            'config'      => $item['config'],
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ]);

        $new_id = $wpdb->insert_id;

        Cache::flush('shortcodes');

        $request->set_param('id', $new_id);
        return $this->get_item($request);
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
