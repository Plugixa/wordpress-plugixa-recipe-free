<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\AppManager\Http;

use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Core\Modules\ModuleRegistry;
use PlugixaRecipe\Modules\AppManager\Services\AppCatalogue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * GET/POST /apps - list the modules and switch them on or off.
 *
 * Settings-level on both verbs: switching a module off removes screens and REST routes for
 * every user, which is a site-configuration decision, not an editorial one.
 */
class AppController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'apps';
        $this->entity_type = 'settings';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_apps'],
                'permission_callback' => [$this, 'check_settings_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_apps'],
                'permission_callback' => [$this, 'check_settings_permission'],
                'args'                => [
                    'apps' => [
                        'description' => __('Module id => on/off.', 'plugixa-recipe'),
                        'type'        => 'object',
                        'required'    => true,
                        'additionalProperties' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ]);
    }

    public function get_apps($request): WP_REST_Response|WP_Error {
        return $this->success(AppCatalogue::describe(true));
    }

    /**
     * Save a patch of switches. Every id is checked before anything is written, so a
     * request naming one core module changes nothing at all.
     */
    public function update_apps($request): WP_REST_Response|WP_Error {
        $registry    = ModuleRegistry::instance();
        $descriptors = $registry->descriptors();
        $patch       = [];

        foreach ((array) $request->get_param('apps') as $id => $on) {
            $id = sanitize_key((string) $id);

            if (!isset($descriptors[$id])) {
                /* translators: %s: module id */
                return $this->error(sprintf(__('Unknown module "%s".', 'plugixa-recipe'), $id), 400);
            }
            if ($descriptors[$id]['core']) {
                /* translators: %s: module id */
                return $this->error(sprintf(__('"%s" is a core module and cannot be switched off.', 'plugixa-recipe'), $id), 400);
            }

            $blocker = $registry->module($id)?->blocker();
            if ($on && null !== $blocker) {
                return $this->error($blocker, 409);
            }

            $patch[$id] = (bool) $on;
        }

        $registry->state()->save($patch);
        $registry->refresh();

        // A module's routes and hooks come and go with it; anything cached from the old set
        // (the admin's module list, the public listing's feature flags) is stale now.
        \PlugixaRecipe\Core\Database\Cache::flush('recipes');

        return $this->success(AppCatalogue::describe(true), __('Apps updated.', 'plugixa-recipe'));
    }
}
