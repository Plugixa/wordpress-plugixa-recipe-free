<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every ADMIN query against `reviews`.
 *
 * The admin list filters status through a bound gate, so this repository sees pending, rejected
 * and spam reviews, and selects every column - reviewer email and IP included. The public
 * reads pin status as a hard literal and select a written-out public column list; they live
 * in PublicReviewRepository, and the two shapes are kept in separate files on purpose.
 *
 * There is no insert here and there must never be one: a review is only ever created by a
 * visitor, through the public path.
 *
 * Every write changes what a recipe's rating is computed from, so each one recalculates the
 * affected recipes (RecipeRatingRepository) and then invalidates both 'reviews' and 'recipes'.
 */
final class ReviewRepository extends Repository {

    private RecipeRatingRepository $ratings;

    public function __construct(RecipeRatingRepository $ratings) {
        $this->ratings = $ratings;
    }

    /** A review also changes the recipe's rating aggregate shown in lists. */
    protected function entities(): array {
        return ['reviews', 'recipes'];
    }

    /**
     * Bound gate/value pairs for the shared WHERE block, in placeholder order.
     *
     * Every optional filter is a gate placeholder: bind 0 and the clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled.
     *
     * @return array<int, mixed>
     */
    private function filter(string $search, string $status, int $recipe_id, int $rating, int $min_rating, int $owner = 0): array {
        global $wpdb;

        $search = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        return [
            $search !== '' ? 1 : 0, $search, $search, $search,
            $status !== '' ? 1 : 0, $status,
            $recipe_id ? 1 : 0, $recipe_id,
            $rating ? 1 : 0, $rating,
            $min_rating ? 1 : 0, $min_rating,
            // Owner gate: only reviews of recipes this user created (0 = every review).
            $owner, $this->table('recipes'), $owner,
        ];
    }

