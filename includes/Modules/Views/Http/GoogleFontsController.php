<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Views\Http;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PlugixaRecipe\Core\Http\RestController;

/**
 * Google Fonts REST Controller
 */
class GoogleFontsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'google-fonts';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_fonts'],
                'permission_callback' => [$this, 'check_settings_permission'],
            ],
        ]);
    }

    /**
     * Return the curated list of self-hosted fonts bundled with the plugin.
     *
     * The plugin ships these font files locally (assets/frontend/fonts/) and does
     * NOT call any external font service, so the list is static and matches the
     * bundled @font-face declarations exactly.
     */
    public function get_fonts($request): WP_REST_Response|WP_Error {
        return $this->success($this->get_bundled_fonts());
    }

    /**
     * The curated families bundled under assets/frontend/fonts/ (latin subset).
     * Keep this in sync with assets/frontend/fonts/fonts.css.
     */
    private function get_bundled_fonts(): array {
        return [
            ['family' => 'Inter', 'category' => 'sans-serif', 'variants' => ['300', '400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Roboto', 'category' => 'sans-serif', 'variants' => ['300', '400', '500', '700'], 'subsets' => ['latin']],
            ['family' => 'Open Sans', 'category' => 'sans-serif', 'variants' => ['300', '400', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Lato', 'category' => 'sans-serif', 'variants' => ['300', '400', '700'], 'subsets' => ['latin']],
            ['family' => 'Montserrat', 'category' => 'sans-serif', 'variants' => ['300', '400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Poppins', 'category' => 'sans-serif', 'variants' => ['300', '400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Nunito', 'category' => 'sans-serif', 'variants' => ['300', '400', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Raleway', 'category' => 'sans-serif', 'variants' => ['300', '400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Source Sans 3', 'category' => 'sans-serif', 'variants' => ['300', '400', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Oswald', 'category' => 'sans-serif', 'variants' => ['300', '400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Playfair Display', 'category' => 'serif', 'variants' => ['400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'Merriweather', 'category' => 'serif', 'variants' => ['300', '400', '700'], 'subsets' => ['latin']],
            ['family' => 'Lora', 'category' => 'serif', 'variants' => ['400', '500', '600', '700'], 'subsets' => ['latin']],
            ['family' => 'PT Serif', 'category' => 'serif', 'variants' => ['400', '700'], 'subsets' => ['latin']],
            ['family' => 'Fira Code', 'category' => 'monospace', 'variants' => ['300', '400', '500', '600', '700'], 'subsets' => ['latin']],
        ];
    }
}
