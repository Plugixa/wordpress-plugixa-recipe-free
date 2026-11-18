<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Archive Page Template
 *
 * Displays a category or cuisine archive with header info + recipe grid + pagination.
 *
 * @var array $data  From $GLOBALS['plugixa_recipe_archive_data']:
 *   - taxonomy      object  The category/cuisine object
 *   - type          string  'category' or 'cuisine'
 *   - recipes       array   Enriched recipe objects
 *   - page          int     Current page number
 *   - total_pages   int     Total pages
 *   - total         int     Total recipe count
 *   - per_page      int     Recipes per page
 *   - archive_base  string  URL base ('recipe-category' or 'recipe-cuisine')
 *   - view_config   array   View configuration for recipe-card.php
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

include plugixa_recipe_template( 'archive/vars.php' );

if ( ! $archive_term ) {
    return;
}
?>

<div class="pxr-archive">

    <!-- Archive Header -->
    <?php include plugixa_recipe_template( 'archive/header.php' ); ?>

    <!-- The other categories / cuisines -->
    <?php include plugixa_recipe_template( 'archive/terms.php' ); ?>

    <!-- Recipe Grid -->
    <?php if ( ! empty( $recipes ) ) : ?>
        <div class="pxr-custom-grid pxr-archive__grid">
            <?php
            $layout = 'grid';
            foreach ( $recipes as $recipe ) :
                ?>
                <div class="pxr-custom-grid__item">
                    <?php include plugixa_recipe_template( 'recipe-card.php' ); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php include plugixa_recipe_template( 'archive/pagination.php' ); ?>

    <?php else : ?>
        <div class="pxr-no-recipes">
            <?php esc_html_e( 'No recipes found in this category.', 'plugixa-recipe' ); ?>
        </div>
    <?php endif; ?>

</div>
