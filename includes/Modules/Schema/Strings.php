<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Schema;

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
            'schema_markup' => __('Schema Markup', 'plugixa-recipe'),
            'schema_markup_hint' => __('JSON-LD structured data for search engines.', 'plugixa-recipe'),
            'enable_json_ld'     => __('Enable JSON-LD structured data', 'plugixa-recipe'),
            'enable_json_ld_hint' => __('Describes each recipe a page shows, so search results can display its rating, time and image. A single recipe can be left out from its own editor.', 'plugixa-recipe'),
            'enable_breadcrumb_schema'      => __('Add breadcrumbs to recipe pages', 'plugixa-recipe'),
            'enable_breadcrumb_schema_hint' => __('Home, then the recipe\'s first category, then the recipe - shown by search engines in place of the bare address.', 'plugixa-recipe'),
        ];
    }
}
