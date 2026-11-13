<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Nutrition Facts Label Template
 *
 * Renders an FDA-style nutrition facts label with proper formatting,
 * thick/thin separator lines, and daily value percentages.
 *
 * Available variables:
 * @var object $nutrition  Nutrition data object with all nutrient fields
 * @var int    $servings   Number of servings (from parent template or recipe)
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

if ( ! $nutrition ) {
    return;
}

// Extract nutrition values with defaults.
$serving_size       = isset( $nutrition->serving_size ) ? $nutrition->serving_size : '';
$calories           = isset( $nutrition->calories ) ? $nutrition->calories : null;
$total_fat          = isset( $nutrition->total_fat ) ? $nutrition->total_fat : null;
$saturated_fat      = isset( $nutrition->saturated_fat ) ? $nutrition->saturated_fat : null;
$trans_fat          = isset( $nutrition->trans_fat ) ? $nutrition->trans_fat : null;
$cholesterol        = isset( $nutrition->cholesterol ) ? $nutrition->cholesterol : null;
$sodium             = isset( $nutrition->sodium ) ? $nutrition->sodium : null;
$total_carbohydrate = isset( $nutrition->total_carbohydrate ) ? $nutrition->total_carbohydrate : null;
$dietary_fiber      = isset( $nutrition->dietary_fiber ) ? $nutrition->dietary_fiber : null;
$total_sugars       = isset( $nutrition->total_sugars ) ? $nutrition->total_sugars : null;
$added_sugars       = isset( $nutrition->added_sugars ) ? $nutrition->added_sugars : null;
$protein            = isset( $nutrition->protein ) ? $nutrition->protein : null;
$vitamin_d          = isset( $nutrition->vitamin_d ) ? $nutrition->vitamin_d : null;
$calcium            = isset( $nutrition->calcium ) ? $nutrition->calcium : null;
$iron               = isset( $nutrition->iron ) ? $nutrition->iron : null;
$potassium          = isset( $nutrition->potassium ) ? $nutrition->potassium : null;
$vitamin_a          = isset( $nutrition->vitamin_a ) ? $nutrition->vitamin_a : null;
$vitamin_c          = isset( $nutrition->vitamin_c ) ? $nutrition->vitamin_c : null;

// FDA daily values for percentage calculation (based on 2,000 calorie diet).
$daily_values = array(
    'total_fat'          => 78,    // g
    'saturated_fat'      => 20,    // g
    'cholesterol'        => 300,   // mg
    'sodium'             => 2300,  // mg
    'total_carbohydrate' => 275,   // g
    'dietary_fiber'      => 28,    // g
    'added_sugars'       => 50,    // g
    'vitamin_d'          => 20,    // mcg
    'calcium'            => 1300,  // mg
    'iron'               => 18,    // mg
    'potassium'          => 4700,  // mg
);

/**
 * Calculate daily value percentage.
 */
if ( ! function_exists( 'plugixa_recipe_daily_value_pct' ) ) {
    function plugixa_recipe_daily_value_pct( $value, $dv ) {
        if ( $value === null || $dv <= 0 ) {
            return null;
        }
        return round( ( floatval( $value ) / $dv ) * 100 );
    }
}
?>

