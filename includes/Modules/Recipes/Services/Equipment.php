<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

defined('ABSPATH') || exit;

/**
 * A recipe's equipment list, as the `recipes.equipment` JSON column stores it.
 *
 * It was a list of names. An item may now carry a link (where to buy the stand mixer), and
 * that item alone is stored as {"name": ..., "link_url": ...}; an item without one stays the
 * bare string it always was. So a recipe nobody has touched keeps its stored shape, and a
 * reader that wants names only asks names() instead of assuming every item is a string.
 */
final class Equipment {

    /**
     * @param mixed $stored The column's JSON, or an already decoded list.
     * @return array<int, array{name: string, link_url: string}>
     */
    public static function decode($stored): array {
        if (is_string($stored)) {
            $stored = '' === $stored ? [] : json_decode($stored, true);
        }

        $items = [];
        foreach (is_array($stored) ? $stored : [] as $item) {
            $name = is_array($item) ? ($item['name'] ?? '') : $item;
            $name = is_scalar($name) ? sanitize_text_field((string) $name) : '';
            if ('' === $name) {
                continue;
            }

            $link = is_array($item) && is_scalar($item['link_url'] ?? null) ? esc_url_raw((string) $item['link_url']) : '';

            // The first mention of a name wins; a list holds each tool once.
            $items[strtolower($name)] ??= ['name' => $name, 'link_url' => $link];
        }

        return array_values($items);
    }

    /**
     * @param mixed $stored The column's JSON, or an already decoded list.
     * @return string[]
     */
    public static function names($stored): array {
        return array_column(self::decode($stored), 'name');
    }

    /**
     * The column value for a list of names and/or {name, link_url} items; null when empty.
     *
     * @param mixed $items Raw input.
     */
    public static function encode($items): ?string {
        $stored = [];
        foreach (self::decode($items) as $item) {
            $stored[] = '' === $item['link_url'] ? $item['name'] : $item;
        }

        return $stored ? (string) wp_json_encode($stored) : null;
    }
}
