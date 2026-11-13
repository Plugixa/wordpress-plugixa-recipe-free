<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Nutrition;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Nutrition\Http\NutritionController;

defined('ABSPATH') || exit;

/**
 * Nutrition - an optional module the App Manager can switch off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'nutrition';
    }

    public function app(): array {
        return [
            'order'    => 60,
            'category' => 'content',
            'core'     => false,
            'default'  => true,
            'icon'     => 'monitor_heart',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Nutrition', 'plugixa-recipe'),
            'description' => __('Nutrition facts per recipe, shown as a label on the recipe page.', 'plugixa-recipe'),
        ];
    }

    /** This module's templates resolve by name through plugixa_recipe_template(). */
    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');
    }

    public function registerRoutes(): void {
        $this->routes([
            NutritionController::class,
        ]);
    }
}
