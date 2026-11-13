<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The one place a recipe's rating aggregate is recomputed from its reviews.
 *
 * The admin controller and the public controller each used to carry a copy of this, with the
 * same SQL. It is its own class, rather than a method of either review repository, because
 * both paths need it and the two must not share a file: ReviewRepository filters status through
 * a bound gate for the admin list, PublicReviewRepository pins status as a hard literal. The
 * pin here - only APPROVED reviews count towards a rating - is a hard literal too, and this
 * file holds no gate of any kind.
 *
 * It reads nothing through the cache and invalidates nothing itself. A recalculation is always
 * one step of a larger write (a review saved, moderated, deleted, submitted), and that write
 * owns the single invalidation that follows it; entities() names what such a write makes stale.
 */
final class RecipeRatingRepository extends Repository {

    protected function entities(): array {
        return ['reviews', 'recipes'];
    }

    /**
     * Recalculate the average rating and count for a recipe based on approved reviews.
     *
     * Deliberately ends without invalidating the cache: every caller does that once, after
     * all the recipes it touched have been recalculated.
     */
    public function recalculate(int $recipe_id): void {
        global $wpdb;

        $stats = $wpdb->get_row($wpdb->prepare(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS count
            FROM %i
            WHERE recipe_id = %d AND status = \'approved\'',
            $this->table('reviews'),
            $recipe_id
        ));

        $wpdb->update($this->table('recipes'), [
            'rating_average' => $stats->avg_rating ? round((float) $stats->avg_rating, 2) : 0,
            'rating_count'   => (int) $stats->count,
        ], ['id' => $recipe_id]);
    }
}
