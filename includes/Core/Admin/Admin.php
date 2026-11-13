<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Class - Handles admin menu and asset loading
 */
class Admin {

    public function add_admin_menu(): void {
        $icon = 'dashicons-food';

        add_menu_page(
            __('Plugixa Recipe', 'plugixa-recipe'),
            __('Recipes', 'plugixa-recipe'),
            'edit_posts',
            'plugixa-recipe',
            [$this, 'render_app'],
            $icon,
            26
        );

        $submenus = [
            'plugixa-recipe'              => __('Dashboard', 'plugixa-recipe'),
            'plugixa-recipe-recipes'      => __('Recipes', 'plugixa-recipe'),
            'plugixa-recipe-categories'   => __('Categories', 'plugixa-recipe'),
            'plugixa-recipe-cuisines'     => __('Cuisines', 'plugixa-recipe'),
            'plugixa-recipe-reviews'      => __('Reviews', 'plugixa-recipe'),
            'plugixa-recipe-collections'  => __('Collections', 'plugixa-recipe'),
            'plugixa-recipe-views'        => __('Views', 'plugixa-recipe'),
            'plugixa-recipe-shortcodes'   => __('Shortcodes', 'plugixa-recipe'),
            'plugixa-recipe-settings'     => __('Settings', 'plugixa-recipe'),
        ];

        foreach ($submenus as $slug => $title) {
            add_submenu_page(
                'plugixa-recipe',
                $title,
                $title,
                'edit_posts',
                $slug,
                [$this, 'render_app']
            );
        }
    }

    public function render_app(): void {
        echo '<div id="plugixa-recipe-root"></div>';
    }

    public function enqueue_assets(string $hook): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, no state change.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'plugixa-recipe') !== 0) {
            return;
        }

        $assets = new Assets(
            PLUGIXA_RECIPE_PATH,
            PLUGIXA_RECIPE_URL,
            PLUGIXA_RECIPE_VERSION
        );
        $assets->enqueue_app_assets();
    }
}
