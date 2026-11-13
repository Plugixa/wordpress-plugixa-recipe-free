<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `shortcodes`, plus the one read of a view's name that a saved
 * shortcode is shown with.
 */
final class ShortcodeRepository extends Repository {

    protected function entities(): array {
        return ['shortcodes'];
    }

    /**
     * Bound values for the shared WHERE block, table name first, in placeholder order.
     *
     * The search filter is a gate placeholder: bind 0 and the clause is constant-true,
     * which keeps WHERE literal instead of PHP-assembled.
     *
     * @return array<int, mixed> [table, search gate, search, search]
     */
    private function filter_args(string $search): array {
        global $wpdb;

        $search = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        return [
            $this->table('shortcodes'),
            $search !== '' ? 1 : 0, $search, $search,
        ];
    }

    /** Rows matching the list filter. An empty string means "no filter". */
    public function count(string $search): int {
        global $wpdb;

        $filter_args = $this->filter_args($search);

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )',
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
    public function page(string $search, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        $args = array_merge($this->filter_args($search), [$orderby, $limit, $offset]);

        return (array) $this->remember('items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT * FROM %i
                      WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
                      ORDER BY %i ASC, id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i
                  WHERE ( %d = 0 OR (name LIKE %s OR description LIKE %s) )
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
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('shortcodes'), $id),
                ARRAY_A
            );
        });

        return $row ?: null;
    }

    /**
     * The row as it is in the database right now - for an update that must compare against
     * the stored slug and config, and for a duplicate, where a cached copy could be one
     * write behind.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('shortcodes'), $id), ARRAY_A);

        return $row ?: null;
    }

    public function exists(int $id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('shortcodes'), $id));
    }

    /**
     * The name of the view a shortcode points at, or '' when there is no such view.
     *
     * Cached under the "views" entity: a view write invalidates this name lookup.
     *
     * @return string|null The stored value, uncast, as the response has always carried it.
     */
    public function view_name(int $view_id) {
        global $wpdb;

        $view = $this->remember_as('views', "name:{$view_id}", function () use ($wpdb, $view_id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT name FROM %i WHERE id = %d', $this->table('views'), $view_id
            ), ARRAY_A);
        });

        return $view ? $view['name'] : '';
    }

    /** Is the slug used by a shortcode other than $exclude_id? */
    public function slug_taken(string $slug, int $exclude_id = 0): bool {
        global $wpdb;

        if ($exclude_id) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $this->table('shortcodes'), $slug, $exclude_id));
        }

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('shortcodes'), $slug));
    }

    /**
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed (nothing is flushed then).
     */
    public function insert(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('shortcodes'), $data);
        $id = (int) $wpdb->insert_id;

        if ($id) {
            $this->flush();
        }

        return $id;
    }

    /**
     * Insert the copy made by "duplicate".
     *
     * Separate from insert() because this path has always flushed whether or not the insert
     * produced an id, and the caller re-reads whatever id comes back.
     *
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert_copy(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('shortcodes'), $data);
        $id = (int) $wpdb->insert_id;

        $this->flush();

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('shortcodes'), $data, ['id' => $id]);

        $this->flush();
    }

    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('shortcodes'), ['id' => $id]);

        $this->flush();
    }

    /**
     * @param string $id_list Comma-separated ids, already intval-mapped by the caller.
     * @return int|false Rows deleted, exactly as $wpdb->query() reports it.
     */
    public function delete_many(string $id_list) {
        global $wpdb;

        // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length IN (...),
        // so the statement stays a constant literal.
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s)',
            $this->table('shortcodes'),
            $id_list
        ));

        $this->flush();

        return $deleted;
    }
}
