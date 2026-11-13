<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\NutritionCalculator\Http\NutritionCalculatorController;

defined('ABSPATH') || exit;

/**
 * Nutrition facts worked out from the ingredient list, using USDA FoodData Central.
 *
 * Off until the site owner switches it on, and useless until they enter their own API
 * key: it is the one feature of the free build that calls an outside service, so it does
 * nothing by default. The editor confirms every match and every weight before a figure
 * reaches the recipe.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'nutrition_calculator';
    }

    public function app(): array {
        return [
            'order'    => 26,
            'category' => 'tools',
            'core'     => false,
            'default'  => false,
            'icon'     => 'calculate',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Nutrition Calculator', 'plugixa-recipe'),
            'description' => __('Work out a recipe\'s nutrition facts from its ingredients with the USDA FoodData Central database. Needs your own free API key; ingredient names are sent to the USDA service when you press Calculate.', 'plugixa-recipe'),
        ];
    }

    public function registerServices(): void {
        add_filter('plugixa_recipe_settings_defaults', static fn(array $defaults): array => array_merge($defaults, [
            // Read by Services\UsdaClient. Empty = the calculator asks for it.
            'usda_api_key' => '',
        ]));
        add_filter('plugixa_recipe_sanitize_settings', static function (array $settings, array $params): array {
            if (isset($params['usda_api_key']) && is_scalar($params['usda_api_key'])) {
                // api.data.gov keys are letters and digits; anything else is a paste error.
                $settings['usda_api_key'] = substr((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $params['usda_api_key']), 0, 80);
            }
            return $settings;
        }, 10, 2);
    }

    public function registerRoutes(): void {
        $this->routes([NutritionCalculatorController::class]);
    }
}
