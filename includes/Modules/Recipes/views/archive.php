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

$data         = $GLOBALS['plugixa_recipe_archive_data'] ?? [];
$archive_term     = $data['taxonomy'] ?? null;
$archive_type         = $data['type'] ?? 'category';
$recipes      = $data['recipes'] ?? [];
$archive_page         = $data['page'] ?? 1;
$total_pages  = $data['total_pages'] ?? 1;
$total        = $data['total'] ?? 0;
$archive_base = $data['archive_base'] ?? 'recipe-category';
$view_config  = $data['view_config'] ?? [];

if ( ! $archive_term ) {
    return;
}

$type_label = $archive_type === 'category'
    ? __( 'Category', 'plugixa-recipe' )
    : __( 'Cuisine', 'plugixa-recipe' );

$image_url = '';
if ( ! empty( $archive_term->image_id ) ) {
    $image_url = wp_get_attachment_image_url( (int) $archive_term->image_id, 'large' ) ?: '';
}
?>

<div class="pxr-archive">

    <!-- Archive Header -->
    <header class="pxr-archive__header">
        <?php if ( $image_url ) : ?>
            <div class="pxr-archive__hero">
                <img src="<?php echo esc_url( $image_url ); ?>"
                     alt="<?php echo esc_attr( $archive_term->name ); ?>"
                     class="pxr-archive__hero-image"
                     loading="lazy" />
            </div>
        <?php endif; ?>

        <div class="pxr-archive__header-content">
            <span class="pxr-archive__type-label"><?php echo esc_html( $type_label ); ?></span>
            <h1 class="pxr-archive__title"><?php echo esc_html( $archive_term->name ); ?></h1>

            <?php if ( ! empty( $archive_term->description ) ) : ?>
                <p class="pxr-archive__description"><?php echo wp_kses_post( $archive_term->description ); ?></p>
            <?php endif; ?>

            <span class="pxr-archive__count">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of recipes. */
                        _n( '%d Recipe', '%d Recipes', $total, 'plugixa-recipe' ),
                        $total
                    )
                );
                ?>
            </span>
        </div>
    </header>

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
        <?php if ( $total_pages > 1 ) : ?>
            <nav class="pxr-archive__pagination" aria-label="<?php esc_attr_e( 'Archive pagination', 'plugixa-recipe' ); ?>">
                <?php
                $base_url = home_url( "/{$archive_base}/" . rawurlencode( $archive_term->slug ) );

                // Previous
                if ( $archive_page > 1 ) :
                    $prev_url = $archive_page === 2 ? $base_url . '/' : $base_url . '/page/' . ( $archive_page - 1 ) . '/';
                    ?>
                    <a href="<?php echo esc_url( $prev_url ); ?>" class="pxr-archive__pagination-link pxr-archive__pagination-link--prev">
                        &laquo; <?php esc_html_e( 'Previous', 'plugixa-recipe' ); ?>
                    </a>
                <?php endif; ?>

                <?php
                // Page numbers
                $range = 2;
                $start = max( 1, $archive_page - $range );
                $end   = min( $total_pages, $archive_page + $range );

                if ( $start > 1 ) : ?>
                    <a href="<?php echo esc_url( $base_url . '/' ); ?>" class="pxr-archive__pagination-link">1</a>
                    <?php if ( $start > 2 ) : ?>
                        <span class="pxr-archive__pagination-dots">&hellip;</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ( $i = $start; $i <= $end; $i++ ) :
                    $page_url = $i === 1 ? $base_url . '/' : $base_url . '/page/' . $i . '/';
                    ?>
                    <?php if ( $i === $archive_page ) : ?>
                        <span class="pxr-archive__pagination-link pxr-archive__pagination-link--current"><?php echo absint( $i ); ?></span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( $page_url ); ?>" class="pxr-archive__pagination-link"><?php echo absint( $i ); ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ( $end < $total_pages ) : ?>
                    <?php if ( $end < $total_pages - 1 ) : ?>
                        <span class="pxr-archive__pagination-dots">&hellip;</span>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $base_url . '/page/' . $total_pages . '/' ); ?>" class="pxr-archive__pagination-link"><?php echo absint( $total_pages ); ?></a>
                <?php endif; ?>

                <?php
                // Next
                if ( $archive_page < $total_pages ) :
                    $next_url = $base_url . '/page/' . ( $archive_page + 1 ) . '/';
                    ?>
                    <a href="<?php echo esc_url( $next_url ); ?>" class="pxr-archive__pagination-link pxr-archive__pagination-link--next">
                        <?php esc_html_e( 'Next', 'plugixa-recipe' ); ?> &raquo;
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    <?php else : ?>
        <div class="pxr-no-recipes">
            <?php esc_html_e( 'No recipes found in this category.', 'plugixa-recipe' ); ?>
        </div>
    <?php endif; ?>

</div>
