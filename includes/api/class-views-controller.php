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
 * Views REST Controller
 */
class ViewsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'views';
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/set-default', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'set_default'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/default-config', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_default_config'],
                'permission_callback' => [$this, 'check_read_permission'],
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
        // constant-true, which keeps WHERE literal instead of PHP-assembled. is_default is
        // tri-state - absent means no filter, else match the given 0/1 exactly.
        $search = !empty($filters['search'])
            ? '%' . $wpdb->esc_like($filters['search']) . '%'
            : '';

        $has_default = isset($filters['is_default']) && $filters['is_default'] !== '';
        $default_val = $has_default ? (int) $filters['is_default'] : 0;

        $layout = !empty($filters['layout_style'])
            ? '%"style":"' . $wpdb->esc_like($filters['layout_style']) . '"%'
            : '';

        $allowed_orderby = ['name', 'created_at', 'modified_at', 'is_default'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound values for the shared WHERE block, in placeholder order.
        $filter_args = [
            $prefix . 'views',
            $search !== '' ? 1 : 0, $search, $search,
            $has_default ? 1 : 0, $default_val,
            $layout !== '' ? 1 : 0, $layout,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text.
        $total = (int) Cache::remember('views', 'count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                    AND ( %d = 0 OR is_default = %d )
                    AND ( %d = 0 OR config LIKE %s )',
                ...$filter_args
            ));
        });

        // The ORDER BY column binds with the %i identifier placeholder (WP 6.2+) from the
        // $allowed_orderby list above; a direction keyword cannot be bound, so ASC/DESC is
        // two literal queries. The id tiebreaker keeps paging stable on ties.
        $args = array_merge($filter_args, [$orderby, $pagination['limit'], $pagination['offset']]);

        $items = Cache::remember('views', 'items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT * FROM %i
                      WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                        AND ( %d = 0 OR is_default = %d )
                        AND ( %d = 0 OR config LIKE %s )
                      ORDER BY %i ASC, id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                    AND ( %d = 0 OR is_default = %d )
                    AND ( %d = 0 OR config LIKE %s )
                  ORDER BY %i DESC, id DESC
                  LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A);
        });

        foreach ($items as &$item) {
            $item['is_default'] = (bool) $item['is_default'];
            $item['config'] = json_decode($item['config'], true);
            $item['layout'] = $this->extract_layout($item['config']);
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

        $item = Cache::remember('views', "item:{$id}", function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'views', $id),
                ARRAY_A
            );
        });

        if (!$item) {
            return $this->error(__('View not found.', 'plugixa-recipe'), 404);
        }

        $item['is_default'] = (bool) $item['is_default'];
        $item['config'] = json_decode($item['config'], true);
        $item['layout'] = $this->extract_layout($item['config']);

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
        $slug = $this->ensure_unique_slug($slug, $prefix . 'views');

        $config = $params['config'] ?? [];
        if (isset($params['layout'])) {
            $layout_style = sanitize_text_field($params['layout']);
            $existing_layout = is_array($config['layout'] ?? null) ? $config['layout'] : [];
            $config['layout'] = array_merge($existing_layout, ['style' => $layout_style]);
        }

        $data = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'config'      => wp_json_encode($config),
            'is_default'  => 0,
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ];

        $wpdb->insert($prefix . 'views', $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create view.', 'plugixa-recipe'), 500);
        }

        Cache::flush('views');

        $request->set_param('id', $id);
        return $this->get_item($request);
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'views', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('View not found.', 'plugixa-recipe'), 404);
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
                $slug = $this->ensure_unique_slug($slug, $prefix . 'views', $id);
            }
            $data['slug'] = $slug;
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['config'])) {
            $config = $params['config'];
            if (isset($params['layout'])) {
                $layout_style = sanitize_text_field($params['layout']);
                $existing_layout = is_array($config['layout'] ?? null) ? $config['layout'] : [];
                $config['layout'] = array_merge($existing_layout, ['style' => $layout_style]);
            }
            $data['config'] = wp_json_encode($config);
        } elseif (isset($params['layout'])) {
            $existing_config = json_decode($existing['config'], true) ?: [];
            $layout_style = sanitize_text_field($params['layout']);
            $existing_layout = is_array($existing_config['layout'] ?? null) ? $existing_config['layout'] : [];
            $existing_config['layout'] = array_merge($existing_layout, ['style' => $layout_style]);
            $data['config'] = wp_json_encode($existing_config);
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        $wpdb->update($prefix . 'views', $data, ['id' => $id]);

        Cache::flush('views');

        return $this->get_item($request);
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $view = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'views', $id), ARRAY_A);
        if (!$view) {
            return $this->error(__('View not found.', 'plugixa-recipe'), 404);
        }

        if ($view['is_default']) {
            return $this->error(__('Cannot delete the default view.', 'plugixa-recipe'));
        }

        $wpdb->delete($prefix . 'views', ['id' => $id]);

        Cache::flush('views');

        return $this->success(null, __('View deleted.', 'plugixa-recipe'));
    }

    public function bulk_delete($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids = array_map('intval', $params['ids'] ?? []);

        if (empty($ids)) {
            return $this->error(__('No IDs provided.', 'plugixa-recipe'));
        }

        // Exclude default view
        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so the statement stays a constant literal. $ids is already intval-mapped above.
        $id_list = implode(',', $ids);
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s) AND is_default = 0',
            $prefix . 'views',
            $id_list
        ));

        Cache::flush('views');

        return $this->success(['deleted' => $deleted], __('Views deleted.', 'plugixa-recipe'));
    }

    public function duplicate_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $now = current_time('mysql');

        $view = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'views', $id), ARRAY_A);
        if (!$view) {
            return $this->error(__('View not found.', 'plugixa-recipe'), 404);
        }

        $new_name = $view['name'] . ' (Copy)';
        $new_slug = $this->ensure_unique_slug(sanitize_title($new_name), $prefix . 'views');

        $wpdb->insert($prefix . 'views', [
            'name'        => $new_name,
            'slug'        => $new_slug,
            'description' => $view['description'],
            'config'      => $view['config'],
            'is_default'  => 0,
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ]);

        $new_id = $wpdb->insert_id;

        Cache::flush('views');

        $request->set_param('id', $new_id);
        return $this->get_item($request);
    }

    public function set_default($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $prefix . 'views', $id));
        if (!$exists) {
            return $this->error(__('View not found.', 'plugixa-recipe'), 404);
        }

        // Unset all defaults
        $wpdb->update($prefix . 'views', ['is_default' => 0], ['is_default' => 1]);

        // Set this one as default
        $wpdb->update($prefix . 'views', ['is_default' => 1], ['id' => $id]);

        Cache::flush('views');

        return $this->success(['id' => $id, 'is_default' => true], __('Default view updated.', 'plugixa-recipe'));
    }

    public function get_default_config($request): WP_REST_Response|WP_Error {
        $config = [
            'layout' => [
                'style'   => 'grid',
                'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
            ],
            'colors' => [
                'cardBg'         => '#ffffff',
                'textColor'      => '#1a1a2e',
                'titleColor'     => '#1a1a2e',
                'metaColor'      => '#6b7280',
                'starColor'      => '#f59e0b',
                'starEmptyColor' => '#d1d5db',
                'borderColor'    => '#e5e7eb',
                'badgeBg'        => '#f3f4f6',
                'badgeText'      => '#374151',
            ],
            'darkMode' => [
                'enabled'      => false,
                'containerBg'  => '#1a1a2e',
                'cardBg'       => '#1e1e2e',
                'textColor'    => '#e5e7eb',
                'titleColor'   => '#f9fafb',
                'metaColor'    => '#9ca3af',
                'starColor'    => '#f59e0b',
                'starEmptyColor' => '#4b5563',
                'borderColor'  => '#374151',
                'badgeBg'      => '#374151',
                'badgeText'    => '#d1d5db',
                'categories'   => [
                    'backgroundColor'       => '#374151',
                    'textColor'             => '#e5e7eb',
                    'activeBackgroundColor' => '#3b82f6',
                    'activeTextColor'       => '#ffffff',
                    'borderColor'           => '#4b5563',
                ],
                'pagination' => [
                    'numbers' => [
                        'bg' => '#1e1e2e', 'text' => '#e5e7eb', 'border' => '#374151',
                        'activeBg' => '#3b82f6', 'activeText' => '#ffffff', 'activeBorder' => '#3b82f6',
                    ],
                    'loadMore' => [
                        'bg' => '#3b82f6', 'text' => '#ffffff', 'border' => '#3b82f6',
                        'hoverBg' => '#2563eb', 'hoverText' => '#ffffff', 'hoverBorder' => '#2563eb',
                    ],
                    'infiniteScroll' => ['spinnerColor' => '#3b82f6', 'textColor' => '#9ca3af'],
                ],
            ],
            'typography' => [
                'titleFontSize'   => 18,
                'titleFontWeight' => 600,
                'titleFontFamily' => 'inherit',
                'textFontSize'    => 14,
                'textFontFamily'  => 'inherit',
                'metaFontSize'    => 13,
                'metaFontFamily'  => 'inherit',
            ],
            'spacing' => [
                'cardPadding'    => 0,
                'contentPadding' => 16,
                'gap'            => 24,
            ],
            'borders' => [
                'radius' => 12,
                'width'  => 1,
            ],
            'shadows' => [
                'enabled' => true,
                'value'   => '0 1px 3px rgba(0,0,0,0.06)',
            ],
            'image' => [
                'aspectRatio'  => '4/3',
                'objectFit'    => 'cover',
                'size'         => 'medium',
                'borderRadius' => 0,
                'hoverEffect'  => 'lift',
            ],
            'categories' => [
                'displayStyle' => 'none',
                'colors' => [
                    'backgroundColor'       => '#ffffff',
                    'textColor'             => '#374151',
                    'activeBackgroundColor' => '#1a1a2e',
                    'activeTextColor'       => '#ffffff',
                    'borderColor'           => '#e5e7eb',
                ],
                'typography' => ['fontSize' => 14, 'fontWeight' => 500, 'fontFamily' => 'inherit'],
                'spacing'    => ['padding' => 12, 'gap' => 8],
                'border'     => ['width' => 1, 'radius' => 100],
            ],
            'pagination' => [
                'style'     => 'numbers',
                'alignment' => 'center',
                'numbers' => [
                    'bg' => '#ffffff', 'text' => '#1a1a2e', 'border' => '#e5e7eb',
                    'activeBg' => '#1a1a2e', 'activeText' => '#ffffff', 'activeBorder' => '#1a1a2e',
                ],
                'loadMore' => [
                    'bg' => '#1a1a2e', 'text' => '#ffffff', 'border' => '#1a1a2e',
                    'hoverBg' => '#374151', 'hoverText' => '#ffffff', 'hoverBorder' => '#374151',
                ],
                'infiniteScroll' => [
                    'spinnerColor' => '#1a1a2e', 'textColor' => '#6b7280',
                ],
            ],
        ];

        return $this->success($config);
    }

    private function extract_layout(array $config): string {
        $layout = $config['layout'] ?? 'grid';
        if (is_array($layout)) {
            return $layout['style'] ?? 'grid';
        }
        return (string) $layout;
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
