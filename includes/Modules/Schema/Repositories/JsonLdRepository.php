<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Schema\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the JSON-LD builder, which runs once per page render over the recipes the page actually displayed (RenderedRecipes), capped at MAX_SCHEMA_RECIPES. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the JSON-LD builder (Services\JsonLd) runs: the published recipes a page
 * rendered, and for each one its categories, cuisines, nutrition, ingredients, instructions
 * and approved reviews.
 *
 * Public reads only. `status = 'published'` and `status = 'approved'` are hard literals no
 * caller can switch off, and there is no gated status predicate in this file. WHICH recipes
 * to describe is not decided here: the builder reads the RenderedRecipes registry, applies
 * its cap, and hands over the id list. Whether reviews are read at all is not decided here
 * either - the builder asks plugixa_recipe_app_enabled('reviews') before calling
 * approved_reviews().
 *
 * Nothing here is cached and nothing here writes, so there is no flush.
 */
final class JsonLdRepository extends Repository {

    /** Unused in practice (no cached read, no write); recipes are what this reads. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * The published, schema-enabled recipes among the given ids, in storage order.
     *
     * A variable-length id list binds as ONE %s through FIND_IN_SET rather than an
     * assembled IN (%d,%d,...), which is what keeps this string a constant literal for
     * any number of ids. Same form as every other variable-length list in this plugin,
     * and it retires the placeholder-counting suppression the old IN () needed.
     *
     * @param string $id_list Comma-separated recipe ids, already absint()ed.
     * @return array<int, array<string, mixed>>|null
     */
    public function published_schema_recipes(string $id_list) {
        global $wpdb;

        // Column list = Columns::recipe_public('r') written out as a literal, PLUS two
        // documented extras: created_by (author display-name fallback) and enable_schema
        // (the gating flag). Neither is returned to a client - this builds JSON-LD
        // server-side. SqlLiteralTest knows about these two.
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.notes, r.tips, r.image_id,
                    r.gallery, r.tags, r.equipment, r.video_url, r.servings,
                    r.servings_unit, r.yield_amount, r.yield_unit, r.prep_time, r.cook_time,
                    r.rest_time, r.total_time, r.difficulty, r.cost, r.season,
                    r.is_featured, r.is_editor_pick, r.display_order, r.source, r.source_url,
                    r.author_name, r.view_count, r.rating_average, r.rating_count, r.like_count,
                    r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free, r.is_keto,
                    r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free, r.is_halal,
                    r.is_kosher, r.published_at, r.created_at,
                    r.created_by, r.enable_schema
                  FROM %i r
                  WHERE FIND_IN_SET(r.id, %s)
                    AND r.status = \'published\'
                    AND r.enable_schema = 1',
                $this->table('recipes'),
                $id_list
            ),
            ARRAY_A
        );
    }

    /**
     * A recipe's categories (id, name, slug).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipe_categories(int $recipe_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                 INNER JOIN %i rc ON c.id = rc.category_id
                 WHERE rc.recipe_id = %d',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $recipe_id
        ), ARRAY_A);
    }

    /**
     * A recipe's cuisines (id, name, slug).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipe_cuisines(int $recipe_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                 INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                 WHERE rcu.recipe_id = %d',
                $this->table('cuisines'),
                $this->table('recipe_cuisines'),
                $recipe_id
        ), ARRAY_A);
    }

    /**
     * A recipe's courses (id, name, slug), in their own order.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipe_courses(int $recipe_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
                'SELECT co.id, co.name, co.slug FROM %i co
                 INNER JOIN %i rco ON co.id = rco.course_id
                 WHERE rco.recipe_id = %d
                 ORDER BY co.display_order ASC, co.id ASC',
                $this->table('courses'),
                $this->table('recipe_courses'),
                $recipe_id
        ), ARRAY_A);
    }

    /**
     * A recipe's nutrition row, or an empty array when it has none.
     *
     * @return array<string, mixed>
     */
    public function recipe_nutrition(int $recipe_id): array {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
                'SELECT id, recipe_id, serving_size, calories, total_fat,
                    saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                    dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                    calcium, iron, potassium, source
                  FROM %i
                  WHERE recipe_id = %d',
                $this->table('nutrition'),
                $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * A recipe's ingredients, in display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recipe_ingredients(int $recipe_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, group_name, group_order, sort_order,
                quantity, unit, name, notes, link_url
              FROM %i
              WHERE recipe_id = %d
              ORDER BY group_order ASC, sort_order ASC',
            $this->table('ingredients'),
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * A recipe's instructions, in display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recipe_instructions(int $recipe_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, group_name, group_order, sort_order,
                title, instruction_text, image_id, video_url, duration
              FROM %i
              WHERE recipe_id = %d
              ORDER BY group_order ASC, sort_order ASC',
            $this->table('instructions'),
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * A recipe's ten newest APPROVED reviews, public columns only.
     *
     * `status = 'approved'` is the whole defence that keeps pending (and demo-seeded)
     * reviews out of the Review / AggregateRating nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function approved_reviews(int $recipe_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, author_name, rating,
                review_text, images, is_verified, helpful_count,
                admin_reply, admin_reply_at, created_at
              FROM %i
              WHERE recipe_id = %d AND status = \'approved\'
              ORDER BY created_at DESC
              LIMIT 10',
            $this->table('reviews'),
            $recipe_id
        ), ARRAY_A) ?: [];
    }
}
