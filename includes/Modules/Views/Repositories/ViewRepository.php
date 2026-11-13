<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Views\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `views` - the saved display configurations the admin app edits.
 */
final class ViewRepository extends Repository {

    protected function entities(): array {
        return ['views'];
    }

    /**
     * Bound values for the shared WHERE block, in placeholder order.
     *
     * Every optional filter is a gate placeholder: bind 0 and the clause is
     * constant-true, which keeps WHERE literal instead of PHP-assembled. is_default is
     * tri-state - absent (null) means no filter, else match the given 0/1 exactly.
     *
     * @return array<int, mixed> [search gate, search, search, default gate, default, layout gate, layout]
     */
    private function filter(string $search, ?int $is_default, string $layout_style): array {
        global $wpdb;

        $search = '' !== $search
            ? '%' . $wpdb->esc_like($search) . '%'
            : '';

        $has_default = null !== $is_default;
        $default_val = $has_default ? $is_default : 0;

        $layout = '' !== $layout_style
            ? '%"style":"' . $wpdb->esc_like($layout_style) . '"%'
            : '';

        return [
            $search !== '' ? 1 : 0, $search, $search,
            $has_default ? 1 : 0, $default_val,
            $layout !== '' ? 1 : 0, $layout,
        ];
    }

    /** Rows matching the list filters. Empty strings and a null is_default mean "no filter". */
    public function count(string $search, ?int $is_default, string $layout_style): int {
        global $wpdb;

        // Bound values for the shared WHERE block, in placeholder order.
        $filter_args = array_merge([$this->table('views')], $this->filter($search, $is_default, $layout_style));

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                    AND ( %d = 0 OR is_default = %d )
                    AND ( %d = 0 OR config LIKE %s )',
                ...$filter_args
            ));
        });
    }

    /**
     * One page of the list.
     *
     * The ORDER BY column binds with the %i identifier placeholder (WP 6.2+) from the
     * service's allow-list; a direction keyword cannot be bound, so ASC/DESC is
     * two literal queries. The id tiebreaker keeps paging stable on ties.
     *
     * @param string $orderby A column from the service's allow-list.
     * @param string $order   'ASC' or 'DESC'
     * @return array<int, array<string, mixed>>
     */
    public function page(string $search, ?int $is_default, string $layout_style, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        $args = array_merge(
            [$this->table('views')],
            $this->filter($search, $is_default, $layout_style),
            [$orderby, $limit, $offset]
        );

        return (array) $this->remember('items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT * FROM %i
                      WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                        AND ( %d = 0 OR is_default = %d )
                        AND ( %d = 0 OR config LIKE %s )
                      ORDER BY %i ASC, id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                    AND ( %d = 0 OR is_default = %d )
                    AND ( %d = 0 OR config LIKE %s )
                  ORDER BY %i DESC, id DESC
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
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('views'), $id),
                ARRAY_A
            );
        });

        return $row ?: null;
    }

    /**
     * The row as it is in the database right now - for a write that must decide against the
     * stored slug, config or is_default, where a cached copy could be one write behind.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('views'), $id), ARRAY_A);

        return $row ?: null;
    }

    public function exists(int $id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('views'), $id));
    }

    /** Is the slug used by a view other than $exclude_id? */
    public function slug_taken(string $slug, int $exclude_id = 0): bool {
        global $wpdb;

        if ($exclude_id) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $this->table('views'), $slug, $exclude_id));
        }

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('views'), $slug));
    }

    /**
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed (nothing is flushed then).
     */
    public function insert(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('views'), $data);
        $id = (int) $wpdb->insert_id;

        if ($id) {
            $this->flush();
        }

        return $id;
    }

    /**
     * Insert the copy made by "duplicate". Unlike insert(), this flushes whether or not the
     * row was written - the duplicate route always has, and it is kept that way.
     *
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert_copy(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('views'), $data);
        $id = (int) $wpdb->insert_id;

        $this->flush();

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('views'), $data, ['id' => $id]);

        $this->flush();
    }

    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('views'), ['id' => $id]);

        $this->flush();
    }

    /**
     * Delete every listed view except the default one.
     *
     * @param string $id_list Comma-separated ids, already intval-mapped by the caller.
     * @return int|false Rows deleted, or false when the statement failed.
     */
    public function delete_many(string $id_list) {
        global $wpdb;

        // Exclude default view
        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so the statement stays a constant literal. $ids is already intval-mapped above.
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s) AND is_default = 0',
            $this->table('views'),
            $id_list
        ));

        $this->flush();

        return $deleted;
    }

    /** Make one view the default: two UPDATEs, one flush. */
    public function make_default(int $id): void {
        global $wpdb;

        // Unset all defaults
        $wpdb->update($this->table('views'), ['is_default' => 0], ['is_default' => 1]);

        // Set this one as default
        $wpdb->update($this->table('views'), ['is_default' => 1], ['id' => $id]);

        $this->flush();
    }
}
