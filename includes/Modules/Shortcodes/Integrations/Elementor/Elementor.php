<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Shortcodes\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Integration - Singleton loader.
 *
 * Registers the PlugixaRecipe widget category and loads the recipe widget
 * when Elementor is active.
 */
class Elementor {

    private static ?Elementor $instance = null;

    public static function instance(): Elementor {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize Elementor integration hooks.
     */
    private function init_hooks(): void {
        add_action('elementor/widgets/register', [$this, 'register_widgets']);
        add_action('elementor/elements/categories_registered', [$this, 'register_category']);
        add_action('elementor/editor/before_enqueue_scripts', [$this, 'enqueue_editor_assets']);
        add_action('elementor/preview/enqueue_styles', [$this, 'enqueue_preview_assets']);
    }

    /**
     * Register the PlugixaRecipe widget category.
     *
     * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
     */
    public function register_category($elements_manager): void {
        $elements_manager->add_category('plugixa-recipe', [
            'title' => __('Plugixa Recipe', 'plugixa-recipe'),
            'icon'  => 'eicon-food-beverage',
        ]);
    }

    /**
     * Register Elementor widgets.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
     */
    public function register_widgets($widgets_manager): void {
        // RecipeWidget extends an Elementor class, so it is only ever loaded from here - on
        // Elementor's own hook, when that parent is guaranteed to exist.
        $widgets_manager->register(new RecipeWidget());
        $widgets_manager->register(new SingleRecipeWidget());
    }

    /**
     * Load the listing's stylesheet and scripts into Elementor's preview frame.
     *
     * Everywhere else they are enqueued while the shortcode renders, which is early enough
     * on a page. In the editor it never happens: Elementor draws a widget through an AJAX
     * request and injects the markup into a frame that has already printed its head, so the
     * recipes appeared with no CSS at all - a full-width photo and a star icon the size of
     * the column. 'slider' is asked for because it is the superset (it adds Swiper), and the
     * person editing can switch the widget to any layout without reloading the frame.
     */
    public function enqueue_preview_assets(): void {
        \PlugixaRecipe\Core\Frontend\Assets::listing('slider');
    }

    /**
     * Enqueue editor-specific assets for the Elementor editor.
     */
    public function enqueue_editor_assets(): void {
        $editor_css = PLUGIXA_RECIPE_PATH . 'assets/integrations/elementor/editor.css';

        if (file_exists($editor_css)) {
            wp_enqueue_style(
                'plugixa-recipe-elementor-editor',
                PLUGIXA_RECIPE_URL . 'assets/integrations/elementor/editor.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }
    }
}
