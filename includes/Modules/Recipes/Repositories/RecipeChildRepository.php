<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the admin recipe CRUD. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write, bulk and duplicate paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The rows a recipe SAVE rewrites besides the recipe row itself: the three taxonomy pivots,
 * the ingredients, the instructions and the nutrition row - and the statements that copy all
 * six when a recipe is duplicated.
 *
 * This is the recipe editor's own child-row SQL. It is not the Ingredients / Instructions
 * routes' (IngredientRepository, InstructionRepository), whose statements differ, and it does
 * not call them.
 *
 * Nothing here reads through the cache and nothing here invalidates it. Every method is one
 * step of a save or a duplicate that RecipeService orchestrates, with a hook fired after the
 * last of them; RecipeRepository::created() / updated() / duplicated() are the invalidations
 * that close it, which is where the flush has always sat.
 */
final class RecipeChildRepository extends Repository {

    /** The entities a save reaches. Flushed by RecipeRepository, never from here. */
    protected function entities(): array {
        return ['recipes', 'categories', 'cuisines', 'courses'];
    }

    /**
     * Replace a recipe's categories. The array key is the display order.
     *
     * @param array<int|string, mixed> $category_ids
     */
    public function sync_categories(int $recipe_id, array $category_ids): void {
        global $wpdb;

        $wpdb->delete($this->table('recipe_categories'), ['recipe_id' => $recipe_id]);
        foreach ($category_ids as $i => $cid) {
            $wpdb->insert($this->table('recipe_categories'), [
                'recipe_id'     => $recipe_id,
                'category_id'   => (int) $cid,
                'display_order' => $i,
            ]);
        }
    }

    /**
     * Replace a recipe's cuisines. The array key is the display order.
     *
     * @param array<int|string, mixed> $cuisine_ids
     */
    public function sync_cuisines(int $recipe_id, array $cuisine_ids): void {
        global $wpdb;

        $wpdb->delete($this->table('recipe_cuisines'), ['recipe_id' => $recipe_id]);
        foreach ($cuisine_ids as $i => $cid) {
            $wpdb->insert($this->table('recipe_cuisines'), [
                'recipe_id'     => $recipe_id,
                'cuisine_id'    => (int) $cid,
                'display_order' => $i,
            ]);
        }
    }

    /**
     * Replace a recipe's courses.
     *
     * @param array<int|string, mixed> $course_ids
     */
    public function sync_courses(int $recipe_id, array $course_ids): void {
        global $wpdb;

        $wpdb->delete($this->table('recipe_courses'), ['recipe_id' => $recipe_id]);
        foreach ($course_ids as $cid) {
            $wpdb->insert($this->table('recipe_courses'), [
                'recipe_id' => $recipe_id,
                'course_id' => (int) $cid,
            ]);
        }
    }

    /** Remove every ingredient of a recipe, before the save writes the new list. */
    public function clear_ingredients(int $recipe_id): void {
        global $wpdb;

        $wpdb->delete($this->table('ingredients'), ['recipe_id' => $recipe_id]);
    }

    /**
     * Store one ingredient row.
     *
     * @param array<string, mixed> $row Sanitised by the service, recipe_id included.
     */
    public function insert_ingredient(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('ingredients'), $row);
    }

    /** Remove every instruction of a recipe, before the save writes the new list. */
    public function clear_instructions(int $recipe_id): void {
        global $wpdb;

        $wpdb->delete($this->table('instructions'), ['recipe_id' => $recipe_id]);
    }

    /**
     * Store one instruction row.
     *
     * @param array<string, mixed> $row Sanitised by the service, recipe_id included.
     */
    public function insert_instruction(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('instructions'), $row);
    }

    /**
     * The id of a recipe's nutrition row, if it has one. Uncached: it decides between an
     * insert and an update.
     *
     * @return string|null
     */
    public function nutrition_id(int $recipe_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE recipe_id = %d', $this->table('nutrition'), $recipe_id));
    }

    /** @param array<string, mixed> $data Sanitised by the service, recipe_id included. */
    public function update_nutrition(int $recipe_id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('nutrition'), $data, ['recipe_id' => $recipe_id]);
    }

    /** @param array<string, mixed> $data Sanitised by the service, recipe_id included. */
    public function insert_nutrition(array $data): void {
        global $wpdb;

        $wpdb->insert($this->table('nutrition'), $data);
    }

    /**
     * Copy a recipe's pivots, ingredients, instructions and nutrition onto its duplicate.
     *
     * @param int $new_id The new (duplicated) recipe id.
     * @param int $id     The recipe it was copied from.
     */
    public function copy(int $new_id, int $id): void {
        global $wpdb;

        // Copy categories pivot
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, category_id, display_order)
            SELECT %d, category_id, display_order FROM %i WHERE recipe_id = %d',
            $this->table('recipe_categories'),
            $new_id,
            $this->table('recipe_categories'),
            $id
        ));

        // Copy cuisines pivot
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, cuisine_id, display_order)
            SELECT %d, cuisine_id, display_order FROM %i WHERE recipe_id = %d',
            $this->table('recipe_cuisines'),
            $new_id,
            $this->table('recipe_cuisines'),
            $id
        ));

        // Copy courses pivot
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, course_id)
            SELECT %d, course_id FROM %i WHERE recipe_id = %d',
            $this->table('recipe_courses'),
            $new_id,
            $this->table('recipe_courses'),
            $id
        ));

        // Copy ingredients
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, quantity, unit, name, notes, link_url)
            SELECT %d, group_name, group_order, sort_order, quantity, unit, name, notes, link_url FROM %i WHERE recipe_id = %d',
            $this->table('ingredients'),
            $new_id,
            $this->table('ingredients'),
            $id
        ));

        // Copy instructions
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, title, instruction_text, image_id, video_url, duration)
            SELECT %d, group_name, group_order, sort_order, title, instruction_text, image_id, video_url, duration FROM %i WHERE recipe_id = %d',
            $this->table('instructions'),
            $new_id,
            $this->table('instructions'),
            $id
        ));

        // Copy nutrition
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source)
            SELECT %d, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source FROM %i WHERE recipe_id = %d',
            $this->table('nutrition'),
            $new_id,
            $this->table('nutrition'),
            $id
        ));
    }
}
