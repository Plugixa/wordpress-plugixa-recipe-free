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

    private const SLUG = 'plugixa-recipe';

    /**
     * The page slugs the admin had while every screen was its own submenu, and the app
     * route each one opened. Notification emails already delivered link to them.
     */
    private const LEGACY_PAGES = [
        'plugixa-recipe-recipes'      => '/recipes',
        'plugixa-recipe-categories'   => '/categories',
        'plugixa-recipe-cuisines'     => '/cuisines',
        'plugixa-recipe-reviews'      => '/reviews',
        'plugixa-recipe-collections'  => '/collections',
        'plugixa-recipe-views'        => '/views',
        'plugixa-recipe-shortcodes'   => '/shortcodes',
        'plugixa-recipe-settings'     => '/settings',
    ];

    /**
     * One entry in the WordPress sidebar: every screen is a route inside the app, which
     * draws its own navigation.
     */
    public function add_admin_menu(): void {
        $this->redirect_legacy_page();

        add_menu_page(
            __('Plugixa Recipe', 'plugixa-recipe'),
            __('Plugixa Recipe', 'plugixa-recipe'),
            // Derived at runtime from edit_posts, plus any add-on grant: Capabilities::has_cap().
            \PlugixaRecipe\Core\Security\Capabilities::MANAGE,
            self::SLUG,
            [$this, 'render_app'],
            'dashicons-food',
            26
        );
    }

    /**
     * Sends an old submenu URL to the same screen inside the app.
     *
     * Runs on `admin_menu` because WordPress refuses a page slug nobody registered as soon
     * as that action returns - before `admin_init`, so a redirect hooked there never runs.
     */
    private function redirect_legacy_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, no state change.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (!isset(self::LEGACY_PAGES[$page])) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '#' . self::LEGACY_PAGES[$page]));
        exit;
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
