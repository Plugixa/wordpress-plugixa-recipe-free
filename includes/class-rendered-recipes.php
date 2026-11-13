<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe;

defined( 'ABSPATH' ) || exit;

/**
 * Which recipes the current request actually rendered as page content.
 *
 * Structured data has to describe what a visitor can see. Search engines treat markup for
 * content that is not on the page as spam, so the JSON-LD builder needs to know the exact
 * set the page put on screen - not "the 20 most recent published recipes", which is what it
 * used to guess and which was wrong on every filtered listing and on the submission form.
 *
 * This registry is the seam. It lives in the free core because the render paths that fill
 * it are free; the premium JSON-LD builder is the only consumer, and it simply reads an
 * empty list when the overlay is absent.
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
     * Record recipes rendered as page content.
     *
     * Safe to call repeatedly: several shortcodes can appear on one page, and a recipe
     * shown twice is still one recipe.
     *
     * @param iterable<int|string|array<string,mixed>> $recipes Recipe IDs, or rows carrying an 'id'.
     */
    public static function add( iterable $recipes ): void {
        foreach ( $recipes as $recipe ) {
            if ( is_array( $recipe ) || is_object( $recipe ) ) {
                $recipe = ( (array) $recipe )['id'] ?? 0;
            }

            $id = (int) $recipe;
            if ( $id > 0 && ! in_array( $id, self::$ids, true ) ) {
                self::$ids[] = $id;
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
     * Did this request render any recipe as page content?
     */
    public static function has(): bool {
        return ! empty( self::$ids );
    }

    /**
     * Reset the registry. Test-support only; a real request is a fresh process.
     */
    public static function reset(): void {
        self::$ids = [];
    }
}
