<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Shortcodes\Blocks\RecipeBlock;
use PlugixaRecipe\Modules\Shortcodes\Blocks\SingleRecipeBlock;
use PlugixaRecipe\Modules\Shortcodes\Frontend\ListingShortcode;
use PlugixaRecipe\Modules\Shortcodes\Frontend\RetiredShortcodes;
use PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\RecipeCountWidget;
use PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\RecipeListWidget;
use PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\RecipeOfDayWidget;
use PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\RecipeSearchWidget;
use PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\RelatedRecipesWidget;
use PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\TaxonomyWidget;
use PlugixaRecipe\Modules\Shortcodes\Http\ShortcodesController;
use PlugixaRecipe\Modules\Shortcodes\Integrations\Elementor\Elementor;

defined('ABSPATH') || exit;

/**
 * Shortcodes - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'shortcodes';
    }

    public function app(): array {
        return [
            'order'    => 60,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'code',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Shortcodes', 'plugixa-recipe'),
            'description' => __('Saved shortcodes that place a recipe listing on any page.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('shortcodes');

        /* translators: %d: number of items */
        return [sprintf(_n('%d shortcode', '%d shortcodes', $count, 'plugixa-recipe'), $count)];
    }

    /** This module's templates resolve by name through plugixa_recipe_template(). */
    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');

        // Late, and only for a tag nothing else has registered by then.
        add_action('init', [new RetiredShortcodes(), 'register'], 99);
    }

    /**
     * Every way a recipe listing reaches a page: the shortcodes, the widget shortcodes, the
     * Gutenberg block and the Elementor widget. A core module, so this always runs.
     */
    public function registerHooks(): void {
        add_action('init', [new ListingShortcode(), 'register']);
        add_action('init', [new RecipeBlock(), 'register']);
        // After RecipeBlock: it registers the editor stylesheet this block shares.
        add_action('init', [new SingleRecipeBlock(), 'register'], 11);

        // Widget shortcodes: sidebar-sized pieces, one class per kind.
        foreach ([
            new TaxonomyWidget(),
            new RecipeListWidget(),
            new RelatedRecipesWidget(),
            new RecipeSearchWidget(),
            new RecipeOfDayWidget(),
            new RecipeCountWidget(),
        ] as $widget) {
            add_action('init', [$widget, 'register']);
        }

        // Elementor announces itself on plugins_loaded; 20 is after it has.
        add_action('plugins_loaded', static function (): void {
            if (did_action('elementor/loaded')) {
                Elementor::instance();
            }
        }, 20);
    }

    public function registerRoutes(): void {
        $this->routes([
            ShortcodesController::class,
        ]);
    }
}
