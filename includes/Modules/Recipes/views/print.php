<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Print Template
 *
 * Print-optimized full-page recipe layout. No navigation, no ads,
 * clean typography with @media print optimizations.
 *
 * Available variables:
 * @var object $recipe          Full recipe object with all fields
 * @var array  $view_config     Decoded JSON view configuration
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$recipe_title              = isset( $recipe->title ) ? $recipe->title : '';
$description        = isset( $recipe->description ) ? $recipe->description : '';
$image_url          = isset( $recipe->image_url ) ? $recipe->image_url : '';
$author_name        = isset( $recipe->author_name ) ? $recipe->author_name : '';
$source_url         = isset( $recipe->permalink ) ? $recipe->permalink : '';
$prep_time          = isset( $recipe->prep_time ) ? absint( $recipe->prep_time ) : 0;
$cook_time          = isset( $recipe->cook_time ) ? absint( $recipe->cook_time ) : 0;
$total_time         = $prep_time + $cook_time;
$servings           = isset( $recipe->servings ) ? absint( $recipe->servings ) : 0;
$difficulty         = isset( $recipe->difficulty ) ? $recipe->difficulty : '';
$cuisine            = isset( $recipe->cuisine ) ? $recipe->cuisine : '';
$ingredient_groups  = isset( $recipe->ingredient_groups ) && is_array( $recipe->ingredient_groups ) ? $recipe->ingredient_groups : array();
$instruction_groups = isset( $recipe->instruction_groups ) && is_array( $recipe->instruction_groups ) ? $recipe->instruction_groups : array();
$nutrition          = isset( $recipe->nutrition ) ? $recipe->nutrition : null;
$site_name          = get_bloginfo( 'name' );
$site_url           = home_url( '/' );

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
<body>

    <!-- Print Button -->
    <div class="pxr-print-action">
        <button type="button" onclick="window.print();" aria-label="<?php esc_attr_e( 'Print this recipe', 'plugixa-recipe' ); ?>">
            <?php esc_html_e( 'Print Recipe', 'plugixa-recipe' ); ?>
        </button>
    </div>

    <!-- Header -->
    <header class="pxr-print-header">
        <h1><?php echo esc_html( $recipe_title ); ?></h1>

        <?php if ( $description ) : ?>
            <p class="pxr-print-description"><?php echo esc_html( wp_strip_all_tags( $description ) ); ?></p>
        <?php endif; ?>

        <?php if ( $author_name ) : ?>
            <p style="font-size: 13px; color: #666;">
                <?php
                /* translators: %s: author name */
                echo esc_html( sprintf( __( 'By %s', 'plugixa-recipe' ), $author_name ) );
                ?>
            </p>
        <?php endif; ?>

        <!-- Meta -->
        <div class="pxr-print-meta" style="margin-top: 12px;">
            <?php if ( $prep_time ) : ?>
                <div class="pxr-print-meta-item">
                    <dt><?php esc_html_e( 'Prep:', 'plugixa-recipe' ); ?></dt>
                    <dd><?php echo esc_html( plugixa_recipe_format_time( $prep_time ) ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $cook_time ) : ?>
                <div class="pxr-print-meta-item">
                    <dt><?php esc_html_e( 'Cook:', 'plugixa-recipe' ); ?></dt>
                    <dd><?php echo esc_html( plugixa_recipe_format_time( $cook_time ) ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $total_time ) : ?>
                <div class="pxr-print-meta-item">
                    <dt><?php esc_html_e( 'Total:', 'plugixa-recipe' ); ?></dt>
                    <dd><?php echo esc_html( plugixa_recipe_format_time( $total_time ) ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $servings ) : ?>
                <div class="pxr-print-meta-item">
                    <dt><?php esc_html_e( 'Servings:', 'plugixa-recipe' ); ?></dt>
                    <dd><?php echo esc_html( $servings ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $difficulty ) : ?>
                <div class="pxr-print-meta-item">
                    <dt><?php esc_html_e( 'Difficulty:', 'plugixa-recipe' ); ?></dt>
                    <dd><?php echo esc_html( $difficulty ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $cuisine ) : ?>
                <div class="pxr-print-meta-item">
                    <dt><?php esc_html_e( 'Cuisine:', 'plugixa-recipe' ); ?></dt>
                    <dd><?php echo esc_html( $cuisine ); ?></dd>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Image -->
    <?php if ( $image_url ) : ?>
        <img src="<?php echo esc_url( $image_url ); ?>"
             alt="<?php echo esc_attr( $recipe_title ); ?>"
             class="pxr-print-image" />
    <?php endif; ?>

    <!-- Ingredients -->
    <?php if ( ! empty( $ingredient_groups ) ) : ?>
        <section class="pxr-print-ingredients">
            <h2><?php esc_html_e( 'Ingredients', 'plugixa-recipe' ); ?></h2>
            <?php foreach ( $ingredient_groups as $group ) : ?>
                <?php if ( ! empty( $group->name ) ) : ?>
                    <h3><?php echo esc_html( $group->name ); ?></h3>
                <?php endif; ?>
                <?php if ( ! empty( $group->items ) && is_array( $group->items ) ) : ?>
                    <ul>
                        <?php foreach ( $group->items as $ingredient ) : ?>
                            <li>
                                <?php if ( ! empty( $ingredient->amount ) ) : ?>
                                    <strong><?php echo esc_html( $ingredient->amount ); ?></strong>
                                <?php endif; ?>
                                <?php if ( ! empty( $ingredient->unit ) ) : ?>
                                    <?php echo esc_html( $ingredient->unit ); ?>
                                <?php endif; ?>
                                <?php echo esc_html( $ingredient->name ?? $ingredient->text ?? '' ); ?>
                                <?php if ( ! empty( $ingredient->notes ) ) : ?>
                                    <em>(<?php echo esc_html( $ingredient->notes ); ?>)</em>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- Instructions -->
    <?php if ( ! empty( $instruction_groups ) ) : ?>
        <section class="pxr-print-instructions">
            <h2><?php esc_html_e( 'Instructions', 'plugixa-recipe' ); ?></h2>
            <?php foreach ( $instruction_groups as $group ) : ?>
                <?php if ( ! empty( $group->name ) ) : ?>
                    <h3><?php echo esc_html( $group->name ); ?></h3>
                <?php endif; ?>
                <?php if ( ! empty( $group->steps ) && is_array( $group->steps ) ) : ?>
                    <ol>
                        <?php foreach ( $group->steps as $step ) : ?>
                            <li>
                                <?php echo esc_html( wp_strip_all_tags( $step->text ?? '' ) ); ?>
                                <?php if ( ! empty( $step->tip ) ) : ?>
                                    <br /><em><?php esc_html_e( 'Tip:', 'plugixa-recipe' ); ?> <?php echo esc_html( $step->tip ); ?></em>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- Nutrition Facts (Simplified Table) -->
    <?php if ( $nutrition ) : ?>
        <section class="pxr-print-nutrition">
            <h2><?php esc_html_e( 'Nutrition Facts', 'plugixa-recipe' ); ?></h2>
            <?php if ( ! empty( $nutrition->serving_size ) ) : ?>
                <p style="font-size: 13px; margin-bottom: 8px;">
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
                    ?>
                        <tr>
                            <td><?php echo esc_html( $info[0] ); ?></td>
                            <td><?php echo esc_html( $value . $info[1] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="pxr-print-footer">
        <p>
            <?php
            echo wp_kses(
                /* translators: 1: site name, 2: site URL. */
                sprintf( __( 'Printed from <a href="%2$s">%1$s</a>', 'plugixa-recipe' ), esc_html( $site_name ), esc_url( $site_url ) ),
                array( 'a' => array( 'href' => array() ) )
            );
            ?>
            <?php if ( $source_url ) : ?>
                &mdash;
                <a href="<?php echo esc_url( $source_url ); ?>"><?php echo esc_html( $source_url ); ?></a>
            <?php endif; ?>
        </p>
    </footer>

    <?php wp_print_scripts( 'plugixa-recipe-print' ); ?>

</body>
</html>