    /** Rows matching the list filters. Empty strings and 0 mean "no filter". */
    public function count(string $search, string $status, int $recipe_id, int $rating, int $min_rating, int $owner = 0): int {
        global $wpdb;

        // Bound values for the shared WHERE block, in placeholder order. The count query
        // needs only the reviews table; the list query joins recipes too, so it gets its own
        // leading pair of identifiers.
        $filter_gates = $this->filter($search, $status, $recipe_id, $rating, $min_rating, $owner);

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_gates)), function () use ($wpdb, $filter_gates) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i rv
                  WHERE ( %d = 0 OR (rv.author_name LIKE %s OR rv.review_text LIKE %s OR rv.author_email LIKE %s) )
                    AND ( %d = 0 OR rv.status = %s )
                    AND ( %d = 0 OR rv.recipe_id = %d )
                    AND ( %d = 0 OR rv.rating = %d )
                    AND ( %d = 0 OR rv.rating >= %d )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ro WHERE ro.id = rv.recipe_id AND ro.created_by = %d) )',
                ...array_merge([$this->table('reviews')], $filter_gates)
            ));
        });
    }

    /**
     * Of these review ids, the ones on recipes a given user created. Uncached: it guards a write.
     *
     * @param string $id_list Comma-separated integer ids (bound as ONE value, FIND_IN_SET).
     * @return int[]
     */
    public function owned_ids(string $id_list, int $owner): array {
        global $wpdb;

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT rv.id FROM %i rv
              WHERE FIND_IN_SET(rv.id, %s)
                AND EXISTS (SELECT 1 FROM %i ro WHERE ro.id = rv.recipe_id AND ro.created_by = %d)
              ORDER BY rv.id ASC',
            $this->table('reviews'),
            $id_list,
            $this->table('recipes'),
            $owner
        )));
    }

    /**
     * One page of the list, each row with its recipe title.
     *
     * The ORDER BY column binds with %i from the allow-list in the service; a direction
     * keyword cannot be bound, so ASC/DESC is two literal queries. The id tiebreaker keeps
     * paging stable on ties.
     *
     * @param string $orderby A column from the allow-list in the service.
     * @param string $order   ASC or DESC.
     * @return array<int, array<string, mixed>>
     */
    public function page(string $search, string $status, int $recipe_id, int $rating, int $min_rating, string $orderby, string $order, int $limit, int $offset, int $owner = 0): array {
        global $wpdb;

        $filter_gates = $this->filter($search, $status, $recipe_id, $rating, $min_rating, $owner);

        $args = array_merge(
            [$this->table('reviews'), $this->table('recipes')],
            $filter_gates,
            [$orderby, $limit, $offset]
        );

        return (array) $this->remember('list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT rv.*, r.title AS recipe_title
                    FROM %i rv
                    LEFT JOIN %i r ON rv.recipe_id = r.id
                    WHERE ( %d = 0 OR (rv.author_name LIKE %s OR rv.review_text LIKE %s OR rv.author_email LIKE %s) )
                      AND ( %d = 0 OR rv.status = %s )
                      AND ( %d = 0 OR rv.recipe_id = %d )
                      AND ( %d = 0 OR rv.rating = %d )
                      AND ( %d = 0 OR rv.rating >= %d )
                      AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ro WHERE ro.id = rv.recipe_id AND ro.created_by = %d) )
                    ORDER BY rv.%i ASC, rv.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT rv.*, r.title AS recipe_title
                FROM %i rv
                LEFT JOIN %i r ON rv.recipe_id = r.id
                WHERE ( %d = 0 OR (rv.author_name LIKE %s OR rv.review_text LIKE %s OR rv.author_email LIKE %s) )
                  AND ( %d = 0 OR rv.status = %s )
                  AND ( %d = 0 OR rv.recipe_id = %d )
                  AND ( %d = 0 OR rv.rating = %d )
                  AND ( %d = 0 OR rv.rating >= %d )
                  AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i ro WHERE ro.id = rv.recipe_id AND ro.created_by = %d) )
                ORDER BY rv.%i DESC, rv.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * One review with its recipe title. Read-through object cache; key = review id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array {
        global $wpdb;

        $row = $this->remember('item|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT rv.*, r.title AS recipe_title
                    FROM %i rv
                    LEFT JOIN %i r ON rv.recipe_id = r.id
                    WHERE rv.id = %d',
                    $this->table('reviews'),
                    $this->table('recipes'),
                    $id
                ),
                ARRAY_A
            );
        });

        return $row ?: null;
    }

    /**
     * The row as it is in the database right now - for the write paths, which need the
     * recipe the review belongs to, where a cached copy could be one write behind.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('reviews'), $id), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Store new field values for one review.
     *
     * @param array<string, mixed> $data
     * @param int                  $recipe_id The recipe the review belongs to.
     */
    public function update(int $id, array $data, int $recipe_id): void {
        global $wpdb;

        $wpdb->update($this->table('reviews'), $data, ['id' => $id]);

        // Recalculate recipe rating
        $this->ratings->recalculate($recipe_id);

        // Content write: a review also changes the recipe's rating aggregate shown in lists.
        $this->flush();
    }

    /**
     * Remove one review.
     *
     * @param int $recipe_id The recipe the review belonged to.
     */
    public function delete(int $id, int $recipe_id): void {
        global $wpdb;

        // The review's helpful votes go with it. They are keyed by review, not by recipe, so
        // no cascade reached them and every deleted review left its votes behind.
        $wpdb->delete($this->table('review_helpful_votes'), ['review_id' => $id]);
        $wpdb->delete($this->table('reviews'), ['id' => $id]);

        // Recalculate recipe rating
        $this->ratings->recalculate($recipe_id);

        // Content write: a review also changes the recipe's rating aggregate shown in lists.
        $this->flush();
    }

    /**
     * Move one review to another moderation status.
     *
     * @param array<string, mixed> $data      The status and its modified_at stamp.
     * @param int                  $recipe_id The recipe the review belongs to.
     */
    public function set_status(int $id, array $data, int $recipe_id): void {
        global $wpdb;

        $wpdb->update($this->table('reviews'), $data, ['id' => $id]);

        // Recalculate recipe rating
        $this->ratings->recalculate($recipe_id);

        // Content write: approve/reject/spam changes the recipe's rating aggregate shown in lists.
        $this->flush();
    }

    /**
     * Remove several reviews at once.
     *
     * The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
     * so each statement stays a constant literal.
     *
     * @param string $id_list Comma-separated integer ids, already intval-mapped by the service.
     * @return int|false Rows removed, as the database reported it.
     */
    public function delete_many(string $id_list) {
        global $wpdb;

        // Get affected recipe IDs for recalculation
        $recipe_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT recipe_id FROM %i WHERE FIND_IN_SET(id, %s)',
            $this->table('reviews'),
            $id_list
        ));

        // The reviews' helpful votes first - see delete().
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(review_id, %s)',
            $this->table('review_helpful_votes'),
            $id_list
        ));

        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s)',
            $this->table('reviews'),
            $id_list
        ));

        // Recalculate ratings for affected recipes
        foreach ($recipe_ids as $recipe_id) {
            $this->ratings->recalculate((int) $recipe_id);
        }

        // Content write: reviews also change the recipes' rating aggregates shown in lists.
        $this->flush();

        return $deleted;
    }

    /**
     * Move several reviews to another moderation status at once.
     *
     * The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
     * so each statement stays a constant literal.
     *
     * @param string $id_list Comma-separated integer ids, already intval-mapped by the service.
     * @param string $now     MySQL datetime, in the site's timezone.
     */
    public function set_status_many(string $id_list, string $status, string $now): void {
        global $wpdb;

        // Get affected recipe IDs
        $recipe_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT recipe_id FROM %i WHERE FIND_IN_SET(id, %s)',
            $this->table('reviews'),
            $id_list
        ));

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET status = %s, modified_at = %s WHERE FIND_IN_SET(id, %s)',
            $this->table('reviews'),
            $status,
            $now,
            $id_list
        ));

        // Recalculate ratings for affected recipes
        foreach ($recipe_ids as $recipe_id) {
            $this->ratings->recalculate((int) $recipe_id);
        }

        // Content write: bulk status changes also change the recipes' rating aggregates.
        $this->flush();
    }
}
