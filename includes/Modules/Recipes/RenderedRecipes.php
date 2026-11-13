<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes;

defined( 'ABSPATH' ) || exit;

/**
 * Which recipes the current request actually rendered as page content.
 *
 * Structured data has to describe what a visitor can see. Search engines treat markup for
 * content that is not on the page as spam, so the JSON-LD builder needs to know the exact
 * set the page put on screen - not "the 20 most recent published recipes", which is what it
 * used to guess and which was wrong on every filtered listing and on the submission form.
 *
 * This registry is the seam between the render paths, which fill it, and the JSON-LD
 * builder (Modules\Schema), its only reader.
 *
 * A recipe is on a page in one of two ways, and the markup differs:
 * - as the page's SUBJECT - the recipe's own page, or a recipe embedded in a post. It is
 *   described in full (a schema.org Recipe);
 * - as an entry in a LIST - a listing shortcode, an archive. The page is a summary, so it
 *   gets one ItemList of links to the recipes' own pages, where the full description is.
 *   Twenty full Recipe payloads on a listing told a search engine the page was twenty
 *   recipes at once.
 *
 * What registers: page content that displays a recipe - the [plugixa_recipe] listing and
 * the single-recipe page.
 *
 * What deliberately does NOT register:
 * - the submission form, which shows no recipes at all (it used to trip the old gate, so a
 *   bare form page advertised twenty recipes it never displayed);
 * - the sidebar widget shortcodes (latest / popular / related / recipe of the day), which
 *   are supplementary navigation rather than the subject of the page. Emitting a full
 *   Recipe payload for every sidebar link would bury the page's actual subject.
 */
final class RenderedRecipes {

    /**
     * Recipe IDs in the order they were rendered, first occurrence wins.
     *
     * @var int[]
     */
    private static array $ids = [];

    /**
     * Those of the ids above that are the page's subject, in render order.
     *
     * @var int[]
     */
    private static array $subjects = [];

    /**
     * Record recipes rendered as page content.
     *
     * Safe to call repeatedly: several shortcodes can appear on one page, and a recipe
     * shown twice is still one recipe.
     *
     * @param iterable<int|string|array<string,mixed>> $recipes Recipe IDs, or rows carrying an 'id'.
     * @param bool                                     $subject True when the page is ABOUT these
     *                                                          recipes rather than listing them.
     */
    public static function add( iterable $recipes, bool $subject = false ): void {
        foreach ( $recipes as $recipe ) {
            if ( is_array( $recipe ) || is_object( $recipe ) ) {
                $recipe = ( (array) $recipe )['id'] ?? 0;
            }

            $id = (int) $recipe;
            if ( $id <= 0 ) {
                continue;
            }
            if ( ! in_array( $id, self::$ids, true ) ) {
                self::$ids[] = $id;
            }
            // Shown in a list AND as the subject, it is the subject.
            if ( $subject && ! in_array( $id, self::$subjects, true ) ) {
                self::$subjects[] = $id;
            }
        }
    }

    /**
     * Recipe IDs rendered on this request, in render order.
     *
     * @return int[]
     */
    public static function all(): array {
        return self::$ids;
    }

    /**
     * The recipes this page is about, in render order.
     *
     * @return int[]
     */
    public static function subjects(): array {
        return self::$subjects;
    }

    /**
     * The recipes this page lists without being about them, in render order.
     *
     * @return int[]
     */
    public static function listed(): array {
        return array_values( array_diff( self::$ids, self::$subjects ) );
    }

    /**
     * Did this request render any recipe as page content?
     */
    public static function has(): bool {
        return ! empty( self::$ids );
    }

    /**
     * Reset the registry. Test-support only; a real request is a fresh process.
     */
    public static function reset(): void {
        self::$ids      = [];
        self::$subjects = [];
    }
}
