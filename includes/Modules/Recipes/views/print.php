<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Print Template
 *
 * A document of its own: no theme, no navigation, no ads. It draws the same struct the
 * recipe's page draws, so a field the page shows is here too.
 *
 * An ingredient line carries the recipe page's data attributes. When the reader tools are
 * on, print.js reads the scale and units the reader chose on the page and writes them into
 * those lines; without them the list prints as the author wrote it.
 *
 * Available variables:
 * @var object $recipe The presented recipe (Services\RecipePresenter).
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$recipe_id          = isset( $recipe->id ) ? absint( $recipe->id ) : 0;
$recipe_title       = isset( $recipe->title ) ? $recipe->title : '';
$description        = isset( $recipe->description ) ? $recipe->description : '';
$image_url          = isset( $recipe->image_url ) ? $recipe->image_url : '';
$image_alt          = ! empty( $recipe->image_alt ) ? $recipe->image_alt : $recipe_title;
$author_name        = isset( $recipe->author_name ) ? $recipe->author_name : '';
$recipe_url         = isset( $recipe->permalink ) ? $recipe->permalink : '';
$prep_time          = isset( $recipe->prep_time ) ? absint( $recipe->prep_time ) : 0;
$cook_time          = isset( $recipe->cook_time ) ? absint( $recipe->cook_time ) : 0;
$rest_time          = isset( $recipe->rest_time ) ? absint( $recipe->rest_time ) : 0;
// The stored column counts rest time too; the sum is for a caller that did not select it.
$total_time         = isset( $recipe->total_time ) ? absint( $recipe->total_time ) : $prep_time + $cook_time + $rest_time;
$servings           = isset( $recipe->servings ) ? absint( $recipe->servings ) : 0;
// The label ("Easy"); a caller that passes only the stored value shows that.
$difficulty         = ! empty( $recipe->difficulty_label ) ? $recipe->difficulty_label : ( isset( $recipe->difficulty ) ? $recipe->difficulty : '' );
$cuisine            = isset( $recipe->cuisine ) ? $recipe->cuisine : '';
$courses            = ! empty( $recipe->courses ) && is_array( $recipe->courses ) ? implode( ', ', array_column( $recipe->courses, 'name' ) ) : '';
$ingredient_groups  = isset( $recipe->ingredient_groups ) && is_array( $recipe->ingredient_groups ) ? $recipe->ingredient_groups : array();
$instruction_groups = isset( $recipe->instruction_groups ) && is_array( $recipe->instruction_groups ) ? $recipe->instruction_groups : array();
$equipment          = isset( $recipe->equipment_items ) && is_array( $recipe->equipment_items ) ? $recipe->equipment_items : array();
$notes              = isset( $recipe->notes ) ? trim( (string) $recipe->notes ) : '';
$tips               = isset( $recipe->tips ) ? trim( (string) $recipe->tips ) : '';
$source             = isset( $recipe->source ) ? trim( (string) $recipe->source ) : '';
$source_url         = isset( $recipe->source_url ) ? trim( (string) $recipe->source_url ) : '';
// A switched-off app shows nothing, here as on the recipe's page.
$nutrition          = isset( $recipe->nutrition ) && plugixa_recipe_app_enabled( 'nutrition' ) ? $recipe->nutrition : null;
$site_name          = get_bloginfo( 'name' );
$site_url           = home_url( '/' );

// Which of the three things a reader can leave out this recipe has at all.
$has_images = (bool) $image_url;
$has_notes  = '' !== $notes || '' !== $tips;
foreach ( $instruction_groups as $group ) {
    foreach ( ( isset( $group->steps ) && is_array( $group->steps ) ? $group->steps : array() ) as $step ) {
        $has_images = $has_images || ! empty( $step->image_url );
        $has_notes  = $has_notes || ! empty( $step->tip );
    }
}

if ( ! function_exists( 'plugixa_recipe_format_time' ) ) {
    function plugixa_recipe_format_time( $minutes ) {
        if ( $minutes < 60 ) {
            /* translators: %d: number of minutes. */
            return sprintf( _n( '%d min', '%d mins', $minutes, 'plugixa-recipe' ), $minutes );
        }
        $hours = floor( $minutes / 60 );
        $mins  = $minutes % 60;
        if ( $mins === 0 ) {
            /* translators: %d: number of hours. */
            return sprintf( _n( '%d hr', '%d hrs', $hours, 'plugixa-recipe' ), $hours );
        }
        /* translators: 1: number of hours, 2: number of minutes. */
        return sprintf( __( '%1$d hr %2$d min', 'plugixa-recipe' ), $hours, $mins );
    }
}

