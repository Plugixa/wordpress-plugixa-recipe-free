<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Admin\Admin;
use PlugixaRecipe\Core\Modules\ModuleRegistry;
use PlugixaRecipe\Core\Security\Capabilities;

/**
 * Main Plugin Class - Singleton pattern
 */
class Plugin {

    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Every feature is a module (includes/Modules/*, pro/includes/Modules/*), discovered
        // from disk - premium ones only when pro/ is on disk, so presence is the only
        // "premium" signal there is, never a licence check.
        //
        // registerServices() runs for ALL modules, enabled or not, and it is where a module
        // adds its settings defaults. It MUST run before Activator::maintain(), which
        // merges missing default keys into the stored option: with the order reversed, a
        // site upgraded in place from free to premium would never get the premium settings
        // keys. maintain() is also what migrates the modules' tables when that is due.
        $modules = ModuleRegistry::instance();
        $modules->registerServices();

        Activator::maintain();

        // Frontend output, actions and filters of ENABLED modules only. Everything a visitor
        // sees is registered here by the module that owns it - the recipe pages by Recipes,
        // the shortcodes, widgets, block and Elementor widget by Shortcodes. Core wires none
        // of it, and so names no module.
        $modules->registerHooks();

        $this->init_hooks();
    }

    private function init_hooks(): void {
        // No load_plugin_textdomain(): since WP 4.6 translations for WordPress.org-hosted
        // plugins are loaded just-in-time, so calling it is redundant (and Plugin Check
        // flags it). The Domain Path header is likewise dropped.

        // Derives plugixa_recipe_manage ("may open the app") at runtime; see Capabilities.
        Capabilities::register_hooks();

        if (is_admin()) {
            $admin = new Admin();
            add_action('admin_menu', [$admin, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);
        }

        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Image quality filter from settings.
        add_filter('wp_editor_set_quality', function ($quality) {
            $settings = get_option('plugixa_recipe_settings', []);
            if (!empty($settings['image_optimization'])) {
                return (int) ($settings['thumbnail_quality'] ?? 82);
            }
            return $quality;
        });
    }

    public function register_rest_routes(): void {
        // Each ENABLED module registers its own controllers. A switched-off module's routes
        // do not exist, so a request for one is WordPress's own 404.
        ModuleRegistry::instance()->registerRoutes();

        /**
         * Controllers contributed by an overlay.
         *
         * The free core names none of them. An overlay supplies its own class names
         * here, the same way it supplies settings defaults through
         * `plugixa_recipe_settings_defaults` - and the same way the admin app's
         * proRegistry keeps its keys opaque to the free bundle. An overlay that needs a
         * further condition (a third-party plugin being active, say) applies it before
         * contributing the class name.
         *
         * There is deliberately no licence check: code that is not shipped cannot be
         * "locked", which is what keeps the free build clear of the trialware guideline.
         * class_exists() stays as a guard so a stale entry can never fatal the REST API.
         *
         * @param string[] $controllers Fully-qualified controller class names.
         */
        $controllers = (array) apply_filters('plugixa_recipe_rest_controllers', []);

        foreach ($controllers as $controller) {
            if (is_string($controller) && class_exists($controller)) {
                ModuleRegistry::instance()->container()->get($controller)->register_routes();
            }
        }
    }

}
