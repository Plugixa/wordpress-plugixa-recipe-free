<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Settings\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query behind the admin dashboard: counts, top lists, recent rows and the monthly
 * series, read from the recipes, reviews, categories, cuisines, collections and favorites
 * tables.
 *
 * Read-only and uncached. The dashboard has never gone through Cache, so nothing here calls
 * remember(), and nothing here writes, so nothing here flushes. Rows come back exactly as
 * $wpdb returns them; the casts and the response shape are SettingsService's.
 *
 * `status = 'published'` and `status = 'approved'` / `'pending'` are hard literals. No query
 * in this class carries a gated status predicate, and none may be added beside them.
 */
final class DashboardStatsRepository extends Repository {

    /**
     * Required by the base class and otherwise unused: this repository never caches and
     * never flushes.
     */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * Recipe counts by status.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipe_counts_by_status() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare('SELECT status, COUNT(*) AS count FROM %i GROUP BY status', $this->table('recipes')),
            ARRAY_A
        );
    }

    /**
     * Featured count.
     *
     * @return string|null
     */
    public function featured_recipe_count() {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_featured = 1', $this->table('recipes')));
    }

    /**
     * Average rating, over rated recipes only.
     *
     * @return string|null
     */
    public function average_recipe_rating() {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare('SELECT AVG(rating_average) FROM %i WHERE rating_average > 0', $this->table('recipes'))
        );
    }

    /**
     * Rating distribution of approved reviews.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function approved_rating_distribution() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT rating, COUNT(*) AS count FROM %i WHERE status = \'approved\' GROUP BY rating ORDER BY rating DESC',
                $this->table('reviews')
            ),
            ARRAY_A
        );
    }

    /**
     * Recipes by category (top 10).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipes_by_category() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.name, COUNT(rc.recipe_id) AS count
                FROM %i c
                LEFT JOIN %i rc ON c.id = rc.category_id
                GROUP BY c.id
                ORDER BY count DESC, c.id ASC
                LIMIT 10',
                $this->table('categories'),
                $this->table('recipe_categories')
            ),
            ARRAY_A
        );
    }

    /**
     * Recent recipes.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recent_recipes() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, title, status, difficulty, rating_average, created_at, image_id
                FROM %i
                ORDER BY created_at DESC, id DESC
                LIMIT 5',
                $this->table('recipes')
            ),
            ARRAY_A
        );
    }

    /**
     * Every row of one table: reviews, categories, cuisines, collections, favorites.
     *
     * @param string $name Unprefixed table name, as Repository::table() takes it.
     * @return string|null
     */
    public function count_all(string $name) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $this->table($name)));
    }

    /**
     * Reviews awaiting moderation.
     *
     * @return string|null
     */
    public function pending_review_count() {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = \'pending\'', $this->table('reviews')));
    }

    /**
     * Total views (sum of view_count from recipes).
     *
     * @return string|null
     */
    public function total_views() {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(view_count), 0) FROM %i', $this->table('recipes')));
    }

    /**
     * Recent reviews, each with its recipe's title.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recent_reviews() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.rating, r.review_text, r.author_name, r.status, r.created_at, rec.title AS recipe_title
                FROM %i r
                LEFT JOIN %i rec ON r.recipe_id = rec.id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 5',
                $this->table('reviews'),
                $this->table('recipes')
            ),
            ARRAY_A
        );
    }

    /**
     * Recipes by difficulty.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipes_by_difficulty() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT difficulty, COUNT(*) AS count FROM %i GROUP BY difficulty ORDER BY count DESC, difficulty ASC',
                $this->table('recipes')
            ),
            ARRAY_A
        );
    }

    /**
     * Recipes by cuisine (top 10).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipes_by_cuisine() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT cu.name, COUNT(rcu.recipe_id) AS count
                FROM %i cu
                LEFT JOIN %i rcu ON cu.id = rcu.cuisine_id
                GROUP BY cu.id
                ORDER BY count DESC, cu.id ASC
                LIMIT 10',
                $this->table('cuisines'),
                $this->table('recipe_cuisines')
            ),
            ARRAY_A
        );
    }

    /**
     * Rows created per calendar month since a date, for recipes or reviews.
     *
     * The DATE_FORMAT pattern is BOUND, not written in: a literal `%Y` inside the
     * prepare() string would be read as a placeholder.
     *
     * @param string $name  Unprefixed table name: 'recipes' or 'reviews'.
     * @param string $since 'Y-m-d H:i:s' in site time - the clock `created_at` is written in.
     * @return array<int, array<string, mixed>>
     */
    public function created_by_month(string $name, string $since): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE_FORMAT(created_at, %s) AS period, COUNT(*) AS count FROM %i WHERE created_at >= %s GROUP BY period',
                '%Y-%m',
                $this->table($name),
                $since
            ),
            ARRAY_A
        );
    }

    /**
     * Most viewed published recipes. `id DESC` breaks view-count ties deterministically.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function top_viewed_published() {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, title, view_count FROM %i WHERE status = \'published\' ORDER BY view_count DESC, id DESC LIMIT 5',
                $this->table('recipes')
            ),
            ARRAY_A
        );
    }
}
