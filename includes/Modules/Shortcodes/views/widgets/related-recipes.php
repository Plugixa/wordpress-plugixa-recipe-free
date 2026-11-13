<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Related Recipes
 *
 * Card grid that reuses recipe-card.php for each item.
 *
 * @var array  $recipes     Array of enriched recipe objects
 * @var int    $columns     Number of grid columns
 * @var string $title       Widget title
 * @var string $unique_id   Unique wrapper ID
 * @var array  $view_config View configuration for recipe-card.php
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.
?>

<section id="<?php echo esc_attr( $unique_id ); ?>" class="pxr-widget pxr-widget-related">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title pxr-widget-related__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <div class="pxr-widget-related__grid pxr:grid pxr:gap-6"
         style="grid-template-columns: repeat(<?php echo absint( $columns ); ?>, 1fr);">
        <?php
        $layout = 'grid';
        foreach ( $recipes as $recipe ) :
            ?>
            <div class="pxr-widget-related__item pxr-custom-grid__item">
                <?php include plugixa_recipe_template( 'recipe-card.php' ); ?>
            </div>
        <?php endforeach; ?>
    </div>

</section>
