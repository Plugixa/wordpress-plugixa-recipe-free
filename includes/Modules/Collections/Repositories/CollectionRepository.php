<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Collections\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every ADMIN query against `collections` and its `collection_recipes` pivot.
 *
 * The admin list filters by status and visibility through bound gates, so this class sees
 * drafts and private collections. The public read pins both as hard literals and lives in
 * CollectionReadRepository; the two shapes are kept in separate files on purpose.
 */
final class CollectionRepository extends Repository {

    protected function entities(): array {
        return ['collections'];
    }

    /**
     * Bound gate/value pairs for the shared WHERE block, in placeholder order.
     *
     * Every optional filter is a gate placeholder: bind 0 and the clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled.
     *
     * @return array<int, mixed>
     */
    private function filter(string $search, string $type, string $status, int $user_id, string $visibility): array {
        global $wpdb;

        $search = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        return [
            $search !== '' ? 1 : 0, $search, $search,
            $type !== '' ? 1 : 0, $type,
            $status !== '' ? 1 : 0, $status,
            $user_id ? 1 : 0, $user_id,
            $visibility !== '' ? 1 : 0, $visibility,
        ];
    }

    /** Rows matching the list filters. Empty strings and 0 mean "no filter". */
    public function count(string $search, string $type, string $status, int $user_id, string $visibility): int {
        global $wpdb;

        // The count query needs only the collections table; the list query joins the pivot
        // too, so each gets its own leading identifiers.
        $filter_gates = $this->filter($search, $type, $status, $user_id, $visibility);

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_gates)), function () use ($wpdb, $filter_gates) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i col
                  WHERE ( %d = 0 OR (col.name LIKE %s OR col.description LIKE %s) )
                    AND ( %d = 0 OR col.type = %s )
                    AND ( %d = 0 OR col.status = %s )
                    AND ( %d = 0 OR col.user_id = %d )
                    AND ( %d = 0 OR col.visibility = %s )',
                ...array_merge([$this->table('collections')], $filter_gates)
            ));
        });
    }

    /**
     * One page of the list, each row with its recipe_count.
     *
     * The ORDER BY column binds with %i from the allow-list in the service; a direction
     * keyword cannot be bound, so ASC/DESC is two literal queries. The id tiebreaker keeps
     * paging stable on ties.
     *
     * @param string $orderby A column from the allow-list in the service.
     * @param string $order   ASC or DESC.
     * @return array<int, array<string, mixed>>
     */
    public function page(string $search, string $type, string $status, int $user_id, string $visibility, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        $filter_gates = $this->filter($search, $type, $status, $user_id, $visibility);

        $args = array_merge(
            [$this->table('collections'), $this->table('collection_recipes')],
            $filter_gates,
            [$orderby, $limit, $offset]
        );

        // Cache key: a stable digest of the bound values and the direction, never the SQL text.
        return (array) $this->remember('list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT col.*, COUNT(cr.recipe_id) AS recipe_count
                    FROM %i col
                    LEFT JOIN %i cr ON col.id = cr.collection_id
                    WHERE ( %d = 0 OR (col.name LIKE %s OR col.description LIKE %s) )
                      AND ( %d = 0 OR col.type = %s )
                      AND ( %d = 0 OR col.status = %s )
                      AND ( %d = 0 OR col.user_id = %d )
                      AND ( %d = 0 OR col.visibility = %s )
                    GROUP BY col.id
                    ORDER BY col.%i ASC, col.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT col.*, COUNT(cr.recipe_id) AS recipe_count
                FROM %i col
                LEFT JOIN %i cr ON col.id = cr.collection_id
                WHERE ( %d = 0 OR (col.name LIKE %s OR col.description LIKE %s) )
                  AND ( %d = 0 OR col.type = %s )
                  AND ( %d = 0 OR col.status = %s )
                  AND ( %d = 0 OR col.user_id = %d )
                  AND ( %d = 0 OR col.visibility = %s )
                GROUP BY col.id
                ORDER BY col.%i DESC, col.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Read-through object cache; key = collection id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array {
        global $wpdb;

        $row = $this->remember('item|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('collections'), $id),
                ARRAY_A
            );
        });

        return $row ?: null;
    }

    /**
     * The row as it is in the database right now - for the write paths, which compare
     * against the stored slug and type, where a cached copy could be one write behind.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('collections'), $id), ARRAY_A);

        return $row ?: null;
    }

    public function exists(int $id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('collections'), $id));
    }

    /** Is there a recipe with this id? Read from the recipes table, uncached. */
    public function recipe_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('recipes'), $recipe_id));
    }

    /**
     * The recipes of one collection, as the single-collection response lists them.
     * Read-through object cache; key = collection id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function item_recipes(int $id): array {
        global $wpdb;

        return (array) $this->remember('item_recipes|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT r.id, r.title, r.slug, r.image_id, r.prep_time, r.cook_time, r.total_time,
                            r.difficulty, r.rating_average, r.status,
                            cr.display_order, cr.day_number, cr.meal_type, cr.added_at
                    FROM %i r
                    INNER JOIN %i cr ON r.id = cr.recipe_id
                    WHERE cr.collection_id = %d
                    ORDER BY cr.display_order ASC, r.id ASC',
                    $this->table('recipes'),
                    $this->table('collection_recipes'),
                    $id
                ),
                ARRAY_A
            ) ?: [];
        });
    }

    /**
     * The full recipe rows of one collection, for the /recipes sub-route.
     * Read-through object cache; key = collection id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recipes(int $id): array {
        global $wpdb;

        return (array) $this->remember('recipes|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT r.*, cr.display_order, cr.day_number, cr.meal_type, cr.added_at
                    FROM %i r
                    INNER JOIN %i cr ON r.id = cr.recipe_id
                    WHERE cr.collection_id = %d
                    ORDER BY cr.display_order ASC, r.id ASC',
                    $this->table('recipes'),
                    $this->table('collection_recipes'),
                    $id
                ),
                ARRAY_A
            ) ?: [];
        });
    }

    /** The highest display_order in the collection, 0 when it is empty. */
    public function max_recipe_order(int $id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(display_order) FROM %i WHERE collection_id = %d',
            $this->table('collection_recipes'),
            $id
        ));
    }

    /** Is the slug used by a collection other than $exclude_id? */
    public function slug_taken(string $slug, int $exclude_id = 0): bool {
        global $wpdb;

        if ($exclude_id) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $this->table('collections'), $slug, $exclude_id));
        }

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('collections'), $slug));
    }

    /**
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('collections'), $data);
        $id = (int) $wpdb->insert_id;

        if ($id) {
            // Content write.
            $this->flush();
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('collections'), $data, ['id' => $id]);

        // Content write.
        $this->flush();
    }

    /** Delete a collection and its pivot entries. The recipes themselves are untouched. */
    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('collection_recipes'), ['collection_id' => $id]);
        $wpdb->delete($this->table('collections'), ['id' => $id]);

        // Content write.
        $this->flush();
    }

    /**
     * Link a recipe to a collection and stamp the collection as modified.
     *
     * @param array<string, mixed> $insert_data The pivot row, collection_id included.
     * @return bool False when the row could not be inserted (it already exists, or the
     *              insert failed); nothing else is written and nothing is flushed then.
     */
    public function add_recipe(int $id, array $insert_data): bool {
        global $wpdb;

        $result = $wpdb->insert($this->table('collection_recipes'), $insert_data);

        if ($result === false) {
            return false;
        }

        // Update collection modified_at
        $wpdb->update($this->table('collections'), ['modified_at' => current_time('mysql')], ['id' => $id]);

        // Content write.
        $this->flush();

        return true;
    }

    /**
     * Unlink a recipe from a collection and stamp the collection as modified.
     *
     * @return bool False when there was no such link; nothing is flushed then.
     */
    public function remove_recipe(int $id, int $recipe_id): bool {
        global $wpdb;

        $deleted = $wpdb->delete($this->table('collection_recipes'), [
            'collection_id' => $id,
            'recipe_id'     => $recipe_id,
        ]);

        if (!$deleted) {
            return false;
        }

        $wpdb->update($this->table('collections'), ['modified_at' => current_time('mysql')], ['id' => $id]);

        // Content write.
        $this->flush();

        return true;
    }

    /**
     * Store new pivot values for the recipes of one collection. One flush for the batch.
     *
     * @param array<int, array{recipe_id: int, update: array<string, mixed>}> $rows
     */
    public function reorder_recipes(int $id, array $rows): void {
        global $wpdb;

        foreach ($rows as $row) {
            $wpdb->update($this->table('collection_recipes'), $row['update'], [
                'collection_id' => $id,
                'recipe_id'     => $row['recipe_id'],
            ]);
        }

        // Content write.
        $this->flush();
    }
}
