<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Reviews\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Reviews\Services\ReviewService;

/**
 * Reviews REST Controller
 *
 * Routes, permission checks and the request/response mapping - nothing else. What may be
 * done to a review belongs to ReviewService; every query belongs to ReviewRepository.
 *
 * There is deliberately no create route: a review is only ever written by a visitor, through
 * the public controller.
 */
class ReviewsController extends RestController {

    private ReviewService $reviews;

    public function __construct(ReviewService $reviews) {
        parent::__construct();
        $this->rest_base = 'reviews';
        $this->entity_type = 'reviews';
        $this->reviews   = $reviews;
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
                'permission_callback' => [$this, 'check_update_permission'],
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
                'permission_callback' => [$this, 'check_moderate_permission'],
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
                'permission_callback' => [$this, 'check_moderate_permission'],
            ],
        ]);
    }

    /**
     * Get reviews list with filters, includes recipe title via JOIN.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->success($this->reviews->list(
            $this->get_filters($request),
            $this->get_pagination($request),
            $this->get_sort($request)
        ));
    }

    /**
     * Get single review.
     */
    public function get_item($request): WP_REST_Response|WP_Error {
        $item = $this->reviews->get((int) $request->get_param('id'));

        if (null === $item) {
            return $this->error(__('Review not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($item);
    }

    /**
     * Moderate reviews: the edit capability AND the publish capability.
     *
     * The status route and the bulk action do nothing but move reviews between pending,
     * approved, rejected and spam, and an approved review is printed on the recipe and
     * counted into its rating. Until 1.0.1 both sat behind the edit check alone, so a
     * Contributor could approve any review.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_moderate_permission($request) {
        $edit = $this->check_edit_permission($request);

        return true === $edit ? $this->check_publish_permission($request) : $edit;
    }

    /**
     * Edit a review: the edit capability, and moderation rights too when the payload moves
     * the review to another status. Saving it with its status unchanged - which the review
     * form does - stays an edit.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_update_permission($request) {
        // Same expressions as update_item(), so the check judges the review it will write.
        $moderates = $this->reviews->changes_status((int) $request->get_param('id'), (array) $request->get_json_params());

        return $moderates ? $this->check_moderate_permission($request) : $this->check_edit_permission($request);
    }

    /**
     * Update a review.
     */
    public function update_item($request): WP_REST_Response|WP_Error {
        $updated = $this->reviews->update((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->get_item($request);
    }

    /**
     * Delete a review.
     */
    public function delete_item($request): WP_REST_Response|WP_Error {
        $deleted = $this->reviews->delete((int) $request->get_param('id'));

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(null, __('Review deleted.', 'plugixa-recipe'));
    }

    /**
     * Update review status.
     */
    public function update_status($request): WP_REST_Response|WP_Error {
        $updated = $this->reviews->set_status((int) $request->get_param('id'), (array) $request->get_json_params());

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $this->success($updated, __('Review status updated.', 'plugixa-recipe'));
    }

    /**
     * Bulk delete reviews.
     */
    public function bulk_delete($request): WP_REST_Response|WP_Error {
        $params  = (array) $request->get_json_params();
        $deleted = $this->reviews->bulk_delete($params['ids'] ?? []);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return $this->success(['deleted' => $deleted], __('Reviews deleted.', 'plugixa-recipe'));
    }

    /**
     * Bulk action on reviews (approve, reject, spam).
     */
    public function bulk_action($request): WP_REST_Response|WP_Error {
        $params = (array) $request->get_json_params();
        $action = $this->reviews->bulk_action($params['ids'] ?? [], $params['action'] ?? '');

        if (is_wp_error($action)) {
            return $action;
        }

        /* translators: %s: the bulk action that was applied (e.g. approve, reject, spam). */
        return $this->success(null, sprintf(__('Bulk %s completed.', 'plugixa-recipe'), $action));
    }
}
