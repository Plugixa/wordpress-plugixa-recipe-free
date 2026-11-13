<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Categories\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` / `...$filter_args` list queries report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `categories` and its `recipe_categories` pivot.
 */
final class CategoryRepository extends Repository {

    /** A category list carries recipe counts, and the public widgets cache under both. */
    protected function entities(): array {
        return ['categories', 'recipes'];
    }

    /**
     * Bound values for the shared WHERE block, in placeholder order.
     *
     * Every optional filter is a gate placeholder: bind 0 and the clause is constant-true,
     * which keeps WHERE literal instead of PHP-assembled.
     *
     * @return array<int, mixed> [search gate, search, search, language gate, language]
     */
    private function filter(string $search, string $language): array {
        global $wpdb;

        $search = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        return [
            $search !== '' ? 1 : 0, $search, $search,
            $language !== '' ? 1 : 0, $language,
        ];
    }

    /** Rows matching the list filters. Empty strings mean "no filter". */
    public function count(string $search, string $language): int {
        global $wpdb;

        // %i identifiers and %s/%d values share one positional list, so the table name sits
        // inline at the head of it.
        $filter_args = array_merge([$this->table('categories')], $this->filter($search, $language));

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i c
                  WHERE ( %d = 0 OR (c.name LIKE %s OR c.description LIKE %s) )
                    AND ( %d = 0 OR c.language_code = %s )',
                ...$filter_args
            ));
        });
    }

    /**
     * One page of the list, each row with its recipe_count.
     *
     * The ORDER BY column binds with the %i identifier placeholder (WP 6.2+) from the
     * service's allow-list; a direction keyword cannot be bound at all, so ASC/DESC is two
     * literal queries. The id tiebreaker keeps paging stable when the sort column has ties.
     *
     * @param string $orderby A column from the service's allow-list.
     * @param string $order   'ASC' or 'DESC'
     * @return array<int, array<string, mixed>>
     */
    public function page(string $search, string $language, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        $args = array_merge(
            [$this->table('categories'), $this->table('recipe_categories')],
            $this->filter($search, $language),
            [$orderby, $limit, $offset]
        );

        // Cache key: stable digest of the query shape and bound values, not the SQL text.
        return (array) $this->remember('items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT c.*, COUNT(rc.recipe_id) AS recipe_count
                    FROM %i c
                    LEFT JOIN %i rc ON c.id = rc.category_id
                    WHERE ( %d = 0 OR (c.name LIKE %s OR c.description LIKE %s) )
                      AND ( %d = 0 OR c.language_code = %s )
                    GROUP BY c.id
                    ORDER BY c.%i ASC, c.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.*, COUNT(rc.recipe_id) AS recipe_count
                FROM %i c
                LEFT JOIN %i rc ON c.id = rc.category_id
                WHERE ( %d = 0 OR (c.name LIKE %s OR c.description LIKE %s) )
                      AND ( %d = 0 OR c.language_code = %s )
                GROUP BY c.id
                ORDER BY c.%i DESC, c.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A);
        });
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array {
        global $wpdb;

        $row = $this->remember("item:{$id}", function () use ($wpdb, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('categories'), $id),
                ARRAY_A
            );
        });

        return $row ?: null;
    }

    /**
     * The row as it is in the database right now - for an update that must compare against
     * the stored slug, where a cached copy could be one write behind.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('categories'), $id), ARRAY_A);

        return $row ?: null;
    }

    public function exists(int $id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('categories'), $id));
    }

    /** How many recipes carry this category. */
    public function recipe_count(int $id): int {
        global $wpdb;

        return (int) $this->remember("item-recipe-count:{$id}", function () use ($wpdb, $id) {
            return (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE category_id = %d', $this->table('recipe_categories'), $id)
            );
        });
    }

    /** Is the slug used by a category other than $exclude_id? */
    public function slug_taken(string $slug, int $exclude_id = 0): bool {
        global $wpdb;

        if ($exclude_id) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $this->table('categories'), $slug, $exclude_id));
        }

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('categories'), $slug));
    }

    /**
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('categories'), $data);
        $id = (int) $wpdb->insert_id;

        if ($id) {
            $this->flush();
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('categories'), $data, ['id' => $id]);

        $this->flush();
    }

    /** Remove a category and unlink it from every recipe. The recipes themselves are untouched. */
    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('recipe_categories'), ['category_id' => $id]);
        $wpdb->delete($this->table('categories'), ['id' => $id]);

        $this->flush();
    }

    /**
     * @param string $id_list Comma-separated ids, already intval-mapped by the caller.
     */
    public function delete_many(string $id_list): void {
        global $wpdb;

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so each statement stays a constant literal.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE FIND_IN_SET(category_id, %s)', $this->table('recipe_categories'), $id_list));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE FIND_IN_SET(id, %s)', $this->table('categories'), $id_list));

        $this->flush();
    }

    /**
     * Store a new display order. One flush for the whole batch, not one per row.
     *
     * @param array<int, array{id: int, display_order: int}> $items
     */
    public function reorder(array $items): void {
        global $wpdb;

        foreach ($items as $item) {
            $wpdb->update(
                $this->table('categories'),
                ['display_order' => (int) $item['display_order']],
                ['id' => (int) $item['id']]
            );
        }

        $this->flush();
    }
}
