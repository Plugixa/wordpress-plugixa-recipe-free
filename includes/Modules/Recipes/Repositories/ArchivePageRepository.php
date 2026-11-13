<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the taxonomy archive; the per-recipe enrichment reads run once per rendered card and are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the category / cuisine archive PAGE runs (Frontend\ArchivePage): the term
 * behind the URL, the count and one page of its published recipes, and what each card needs
 * (its categories, its cuisines, the visitor's like and favourite).
 *
 * `r.status = 'published'` is a hard literal in both recipe queries and nothing gates it.
 * Nothing here is cached and nothing here writes, so nothing flushes. The rewrite rules and
 * their self-healing flush are not database work on our tables and stay with the page.
 */
final class ArchivePageRepository extends Repository {

    /** Nothing is cached or flushed here; the recipe is what every read is about. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * Look up a taxonomy item by slug.
     *
     * @param string $type 'category', anything else reads cuisines.
     */
    public function find_term(string $type, string $slug): ?object {
        global $wpdb;
        $table = $type === 'category' ? 'categories' : 'cuisines';

        $result = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, name, slug, description, image_id, display_order FROM %i WHERE slug = %s LIMIT 1',
            $this->table( $table ),
            $slug
        ) );

        return $result ?: null;
    }

    /**
     * Count published recipes in a taxonomy.
     */
    public function count_published( string $type, int $taxonomy_id ): int {
        global $wpdb;
        $pivot_table = $type === 'category' ? 'recipe_categories' : 'recipe_cuisines';
        $pivot_fk    = $type === 'category' ? 'category_id' : 'cuisine_id';

        // The pivot table and its FK column are bound as identifiers with %i; both come
        // from the two-way ternaries above, never from the request.
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(DISTINCT r.id) FROM %i r
             INNER JOIN %i p ON r.id = p.recipe_id
             WHERE p.%i = %d AND r.status = \'published\'',
            $this->table( 'recipes' ),
            $this->table( $pivot_table ),
            $pivot_fk,
            $taxonomy_id
        ) );
    }

    /**
     * One page of a taxonomy's published recipes, card columns only.
     */
    public function published_page( string $type, int $taxonomy_id, int $page, int $per_page ): array {
        global $wpdb;
        $pivot_table = $type === 'category' ? 'recipe_categories' : 'recipe_cuisines';
        $pivot_fk    = $type === 'category' ? 'category_id' : 'cuisine_id';
        $offset      = ( $page - 1 ) * $per_page;

        $recipes_table = $this->table( 'recipes' );
        $pivot         = $this->table( $pivot_table );

        // Column list = Columns::recipe_card('r'), written out so the string handed to
        // prepare() is a constant literal. The r. prefix is load-bearing: the INNER JOIN
        // puts the pivot table in scope too. SqlLiteralTest pins this list to the allowlist.
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                r.is_halal, r.is_kosher, r.published_at, r.created_at
             FROM %i r
             INNER JOIN %i p ON r.id = p.recipe_id
             WHERE p.%i = %d AND r.status = \'published\'
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d',
            $recipes_table,
            $pivot,
            $pivot_fk,
            $taxonomy_id,
            $per_page,
            $offset
        ), ARRAY_A );
    }

    /**
     * A card's categories. Uncached: runs once per rendered card.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function categories( int $recipe_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            'SELECT c.id, c.name, c.slug FROM %i c
             INNER JOIN %i rc ON c.id = rc.category_id
             WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC',
            $this->table( 'categories' ),
            $this->table( 'recipe_categories' ),
            $recipe_id
        ), ARRAY_A );
    }

    /**
     * A card's cuisines. Uncached: runs once per rendered card.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function cuisines( int $recipe_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            'SELECT cu.id, cu.name, cu.slug FROM %i cu
             INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
             WHERE rcu.recipe_id = %d',
            $this->table( 'cuisines' ),
            $this->table( 'recipe_cuisines' ),
            $recipe_id
        ), ARRAY_A );
    }

    /** Has this user liked the recipe? Per-user state: not cached. */
    public function is_liked( int $uid, int $recipe_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d', $this->table( 'likes' ), $uid, $recipe_id
        ) );
    }

    /** Has this user favourited the recipe? Per-user state: not cached. */
    public function is_favorited( int $uid, int $recipe_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d', $this->table( 'favorites' ), $uid, $recipe_id
        ) );
    }
}
