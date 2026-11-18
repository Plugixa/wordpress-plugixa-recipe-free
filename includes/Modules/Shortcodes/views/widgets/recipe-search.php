<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Recipe Search
 *
 * Search form with optional category/cuisine filters.
 *
 * @var string $placeholder Search input placeholder
 * @var string $style       'simple' or 'expanded'
 * @var string $action      Form action URL (empty = current page)
 * @var string $title       Widget title
 * @var array  $categories  Category objects for expanded mode
 * @var array  $cuisines    Cuisine objects for expanded mode
 * @var array  $courses     Course objects for expanded mode
 * @var array  $current     The visitor's choices (Frontend\VisitorFilters::read())
 * @var array  $active      The choices in force, each [label, url]: the url is this page without it
 * @var string $clear_url   This page with no choice at all
 * @var string $unique_id   Unique wrapper ID
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

// What the visitor already chose: read and narrowed by VisitorFilters, the reading the
// listing itself applies, so the form can never show a choice the list ignored.
$current          = isset( $current ) && is_array( $current ) ? $current : array();
$current_search   = (string) ( $current['search'] ?? '' );
$current_category = (string) ( $current['category'] ?? '' );
$current_cuisine  = (string) ( $current['cuisine'] ?? '' );
$current_course   = (string) ( $current['course'] ?? '' );
$current_level    = (string) ( $current['difficulty'] ?? '' );
$current_time     = (int) ( $current['max_time'] ?? 0 );
$current_diet     = (string) ( $current['diet'] ?? '' );
$courses          = isset( $courses ) ? $courses : array();
$active           = isset( $active ) && is_array( $active ) ? $active : array();
$clear_url        = isset( $clear_url ) ? (string) $clear_url : '';

// A filter in force is marked on its own control as well as by its chip.
$select_class = static function ( $chosen ): string {
    return 'pxr-widget-search__select' . ( $chosen ? ' pxr-widget-search__select--set' : '' );
};
?>

