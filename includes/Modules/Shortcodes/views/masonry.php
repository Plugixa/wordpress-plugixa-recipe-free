<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Masonry Layout Template
 *
 * Renders recipes in a CSS columns masonry layout using column-count
 * and column-gap properties. Responsive via --pxr-columns-* CSS variables.
 *
 * Available variables:
 * @var array  $recipes          Array of recipe objects
 * @var array  $view_config      Decoded JSON view configuration
 * @var array  $shortcode_config Shortcode configuration
 * @var string $css_vars_style   Inline CSS with --pxr-* variables
 * @var string $unique_id        Unique shortcode instance ID
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$show_categories  = isset( $view_config['show_category_nav'] ) ? (bool) $view_config['show_category_nav'] : false;
$show_cuisines    = isset( $view_config['show_cuisine_nav'] ) ? (bool) $view_config['show_cuisine_nav'] : false;
$show_alpha       = isset( $view_config['show_alpha_filter'] ) ? (bool) $view_config['show_alpha_filter'] : false;
$show_ingredients = isset( $view_config['show_ingredient_filter'] ) ? (bool) $view_config['show_ingredient_filter'] : false;
$show_pagination  = isset( $view_config['show_pagination'] ) ? (bool) $view_config['show_pagination'] : false;
$current_page    = isset( $shortcode_config['page'] ) ? absint( $shortcode_config['page'] ) : 1;
$total_pages     = isset( $shortcode_config['total_pages'] ) ? absint( $shortcode_config['total_pages'] ) : 1;
$column_gap      = isset( $view_config['column_gap'] ) ? absint( $view_config['column_gap'] ) : 24;

// Collect unique categories.
$all_categories = array();
if ( $show_categories ) {
    foreach ( $recipes as $recipe ) {
        if ( ! empty( $recipe->categories ) && is_array( $recipe->categories ) ) {
            foreach ( $recipe->categories as $recipe_cat ) {
                $cat_slug = is_array( $recipe_cat ) ? ( $recipe_cat['slug'] ?? sanitize_title( $recipe_cat['name'] ?? '' ) ) : sanitize_title( $recipe_cat );
                $cat_name = is_array( $recipe_cat ) ? ( $recipe_cat['name'] ?? '' ) : $recipe_cat;
                if ( ! isset( $all_categories[ $cat_slug ] ) ) {
                    $all_categories[ $cat_slug ] = $cat_name;
                }
            }
        }
    }
}

// Collect unique cuisines.
$all_cuisines = array();
if ( $show_cuisines ) {
    foreach ( $recipes as $recipe ) {
        if ( ! empty( $recipe->cuisines ) && is_array( $recipe->cuisines ) ) {
            foreach ( $recipe->cuisines as $cui ) {
                $cui_slug = is_array( $cui ) ? ( $cui['slug'] ?? sanitize_title( $cui['name'] ?? '' ) ) : sanitize_title( $cui );
                $cui_name = is_array( $cui ) ? ( $cui['name'] ?? '' ) : $cui;
                if ( ! isset( $all_cuisines[ $cui_slug ] ) ) {
                    $all_cuisines[ $cui_slug ] = $cui_name;
                }
            }
        }
    }
}

// Collect first letters for alpha filter.
$active_letters = array();
if ( $show_alpha ) {
    foreach ( $recipes as $recipe ) {
        $recipe_title = isset( $recipe->title ) ? trim( $recipe->title ) : '';
        if ( $recipe_title !== '' ) {
            $letter = strtoupper( mb_substr( $recipe_title, 0, 1 ) );
            if ( ctype_alpha( $letter ) ) {
                $active_letters[ $letter ] = true;
            }
        }
    }
}
?>

