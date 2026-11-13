<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews;

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
            'reviews' => __('Reviews', 'plugixa-recipe'),
            'rating'  => __('Rating', 'plugixa-recipe'),
        ];
    }
}
