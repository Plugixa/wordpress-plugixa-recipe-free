<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Admin\Admin;
use PlugixaRecipe\Api\RecipesController;
use PlugixaRecipe\Api\CategoriesController;
use PlugixaRecipe\Api\CuisinesController;
use PlugixaRecipe\Api\CoursesController;
use PlugixaRecipe\Api\IngredientsController;
use PlugixaRecipe\Api\InstructionsController;
use PlugixaRecipe\Api\NutritionController;
use PlugixaRecipe\Api\ReviewsController;
use PlugixaRecipe\Api\CollectionsController;
use PlugixaRecipe\Api\ShoppingListsController;
use PlugixaRecipe\Api\FavoritesController;
use PlugixaRecipe\Api\UnitsController;
use PlugixaRecipe\Api\SettingsController;
use PlugixaRecipe\Api\ViewsController;
use PlugixaRecipe\Api\ShortcodesController;
use PlugixaRecipe\Api\GoogleFontsController;
use PlugixaRecipe\Api\FrontendApi;
use PlugixaRecipe\Api\MultilingualController;
use PlugixaRecipe\Frontend\Shortcodes;
use PlugixaRecipe\Frontend\Schema;
use PlugixaRecipe\Frontend\Seo;
use PlugixaRecipe\Frontend\RecipePrint;
use PlugixaRecipe\Frontend\RecipeSingle;
use PlugixaRecipe\Frontend\WidgetShortcodes;
use PlugixaRecipe\Frontend\RecipeArchive;
use PlugixaRecipe\Integrations\Elementor\Elementor;
use PlugixaRecipe\Integrations\WooCommerce\WooCommerce;
use PlugixaRecipe\Blocks\RecipeBlock;
use PlugixaRecipe\Multilingual;

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
        // Global helper functions (plugixa_recipe_allowed_html / plugixa_recipe_kses) and
        // the safecss_filter_attr_allow_css hook that keeps --pxr-* custom properties alive
        // through wp_kses(). Loaded first: templates and integrations call these at render
        // time, which is after this point but on any request.
        require_once PLUGIXA_RECIPE_PATH . 'includes/helpers.php';

        // Premium overlay bootstrap. ProBootstrap lives in pro/includes/ and is absent
        // from the free build, so class_exists() is the only "pro" signal there is. It
        // registers premium features into the core's extension seams (settings defaults
        // and sanitizing, admin i18n strings, recipe CRUD, template sections) - never a
        // licence check.
        //
        // It MUST run before Activator::maybe_create_tables(), because that migrates any
        // missing default settings keys into the stored option and therefore has to see
        // the premium defaults filter already registered. With the order reversed, a site
        // upgraded in place from free to premium would never get the premium settings keys.
        if (class_exists(ProBootstrap::class)) {
            ProBootstrap::init();
        }

        Activator::maybe_create_tables();
        Multilingual::instance();

        $this->init_hooks();
    }

    private function init_hooks(): void {
        // No load_plugin_textdomain(): since WP 4.6 translations for WordPress.org-hosted
        // plugins are loaded just-in-time, so calling it is redundant (and Plugin Check
        // flags it). The Domain Path header is likewise dropped.

        if (is_admin()) {
            $admin = new Admin();
            add_action('admin_menu', [$admin, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);
        }

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('plugins_loaded', [$this, 'init_elementor'], 20);
        add_action('plugins_loaded', [$this, 'init_woocommerce'], 20);
        $this->init_frontend();
    }

    public function register_rest_routes(): void {
        // Free controllers (always registered)
        (new RecipesController())->register_routes();
        (new CategoriesController())->register_routes();
        (new CuisinesController())->register_routes();
        (new CoursesController())->register_routes();
        (new IngredientsController())->register_routes();
        (new InstructionsController())->register_routes();
        (new NutritionController())->register_routes();
        (new ReviewsController())->register_routes();
        (new CollectionsController())->register_routes();
        (new ShoppingListsController())->register_routes();
        (new FavoritesController())->register_routes();
        (new UnitsController())->register_routes();
        (new SettingsController())->register_routes();
        (new ViewsController())->register_routes();
        (new ShortcodesController())->register_routes();
        (new GoogleFontsController())->register_routes();
        (new FrontendApi())->register_routes();
        (new MultilingualController())->register_routes();

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
                (new $controller())->register_routes();
            }
        }
    }

    private function init_frontend(): void {
        $shortcodes = new Shortcodes();
        add_action('init', [$shortcodes, 'register']);
        add_action('init', [$this, 'register_blocks']);

        // Widget shortcodes (categories, latest, related, search, etc.)
        $widget_shortcodes = new WidgetShortcodes();
        add_action('init', [$widget_shortcodes, 'register']);

        // Category/Cuisine archive pages
        $archive = new RecipeArchive();
        $archive->init();

        // Recipe JSON-LD. Schema lives in pro/includes/frontend/ and is absent from the
        // free build, which emits schema.org Recipe microdata from the templates instead.
        if (class_exists(Schema::class)) {
            $schema = new Schema();
            $schema->init();
        }

        $seo = new Seo();
        $seo->init();

        $print = new RecipePrint();
        $print->init();

        $single = new RecipeSingle();
        $single->init();

        // Image quality filter from settings.
        add_filter('wp_editor_set_quality', function ($quality) {
            $settings = get_option('plugixa_recipe_settings', []);
            if (!empty($settings['image_optimization'])) {
                return (int) ($settings['thumbnail_quality'] ?? 82);
            }
            return $quality;
        });
    }

    public function init_elementor(): void {
        if (did_action('elementor/loaded')) {
            Elementor::instance();
        }
    }

    public function init_woocommerce(): void {
        // WooCommerce::class here is OUR integration class, which lives in pro/includes/
        // and is absent from the free build; 'WooCommerce' is the WooCommerce plugin.
        if (class_exists('WooCommerce') && class_exists(WooCommerce::class)) {
            WooCommerce::instance();
        }
    }

    public function register_blocks(): void {
        $block = new RecipeBlock();
        $block->register();
    }
}