<div class="pxr-custom-container pxr-custom-masonry-container pxr:mx-auto"
     data-pxr-layout="masonry"
     aria-label="<?php esc_attr_e( 'Recipe collection', 'plugixa-recipe' ); ?>">

    <?php if ( $show_categories && ! empty( $all_categories ) ) : ?>
        <!-- Category Navigation -->
        <nav class="pxr-custom-category-nav pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mb-6 pxr:justify-center"
             aria-label="<?php esc_attr_e( 'Filter by category', 'plugixa-recipe' ); ?>">
            <button type="button"
                    class="pxr-custom-category-btn pxr-custom-category-btn--active pxr:px-4 pxr:py-2 pxr:rounded-full pxr:text-sm pxr:font-medium pxr:border pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                    data-pxr-category="all"
                    aria-pressed="true">
                <?php esc_html_e( 'All', 'plugixa-recipe' ); ?>
            </button>
            <?php foreach ( $all_categories as $slug => $label ) : ?>
                <button type="button"
                        class="pxr-custom-category-btn pxr:px-4 pxr:py-2 pxr:rounded-full pxr:text-sm pxr:font-medium pxr:border pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                        data-pxr-category="<?php echo esc_attr( $slug ); ?>"
                        aria-pressed="false">
                    <?php echo esc_html( $label ); ?>
                </button>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ( $show_cuisines && ! empty( $all_cuisines ) ) : ?>
        <!-- Cuisine Navigation -->
        <nav class="pxr-custom-cuisine-nav pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mb-6 pxr:justify-center"
             aria-label="<?php esc_attr_e( 'Filter by cuisine', 'plugixa-recipe' ); ?>">
            <button type="button"
                    class="pxr-custom-cuisine-btn pxr-custom-cuisine-btn--active pxr:px-4 pxr:py-2 pxr:rounded-full pxr:text-sm pxr:font-medium pxr:border pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                    data-pxr-cuisine="all"
                    aria-pressed="true">
                <?php esc_html_e( 'All', 'plugixa-recipe' ); ?>
            </button>
            <?php foreach ( $all_cuisines as $slug => $label ) : ?>
                <button type="button"
                        class="pxr-custom-cuisine-btn pxr:px-4 pxr:py-2 pxr:rounded-full pxr:text-sm pxr:font-medium pxr:border pxr:cursor-pointer pxr:transition-colors pxr:duration-200"
                        data-pxr-cuisine="<?php echo esc_attr( $slug ); ?>"
                        aria-pressed="false">
                    <?php echo esc_html( $label ); ?>
                </button>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ( $show_alpha ) : ?>
        <!-- Alphabetical Filter -->
        <nav class="pxr-custom-alpha-nav pxr:flex pxr:flex-wrap pxr:gap-1 pxr:mb-6 pxr:justify-center"
             aria-label="<?php esc_attr_e( 'Filter by letter', 'plugixa-recipe' ); ?>">
            <button type="button"
                    class="pxr-custom-alpha-btn pxr-custom-alpha-btn--active pxr:w-9 pxr:h-9 pxr:rounded-full pxr:text-sm pxr:font-medium pxr:border pxr:cursor-pointer pxr:transition-colors pxr:duration-200 pxr:flex pxr:items-center pxr:justify-center"
                    data-pxr-letter="all"
                    aria-pressed="true">
                <?php esc_html_e( 'All', 'plugixa-recipe' ); ?>
            </button>
            <?php foreach ( range( 'A', 'Z' ) as $letter ) :
                $has_recipes = isset( $active_letters[ $letter ] );
            ?>
                <button type="button"
                        class="pxr-custom-alpha-btn<?php echo $has_recipes ? '' : ' pxr-custom-alpha-btn--disabled'; ?> pxr:w-9 pxr:h-9 pxr:rounded-full pxr:text-sm pxr:font-medium pxr:border pxr:transition-colors pxr:duration-200 pxr:flex pxr:items-center pxr:justify-center<?php echo $has_recipes ? ' pxr:cursor-pointer' : ' pxr:cursor-default'; ?>"
                        data-pxr-letter="<?php echo esc_attr( $letter ); ?>"
                        aria-pressed="false"
                        <?php echo $has_recipes ? '' : ' disabled'; ?>>
                    <?php echo esc_html( $letter ); ?>
                </button>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ( $show_ingredients ) : ?>
        <!-- Ingredient Search -->
        <div class="pxr-custom-ingredient-filter pxr:mb-6 pxr:flex pxr:justify-center">
            <div class="pxr-custom-ingredient-filter__wrapper pxr:relative pxr:w-full" style="max-width: 400px;">
                <svg class="pxr-custom-ingredient-filter__icon pxr:absolute pxr:left-3 pxr:top-1/2 pxr:w-4 pxr:h-4 pxr:opacity-40" style="transform: translateY(-50%);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text"
                       class="pxr-custom-ingredient-search pxr:w-full pxr:pl-10 pxr:pr-4 pxr:py-2.5 pxr:rounded-full pxr:border pxr:text-sm pxr:transition-colors pxr:duration-200"
                       placeholder="<?php esc_attr_e( 'Filter by ingredient...', 'plugixa-recipe' ); ?>"
                       aria-label="<?php esc_attr_e( 'Filter recipes by ingredient', 'plugixa-recipe' ); ?>" />
            </div>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $recipes ) ) : ?>
        <!-- Masonry Grid -->
        <div class="pxr-custom-masonry"
             role="list"
             style="column-count: var(--pxr-columns-desktop, 3); column-gap: <?php echo esc_attr( $column_gap ); ?>px;">
            <?php foreach ( $recipes as $recipe ) :
                $cat_slugs = array();
                if ( ! empty( $recipe->categories ) && is_array( $recipe->categories ) ) {
                    $cat_slugs = array_map( function( $recipe_cat ) {
                        return is_array( $recipe_cat ) ? ( $recipe_cat['slug'] ?? sanitize_title( $recipe_cat['name'] ?? '' ) ) : sanitize_title( $recipe_cat );
                    }, $recipe->categories );
                }
                $cui_slugs = array();
                if ( ! empty( $recipe->cuisines ) && is_array( $recipe->cuisines ) ) {
                    $cui_slugs = array_map( function( $cui ) {
                        return is_array( $cui ) ? ( $cui['slug'] ?? sanitize_title( $cui['name'] ?? '' ) ) : sanitize_title( $cui );
                    }, $recipe->cuisines );
                }
            ?>
                <div class="pxr-custom-masonry__item pxr:mb-6 pxr:break-inside-avoid pxr:rounded-lg pxr:overflow-hidden pxr:shadow-sm pxr:transition-shadow pxr:duration-200 hover:pxr:shadow-md"
                     role="listitem"
                     <?php if ( ! empty( $cat_slugs ) ) : ?>
                         data-pxr-categories="<?php echo esc_attr( implode( ',', $cat_slugs ) ); ?>"
                     <?php endif; ?>
                     <?php if ( ! empty( $cui_slugs ) ) : ?>
                         data-pxr-cuisines="<?php echo esc_attr( implode( ',', $cui_slugs ) ); ?>"
                     <?php endif; ?>
                     <?php if ( ! empty( $recipe->ingredient_names ) ) : ?>
                         data-pxr-ingredients="<?php echo esc_attr( $recipe->ingredient_names ); ?>"
                     <?php endif; ?>
                     data-pxr-title="<?php echo esc_attr( $recipe->title ?? '' ); ?>">
                    <?php
                    $layout = 'masonry';
                    include plugixa_recipe_template( 'recipe-card.php' );
                    ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else : ?>
        <p class="pxr-custom-empty pxr:text-center pxr:py-12 pxr:opacity-60">
            <?php esc_html_e( 'No recipes found.', 'plugixa-recipe' ); ?>
        </p>
    <?php endif; ?>

    <?php if ( $show_pagination && $total_pages > 1 ) : ?>
        <!-- Pagination -->
        <nav class="pxr-custom-pagination pxr:flex pxr:justify-center pxr:items-center pxr:gap-2 pxr:mt-8 pxr:py-4"
             data-config-key="<?php echo esc_attr( $config_key ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'plugixa_recipe_frontend' ) ); ?>"
             data-total-pages="<?php echo esc_attr( $total_pages ); ?>"
             aria-label="<?php esc_attr_e( 'Recipe pagination', 'plugixa-recipe' ); ?>">

            <?php if ( $current_page > 1 ) : ?>
                <a href="#"
                   class="pxr-custom-pagination__btn pxr-custom-pagination__btn--prev pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:border pxr:transition-colors pxr:duration-200"
                   data-pxr-page="<?php echo esc_attr( $current_page - 1 ); ?>"
                   aria-label="<?php esc_attr_e( 'Previous page', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-5 pxr:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
            <?php endif; ?>

            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                <?php if ( $i === $current_page ) : ?>
                    <span class="pxr-custom-pagination__btn pxr-custom-pagination__btn--current pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:font-semibold"
                          aria-current="page">
                        <?php echo esc_html( $i ); ?>
                    </span>
                <?php else : ?>
                    <a href="#"
                       class="pxr-custom-pagination__btn pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:border pxr:transition-colors pxr:duration-200"
                       data-pxr-page="<?php echo esc_attr( $i ); ?>">
                        <?php echo esc_html( $i ); ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ( $current_page < $total_pages ) : ?>
                <a href="#"
                   class="pxr-custom-pagination__btn pxr-custom-pagination__btn--next pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:border pxr:transition-colors pxr:duration-200"
                   data-pxr-page="<?php echo esc_attr( $current_page + 1 ); ?>"
                   aria-label="<?php esc_attr_e( 'Next page', 'plugixa-recipe' ); ?>">
                    <svg class="pxr:w-5 pxr:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            <?php endif; ?>

        </nav>
    <?php endif; ?>

</div>
