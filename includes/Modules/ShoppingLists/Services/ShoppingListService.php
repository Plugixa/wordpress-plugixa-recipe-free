<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ShoppingLists\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\ShoppingLists\Repositories\ShoppingListRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a shopping list is and what may be done to one.
 *
 * The rules live here rather than in the controller - a list and an item need a name, the
 * list endpoint shows the current user's lists only, a list generated from a meal plan adds
 * up matching ingredients - so they hold for any caller. It holds no SQL: every query
 * belongs to the repository.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" on a read is null. The
 * controller maps both onto the response.
 */
final class ShoppingListService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['name', 'created_at', 'modified_at', 'status'];

    private ShoppingListRepository $lists;

    public function __construct(ShoppingListRepository $lists) {
        $this->lists = $lists;
    }

    /**
     * Is this list the current user's own?
     *
     * A shopping list is personal: the list route only ever returns the caller's own lists,
     * and every route that takes a list id holds to the same rule. Until this check the
     * single-list routes took any id, so anyone who could edit content could read, rename,
     * fill or delete another user's list by counting upwards.
     *
     * A list that exists but belongs to someone else is answered exactly like one that does
     * not exist (404), so the route does not confirm which ids are taken.
     *
     * @param array<string, mixed>|null $list A shopping_lists row, or null.
     */
    private function owns(?array $list): bool {
        return null !== $list && (int) ($list['user_id'] ?? 0) === get_current_user_id();
    }

    /** How many lines a visitor's own list may hold. */
    private const PERSONAL_LIMIT = 300;

    /**
     * The caller's own list, as the public shopping-list page keeps it.
     *
     * @return array<int, array{name:string, quantity:string, unit:string, checked:bool}>
     */
    public function personal_items(): array {
        $list_id = $this->lists->personal_list_id(get_current_user_id());
        $items   = [];

        foreach ($list_id ? $this->lists->items($list_id) : [] as $row) {
            $items[] = [
                'name'     => (string) $row['ingredient_name'],
                'quantity' => (string) ($row['quantity'] ?? ''),
                'unit'     => (string) ($row['unit'] ?? ''),
                'checked'  => !empty($row['is_checked']),
            ];
        }

        return $items;
    }

    /**
     * Replace the caller's own list with what their browser holds.
     *
     * The whole list is sent each time: the page is the editor and this is its copy, so
     * there is no per-line id for another caller to guess. A line without a name is
     * dropped; anything past the limit is dropped rather than refused, so a long list
     * still saves.
     *
     * @param array<int, mixed> $items Raw, untrusted payload.
     * @return array<int, array{name:string, quantity:string, unit:string, checked:bool}> What was stored.
     */
    public function replace_personal_items(array $items): array {
        $user_id = get_current_user_id();
        $now     = current_time('mysql');
        $rows    = [];

        foreach ($items as $item) {
            if (!is_array($item) || count($rows) >= self::PERSONAL_LIMIT) {
                continue;
            }
            $name = self::line($item['name'] ?? '', 500);
            if ('' === $name) {
                continue;
            }
            $rows[] = [
                'recipe_id'       => null,
                'ingredient_name' => $name,
                'quantity'        => self::line($item['quantity'] ?? '', 50),
                'unit'            => self::line($item['unit'] ?? '', 50),
                'is_checked'      => empty($item['checked']) ? 0 : 1,
                'sort_order'      => count($rows),
                'added_at'        => $now,
            ];
        }

        $list_id = $this->lists->personal_list_id($user_id);
        if (!$list_id) {
            $this->lists->create([
                'user_id'    => $user_id,
                'name'       => __('My shopping list', 'plugixa-recipe'),
                // Not `active`: see ShoppingListRepository::personal_list_id().
                'status'     => 'personal',
                'created_at' => $now,
            ], $rows);
        } else {
            $this->lists->replace_personal_items($list_id, $user_id, $rows);
        }

        return $this->personal_items();
    }

    /** One line of text from an untrusted value, cut to its column. */
    private static function line($value, int $max): string {
        $text = is_scalar($value) ? sanitize_text_field((string) $value) : '';

        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * Shopping lists for the current user.
     *
     * @param array<string, mixed>                  $filters    As read by the controller.
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $user_id = get_current_user_id();

        $search = !empty($filters['search']) ? (string) $filters['search'] : '';
        $status = !empty($filters['status']) ? (string) $filters['status'] : '';

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->lists->count($user_id, $search, $status);
        $items = $this->lists->page($user_id, $search, $status, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            $item['total_items'] = (int) $item['total_items'];
            $item['checked_items'] = (int) $item['checked_items'];
            $item['title'] = $item['name'];
            if (!empty($item['user_id'])) {
                $user = get_userdata((int) $item['user_id']);
                $item['author_name'] = $user ? $user->display_name : '';
            }
        }
        unset($item);

        return [
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /**
     * One shopping list with its items.
     *
     * @return array<string, mixed>|null Null when there is no such list.
     */
    public function get(int $id): ?array {
        $item = $this->lists->find($id);

        if (!$this->owns($item)) {
            return null;
        }

        $item['items'] = $this->lists->items($id);

        foreach ($item['items'] as &$li) {
            $li['is_checked'] = (bool) $li['is_checked'];
        }
        unset($li);

        return $item;
    }

    /**
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new list id.
     */
    public function create(array $params) {
        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return Failure::make(__('Name is required.', 'plugixa-recipe'));
        }

        $data = [
            'user_id'    => get_current_user_id(),
            'name'       => $name,
            'status'     => sanitize_text_field($params['status'] ?? 'active'),
            'created_at' => current_time('mysql'),
        ];

        // Add initial items if provided
        $items = [];
        if (!empty($params['items'])) {
            foreach ($params['items'] as $i => $li) {
                $items[] = [
                    'recipe_id'       => !empty($li['recipe_id']) ? (int) $li['recipe_id'] : null,
                    'ingredient_name' => sanitize_text_field($li['ingredient_name'] ?? ''),
                    'quantity'        => sanitize_text_field($li['quantity'] ?? ''),
                    'unit'            => sanitize_text_field($li['unit'] ?? ''),
                    'is_checked'      => 0,
                    'sort_order'      => $i,
                    'added_at'        => current_time('mysql'),
                ];
            }
        }

        $id = $this->lists->create($data, $items);

        if (!$id) {
            return Failure::make(__('Failed to create shopping list.', 'plugixa-recipe'), 500);
        }

        return $id;
    }

    /**
     * Change the fields present in $params; fields that are absent are left alone.
     * modified_at is always stamped, so there is always something to write.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return true|WP_Error
     */
    public function update(int $id, array $params) {
        $existing = $this->lists->find_uncached($id);
        if (!$this->owns($existing)) {
            return Failure::make(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['status'])) {
            $data['status'] = sanitize_text_field($params['status']);
        }

        $data['modified_at'] = current_time('mysql');

        $this->lists->update($id, $data);

        return true;
    }

    /**
     * Delete a shopping list with items.
     *
     * @return true|WP_Error
     */
    public function delete(int $id) {
        if (!$this->owns($this->lists->find_uncached($id))) {
            return Failure::make(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        $this->lists->delete($id);

        return true;
    }

    /**
     * Add an item to a shopping list, after the ones already in it.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array<string, mixed>|null|WP_Error The stored item row.
     */
    public function add_item(int $id, array $params) {
        if (!$this->owns($this->lists->find_uncached($id))) {
            return Failure::make(__('Shopping list not found.', 'plugixa-recipe'), 404);
        }

        $ingredient_name = sanitize_text_field($params['ingredient_name'] ?? '');
        if (empty($ingredient_name)) {
            return Failure::make(__('Ingredient name is required.', 'plugixa-recipe'));
        }

        $max_order = $this->lists->max_item_order($id);

        return $this->lists->add_item($id, [
            'shopping_list_id' => $id,
            'recipe_id'        => !empty($params['recipe_id']) ? (int) $params['recipe_id'] : null,
            'ingredient_name'  => $ingredient_name,
            'quantity'         => sanitize_text_field($params['quantity'] ?? ''),
            'unit'             => sanitize_text_field($params['unit'] ?? ''),
            'is_checked'       => 0,
            'sort_order'       => $max_order + 1,
            'added_at'         => current_time('mysql'),
        ]);
    }

    /**
     * Update a shopping list item.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array<string, mixed>|WP_Error The stored item row.
     */
    public function update_item(int $id, int $item_id, array $params) {
        $existing = $this->owns($this->lists->find_uncached($id)) ? $this->lists->find_item_in_list($item_id, $id) : null;

        if (null === $existing) {
            return Failure::make(__('Item not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['ingredient_name'])) {
            $data['ingredient_name'] = sanitize_text_field($params['ingredient_name']);
        }
        if (isset($params['quantity'])) {
            $data['quantity'] = sanitize_text_field($params['quantity']);
        }
        if (isset($params['unit'])) {
            $data['unit'] = sanitize_text_field($params['unit']);
        }
        if (isset($params['is_checked'])) {
            $data['is_checked'] = (int) $params['is_checked'];
        }
        if (isset($params['sort_order'])) {
            $data['sort_order'] = (int) $params['sort_order'];
        }

        $updated = $this->lists->update_item($id, $item_id, $data);
        $updated['is_checked'] = (bool) $updated['is_checked'];

        return $updated;
    }

    /**
     * Delete a shopping list item.
     *
     * @return true|WP_Error
     */
    public function delete_item(int $id, int $item_id) {
        if (!$this->owns($this->lists->find_uncached($id)) || !$this->lists->delete_item($id, $item_id)) {
            return Failure::make(__('Item not found.', 'plugixa-recipe'), 404);
        }

        return true;
    }

    /**
     * Create a shopping list from a recipe's ingredients.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new list id.
     */
    public function create_from_recipe(int $recipe_id, array $params) {
        $recipe = $this->lists->recipe($recipe_id);

        if (null === $recipe) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $ingredients = $this->lists->recipe_ingredients($recipe_id);

        if (empty($ingredients)) {
            return Failure::make(__('Recipe has no ingredients.', 'plugixa-recipe'));
        }

        /* translators: %s: recipe title the shopping list was generated from. */
        $list_name = sanitize_text_field($params['name'] ?? '') ?: sprintf(__('Shopping List - %s', 'plugixa-recipe'), $recipe['title']);

        $list = [
            'user_id'    => get_current_user_id(),
            'name'       => $list_name,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ];

        $items = [];
        foreach ($ingredients as $i => $ing) {
            $items[] = [
                'recipe_id'       => $recipe_id,
                'ingredient_name' => $ing['name'],
                'quantity'        => $ing['quantity'],
                'unit'            => $ing['unit'],
                'is_checked'      => 0,
                'sort_order'      => $i,
                'added_at'        => current_time('mysql'),
            ];
        }

        $list_id = $this->lists->create_from_recipe($list, $items);

        if (!$list_id) {
            return Failure::make(__('Failed to create shopping list.', 'plugixa-recipe'), 500);
        }

        return $list_id;
    }

    /**
     * Create a shopping list from all recipes in a meal plan collection.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new list id.
     */
    public function create_from_meal_plan(int $collection_id, array $params) {
        $collection = $this->lists->meal_plan($collection_id);

        if (null === $collection) {
            return Failure::make(__('Meal plan not found.', 'plugixa-recipe'), 404);
        }

        // Get all recipe IDs in the meal plan
        $recipe_ids = $this->lists->meal_plan_recipe_ids($collection_id);

        if (empty($recipe_ids)) {
            return Failure::make(__('Meal plan has no recipes.', 'plugixa-recipe'));
        }

        /* translators: %s: collection (meal plan) name the shopping list was generated from. */
        $list_name = sanitize_text_field($params['name'] ?? '') ?: sprintf(__('Shopping List - %s', 'plugixa-recipe'), $collection['name']);

        $list = [
            'user_id'    => get_current_user_id(),
            'name'       => $list_name,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ];

        // Get all ingredients from all recipes in the meal plan.
        $ingredients = $this->lists->ingredients_for_recipes(implode(',', array_map('intval', $recipe_ids)));

        // Aggregate similar ingredients
        $aggregated = [];
        foreach ($ingredients as $ing) {
            $key = strtolower(trim($ing['name'])) . '|' . strtolower(trim($ing['unit']));
            if (isset($aggregated[$key])) {
                // Add quantities if both are numeric
                if (is_numeric($aggregated[$key]['quantity']) && is_numeric($ing['quantity'])) {
                    $aggregated[$key]['quantity'] = (float) $aggregated[$key]['quantity'] + (float) $ing['quantity'];
                    $aggregated[$key]['quantity'] = (string) $aggregated[$key]['quantity'];
                }
            } else {
                $aggregated[$key] = [
                    'recipe_id'       => (int) $ing['recipe_id'],
                    'ingredient_name' => $ing['name'],
                    'quantity'        => $ing['quantity'],
                    'unit'            => $ing['unit'],
                ];
            }
        }

        $items = [];
        $sort  = 0;
        foreach ($aggregated as $item) {
            $items[] = [
                'recipe_id'       => $item['recipe_id'],
                'ingredient_name' => $item['ingredient_name'],
                'quantity'        => $item['quantity'],
                'unit'            => $item['unit'],
                'is_checked'      => 0,
                'sort_order'      => $sort++,
                'added_at'        => current_time('mysql'),
            ];
        }

        $list_id = $this->lists->create_from_meal_plan($list, $items);

        if (!$list_id) {
            return Failure::make(__('Failed to create shopping list.', 'plugixa-recipe'), 500);
        }

        return $list_id;
    }
}
