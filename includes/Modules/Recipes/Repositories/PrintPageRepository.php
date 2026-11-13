<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the print view: one page render, six small keyed reads, deliberately uncached (the print URL is not a hot path). Every query is a constant literal with table names bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the print view runs (Frontend\PrintPage): one published recipe and its child
 * rows.
 *
 * Deliberately uncached - the print URL is not a hot path - and read-only, so nothing here
 * touches the cache at all. `status = 'published'` is a hard literal and nothing gates it.
 */
final class PrintPageRepository extends Repository {

    /** Nothing is cached or flushed here; the recipe is what every read is about. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * Get a single published recipe by ID.
     */
    public function find_published(int $id): ?array {
        global $wpdb;
        $table = $this->table('recipes');

        // Column list = Columns::recipe_public(''), written out so the string handed to
        // prepare() is a constant literal. RestSecurityTest asserts the two stay in step.
        $recipe = $wpdb->get_row($wpdb->prepare(
            'SELECT id, language_code, translation_group, title, slug,
                description, excerpt, notes, tips, image_id,
                gallery, tags, equipment, video_url, servings,
                servings_unit, yield_amount, yield_unit, prep_time, cook_time,
                rest_time, total_time, difficulty, cost, season,
                is_featured, is_editor_pick, display_order, source, source_url,
                author_name, view_count, rating_average, rating_count, like_count,
                is_vegetarian, is_vegan, is_gluten_free, is_dairy_free, is_keto,
                is_low_carb, is_nut_free, is_paleo, is_sugar_free, is_halal,
                is_kosher, published_at, created_at
              FROM %i
              WHERE id = %d AND status = \'published\'',
            $table,
            $id
        ), ARRAY_A);

        return $recipe ?: null;
    }

    /**
     * Get recipe ingredients.
     */
    public function ingredients(int $recipe_id): array {
        global $wpdb;
        $table = $this->table('ingredients');

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, group_name, group_order, sort_order,
                quantity, unit, name, notes, link_url
              FROM %i
              WHERE recipe_id = %d
              ORDER BY group_order ASC, sort_order ASC',
            $table,
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recipe instructions.
     */
    public function instructions(int $recipe_id): array {
        global $wpdb;
        $table = $this->table('instructions');

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, group_name, group_order, sort_order,
                title, instruction_text, image_id, video_url, duration
              FROM %i
              WHERE recipe_id = %d
              ORDER BY group_order ASC, sort_order ASC',
            $table,
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recipe nutrition data.
     */
    public function nutrition(int $recipe_id): ?array {
        global $wpdb;
        $table = $this->table('nutrition');

        $result = $wpdb->get_row($wpdb->prepare(
            'SELECT id, recipe_id, serving_size, calories, total_fat,
                saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                calcium, iron, potassium, source
              FROM %i
              WHERE recipe_id = %d',
            $table,
            $recipe_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Get recipe categories.
     *
     * Nothing calls this: the print view never read a recipe's categories (the page's own
     * get_categories() had no caller either). Kept so the statement is not lost in the move.
     */
    public function categories(int $recipe_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.name, c.slug FROM %i c
             INNER JOIN %i rc ON c.id = rc.category_id
             WHERE rc.recipe_id = %d',
            $this->table('categories'),
            $this->table('recipe_categories'),
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recipe cuisines.
     */
    public function cuisines(int $recipe_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT cu.name, cu.slug FROM %i cu
             INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
             WHERE rcu.recipe_id = %d',
            $this->table('cuisines'),
            $this->table('recipe_cuisines'),
            $recipe_id
        ), ARRAY_A) ?: [];
    }
}
