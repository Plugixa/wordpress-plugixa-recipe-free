<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Multilingual\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Multilingual\Services\TranslationService;

/**
 * Multilingual REST Controller
 *
 * Routes, permission checks and the request/response mapping - nothing else. Which entities
 * are translatable and what linking them means belongs to TranslationService; every query
 * belongs to TranslationRepository.
 */
class MultilingualController extends RestController {

    private TranslationService $translations;

    public function __construct(TranslationService $translations) {
        parent::__construct();
        $this->rest_base    = 'multilingual';
        $this->entity_type = 'translations';
        $this->translations = $translations;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/languages', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_languages'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/status', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z_]+)/(?P<id>\d+)/translations', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_translations'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_translation'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z_]+)/link-translations', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'link_translations'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z_]+)/(?P<id>\d+)/translations/(?P<lang>[a-z_-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'unlink_translation'],
                // Edit, not delete: clears the translation link; no entity row is removed.
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get available languages from WPML/Polylang.
     */
    public function get_languages($request): WP_REST_Response|WP_Error {
        return $this->success($this->translations->languages());
    }

    /**
     * Get multilingual status.
     */
    public function get_status($request): WP_REST_Response|WP_Error {
        return $this->success($this->translations->status());
    }

    /**
     * Get translations for an entity item.
     */
    public function get_translations($request): WP_REST_Response|WP_Error {
        $result = $this->translations->translations($request->get_param('entity'), (int) $request->get_param('id'));

        if (is_wp_error($result)) {
            return $result;
        }

        if (null === $result) {
            return $this->error(__('Item not found.', 'plugixa-recipe'), 404);
        }

        return $this->success($result);
    }

    /**
     * Create a translation copy for a language.
     */
    public function create_translation($request): WP_REST_Response|WP_Error {
        $created = $this->translations->create(
            $request->get_param('entity'),
            (int) $request->get_param('id'),
            (array) $request->get_json_params()
        );

        if (is_wp_error($created)) {
            return $created;
        }

        return $this->success($created, __('Translation created.', 'plugixa-recipe'));
    }

    /**
     * Link two existing items as translations.
     */
    public function link_translations($request): WP_REST_Response|WP_Error {
        $linked = $this->translations->link($request->get_param('entity'), (array) $request->get_json_params());

        if (is_wp_error($linked)) {
            return $linked;
        }

        return $this->success($linked, __('Translations linked.', 'plugixa-recipe'));
    }

    /**
     * Unlink a translation by language.
     */
    public function unlink_translation($request): WP_REST_Response|WP_Error {
        $unlinked = $this->translations->unlink(
            $request->get_param('entity'),
            (int) $request->get_param('id'),
            $request->get_param('lang')
        );

        if (is_wp_error($unlinked)) {
            return $unlinked;
        }

        return $this->success(null, __('Translation unlinked.', 'plugixa-recipe'));
    }
}
