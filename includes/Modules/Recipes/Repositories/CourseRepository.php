<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` / `...$filter_args` list queries report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `courses` and its `recipe_courses` pivot.
 */
final class CourseRepository extends Repository {

    /** A course list carries recipe counts, so a course write also invalidates 'recipes'. */
    protected function entities(): array {
        return ['courses', 'recipes'];
    }

    /**
     * Bound values for the shared WHERE block, in placeholder order.
     *
     * Every optional filter is a gate placeholder: bind 0 and the clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled.
     *
     * @return array<int, mixed> [search gate, search, search]
     */
    private function filter(string $search): array {
        global $wpdb;

        $search = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        return [
            $search !== '' ? 1 : 0, $search, $search,
        ];
    }

    /** Rows matching the list filter. An empty string means "no filter". */
    public function count(string $search): int {
        global $wpdb;

        // Bound values for the shared WHERE block, in placeholder order. %i identifiers
        // and %s/%d values share one positional list, so the table name sits inline.
        $filter_args = array_merge([$this->table('courses')], $this->filter($search));

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i co
                  WHERE ( %d = 0 OR (co.name LIKE %s OR co.description LIKE %s) )',
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
    public function page(string $search, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        $args = array_merge(
            [$this->table('courses'), $this->table('recipe_courses')],
            $this->filter($search),
            [$orderby, $limit, $offset]
        );

        // Cache key: stable digest of the query shape and bound values, not the SQL text.
        return (array) $this->remember('items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT co.*, COUNT(rco.recipe_id) AS recipe_count
                    FROM %i co
                    LEFT JOIN %i rco ON co.id = rco.course_id
                    WHERE ( %d = 0 OR (co.name LIKE %s OR co.description LIKE %s) )
                    GROUP BY co.id
                    ORDER BY co.%i ASC, co.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT co.*, COUNT(rco.recipe_id) AS recipe_count
                FROM %i co
                LEFT JOIN %i rco ON co.id = rco.course_id
                WHERE ( %d = 0 OR (co.name LIKE %s OR co.description LIKE %s) )
                GROUP BY co.id
                ORDER BY co.%i DESC, co.id DESC
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
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('courses'), $id),
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

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('courses'), $id), ARRAY_A);

        return $row ?: null;
    }

    public function exists(int $id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('courses'), $id));
    }

    /** How many recipes carry this course. */
    public function recipe_count(int $id): int {
        global $wpdb;

        return (int) $this->remember("item-recipe-count:{$id}", function () use ($wpdb, $id) {
            return (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE course_id = %d', $this->table('recipe_courses'), $id)
            );
        });
    }

    /** Is the slug used by a course other than $exclude_id? */
    public function slug_taken(string $slug, int $exclude_id = 0): bool {
        global $wpdb;

        if ($exclude_id) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $this->table('courses'), $slug, $exclude_id));
        }

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('courses'), $slug));
    }

    /**
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('courses'), $data);
        $id = (int) $wpdb->insert_id;

        if ($id) {
            $this->flush();
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('courses'), $data, ['id' => $id]);

        $this->flush();
    }

    /** Remove a course and unlink it from every recipe. The recipes themselves are untouched. */
    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('recipe_courses'), ['course_id' => $id]);
        $wpdb->delete($this->table('courses'), ['id' => $id]);

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
                $this->table('courses'),
                ['display_order' => (int) $item['display_order']],
                ['id' => (int) $item['id']]
            );
        }

        $this->flush();
    }
}
