<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Integrations\Elementor;

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
        $widget_file = PLUGIXA_RECIPE_PATH . 'includes/integrations/elementor/class-recipe-widget.php';

        if (file_exists($widget_file)) {
            require_once $widget_file;

            if (class_exists(RecipeWidget::class)) {
                $widgets_manager->register(new RecipeWidget());
            }
        }
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
