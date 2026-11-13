<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes;

defined( 'ABSPATH' ) || exit;

/**
 * The fixed choices a recipe row may hold, in one place.
 *
 * Each writer used to carry its own copy and they had drifted: the admin editor offered
 * four costs, the public form three under different names, and the premium AI importer a
 * third vocabulary. A recipe submitted with cost `medium` then opened in the editor holding
 * a value its own list did not contain.
 *
 * The admin app's copy is `modules/recipes/schema.ts`; RegressionFixesTest compares the two.
 */
final class Enums {

    public const DIFFICULTIES = [ 'easy', 'medium', 'hard', 'expert' ];

    public const COSTS = [ 'budget', 'moderate', 'expensive', 'premium' ];

    public const SEASONS = [ 'spring', 'summer', 'autumn', 'winter', 'all' ];

    /**
     * Words earlier versions stored in `recipes.source` to remember where a recipe came
     * from. That column is the author's credit, and the recipe page prints it: a reader
     * was shown "Source: form". Nothing writes one any more, a reader is never shown one
     * (Services\RecipePresenter), and Schema::upgrade() clears those no code reads.
     */
    public const ORIGIN_MARKS = [ 'form', 'ai', 'import', 'wprm' ];

    /** Names earlier writers stored for a cost, and the choice each one meant. */
    public const LEGACY_COSTS = [
        'low'    => 'budget',
        'medium' => 'moderate',
        'high'   => 'expensive',
    ];

    /**
     * A cost as it is stored: one of COSTS, or '' when the value names none of them.
     *
     * @param mixed $value Raw input.
     */
    public static function cost( $value ): string {
        $value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
        $value = self::LEGACY_COSTS[ $value ] ?? $value;

        return in_array( $value, self::COSTS, true ) ? $value : '';
    }

    /** @return array<string, string> value => label */
    public static function difficulty_labels(): array {
        return [
            'easy'   => __( 'Easy', 'plugixa-recipe' ),
            'medium' => __( 'Medium', 'plugixa-recipe' ),
            'hard'   => __( 'Hard', 'plugixa-recipe' ),
            'expert' => __( 'Expert', 'plugixa-recipe' ),
        ];
    }

    /** @return array<string, string> value => label */
    public static function cost_labels(): array {
        return [
            'budget'    => __( 'Budget', 'plugixa-recipe' ),
            'moderate'  => __( 'Moderate', 'plugixa-recipe' ),
            'expensive' => __( 'Expensive', 'plugixa-recipe' ),
            'premium'   => __( 'Premium', 'plugixa-recipe' ),
        ];
    }

    /** @return array<string, string> value => label */
    public static function season_labels(): array {
        return [
            'all'    => __( 'All Seasons', 'plugixa-recipe' ),
            'spring' => __( 'Spring', 'plugixa-recipe' ),
            'summer' => __( 'Summer', 'plugixa-recipe' ),
            'autumn' => __( 'Autumn', 'plugixa-recipe' ),
            'winter' => __( 'Winter', 'plugixa-recipe' ),
        ];
    }
}