<div id="<?php echo esc_attr( $unique_id ); ?>"
     class="pxr-widget pxr-widget-search pxr-widget-search--<?php echo esc_attr( $style ); ?>">

    <?php if ( $title ) : ?>
        <h3 class="pxr-widget__title"><?php echo esc_html( $title ); ?></h3>
    <?php endif; ?>

    <form class="pxr-widget-search__form" action="<?php echo $action ? esc_url( $action ) : ''; ?>" method="get" role="search">
        <div class="pxr-widget-search__input-wrap">
            <svg class="pxr-widget-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text"
                   name="plugixa_recipe_search"
                   value="<?php echo esc_attr( $current_search ); ?>"
                   placeholder="<?php echo esc_attr( $placeholder ); ?>"
                   class="pxr-widget-search__input"
                   aria-label="<?php esc_attr_e( 'Search recipes', 'plugixa-recipe' ); ?>" />
        </div>

        <?php if ( $style === 'expanded' ) : ?>
            <div class="pxr-widget-search__filters">
                <?php if ( ! empty( $categories ) ) : ?>
                    <select name="plugixa_recipe_category" class="<?php echo esc_attr( $select_class( $current_category ) ); ?>" aria-label="<?php esc_attr_e( 'Filter by category', 'plugixa-recipe' ); ?>">
                        <option value=""><?php esc_html_e( 'All Categories', 'plugixa-recipe' ); ?></option>
                        <?php foreach ( $categories as $recipe_cat ) : ?>
                            <option value="<?php echo esc_attr( $recipe_cat->slug ); ?>"
                                <?php selected( $current_category, $recipe_cat->slug ); ?>>
                                <?php echo esc_html( $recipe_cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ( ! empty( $cuisines ) ) : ?>
                    <select name="plugixa_recipe_cuisine" class="<?php echo esc_attr( $select_class( $current_cuisine ) ); ?>" aria-label="<?php esc_attr_e( 'Filter by cuisine', 'plugixa-recipe' ); ?>">
                        <option value=""><?php esc_html_e( 'All Cuisines', 'plugixa-recipe' ); ?></option>
                        <?php foreach ( $cuisines as $cuisine ) : ?>
                            <option value="<?php echo esc_attr( $cuisine->slug ); ?>"
                                <?php selected( $current_cuisine, $cuisine->slug ); ?>>
                                <?php echo esc_html( $cuisine->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ( ! empty( $courses ) ) : ?>
                    <select name="plugixa_recipe_course" class="<?php echo esc_attr( $select_class( $current_course ) ); ?>" aria-label="<?php esc_attr_e( 'Filter by course', 'plugixa-recipe' ); ?>">
                        <option value=""><?php esc_html_e( 'All Courses', 'plugixa-recipe' ); ?></option>
                        <?php foreach ( $courses as $recipe_course ) : ?>
                            <option value="<?php echo esc_attr( $recipe_course->slug ); ?>"
                                <?php selected( $current_course, $recipe_course->slug ); ?>>
                                <?php echo esc_html( $recipe_course->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <select name="plugixa_recipe_time" class="<?php echo esc_attr( $select_class( $current_time ) ); ?>" aria-label="<?php esc_attr_e( 'Filter by total time', 'plugixa-recipe' ); ?>">
                    <option value=""><?php esc_html_e( 'Any time', 'plugixa-recipe' ); ?></option>
                    <?php foreach ( \PlugixaRecipe\Modules\Shortcodes\Frontend\VisitorFilters::TIMES as $recipe_minutes ) : ?>
                        <option value="<?php echo esc_attr( $recipe_minutes ); ?>" <?php selected( $current_time, $recipe_minutes ); ?>>
                            <?php
                            /* translators: %s: a length of time, e.g. "30 mins". */
                            echo esc_html( sprintf( __( 'Ready in %s', 'plugixa-recipe' ), \PlugixaRecipe\Modules\Recipes\Services\RecipePresenter::duration_label( $recipe_minutes * MINUTE_IN_SECONDS ) ) );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="plugixa_recipe_difficulty" class="<?php echo esc_attr( $select_class( $current_level ) ); ?>" aria-label="<?php esc_attr_e( 'Filter by difficulty', 'plugixa-recipe' ); ?>">
                    <option value=""><?php esc_html_e( 'Any difficulty', 'plugixa-recipe' ); ?></option>
                    <?php foreach ( \PlugixaRecipe\Modules\Recipes\Enums::difficulty_labels() as $recipe_level => $recipe_level_label ) : ?>
                        <option value="<?php echo esc_attr( $recipe_level ); ?>" <?php selected( $current_level, $recipe_level ); ?>>
                            <?php echo esc_html( $recipe_level_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="plugixa_recipe_diet" class="<?php echo esc_attr( $select_class( $current_diet ) ); ?>" aria-label="<?php esc_attr_e( 'Filter by diet', 'plugixa-recipe' ); ?>">
                    <option value=""><?php esc_html_e( 'Any diet', 'plugixa-recipe' ); ?></option>
                    <?php foreach ( \PlugixaRecipe\Modules\Recipes\DietFlags::labels() as $recipe_diet => $recipe_diet_label ) : ?>
                        <option value="<?php echo esc_attr( $recipe_diet ); ?>" <?php selected( $current_diet, $recipe_diet ); ?>>
                            <?php echo esc_html( $recipe_diet_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <button type="submit" class="pxr-widget-search__submit">
            <?php esc_html_e( 'Search', 'plugixa-recipe' ); ?>
        </button>

        <?php if ( $active ) : ?>
            <?php // Inside the form, so the form and what it is filtering by read as one block. ?>
            <ul class="pxr-widget-search__active" aria-label="<?php esc_attr_e( 'Filters in use', 'plugixa-recipe' ); ?>">
                <?php foreach ( $active as $recipe_filter ) : ?>
                    <li>
                        <a class="pxr-widget-search__chip" href="<?php echo esc_url( $recipe_filter['url'] ); ?>" rel="nofollow">
                            <span class="pxr-widget-search__chip-label"><?php echo esc_html( $recipe_filter['label'] ); ?></span>
                            <span class="screen-reader-text"><?php esc_html_e( '(remove this filter)', 'plugixa-recipe' ); ?></span>
                            <svg class="pxr-widget-search__chip-x" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                                <line x1="6" y1="6" x2="18" y2="18"/>
                                <line x1="18" y1="6" x2="6" y2="18"/>
                            </svg>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if ( count( $active ) > 1 && '' !== $clear_url ) : ?>
                    <li>
                        <a class="pxr-widget-search__clear" href="<?php echo esc_url( $clear_url ); ?>" rel="nofollow"><?php esc_html_e( 'Clear all', 'plugixa-recipe' ); ?></a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </form>

</div>
