<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the XML sitemap: requested by crawlers, not visitors, and deliberately uncached. Every query is a constant literal with table names bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * What the sitemap lists (Frontend\SitemapProvider): published recipes, and the category
 * and cuisine archives that have at least one of them.
 *
 * Read-only and uncached. `status = 'published'` is a hard literal and nothing gates it.
 */
final class SitemapRepository extends Repository {

    /** Nothing is cached or flushed here. */
    protected function entities(): array {
        return ['recipes'];
    }

    public function count_recipes(): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE status = \'published\'',
            $this->table('recipes')
        ));
    }

    /**
     * One page of published recipes, oldest id first so a new recipe never shifts a page
     * a crawler has already read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recipes(int $limit, int $offset): array {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, slug, published_at FROM %i
             WHERE status = \'published\'
             ORDER BY id ASC
             LIMIT %d OFFSET %d',
            $this->table('recipes'),
            $limit,
            $offset
        ), ARRAY_A);
    }

    /**
     * How many terms of a kind have a published recipe.
     *
     * @param string $type 'category', anything else reads cuisines.
     */
    public function count_terms(string $type): int {
        global $wpdb;
        [$terms, $pivot, $key] = $this->term_tables($type);

        // Tables and the pivot's key column are bound as identifiers; all three come from
        // term_tables(), never from the request.
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i t
             WHERE EXISTS (
                SELECT 1 FROM %i p
                INNER JOIN %i r ON r.id = p.recipe_id
                WHERE p.%i = t.id AND r.status = \'published\'
             )',
            $this->table($terms),
            $this->table($pivot),
            $this->table('recipes'),
            $key
        ));
    }

    /**
     * One page of the terms of a kind that have a published recipe. An archive with none
     * renders an empty grid, and an empty page is not worth a crawler's visit.
     *
     * @param string $type 'category', anything else reads cuisines.
     * @return array<int, array<string, mixed>>
     */
    public function terms(string $type, int $limit, int $offset): array {
        global $wpdb;
        [$terms, $pivot, $key] = $this->term_tables($type);

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT t.id, t.slug FROM %i t
             WHERE EXISTS (
                SELECT 1 FROM %i p
                INNER JOIN %i r ON r.id = p.recipe_id
                WHERE p.%i = t.id AND r.status = \'published\'
             )
             ORDER BY t.id ASC
             LIMIT %d OFFSET %d',
            $this->table($terms),
            $this->table($pivot),
            $this->table('recipes'),
            $key,
            $limit,
            $offset
        ), ARRAY_A);
    }

    /**
     * @return array{0:string,1:string,2:string} Term table, pivot table, pivot key column.
     */
    private function term_tables(string $type): array {
        return 'category' === $type
            ? ['categories', 'recipe_categories', 'category_id']
            : ['cuisines', 'recipe_cuisines', 'cuisine_id'];
    }
}
