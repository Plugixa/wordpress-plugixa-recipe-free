<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes;

defined( 'ABSPATH' ) || exit;

/**
 * The eleven diet flags a recipe carries, in one place.
 *
 * The recipe page listed six of them as badges, the JSON-LD keywords another six, and the
 * public filter a third six - the columns were eleven all along. Anything that shows, names
 * or publishes a diet reads this.
 */
final class DietFlags {

    /**
     * Every flag column and what it is called.
     *
     * @return array<string, string> column => label
     */
    public static function labels(): array {
        return [
            'is_vegetarian'  => __( 'Vegetarian', 'plugixa-recipe' ),
            'is_vegan'       => __( 'Vegan', 'plugixa-recipe' ),
            'is_gluten_free' => __( 'Gluten-Free', 'plugixa-recipe' ),
            'is_dairy_free'  => __( 'Dairy-Free', 'plugixa-recipe' ),
            'is_nut_free'    => __( 'Nut-Free', 'plugixa-recipe' ),
            'is_keto'        => __( 'Keto', 'plugixa-recipe' ),
            'is_paleo'       => __( 'Paleo', 'plugixa-recipe' ),
            'is_low_carb'    => __( 'Low Carb', 'plugixa-recipe' ),
            'is_sugar_free'  => __( 'Sugar-Free', 'plugixa-recipe' ),
            'is_halal'       => __( 'Halal', 'plugixa-recipe' ),
            'is_kosher'      => __( 'Kosher', 'plugixa-recipe' ),
        ];
    }

    /**
     * The labels of the flags set on a recipe row.
     *
     * @param array<string, mixed> $recipe
     * @return array<string, string> column => label, only those that are on
     */
    public static function of( array $recipe ): array {
        return array_filter(
            self::labels(),
            static fn ( string $column ): bool => ! empty( $recipe[ $column ] ),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Which flags are published as schema.org `suitableForDiet`, and as which diet.
     *
     * schema.org's list (RestrictedDiet) is short and does not match ours: DiabeticDiet,
     * GlutenFreeDiet, HalalDiet, HinduDiet, KosherDiet, LowCalorieDiet, LowFatDiet,
     * LowLactoseDiet, LowSaltDiet, VeganDiet, VegetarianDiet. A flag with no entry here is
     * still published - as a keyword, which claims nothing formal.
     *
     * @return array<string, string> column => schema.org RestrictedDiet name (no URL)
     */
    public static function schema_map(): array {
        // TODO(owner): decide which of the remaining flags, if any, to map.
        // The five below are exact matches. The open ones are the inexact pairs:
        //   is_dairy_free -> LowLactoseDiet ?  ("low lactose" is weaker than "no dairy";
        //                                       someone with a milk allergy reads it differently)
        //   is_sugar_free -> DiabeticDiet ?    (a medical claim the recipe's author never made)
        //   is_low_carb, is_keto, is_paleo, is_nut_free have no counterpart at all.
        // A wrong entry here is a false dietary claim in structured data, read by machines
        // and shown by search engines without the recipe's own wording beside it.
        return [
            'is_vegetarian'  => 'VegetarianDiet',
            'is_vegan'       => 'VeganDiet',
            'is_gluten_free' => 'GlutenFreeDiet',
            'is_halal'       => 'HalalDiet',
            'is_kosher'      => 'KosherDiet',
        ];
    }
}
