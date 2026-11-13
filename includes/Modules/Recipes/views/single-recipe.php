<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Single Recipe Template
 *
 * Full single recipe view for frontend display with hero section,
 * ingredients, instructions, nutrition facts, reviews, and action buttons.
 *
 * Available variables:
 * @var object $recipe          Full recipe object with all fields
 * @var array  $view_config     Decoded JSON view configuration
 * @var string $css_vars_style  Inline CSS with --pxr-* variables
 * @var string $unique_id       Unique instance ID
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

// Every part reads these variables: the recipe's fields, the view config, the app switches.
include plugixa_recipe_template( 'single/vars.php' );
?>

<div id="<?php echo esc_attr( $unique_id ); ?>"
     class="pxr-custom-single pxr:mx-auto"
     style="<?php echo esc_attr( $css_vars_style ); ?>"
     data-pxr-recipe-id="<?php echo esc_attr( $recipe_id ); ?>">

    <!-- Hero Section -->
    <header class="pxr-custom-single__hero pxr:mb-8">
        <?php if ( $image_url ) : ?>
            <!-- Two-Column Hero Grid: Image Left + Content Right -->
            <div class="pxr-custom-single__hero-grid">

                <!-- LEFT COLUMN: Image -->
                <div class="pxr-custom-single__hero-image-col">
                    <?php include plugixa_recipe_template( 'single/hero-image.php' ); ?>
                </div>

                <!-- RIGHT COLUMN: All info + ingredients -->
                <div class="pxr-custom-single__hero-content-col">
                    <?php
                    include plugixa_recipe_template( 'single/categories.php' );
                    include plugixa_recipe_template( 'single/title.php' );
                    include plugixa_recipe_template( 'single/description.php' );
                    include plugixa_recipe_template( 'single/meta.php' );
                    include plugixa_recipe_template( 'single/info-grid.php' );
                    $share_with_pinterest = true;
                    include plugixa_recipe_template( 'single/actions.php' );
                    $servings_input_prefix = 'pxr-servings-input-';
                    include plugixa_recipe_template( 'single/servings.php' );
                    $ingredients_block_class = '';
                    include plugixa_recipe_template( 'single/ingredients.php' );
                    ?>
                </div><!-- /.pxr-custom-single__hero-content-col -->

            </div><!-- /.pxr-custom-single__hero-grid -->

        <?php else : ?>
            <!-- No-image fallback: single-column layout -->
            <?php
            include plugixa_recipe_template( 'single/categories.php' );
            include plugixa_recipe_template( 'single/title.php' );
            include plugixa_recipe_template( 'single/description.php' );
            include plugixa_recipe_template( 'single/meta.php' );
            include plugixa_recipe_template( 'single/info-grid.php' );
            // Nothing to pin without the photo.
            $share_with_pinterest = false;
            include plugixa_recipe_template( 'single/actions.php' );
            $servings_input_prefix = 'pxr-servings-input-noimg-';
            include plugixa_recipe_template( 'single/servings.php' );
            $ingredients_block_class = 'pxr:mb-6';
            include plugixa_recipe_template( 'single/ingredients.php' );
            ?>
        <?php endif; ?>
    </header>

    <?php include plugixa_recipe_template( 'single/instructions.php' ); ?>

    <?php include plugixa_recipe_template( 'single/extras.php' ); ?>

    <?php
    /**
     * Fires inside a single recipe, after its method: markup the reader tools need once
     * (the cook-mode dialog, the timer dock).
     *
     * @since 1.2.0
     *
     * @param object $recipe    The presented recipe.
     * @param string $unique_id The page's id prefix.
     */
    do_action( 'plugixa_recipe_single_tools', $recipe, $unique_id );
    ?>

    <?php
    include plugixa_recipe_template( 'single/video.php' );
    include plugixa_recipe_template( 'single/gallery.php' );
    include plugixa_recipe_template( 'single/nutrition.php' );
    ?>

    <?php
    /**
     * Extra sections contributed by the premium overlay (e.g. the "Additional Details"
     * custom-field list). The free build has nothing hooked here and renders nothing.
     */
    do_action( 'plugixa_recipe_single_recipe_sections', $recipe, $unique_id );
    ?>

    <?php
    include plugixa_recipe_template( 'single/rating-overview.php' );
    include plugixa_recipe_template( 'single/reviews.php' );
    include plugixa_recipe_template( 'single/related.php' );
    ?>

</div>