$facts = array();
if ( $prep_time ) {
    $facts[] = array( __( 'Prep:', 'plugixa-recipe' ), plugixa_recipe_format_time( $prep_time ), '' );
}
if ( $cook_time ) {
    $facts[] = array( __( 'Cook:', 'plugixa-recipe' ), plugixa_recipe_format_time( $cook_time ), '' );
}
if ( $rest_time ) {
    $facts[] = array( __( 'Rest:', 'plugixa-recipe' ), plugixa_recipe_format_time( $rest_time ), '' );
}
if ( $total_time ) {
    $facts[] = array( __( 'Total:', 'plugixa-recipe' ), plugixa_recipe_format_time( $total_time ), '' );
}
if ( $servings ) {
    $facts[] = array( __( 'Servings:', 'plugixa-recipe' ), (string) $servings, 'servings' );
}
if ( ! empty( $recipe->yield ) ) {
    $facts[] = array( __( 'Makes:', 'plugixa-recipe' ), $recipe->yield, '' );
}
if ( $difficulty ) {
    $facts[] = array( __( 'Difficulty:', 'plugixa-recipe' ), $difficulty, '' );
}
if ( $cuisine ) {
    $facts[] = array( __( 'Cuisine:', 'plugixa-recipe' ), $cuisine, '' );
}
if ( $courses ) {
    $facts[] = array( __( 'Course:', 'plugixa-recipe' ), $courses, '' );
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html( $recipe_title ); ?> - <?php esc_html_e( 'Print', 'plugixa-recipe' ); ?></title>
    <?php wp_print_styles( 'plugixa-recipe-print' ); ?>
</head>
<body class="pxr-print"
      data-pxr-print
      data-pxr-recipe-id="<?php echo esc_attr( $recipe_id ); ?>"
      data-pxr-servings="<?php echo esc_attr( $servings ); ?>">

    <!-- On screen only: the button, and what to leave out. -->
    <div class="pxr-print-action">
        <button type="button" data-pxr-print-now aria-label="<?php esc_attr_e( 'Print this recipe', 'plugixa-recipe' ); ?>">
            <?php esc_html_e( 'Print Recipe', 'plugixa-recipe' ); ?>
        </button>

        <?php if ( $has_images || $has_notes || $nutrition ) : ?>
            <?php // Hidden until print.js shows it: a box that changes nothing is worse than none. ?>
            <fieldset class="pxr-print-options" data-pxr-print-options hidden>
                <legend><?php esc_html_e( 'Include', 'plugixa-recipe' ); ?></legend>
                <?php if ( $has_images ) : ?>
                    <label><input type="checkbox" data-pxr-print-option="images" checked /> <?php esc_html_e( 'Images', 'plugixa-recipe' ); ?></label>
                <?php endif; ?>
                <?php if ( $has_notes ) : ?>
                    <label><input type="checkbox" data-pxr-print-option="notes" checked /> <?php esc_html_e( 'Notes and tips', 'plugixa-recipe' ); ?></label>
                <?php endif; ?>
                <?php if ( $nutrition ) : ?>
                    <label><input type="checkbox" data-pxr-print-option="nutrition" checked /> <?php esc_html_e( 'Nutrition', 'plugixa-recipe' ); ?></label>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>
    </div>

    <!-- Header -->
    <header class="pxr-print-header">
        <h1><?php echo esc_html( $recipe_title ); ?></h1>

        <?php if ( $description ) : ?>
            <p class="pxr-print-description"><?php echo esc_html( wp_strip_all_tags( $description ) ); ?></p>
        <?php endif; ?>

        <?php if ( $author_name ) : ?>
            <p class="pxr-print-author">
                <?php
                /* translators: %s: author name */
                echo esc_html( sprintf( __( 'By %s', 'plugixa-recipe' ), $author_name ) );
                ?>
            </p>
        <?php endif; ?>

        <?php if ( $facts ) : ?>
            <dl class="pxr-print-meta">
                <?php foreach ( $facts as $fact ) : ?>
                    <div class="pxr-print-meta-item">
                        <dt><?php echo esc_html( $fact[0] ); ?></dt>
                        <dd<?php echo 'servings' === $fact[2] ? ' data-pxr-print-servings' : ''; ?>><?php echo esc_html( $fact[1] ); ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </header>

    <!-- Image -->
    <?php if ( $image_url ) : ?>
        <img src="<?php echo esc_url( $image_url ); ?>"
             alt="<?php echo esc_attr( $image_alt ); ?>"
             class="pxr-print-image"
             data-pxr-print-part="images" />
    <?php endif; ?>

    <!-- Ingredients -->
    <?php if ( ! empty( $ingredient_groups ) ) : ?>
        <section class="pxr-print-ingredients">
            <h2><?php esc_html_e( 'Ingredients', 'plugixa-recipe' ); ?></h2>

            <?php // Filled and shown by print.js when the amounts below are not the author's. ?>
            <p class="pxr-print-scaled" data-pxr-print-scaled hidden
               data-servings-text="<?php
                   /* translators: 1: the number of servings the recipe is written for, 2: the number the amounts were scaled to. */
                   echo esc_attr( sprintf( __( 'Amounts scaled from %1$s to %2$s servings.', 'plugixa-recipe' ), $servings, '{n}' ) );
               ?>"
               data-ratio-text="<?php
                   /* translators: %s: a multiplier, e.g. 2 or 0.5. */
                   echo esc_attr( sprintf( __( 'Amounts multiplied by %s.', 'plugixa-recipe' ), '{n}' ) );
               ?>"></p>

            <?php foreach ( $ingredient_groups as $group ) : ?>
                <?php if ( ! empty( $group->name ) ) : ?>
                    <h3><?php echo esc_html( $group->name ); ?></h3>
                <?php endif; ?>
                <?php if ( ! empty( $group->items ) && is_array( $group->items ) ) : ?>
                    <ul>
                        <?php foreach ( $group->items as $ingredient ) : ?>
                            <li>
                                <span class="pxr-print-ingredient"
                                      data-pxr-amount="<?php echo esc_attr( $ingredient->amount ?? '' ); ?>"
                                      <?php if ( isset( $ingredient->amount_value ) ) : ?>
                                          data-pxr-amount-value="<?php echo esc_attr( $ingredient->amount_value ); ?>"
                                      <?php endif; ?>
                                      <?php if ( isset( $ingredient->amount_max ) ) : ?>
                                          data-pxr-amount-max="<?php echo esc_attr( $ingredient->amount_max ); ?>"
                                      <?php endif; ?>
                                      <?php if ( ! empty( $ingredient->unit_type ) ) : ?>
                                          data-pxr-unit-type="<?php echo esc_attr( $ingredient->unit_type ); ?>"
                                          data-pxr-unit-system="<?php echo esc_attr( $ingredient->unit_system ); ?>"
                                          data-pxr-unit-factor="<?php echo esc_attr( $ingredient->unit_factor ); ?>"
                                      <?php endif; ?>
                                      data-pxr-unit="<?php echo esc_attr( $ingredient->unit ?? '' ); ?>">
                                    <?php if ( ! empty( $ingredient->amount ) ) : ?>
                                        <strong data-pxr-ingredient-amount><?php echo esc_html( $ingredient->amount ); ?></strong>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->unit ) ) : ?>
                                        <span data-pxr-ingredient-unit><?php echo esc_html( $ingredient->unit ); ?></span>
                                    <?php endif; ?>
                                    <?php echo esc_html( $ingredient->name ?? $ingredient->text ?? '' ); ?>
                                    <?php if ( ! empty( $ingredient->notes ) ) : ?>
                                        <em>(<?php echo esc_html( $ingredient->notes ); ?>)</em>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->is_optional ) ) : ?>
                                        <span class="pxr-print-optional"><?php esc_html_e( 'optional', 'plugixa-recipe' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $ingredient->substitution ) ) : ?>
                                        <span class="pxr-print-substitution">
                                            <?php
                                            /* translators: %s: an ingredient that can be used instead. */
                                            echo esc_html( sprintf( __( 'Or use: %s', 'plugixa-recipe' ), $ingredient->substitution ) );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- Equipment -->
    <?php if ( $equipment ) : ?>
        <section class="pxr-print-equipment">
            <h2><?php esc_html_e( 'Equipment', 'plugixa-recipe' ); ?></h2>
            <ul>
                <?php foreach ( $equipment as $tool ) : ?>
                    <li><?php echo esc_html( $tool->name ?? '' ); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <!-- Instructions -->
    <?php if ( ! empty( $instruction_groups ) ) : ?>
        <section class="pxr-print-instructions">
            <h2><?php esc_html_e( 'Instructions', 'plugixa-recipe' ); ?></h2>
            <?php
            // One count through every group, as on the recipe's page.
            $step_counter = 0;
            foreach ( $instruction_groups as $group ) :
                ?>
                <?php if ( ! empty( $group->name ) ) : ?>
                    <h3><?php echo esc_html( $group->name ); ?></h3>
                <?php endif; ?>
                <?php if ( ! empty( $group->steps ) && is_array( $group->steps ) ) : ?>
                    <ol start="<?php echo esc_attr( $step_counter + 1 ); ?>">
                        <?php foreach ( $group->steps as $step ) : ?>
                            <?php ++$step_counter; ?>
                            <li>
                                <?php if ( ! empty( $step->title ) || ! empty( $step->timer_label ) ) : ?>
                                    <p class="pxr-print-step-head">
                                        <?php if ( ! empty( $step->title ) ) : ?>
                                            <strong><?php echo esc_html( $step->title ); ?></strong>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $step->timer_label ) ) : ?>
                                            <span class="pxr-print-step-time"><?php echo esc_html( $step->timer_label ); ?></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <div class="pxr-print-step-text"><?php echo wp_kses_post( $step->text ?? '' ); ?></div>
                                <?php if ( ! empty( $step->image_url ) ) : ?>
                                    <img src="<?php echo esc_url( $step->image_url ); ?>"
                                         alt="<?php
                                             /* translators: %d: step number */
                                             echo esc_attr( sprintf( __( 'Step %d', 'plugixa-recipe' ), $step_counter ) );
                                         ?>"
                                         class="pxr-print-step-image"
                                         data-pxr-print-part="images" />
                                <?php endif; ?>
                                <?php if ( ! empty( $step->tip ) ) : ?>
                                    <p class="pxr-print-step-tip" data-pxr-print-part="notes">
                                        <strong><?php esc_html_e( 'Tip:', 'plugixa-recipe' ); ?></strong>
                                        <?php echo esc_html( $step->tip ); ?>
                                    </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- Notes and tips -->
    <?php if ( '' !== $notes || '' !== $tips ) : ?>
        <section class="pxr-print-notes" data-pxr-print-part="notes">
            <?php if ( '' !== $notes ) : ?>
                <h2><?php esc_html_e( 'Notes', 'plugixa-recipe' ); ?></h2>
                <div class="pxr-print-note-text"><?php echo wp_kses_post( wpautop( $notes ) ); ?></div>
            <?php endif; ?>
            <?php if ( '' !== $tips ) : ?>
                <h2><?php esc_html_e( 'Tips', 'plugixa-recipe' ); ?></h2>
                <div class="pxr-print-note-text"><?php echo wp_kses_post( wpautop( $tips ) ); ?></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Nutrition Facts (Simplified Table) -->
    <?php if ( $nutrition ) : ?>
        <section class="pxr-print-nutrition" data-pxr-print-part="nutrition">
            <h2><?php esc_html_e( 'Nutrition Facts', 'plugixa-recipe' ); ?></h2>
            <?php if ( ! empty( $nutrition->serving_size ) ) : ?>
                <p class="pxr-print-serving-size">
                    <?php
                    /* translators: %s: serving size */
                    echo esc_html( sprintf( __( 'Serving Size: %s', 'plugixa-recipe' ), $nutrition->serving_size ) );
                    ?>
                </p>
            <?php endif; ?>

            <table class="pxr-print-nutrition-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nutrient', 'plugixa-recipe' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'plugixa-recipe' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $nutrients = array(
                        'calories'           => array( __( 'Calories', 'plugixa-recipe' ), '' ),
                        'total_fat'          => array( __( 'Total Fat', 'plugixa-recipe' ), 'g' ),
                        'saturated_fat'      => array( __( 'Saturated Fat', 'plugixa-recipe' ), 'g' ),
                        'trans_fat'          => array( __( 'Trans Fat', 'plugixa-recipe' ), 'g' ),
                        'cholesterol'        => array( __( 'Cholesterol', 'plugixa-recipe' ), 'mg' ),
                        'sodium'             => array( __( 'Sodium', 'plugixa-recipe' ), 'mg' ),
                        'total_carbohydrate' => array( __( 'Total Carbohydrate', 'plugixa-recipe' ), 'g' ),
                        'dietary_fiber'      => array( __( 'Dietary Fiber', 'plugixa-recipe' ), 'g' ),
                        'total_sugars'       => array( __( 'Total Sugars', 'plugixa-recipe' ), 'g' ),
                        'added_sugars'       => array( __( 'Added Sugars', 'plugixa-recipe' ), 'g' ),
                        'protein'            => array( __( 'Protein', 'plugixa-recipe' ), 'g' ),
                        'vitamin_d'          => array( __( 'Vitamin D', 'plugixa-recipe' ), 'mcg' ),
                        'calcium'            => array( __( 'Calcium', 'plugixa-recipe' ), 'mg' ),
                        'iron'               => array( __( 'Iron', 'plugixa-recipe' ), 'mg' ),
                        'potassium'          => array( __( 'Potassium', 'plugixa-recipe' ), 'mg' ),
                        'vitamin_a'          => array( __( 'Vitamin A', 'plugixa-recipe' ), '%' ),
                        'vitamin_c'          => array( __( 'Vitamin C', 'plugixa-recipe' ), '%' ),
                    );

                    foreach ( $nutrients as $key => $info ) :
                        $value = isset( $nutrition->$key ) ? $nutrition->$key : null;
                        if ( $value === null ) continue;
                        // A stored decimal without its padding: "290.00" is 290, "1.50" is 1.5.
                        if ( is_numeric( $value ) ) {
                            $value = rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
                        }
                    ?>
                        <tr>
                            <td><?php echo esc_html( $info[0] ); ?></td>
                            <td><?php echo esc_html( $value . $info[1] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php // A printed sheet outlives the page: an estimate says so on paper too. ?>
            <?php if ( isset( $nutrition->source ) && 'usda' === $nutrition->source ) : ?>
                <p class="pxr-print-nutrition-note">
                    <?php esc_html_e( 'Calculated from the ingredients with USDA FoodData Central data. An approximation, not a laboratory analysis.', 'plugixa-recipe' ); ?>
                </p>
            <?php endif; ?>
            <?php if ( isset( $nutrition->source ) && 'ai' === $nutrition->source ) : ?>
                <p class="pxr-print-nutrition-note">
                    <?php esc_html_e( 'Estimated from the ingredient list. Not a laboratory analysis - verify before relying on these values for medical or dietary needs.', 'plugixa-recipe' ); ?>
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="pxr-print-footer">
        <?php if ( '' !== $source || '' !== $source_url ) : ?>
            <p class="pxr-print-source">
                <?php esc_html_e( 'Source:', 'plugixa-recipe' ); ?>
                <?php // On paper a link cannot be followed, so the address is written out. ?>
                <?php echo esc_html( '' !== $source ? $source : $source_url ); ?>
                <?php if ( '' !== $source && '' !== $source_url ) : ?>
                    (<?php echo esc_html( $source_url ); ?>)
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <p>
            <?php
            echo wp_kses(
                /* translators: 1: site name, 2: site URL. */
                sprintf( __( 'Printed from <a href="%2$s">%1$s</a>', 'plugixa-recipe' ), esc_html( $site_name ), esc_url( $site_url ) ),
                array( 'a' => array( 'href' => array() ) )
            );
            ?>
            <?php if ( $recipe_url ) : ?>
                &mdash;
                <a class="pxr-print-url" href="<?php echo esc_url( $recipe_url ); ?>"><?php echo esc_html( $recipe_url ); ?></a>
            <?php endif; ?>
        </p>
    </footer>

    <?php wp_print_scripts( 'plugixa-recipe-print' ); ?>

</body>
</html>
