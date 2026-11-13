<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Base REST Controller
 */
abstract class RestController extends WP_REST_Controller {

    public function __construct() {
        $this->namespace = 'plugixa-recipe/v1';
    }

    /**
     * Whether the current user may perform an operation.
     *
     * Delegates to plugixa_recipe_user_can(), the single source of truth shared with
     * the admin bootstrap so the UI cannot advertise a permission the API refuses.
     * See includes/Core/helpers.php for the fallback contract.
     *
     * @param string $plugin_cap   Plugin capability, e.g. 'plugixa_recipe_delete'.
     * @param string $fallback_cap WordPress capability historically accepted.
     */
    protected function can(string $plugin_cap, string $fallback_cap): bool {
        return plugixa_recipe_user_can($plugin_cap, $fallback_cap);
    }

    /**
     * Build the standard 403 for a denied request.
     */
    protected function forbidden(string $message): WP_Error {
        return new WP_Error('rest_forbidden', $message, ['status' => 403]);
    }

    /**
     * Read recipe content (list and single-item GET routes).
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_read_permission($request) {
        return $this->can('plugixa_recipe_manage', 'edit_posts')
            ? true
            : $this->forbidden(__('You do not have permission to view this content.', 'plugixa-recipe'));
    }

    /**
     * Create or update recipe content (POST/PUT routes).
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_edit_permission($request) {
        return $this->can('plugixa_recipe_edit', 'edit_posts')
            ? true
            : $this->forbidden(__('You do not have permission to edit this content.', 'plugixa-recipe'));
    }

    /**
     * Delete recipe content (DELETE and bulk-delete routes).
     *
     * Applies to routes that remove an ENTITY row -- a recipe, category, cuisine, course,
     * review, collection, saved view, saved shortcode, shopping list or nutrition record.
     * It deliberately does NOT apply to routes that rewrite a recipe's own child rows
     * (ingredients, instructions), remove a pivot row (a recipe's membership in a
     * collection), unlink a translation, or retract the caller's own vote: those are
     * ordinary edits, and raising them here would stop edit-level users saving at all.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    /**
     * The fallback is `delete_published_posts`, NOT `edit_posts`.
     *
     * All three content checks used to fall back to edit_posts, so delete inherited edit's
     * bar - and Contributors have edit_posts. A Contributor could therefore delete every
     * recipe, category, cuisine, review, collection, View and Shortcode through the API,
     * verified by calling this method as a real Contributor.
     *
     * `delete_posts` is NOT the fix and was tried first: in WordPress it means "delete my
     * own drafts", and every role from Contributor up holds it, so swapping to it changed
     * nothing. `delete_published_posts` is the capability that means "delete content that
     * is live on this site", which is what a recipe is - it is held by Administrator,
     * Editor and Author, and not by Contributor.
     *
     * This is not "dropping the fallback" (a major-version change, since the fallback
     * grants access the plugin caps alone would not) - it points the fallback at the
     * capability that matches the operation. Anyone explicitly granted
     * plugixa_recipe_delete still passes regardless of role.
     */
    public function check_delete_permission($request) {
        return $this->can('plugixa_recipe_delete', 'delete_published_posts')
            ? true
            : $this->forbidden(__('You do not have permission to delete this content.', 'plugixa-recipe'));
    }

    /**
     * Decide what is live: publish a recipe, take one down, moderate a review.
     *
     * `plugixa_recipe_publish` has been declared and reported to the admin app since
     * 1.0.0, and until 1.0.1 no route asked for it. The edit check was the only bar, so a
     * Contributor - who holds edit_posts and not publish_posts - could publish a recipe and
     * approve a review through the API, which is the one thing that role exists to prevent.
     *
     * The fallback is `publish_posts`, the core capability with the same meaning, and the
     * one Admin\Assets::get_user_permissions() already names: Administrator, Editor and
     * Author hold it, Contributor does not.
     *
     * No route names this check on its own. It is asked IN ADDITION to the edit check, by a
     * callback of the controller that owns the route, so that adding it can only ever narrow
     * who gets in. A route that also saves ordinary edits (a recipe save carries its status)
     * escalates only when its service says the payload changes what is live, or edit-level
     * users could no longer save a draft: RecipesController::check_save_permission() is the
     * reference, ReviewsController::check_moderate_permission() the unconditional form.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_publish_permission($request) {
        return $this->can('plugixa_recipe_publish', 'publish_posts')
            ? true
            : $this->forbidden(__('You do not have permission to publish or moderate this content.', 'plugixa-recipe'));
    }

    /**
     * Change plugin settings, import data, or run destructive maintenance.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_settings_permission($request) {
        return $this->can('plugixa_recipe_settings', 'manage_options')
            ? true
            : $this->forbidden(__('Administrator access required.', 'plugixa-recipe'));
    }

    /**
     * Back-compat alias for the former blanket read/write check.
     *
     * @deprecated Use the operation-specific checks above.
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_permission($request) {
        return $this->check_read_permission($request);
    }

    /**
     * Back-compat alias for the former blanket administrator check.
     *
     * @deprecated Use check_settings_permission().
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_admin_permission($request) {
        return $this->check_settings_permission($request);
    }

    protected function success($data, $message = '') {
        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    protected function error($message, $status = 400, $errors = []) {
        return Failure::make((string) $message, (int) $status, (array) $errors);
    }

    protected function get_pagination($request) {
        $page = (int) ($request->get_param('page') ?? 1);
        $per_page = (int) ($request->get_param('per_page') ?? $request->get_param('limit') ?? 20);
        $limit = $per_page <= 0 ? 999999 : $per_page;
        $offset = (int) ($request->get_param('offset') ?? (($page - 1) * $limit));
        return [
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    protected function get_filters($request) {
        $filters = $request->get_param('filter');

        if (!is_array($filters)) {
            $filters = [];
        }

        if (empty($filters['search']) && $request->get_param('search')) {
            $filters['search'] = $request->get_param('search');
        }
        if (empty($filters['status']) && $request->get_param('status')) {
            $filters['status'] = $request->get_param('status');
        }
        if (empty($filters['category_id']) && $request->get_param('category_id')) {
            $filters['category_id'] = $request->get_param('category_id');
        }

        return $filters;
    }

    protected function get_sort($request) {
        return [
            'orderby' => $request->get_param('sort_by') ?? $request->get_param('orderby') ?? 'created_at',
            'order'   => strtoupper($request->get_param('sort_order') ?? $request->get_param('order') ?? 'DESC'),
        ];
    }

    public function get_collection_params() {
        return [
            'limit' => [
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => -1,
                'sanitize_callback' => function($value) {
                    return (int) $value;
                },
            ],
            'offset' => [
                'type'              => 'integer',
                'default'           => 0,
                'minimum'           => 0,
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'type'    => 'string',
                'default' => 'created_at',
            ],
            'order' => [
                'type'    => 'string',
                'default' => 'DESC',
                'enum'    => ['ASC', 'DESC'],
            ],
            'filter' => [
                'type'    => 'object',
                'default' => [],
            ],
        ];
    }
}