<div class="pxr-custom-nutrition-label pxr:border-2 pxr:border-black pxr:p-2 pxr:max-w-xs pxr:font-sans"
     style="border-color: var(--pxr-nutrition-border-color, #000); max-width: var(--pxr-nutrition-max-width, 280px);"
     role="region"
     aria-label="<?php esc_attr_e( 'Nutrition Facts', 'plugixa-recipe' ); ?>">

    <!-- Header -->
    <div class="pxr-custom-nutrition-label__header pxr:pb-1 pxr:mb-1" style="border-bottom: 1px solid #000;">
        <h3 class="pxr-custom-nutrition-label__title pxr:text-2xl pxr:font-extrabold pxr:leading-none pxr:m-0 pxr:tracking-tight"
            style="font-size: var(--pxr-nutrition-title-size, 2rem);">
            <?php esc_html_e( 'Nutrition Facts', 'plugixa-recipe' ); ?>
        </h3>
    </div>

    <?php if ( isset( $servings ) && $servings ) : ?>
        <div class="pxr-custom-nutrition-label__servings pxr:text-sm pxr:pb-0.5">
            <?php
            /* translators: %d: number of servings per recipe */
            echo esc_html( sprintf( __( '%d servings per recipe', 'plugixa-recipe' ), $servings ) );
            ?>
        </div>
    <?php endif; ?>

    <?php if ( $serving_size ) : ?>
        <div class="pxr-custom-nutrition-label__serving-size pxr:flex pxr:justify-between pxr:text-sm pxr:font-bold pxr:pb-1"
             style="border-bottom: 8px solid #000;">
            <span><?php esc_html_e( 'Serving size', 'plugixa-recipe' ); ?></span>
            <span><?php echo esc_html( $serving_size ); ?></span>
        </div>
    <?php else : ?>
        <div style="border-bottom: 8px solid #000;" class="pxr:pb-1"></div>
    <?php endif; ?>

    <!-- Calories -->
    <?php if ( $calories !== null ) : ?>
        <div class="pxr-custom-nutrition-label__calories pxr:flex pxr:justify-between pxr:items-end pxr:py-1"
             style="border-bottom: 4px solid #000;">
            <div>
                <span class="pxr:block pxr:text-sm pxr:font-bold"><?php esc_html_e( 'Calories', 'plugixa-recipe' ); ?></span>
                <span class="pxr:block pxr:text-3xl pxr:font-extrabold pxr:leading-none">
                    <?php echo esc_html( $calories ); ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Daily Value Header -->
    <div class="pxr-custom-nutrition-label__dv-header pxr:text-right pxr:text-xs pxr:font-bold pxr:py-0.5"
         style="border-bottom: 1px solid #000;">
        <?php esc_html_e( '% Daily Value*', 'plugixa-recipe' ); ?>
    </div>

    <!-- Nutrient Rows -->
    <div class="pxr-custom-nutrition-label__nutrients">

        <?php if ( $total_fat !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $total_fat, $daily_values['total_fat'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><strong><?php esc_html_e( 'Total Fat', 'plugixa-recipe' ); ?></strong> <?php echo esc_html( $total_fat ); ?>g</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $saturated_fat !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $saturated_fat, $daily_values['saturated_fat'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr-custom-nutrition-label__row--indent pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm pxr:pl-4"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Saturated Fat', 'plugixa-recipe' ); ?> <?php echo esc_html( $saturated_fat ); ?>g</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $trans_fat !== null ) : ?>
            <div class="pxr-custom-nutrition-label__row pxr-custom-nutrition-label__row--indent pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm pxr:pl-4"
                 style="border-bottom: 1px solid #000;">
                <span><em><?php esc_html_e( 'Trans', 'plugixa-recipe' ); ?></em> <?php esc_html_e( 'Fat', 'plugixa-recipe' ); ?> <?php echo esc_html( $trans_fat ); ?>g</span>
                <span></span>
            </div>
        <?php endif; ?>

        <?php if ( $cholesterol !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $cholesterol, $daily_values['cholesterol'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><strong><?php esc_html_e( 'Cholesterol', 'plugixa-recipe' ); ?></strong> <?php echo esc_html( $cholesterol ); ?>mg</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $sodium !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $sodium, $daily_values['sodium'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><strong><?php esc_html_e( 'Sodium', 'plugixa-recipe' ); ?></strong> <?php echo esc_html( $sodium ); ?>mg</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $total_carbohydrate !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $total_carbohydrate, $daily_values['total_carbohydrate'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><strong><?php esc_html_e( 'Total Carbohydrate', 'plugixa-recipe' ); ?></strong> <?php echo esc_html( $total_carbohydrate ); ?>g</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $dietary_fiber !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $dietary_fiber, $daily_values['dietary_fiber'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr-custom-nutrition-label__row--indent pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm pxr:pl-4"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Dietary Fiber', 'plugixa-recipe' ); ?> <?php echo esc_html( $dietary_fiber ); ?>g</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $total_sugars !== null ) : ?>
            <div class="pxr-custom-nutrition-label__row pxr-custom-nutrition-label__row--indent pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm pxr:pl-4"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Total Sugars', 'plugixa-recipe' ); ?> <?php echo esc_html( $total_sugars ); ?>g</span>
                <span></span>
            </div>
        <?php endif; ?>

        <?php if ( $added_sugars !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $added_sugars, $daily_values['added_sugars'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr-custom-nutrition-label__row--indent-2 pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm pxr:pl-8"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Includes', 'plugixa-recipe' ); ?> <?php echo esc_html( $added_sugars ); ?>g <?php esc_html_e( 'Added Sugars', 'plugixa-recipe' ); ?></span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $protein !== null ) : ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 8px solid #000;">
                <span><strong><?php esc_html_e( 'Protein', 'plugixa-recipe' ); ?></strong> <?php echo esc_html( $protein ); ?>g</span>
                <span></span>
            </div>
        <?php endif; ?>

        <!-- Vitamins & Minerals (below thick line) -->

        <?php if ( $vitamin_d !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $vitamin_d, $daily_values['vitamin_d'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Vitamin D', 'plugixa-recipe' ); ?> <?php echo esc_html( $vitamin_d ); ?>mcg</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $calcium !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $calcium, $daily_values['calcium'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Calcium', 'plugixa-recipe' ); ?> <?php echo esc_html( $calcium ); ?>mg</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $iron !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $iron, $daily_values['iron'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Iron', 'plugixa-recipe' ); ?> <?php echo esc_html( $iron ); ?>mg</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $potassium !== null ) :
            $dv_pct = plugixa_recipe_daily_value_pct( $potassium, $daily_values['potassium'] );
        ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Potassium', 'plugixa-recipe' ); ?> <?php echo esc_html( $potassium ); ?>mg</span>
                <?php if ( $dv_pct !== null ) : ?>
                    <span class="pxr:font-bold"><?php echo esc_html( $dv_pct ); ?>%</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $vitamin_a !== null ) : ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Vitamin A', 'plugixa-recipe' ); ?></span>
                <span class="pxr:font-bold"><?php echo esc_html( $vitamin_a ); ?>%</span>
            </div>
        <?php endif; ?>

        <?php if ( $vitamin_c !== null ) : ?>
            <div class="pxr-custom-nutrition-label__row pxr:flex pxr:justify-between pxr:py-0.5 pxr:text-sm"
                 style="border-bottom: 1px solid #000;">
                <span><?php esc_html_e( 'Vitamin C', 'plugixa-recipe' ); ?></span>
                <span class="pxr:font-bold"><?php echo esc_html( $vitamin_c ); ?>%</span>
            </div>
        <?php endif; ?>

    </div>

    <?php if ( isset( $nutrition->source ) && 'usda' === $nutrition->source ) : ?>
        <!--
            Worked out from the ingredient list with reference data, not measured for this
            dish: cooking losses, brands and substitutions are not in it.
        -->
        <div class="pxr-custom-nutrition-label__estimate-note pxr:text-xs pxr:pt-1 pxr:leading-snug">
            <?php esc_html_e( 'Calculated from the ingredients with USDA FoodData Central data. An approximation, not a laboratory analysis.', 'plugixa-recipe' ); ?>
        </div>
    <?php endif; ?>

    <?php if ( isset( $nutrition->source ) && 'ai' === $nutrition->source ) : ?>
        <!--
            These figures were estimated from the ingredient list rather than measured,
            and the same numbers are published as schema.org NutritionInformation. The
            structured data has no field for "estimated", so the page has to say it:
            a reader (and a reviewer) must not take an estimate for an analysis.
        -->
        <div class="pxr-custom-nutrition-label__estimate-note pxr:text-xs pxr:pt-1 pxr:leading-snug">
            <?php esc_html_e( 'Estimated from the ingredient list. Not a laboratory analysis - verify before relying on these values for medical or dietary needs.', 'plugixa-recipe' ); ?>
        </div>
    <?php endif; ?>

    <!-- Footnote -->
    <div class="pxr-custom-nutrition-label__footnote pxr:text-xs pxr:pt-1 pxr:leading-snug">
        <span class="pxr:font-bold">*</span>
        <?php esc_html_e( 'The % Daily Value (DV) tells you how much a nutrient in a serving of food contributes to a daily diet. 2,000 calories a day is used for general nutrition advice.', 'plugixa-recipe' ); ?>
    </div>

</div>
