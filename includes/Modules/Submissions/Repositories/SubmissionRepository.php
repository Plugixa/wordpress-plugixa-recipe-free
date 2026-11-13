<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Submissions\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the public routes. Content reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user/per-IP state lookups (favourite, like, helpful vote), the write paths and the view/like counter updates are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the PUBLIC recipe submission form runs.
 *
 * A submission writes into the Recipes module's tables - the recipe row, its taxonomy
 * pivots, its ingredients, instructions and nutrition - and those statements live here, with
 * the form that issues them, rather than in that module's repositories.
 *
 * Write-only by design: the three reads are id probes (is this slug taken, does this term
 * exist), none of them cached. Nothing here selects a recipe row back, so `submission_ip`,
 * which this form stores and which is private, can never reach a response from this class.
 *
 * None of the writes invalidates the cache on its own. A submission is one sequence, the
 * uploads and the notification sit inside it, and submitted() is the single invalidation that
 * closes it - where it has always been.
 */
final class SubmissionRepository extends Repository {

    /**
     * The new recipe, plus its taxonomy pivots, which feed the recipe counts shown in the
     * category/cuisine/course widgets.
     */
    protected function entities(): array {
        return ['recipes', 'categories', 'cuisines', 'courses'];
    }

    /**
     * The id of the recipe already using a slug, if there is one. Uncached: it drives the
     * unique-slug loop of a write.
     *
     * @return string|null
     */
    public function slug_owner(string $slug) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('recipes'), $slug));
    }

    /**
     * Store the submitted recipe row. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $recipe_data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert_recipe(array $recipe_data): int {
        global $wpdb;

        $wpdb->insert($this->table('recipes'), $recipe_data);

        return (int) $wpdb->insert_id;
    }

    /**
     * Record the featured image uploaded with a submission. Part of a submission; see submitted().
     *
     * @param int $attachment_id
     */
    public function set_image(int $recipe_id, $attachment_id): void {
        global $wpdb;

        $wpdb->update($this->table('recipes'), ['image_id' => $attachment_id], ['id' => $recipe_id]);
    }

    /**
     * Record the gallery images uploaded with a submission. Part of a submission; see submitted().
     *
     * @param string|false $gallery JSON list of attachment ids.
     */
    public function set_gallery(int $recipe_id, $gallery): void {
        global $wpdb;

        $wpdb->update($this->table('recipes'), ['gallery' => $gallery], ['id' => $recipe_id]);
    }

    /**
     * Which of these category ids exist. Uncached.
     *
     * @param string $id_list Comma-separated ids.
     * @return array<int, string>
     */
    public function existing_category_ids(string $id_list): array {
        global $wpdb;

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
        // IN (...), which keeps the query a constant literal for any id count.
        return $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i WHERE FIND_IN_SET(id, %s)',
            $this->table('categories'),
            $id_list
        ));
    }

    /**
     * Link the recipe to a category. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $row recipe_id, category_id, display_order.
     */
    public function add_category(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('recipe_categories'), $row);
    }

    /**
     * The cuisine's id, if there is such a cuisine. Uncached.
     *
     * @return string|null
     */
    public function cuisine_exists(int $cuisine_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('cuisines'), $cuisine_id));
    }

    /**
     * Link the recipe to a cuisine. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $row recipe_id, cuisine_id.
     */
    public function add_cuisine(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('recipe_cuisines'), $row);
    }

    /**
     * The course's id, if there is such a course. Uncached.
     *
     * @return string|null
     */
    public function course_exists(int $course_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('courses'), $course_id));
    }

    /**
     * Link the recipe to a course. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $row recipe_id, course_id.
     */
    public function add_course(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('recipe_courses'), $row);
    }

    /**
     * Store one ingredient line. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $row
     */
    public function add_ingredient(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('ingredients'), $row);
    }

    /**
     * Store one instruction step. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $row
     */
    public function add_instruction(array $row): void {
        global $wpdb;

        $wpdb->insert($this->table('instructions'), $row);
    }

    /**
     * Store the recipe's nutrition row. Part of a submission; see submitted().
     *
     * @param array<string, mixed> $nutrition_data
     */
    public function add_nutrition(array $nutrition_data): void {
        global $wpdb;

        $wpdb->insert($this->table('nutrition'), $nutrition_data);
    }

    /**
     * Close a submission: the recipe row, its images and all its child rows are written.
     *
     * Its own method because the invalidation comes last, after the action has fired and the
     * notification has been sent, and everything between insert_recipe() and here is
     * orchestrated by the service.
     */
    public function submitted(): void {
        // Content write: the new recipe (plus its taxonomy pivots, which feed the recipe counts
        // shown in the category/cuisine/course widgets).
        $this->flush();
    }
}
