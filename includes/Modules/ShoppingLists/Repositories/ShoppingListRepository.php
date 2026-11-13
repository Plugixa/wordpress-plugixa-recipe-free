<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ShoppingLists\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `shopping_lists` and its `shopping_list_items`, plus the reads of
 * `recipes`, `ingredients`, `collections` and `collection_recipes` that generating a list
 * from a recipe or a meal plan needs.
 *
 * Lists and their items are one cache entity: an item write changes the list's counts and
 * its modified_at, so every write here flushes 'shopping_lists'.
 */
final class ShoppingListRepository extends Repository {

    protected function entities(): array {
        return ['shopping_lists'];
    }

    /**
     * Bound gate/value pairs for the shared WHERE block, in placeholder order.
     *
     * The user filter always applies; the other two are gate placeholders - bind 0 and
     * the clause is constant-true, which keeps WHERE literal instead of PHP-assembled.
     *
     * @return array<int, mixed> [user id, search gate, search, status gate, status]
     */
    private function filter(int $user_id, string $search, string $status): array {
        global $wpdb;

        $search = '' !== $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        return [
            $user_id,
            $search !== '' ? 1 : 0, $search,
            $status !== '' ? 1 : 0, $status,
        ];
    }

    /** One user's lists matching the filters. Empty strings mean "no filter". */
    public function count(int $user_id, string $search, string $status): int {
        global $wpdb;

        $filter_gates = $this->filter($user_id, $search, $status);

        // Cache key: a stable digest of the bound values, never the SQL text.
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_gates)), function () use ($wpdb, $filter_gates) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i sl
                  WHERE sl.user_id = %d
                    AND ( %d = 0 OR sl.name LIKE %s )
                    AND ( %d = 0 OR sl.status = %s )',
                ...array_merge([$this->table('shopping_lists')], $filter_gates)
            ));
        });
    }

    /**
     * One page of one user's lists, each row with its item counts.
     *
     * The ORDER BY column binds with %i from the allow-list in the service; a direction
     * keyword cannot be bound, so ASC/DESC is two literal queries. The id tiebreaker keeps
     * paging stable on ties.
     *
     * @param string $orderby A column from the allow-list in the service.
     * @param string $order   ASC or DESC.
     * @return array<int, array<string, mixed>>
     */
    public function page(int $user_id, string $search, string $status, string $orderby, string $order, int $limit, int $offset): array {
        global $wpdb;

        $filter_gates = $this->filter($user_id, $search, $status);

        $args = array_merge(
            [$this->table('shopping_lists'), $this->table('shopping_list_items')],
            $filter_gates,
            [$orderby, $limit, $offset]
        );

        return (array) $this->remember('list|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT sl.*,
                        COUNT(sli.id) AS total_items,
                        SUM(CASE WHEN sli.is_checked = 1 THEN 1 ELSE 0 END) AS checked_items
                    FROM %i sl
                    LEFT JOIN %i sli ON sl.id = sli.shopping_list_id
                    WHERE sl.user_id = %d
                      AND ( %d = 0 OR sl.name LIKE %s )
                      AND ( %d = 0 OR sl.status = %s )
                    GROUP BY sl.id
                    ORDER BY sl.%i ASC, sl.id ASC
                    LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT sl.*,
                    COUNT(sli.id) AS total_items,
                    SUM(CASE WHEN sli.is_checked = 1 THEN 1 ELSE 0 END) AS checked_items
                FROM %i sl
                LEFT JOIN %i sli ON sl.id = sli.shopping_list_id
                WHERE sl.user_id = %d
                  AND ( %d = 0 OR sl.name LIKE %s )
                  AND ( %d = 0 OR sl.status = %s )
                GROUP BY sl.id
                ORDER BY sl.%i DESC, sl.id DESC
                LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });
    }

    /**
     * Read-through object cache; key = list id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array {
        global $wpdb;

        $row = $this->remember('item|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('shopping_lists'), $id),
                ARRAY_A
            );
        });

        return $row ?: null;
    }

    /**
     * The list row as it is in the database right now, for the update path.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('shopping_lists'), $id), ARRAY_A);

        return $row ?: null;
    }

    /**
     * The items of one list, each with the title of the recipe it came from (NULL when the
     * item has none, or its recipe was deleted and the item detached).
     * Read-through object cache; key = list id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(int $id): array {
        global $wpdb;

        return (array) $this->remember('item_rows|' . $id, function () use ($wpdb, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT sli.*, r.title AS recipe_title
                    FROM %i sli
                    LEFT JOIN %i r ON sli.recipe_id = r.id
                    WHERE sli.shopping_list_id = %d
                    ORDER BY sli.sort_order ASC, sli.id ASC',
                    $this->table('shopping_list_items'),
                    $this->table('recipes'),
                    $id
                ),
                ARRAY_A
            ) ?: [];
        });
    }

    /**
     * The list the public shopping-list page keeps for a user. It has a status of its own
     * (`personal`): a list the same person made in the admin is `active`, and replacing
     * every line of THAT from a page in their browser would be a loss. Uncached - it
     * decides where a write goes.
     */
    public function personal_list_id(int $user_id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
            $this->table('shopping_lists'),
            $user_id,
            'personal'
        ));
    }

    /**
     * Replace every line of a user's OWN list. The list is matched on its owner as well
     * as its id, so a wrong id rewrites nothing.
     *
     * @param array<int, array<string, mixed>> $rows Item rows WITHOUT shopping_list_id.
     */
    public function replace_personal_items(int $list_id, int $user_id, array $rows): void {
        global $wpdb;

        $owned = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND user_id = %d',
            $this->table('shopping_lists'),
            $list_id,
            $user_id
        ));
        if (!$owned) {
            return;
        }

        $wpdb->delete($this->table('shopping_list_items'), ['shopping_list_id' => $owned]);
        foreach ($rows as $row) {
            $wpdb->insert($this->table('shopping_list_items'), array_merge(['shopping_list_id' => $owned], $row));
        }
        $wpdb->update($this->table('shopping_lists'), ['modified_at' => current_time('mysql')], ['id' => $owned]);

        // Content write.
        $this->flush();
    }

    /** The highest sort_order in the list, 0 when it is empty. */
    public function max_item_order(int $id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(sort_order) FROM %i WHERE shopping_list_id = %d',
            $this->table('shopping_list_items'),
            $id
        ));
    }

    /**
     * One item, only if it belongs to the given list. Uncached.
     *
     * @return array<string, mixed>|null
     */
    public function find_item_in_list(int $item_id, int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND shopping_list_id = %d',
            $this->table('shopping_list_items'),
            $item_id, $id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * A recipe's id and title, read from the recipes table, uncached.
     *
     * @return array<string, mixed>|null
     */
    public function recipe(int $recipe_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, title FROM %i WHERE id = %d',
            $this->table('recipes'),
            $recipe_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * One recipe's ingredient rows, in recipe order. Uncached.
     *
     * @return array<int, array<string, mixed>>|null As $wpdb->get_results() returns it.
     */
    public function recipe_ingredients(int $recipe_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
            $this->table('ingredients'),
            $recipe_id
        ), ARRAY_A);
    }

    /**
     * A collection row, only if it is a meal plan. Uncached.
     *
     * @return array<string, mixed>|null
     */
    public function meal_plan(int $collection_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND type = \'meal_plan\'',
            $this->table('collections'),
            $collection_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * All recipe IDs in the meal plan. Uncached.
     *
     * @return array<int, string>
     */
    public function meal_plan_recipe_ids(int $collection_id): array {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            'SELECT recipe_id FROM %i WHERE collection_id = %d',
            $this->table('collection_recipes'),
            $collection_id
        ));
    }

    /**
     * All ingredients of several recipes. The id list binds as ONE %s via FIND_IN_SET
     * rather than a variable-length IN (...), keeping the query a constant literal for any
     * meal-plan size.
     *
     * @param string $id_list Comma-separated ids, already intval-mapped by the caller.
     * @return array<int, array<string, mixed>>|null As $wpdb->get_results() returns it.
     */
    public function ingredients_for_recipes(string $id_list) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE FIND_IN_SET(recipe_id, %s) ORDER BY recipe_id ASC, group_order ASC, sort_order ASC',
            $this->table('ingredients'),
            $id_list
        ), ARRAY_A);
    }

    /**
     * Insert a list, then its item rows. No flush: each public create method below owns its.
     *
     * An item's recipe_id may be null on purpose - the column is nullable, and a deleted
     * recipe's items are detached rather than removed.
     *
     * @param array<string, mixed>             $list
     * @param array<int, array<string, mixed>> $items Item rows WITHOUT shopping_list_id.
     * @return int The new list id, or 0 when the list insert failed (no item is written).
     */
    private function insert_with_items(array $list, array $items): int {
        global $wpdb;

        $wpdb->insert($this->table('shopping_lists'), $list);
        $id = (int) $wpdb->insert_id;

        if (!$id) {
            return 0;
        }

        foreach ($items as $item) {
            $wpdb->insert($this->table('shopping_list_items'), array_merge(['shopping_list_id' => $id], $item));
        }

        return $id;
    }

    /**
     * A list the user wrote, with any initial items.
     *
     * @param array<string, mixed>             $list
     * @param array<int, array<string, mixed>> $items
     * @return int The new id, or 0 when the insert failed (nothing is flushed then).
     */
    public function create(array $list, array $items): int {
        $id = $this->insert_with_items($list, $items);

        if ($id) {
            // Content write.
            $this->flush();
        }

        return $id;
    }

    /**
     * A list generated from one recipe's ingredients.
     *
     * @param array<string, mixed>             $list
     * @param array<int, array<string, mixed>> $items
     * @return int The new id, or 0 when the insert failed (nothing is flushed then).
     */
    public function create_from_recipe(array $list, array $items): int {
        $id = $this->insert_with_items($list, $items);

        if ($id) {
            // Content write.
            $this->flush();
        }

        return $id;
    }

    /**
     * A list generated from the aggregated ingredients of a meal plan.
     *
     * @param array<string, mixed>             $list
     * @param array<int, array<string, mixed>> $items
     * @return int The new id, or 0 when the insert failed (nothing is flushed then).
     */
    public function create_from_meal_plan(array $list, array $items): int {
        $id = $this->insert_with_items($list, $items);

        if ($id) {
            // Content write.
            $this->flush();
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('shopping_lists'), $data, ['id' => $id]);

        // Content write.
        $this->flush();
    }

    /** Delete a shopping list with items. */
    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('shopping_list_items'), ['shopping_list_id' => $id]);
        $wpdb->delete($this->table('shopping_lists'), ['id' => $id]);

        // Content write.
        $this->flush();
    }

    /**
     * Add one item to a list, stamp the list as modified, and read the new row back.
     *
     * @param array<string, mixed> $row The item row, shopping_list_id included.
     * @return array<string, mixed>|null The stored row; null when it could not be read back.
     */
    public function add_item(int $id, array $row): ?array {
        global $wpdb;

        $wpdb->insert($this->table('shopping_list_items'), $row);

        $item_id = $wpdb->insert_id;

        $wpdb->update($this->table('shopping_lists'), ['modified_at' => current_time('mysql')], ['id' => $id]);

        $new_item = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $this->table('shopping_list_items'),
            $item_id
        ), ARRAY_A);

        // Content write.
        $this->flush();

        return $new_item;
    }

    /**
     * Write the changed fields of one item (none is allowed: nothing is written then),
     * stamp the list as modified, and read the row back. Flushes either way.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null The stored row.
     */
    public function update_item(int $id, int $item_id, array $data): ?array {
        global $wpdb;

        if (!empty($data)) {
            $wpdb->update($this->table('shopping_list_items'), $data, ['id' => $item_id]);
            $wpdb->update($this->table('shopping_lists'), ['modified_at' => current_time('mysql')], ['id' => $id]);
        }

        $updated = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $this->table('shopping_list_items'),
            $item_id
        ), ARRAY_A);

        // Content write.
        $this->flush();

        return $updated;
    }

    /**
     * Delete one item of a list and stamp the list as modified.
     *
     * @return bool False when the list has no such item; nothing is flushed then.
     */
    public function delete_item(int $id, int $item_id): bool {
        global $wpdb;

        $deleted = $wpdb->delete($this->table('shopping_list_items'), [
            'id'               => $item_id,
            'shopping_list_id' => $id,
        ]);

        if (!$deleted) {
            return false;
        }

        $wpdb->update($this->table('shopping_lists'), ['modified_at' => current_time('mysql')], ['id' => $id]);

        // Content write.
        $this->flush();

        return true;
    }
}
