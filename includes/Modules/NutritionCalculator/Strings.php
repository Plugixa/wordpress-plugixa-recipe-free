<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator;

defined('ABSPATH') || exit;

/**
 * This module's admin-app strings, keyed the way the React app asks for them.
 *
 * Merged into the page config by ModuleRegistry::strings() - for every module, switched on
 * or not, because a disabled module's name still appears on its App Manager card.
 */
final class Strings {

    /** @return array<string, string> i18n key => translated text */
    public static function all(): array {
        return [
            'nc_title'          => __('Calculate nutrition from the ingredients', 'plugixa-recipe'),
            'nc_open'           => __('Calculate from ingredients', 'plugixa-recipe'),
            'nc_calculated'     => __('These values were calculated from the ingredient list with USDA FoodData Central data. They are an approximation: check them before publishing.', 'plugixa-recipe'),
            'nc_no_ingredients' => __('Add the ingredients first: the calculation is made from them.', 'plugixa-recipe'),
            'nc_failed'         => __('The food database could not be asked. Try again in a moment.', 'plugixa-recipe'),
            'nc_loading'        => __('Looking the ingredients up…', 'plugixa-recipe'),
            'nc_intro'          => __('Check each match and each weight. A weight is filled in when the unit is a weight, or when you gave one for this food before; otherwise enter how many grams the recipe uses. A row without a weight is left out.', 'plugixa-recipe'),
            'nc_col_ingredient' => __('Ingredient', 'plugixa-recipe'),
            'nc_col_food'       => __('Food in the database', 'plugixa-recipe'),
            'nc_col_grams'      => __('Grams', 'plugixa-recipe'),
            'nc_col_calories'   => __('Calories', 'plugixa-recipe'),
            'nc_leave_out'      => __('Leave out', 'plugixa-recipe'),
            'nc_no_match'       => __('No match found - left out', 'plugixa-recipe'),
            'nc_unweighed'      => __('Some rows have no weight yet and are not counted. Enter their grams to include them.', 'plugixa-recipe'),
            'nc_servings'       => __('Servings', 'plugixa-recipe'),
            'nc_per_serving'    => __('Per serving', 'plugixa-recipe'),
            'nc_source'         => __('Reference data from USDA FoodData Central. The result is an approximation and is labelled as calculated on the recipe page.', 'plugixa-recipe'),
            'nc_apply'          => __('Use these values', 'plugixa-recipe'),
            'nc_settings'       => __('Nutrition Calculator', 'plugixa-recipe'),
            'nc_settings_hint'  => __('Works out nutrition facts from a recipe\'s ingredients.', 'plugixa-recipe'),
            'nc_api_key'        => __('USDA FoodData Central API key', 'plugixa-recipe'),
            'nc_api_key_hint'   => __('The key is free. When you press Calculate on a recipe, the names of its ingredients are sent to the USDA service with this key; nothing else is sent, and nothing is sent at any other time.', 'plugixa-recipe'),
            'nc_api_key_link'   => __('Get a key', 'plugixa-recipe'),
        ];
    }
}
