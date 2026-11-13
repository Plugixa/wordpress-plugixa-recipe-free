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
            <div class="pxr-widget-search__filters pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mt-2">
                <?php if ( ! empty( $categories ) ) : ?>
                    <select name="plugixa_recipe_category" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by category', 'plugixa-recipe' ); ?>">
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
                    <select name="plugixa_recipe_cuisine" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by cuisine', 'plugixa-recipe' ); ?>">
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
                    <select name="plugixa_recipe_course" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by course', 'plugixa-recipe' ); ?>">
                        <option value=""><?php esc_html_e( 'All Courses', 'plugixa-recipe' ); ?></option>
                        <?php foreach ( $courses as $recipe_course ) : ?>
                            <option value="<?php echo esc_attr( $recipe_course->slug ); ?>"
                                <?php selected( $current_course, $recipe_course->slug ); ?>>
                                <?php echo esc_html( $recipe_course->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <select name="plugixa_recipe_time" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by total time', 'plugixa-recipe' ); ?>">
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

                <select name="plugixa_recipe_difficulty" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by difficulty', 'plugixa-recipe' ); ?>">
                    <option value=""><?php esc_html_e( 'Any difficulty', 'plugixa-recipe' ); ?></option>
                    <?php foreach ( \PlugixaRecipe\Modules\Recipes\Enums::difficulty_labels() as $recipe_level => $recipe_level_label ) : ?>
                        <option value="<?php echo esc_attr( $recipe_level ); ?>" <?php selected( $current_level, $recipe_level ); ?>>
                            <?php echo esc_html( $recipe_level_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="plugixa_recipe_diet" class="pxr-widget-search__select" aria-label="<?php esc_attr_e( 'Filter by diet', 'plugixa-recipe' ); ?>">
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
    </form>

</div>
