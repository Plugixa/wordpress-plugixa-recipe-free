<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes;

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
            'recipes'      => __('Recipes', 'plugixa-recipe'),
            'add_recipe'   => __('Add Recipe', 'plugixa-recipe'),
            'edit_recipe'  => __('Edit Recipe', 'plugixa-recipe'),
            'ingredients'  => __('Ingredients', 'plugixa-recipe'),
            'instructions' => __('Instructions', 'plugixa-recipe'),
        ];
    }
}
