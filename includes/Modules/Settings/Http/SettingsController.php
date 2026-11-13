<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Settings\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;
use PlugixaRecipe\Modules\Settings\Services\SettingsService;

/**
 * Settings REST Controller.
 *
 * Routes, permission checks and the request/response mapping - nothing else. What may be
 * stored as a setting, and how the dashboard figures are shaped, belongs to SettingsService;
 * every dashboard query belongs to DashboardStatsRepository.
 */
class SettingsController extends RestController {

    private SettingsService $settings;

    public function __construct(SettingsService $settings) {
        parent::__construct();
        $this->rest_base = 'settings';
        $this->settings  = $settings;
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_settings_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_settings_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/dashboard', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_dashboard'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);
    }

    /**
     * Get plugin settings.
     */
    public function get_settings($request): WP_REST_Response|WP_Error {
        return $this->success($this->settings->get());
    }

    /**
     * Update plugin settings.
     */
    public function update_settings($request): WP_REST_Response|WP_Error {
        $merged = $this->settings->update((array) $request->get_json_params());

        return $this->success($merged, __('Settings updated.', 'plugixa-recipe'));
    }

    /**
     * Get dashboard statistics.
     */
    public function get_dashboard($request): WP_REST_Response|WP_Error {
        return $this->success($this->settings->dashboard());
    }

}
