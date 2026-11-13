<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Frontend;

use PlugixaRecipe\Modules\Recipes\DietFlags;
use PlugixaRecipe\Modules\Recipes\Enums;

defined('ABSPATH') || exit;

/**
 * What a visitor asked a listing for, through the [plugixa_recipe_search] form.
 *
 * Seven GET fields, read in ONE place: the form that prints them, the listing that applies
 * them and the form's "did anyone submit me" check each had their own copy of the first
 * three, and a fourth field added to one of them would have been a filter the form offers
 * and the listing ignores.
 *
 * Every value is narrowed to something the query can take as it is: a slug, a difficulty
 * that exists, a whole number of minutes, a diet column from the one list of them. An
 * unknown value is as if the field were empty - it is a filter on public content, not a
 * form to be validated back at the visitor.
 *
 * No nonce: read-only filters, the same as core's own `s`, and a nonce would break every
 * bookmarked or shared link to a filtered list.
 */
final class VisitorFilters {

    /** The longest "ready in" a visitor can ask for, in minutes. */
    public const TIMES = [15, 30, 45, 60, 120];

    /**
     * @return array{search:string, category:string, cuisine:string, course:string, difficulty:string, max_time:int, diet:string}
     */
    public static function read(): array {
        $difficulty = sanitize_key(self::field('plugixa_recipe_difficulty'));
        $diet       = sanitize_key(self::field('plugixa_recipe_diet'));
        $time       = absint(self::field('plugixa_recipe_time'));
        $search     = sanitize_text_field(self::field('plugixa_recipe_search'));

        return [
            'search'     => function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100),
            'category'   => sanitize_title(self::field('plugixa_recipe_category')),
            'cuisine'    => sanitize_title(self::field('plugixa_recipe_cuisine')),
            'course'     => sanitize_title(self::field('plugixa_recipe_course')),
            'difficulty' => in_array($difficulty, Enums::DIFFICULTIES, true) ? $difficulty : '',
            'max_time'   => in_array($time, self::TIMES, true) ? $time : 0,
            'diet'       => isset(DietFlags::labels()[$diet]) ? $diet : '',
        ];
    }

    /** Did the visitor ask for anything at all? */
    public static function submitted(): bool {
        return (bool) array_filter(self::read());
    }

    private static function field(string $name): string {
        // No nonce: a read-only public filter (see the class docblock). Not sanitized here:
        // every caller in read() narrows the value to what its field can be.
        $value = isset($_GET[$name]) ? wp_unslash($_GET[$name]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see the two lines above.

        return is_scalar($value) ? (string) $value : '';
    }
}
