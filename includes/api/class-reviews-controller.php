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
 * Reviews REST Controller
 */
class ReviewsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'reviews';
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update_status'],
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-action', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk_action'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get reviews list with filters, includes recipe title via JOIN.
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
        $status     = !empty($filters['status']) ? (string) $filters['status'] : '';
        $recipe_id  = !empty($filters['recipe_id']) ? (int) $filters['recipe_id'] : 0;
        $rating     = !empty($filters['rating']) ? (int) $filters['rating'] : 0;
        $min_rating = !empty($filters['min_rating']) ? (int) $filters['min_rating'] : 0;

        $allowed_orderby = ['created_at', 'rating', 'author_name', 'helpful_count'];
        $orderby = in_array($sort['orderby'], $allowed_orderby) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Bound values for the shared WHERE block, in placeholder order. The count query
        // needs only the reviews table; the list query joins recipes too, so it gets its own
        // leading pair of identifiers.
        $filter_gates = [
            $search !== '' ? 1 : 0, $search, $search, $search,
            $status !== '' ? 1 : 0, $status,
            $recipe_id ? 1 : 0, $recipe_id,
            $rating ? 1 : 0, $rating,
            $min_rating ? 1 : 0, $min_rating,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text.
        $total = (int) Cache::remember('reviews', 'count|' . md5(wp_json_encode($filter_gates)), function () use ($wpdb, $prefix, $filter_gates) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i rv
                  WHERE ( %d = 0 OR (rv.author_name LIKE %s OR rv.review_text LIKE %s OR rv.author_email LIKE %s) )
                    AND ( %d = 0 OR rv.status = %s )
                    AND ( %d = 0 OR rv.recipe_id = %d )
                    AND ( %d = 0 OR rv.rating = %d )
                    AND ( %d = 0 OR rv.rating >= %d )',
                ...array_merge([$prefix . 'reviews'], $filter_gates)
            ));
        });

        // Main query with recipe title. The ORDER BY column binds with %i from the
        // $allowed_orderby list above; a direction keyword cannot be bound, so ASC/DESC is
        // two literal queries. The id tiebreaker keeps paging stable on ties.
        $args = array_merge(
            [$prefix . 'reviews', $prefix . 'recipes'],
            $filter_gates,
            [$orderby, $pagination['limit'], $pagination['offset']]
        );

        $items = Cache::remember('reviews', 'list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT rv.*, r.title AS recipe_title
                    FROM %i rv
                    LEFT JOIN %i r ON rv.recipe_id = r.id
                    WHERE ( %d = 0 OR (rv.author_name LIKE %s OR rv.review_text LIKE %s OR rv.author_email LIKE %s) )
                      AND ( %d = 0 OR rv.status = %s )
                      AND ( %d = 0 OR rv.recipe_id = %d )
                      AND ( %d = 0 OR rv.rating = %d )
                      AND ( %d = 0 OR rv.rating >= %d )
                    ORDER BY rv.%i ASC, rv.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT rv.*, r.title AS recipe_title
                FROM %i rv
                LEFT JOIN %i r ON rv.recipe_id = r.id
                WHERE ( %d = 0 OR (rv.author_name LIKE %s OR rv.review_text LIKE %s OR rv.author_email LIKE %s) )
                  AND ( %d = 0 OR rv.status = %s )
                  AND ( %d = 0 OR rv.recipe_id = %d )
                  AND ( %d = 0 OR rv.rating = %d )
                  AND ( %d = 0 OR rv.rating >= %d )
                ORDER BY rv.%i DESC, rv.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });

        foreach ($items as &$item) {
            $item['rating'] = (int) $item['rating'];
            $item['helpful_count'] = (int) $item['helpful_count'];
            $item['is_verified'] = (bool) $item['is_verified'];
            if (!empty($item['images'])) {
                $item['images'] = json_decode($item['images'], true);
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
     * Get single review.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        // Read-through object cache; key = review id.
        $item = Cache::remember('reviews', 'item|' . $id, function () use ($wpdb, $prefix, $id) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT rv.*, r.title AS recipe_title
                    FROM %i rv
                    LEFT JOIN %i r ON rv.recipe_id = r.id
                    WHERE rv.id = %d',
                    $prefix . 'reviews',
                    $prefix . 'recipes',
                    $id
                ),
                ARRAY_A
            );
        });

        if (!$item) {
            return $this->error(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $item['rating'] = (int) $item['rating'];
        $item['helpful_count'] = (int) $item['helpful_count'];
        $item['is_verified'] = (bool) $item['is_verified'];
        if (!empty($item['images'])) {
            $item['images'] = json_decode($item['images'], true);
        }

        return $this->success($item);
    }

    /**
     * Update a review.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'reviews', $id), ARRAY_A);
        if (!$existing) {
            return $this->error(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['author_name'])) {
            $data['author_name'] = sanitize_text_field($params['author_name']);
        }
        if (isset($params['author_email'])) {
            $data['author_email'] = sanitize_email($params['author_email']);
        }
        if (isset($params['rating'])) {
            $data['rating'] = max(1, min(5, (int) $params['rating']));
        }
        if (isset($params['review_text'])) {
            $data['review_text'] = wp_kses_post($params['review_text']);
        }
        if (isset($params['status'])) {
            $data['status'] = sanitize_text_field($params['status']);
        }
        if (isset($params['is_verified'])) {
            $data['is_verified'] = (int) $params['is_verified'];
        }
        if (isset($params['images'])) {
            $data['images'] = wp_json_encode($params['images']);
        }
        if (isset($params['admin_reply'])) {
            $reply = wp_kses_post( trim( $params['admin_reply'] ) );
            $data['admin_reply'] = $reply ?: null;
            $data['admin_reply_at'] = $reply ? current_time('mysql') : null;
        }

        $data['modified_at'] = current_time('mysql');

        $wpdb->update($prefix . 'reviews', $data, ['id' => $id]);

        // Recalculate recipe rating
        $this->recalculate_recipe_rating((int) $existing['recipe_id']);

        // Content write: a review also changes the recipe's rating aggregate shown in lists.
        Cache::flush('reviews', 'recipes');

        return $this->get_item($request);
    }

    /**
     * Delete a review.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');

        $review = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'reviews', $id), ARRAY_A);
        if (!$review) {
            return $this->error(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->delete($prefix . 'reviews', ['id' => $id]);

        // Recalculate recipe rating
        $this->recalculate_recipe_rating((int) $review['recipe_id']);

        // Content write: a review also changes the recipe's rating aggregate shown in lists.
        Cache::flush('reviews', 'recipes');

        return $this->success(null, __('Review deleted.', 'plugixa-recipe'));
    }

    /**
     * Update review status.
     */
    public function update_status($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $status = sanitize_text_field($params['status'] ?? '');

        $allowed = ['pending', 'approved', 'rejected', 'spam'];
        if (!in_array($status, $allowed)) {
            return $this->error(__('Invalid status.', 'plugixa-recipe'));
        }

        $review = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $prefix . 'reviews', $id), ARRAY_A);
        if (!$review) {
            return $this->error(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $wpdb->update($prefix . 'reviews', [
            'status'      => $status,
            'modified_at' => current_time('mysql'),
        ], ['id' => $id]);

        // Recalculate recipe rating
        $this->recalculate_recipe_rating((int) $review['recipe_id']);

        // Content write: approve/reject/spam changes the recipe's rating aggregate shown in lists.
        Cache::flush('reviews', 'recipes');

        return $this->success(['id' => $id, 'status' => $status], __('Review status updated.', 'plugixa-recipe'));
    }

    /**
     * Bulk delete reviews.
     */
    public function bulk_delete($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids = array_map('intval', $params['ids'] ?? []);

        if (empty($ids)) {
            return $this->error(__('No IDs provided.', 'plugixa-recipe'));
        }

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so each statement stays a constant literal. $ids is already intval-mapped above.
        $id_list = implode(',', $ids);

        // Get affected recipe IDs for recalculation
        $recipe_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT recipe_id FROM %i WHERE FIND_IN_SET(id, %s)',
            $prefix . 'reviews',
            $id_list
        ));

        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s)',
            $prefix . 'reviews',
            $id_list
        ));

        // Recalculate ratings for affected recipes
        foreach ($recipe_ids as $recipe_id) {
            $this->recalculate_recipe_rating((int) $recipe_id);
        }

        // Content write: reviews also change the recipes' rating aggregates shown in lists.
        Cache::flush('reviews', 'recipes');

        return $this->success(['deleted' => $deleted], __('Reviews deleted.', 'plugixa-recipe'));
    }

    /**
     * Bulk action on reviews (approve, reject, spam).
     */
    public function bulk_action($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();
        $ids    = array_map('intval', $params['ids'] ?? []);
        $action = sanitize_text_field($params['action'] ?? '');

        if (empty($ids) || empty($action)) {
            return $this->error(__('IDs and action are required.', 'plugixa-recipe'));
        }

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so each statement stays a constant literal. $ids is already intval-mapped above.
        $id_list = implode(',', $ids);
        $now = current_time('mysql');

        $status_map = [
            'approve' => 'approved',
            'reject'  => 'rejected',
            'spam'    => 'spam',
            'pending' => 'pending',
        ];

        if (!isset($status_map[$action])) {
            return $this->error(__('Invalid action.', 'plugixa-recipe'));
        }

        // Get affected recipe IDs
        $recipe_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT recipe_id FROM %i WHERE FIND_IN_SET(id, %s)',
            $prefix . 'reviews',
            $id_list
        ));

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET status = %s, modified_at = %s WHERE FIND_IN_SET(id, %s)',
            $prefix . 'reviews',
            $status_map[$action],
            $now,
            $id_list
        ));

        // Recalculate ratings for affected recipes
        foreach ($recipe_ids as $recipe_id) {
            $this->recalculate_recipe_rating((int) $recipe_id);
        }

        // Content write: bulk status changes also change the recipes' rating aggregates.
        Cache::flush('reviews', 'recipes');

        /* translators: %s: the bulk action that was applied (e.g. approve, reject, spam). */
        return $this->success(null, sprintf(__('Bulk %s completed.', 'plugixa-recipe'), $action));
    }

    /**
     * Recalculate the average rating and count for a recipe based on approved reviews.
     */
    private function recalculate_recipe_rating(int $recipe_id): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $stats = $wpdb->get_row($wpdb->prepare(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS count
            FROM %i
            WHERE recipe_id = %d AND status = \'approved\'',
            $prefix . 'reviews',
            $recipe_id
        ));

        $wpdb->update($prefix . 'recipes', [
            'rating_average' => $stats->avg_rating ? round((float) $stats->avg_rating, 2) : 0,
            'rating_count'   => (int) $stats->count,
        ], ['id' => $recipe_id]);
    }
}
