<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Collections\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Collections\Repositories\CollectionRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a collection (collection, cookbook, meal plan, favorites) is and what may be done to
 * one, on the admin side.
 *
 * The rules live here rather than in the controller - a name is required, a slug is unique,
 * a type comes from a fixed list, the admin app speaks active/inactive and is_public while
 * the table stores published/draft and public/private - so they hold for any caller. It holds
 * no SQL: every query belongs to the repository.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" on a read is null. The
 * controller maps both onto the response.
 */
final class CollectionService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['name', 'created_at', 'modified_at', 'display_order', 'type'];

    private const TYPES = ['collection', 'cookbook', 'meal_plan', 'favorites'];

    private CollectionRepository $collections;

    public function __construct(CollectionRepository $collections) {
        $this->collections = $collections;
    }

    /**
     * @param array<string, mixed>                  $filters    As read by the controller.
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $search     = !empty($filters['search']) ? (string) $filters['search'] : '';
        $type       = !empty($filters['type']) ? (string) $filters['type'] : '';
        $status     = !empty($filters['status']) ? (string) $filters['status'] : '';
        $user_id    = !empty($filters['user_id']) ? (int) $filters['user_id'] : 0;
        $visibility = !empty($filters['visibility']) ? (string) $filters['visibility'] : '';

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->collections->count($search, $type, $status, $user_id, $visibility);
        $items = $this->collections->page($search, $type, $status, $user_id, $visibility, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            $item['recipe_count'] = (int) $item['recipe_count'];
            $item['is_featured'] = (bool) $item['is_featured'];
            $item['title'] = $item['name'];
            $item['is_public'] = ($item['visibility'] ?? 'public') === 'public';
            $item['status'] = $this->map_status_to_frontend($item['status'] ?? 'published');
            if (!empty($item['user_id'])) {
                $user = get_userdata((int) $item['user_id']);
                $item['author_name'] = $user ? $user->display_name : '';
            }
            if (!empty($item['image_id'])) {
                $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'medium');
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
     * One collection with its recipes.
     *
     * @return array<string, mixed>|null Null when there is no such collection.
     */
    public function get(int $id): ?array {
        $item = $this->collections->find($id);

        if (null === $item) {
            return null;
        }

        $item['is_featured'] = (bool) $item['is_featured'];
        $item['title'] = $item['name'];
        $item['is_public'] = ($item['visibility'] ?? 'public') === 'public';
        $item['status'] = $this->map_status_to_frontend($item['status'] ?? 'published');

        if (!empty($item['image_id'])) {
            $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'large');
        }

        // Get recipes in collection
        $item['recipes'] = $this->collections->item_recipes($id);

        foreach ($item['recipes'] as &$recipe) {
            if (!empty($recipe['image_id'])) {
                $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
            }
            $recipe['rating_average'] = (float) $recipe['rating_average'];
        }
        unset($recipe);

        $item['recipe_count'] = count($item['recipes']);

        return $item;
    }

    /**
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new collection id.
     */
    public function create(array $params) {
        $name = sanitize_text_field($params['title'] ?? $params['name'] ?? '');
        if (empty($name)) {
            return Failure::make(__('Name is required.', 'plugixa-recipe'));
        }

        $type = sanitize_text_field($params['type'] ?? 'collection');
        if (!in_array($type, self::TYPES)) {
            $type = 'collection';
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);
        $slug = $this->unique_slug($slug);

        // Map frontend status (active/inactive) to DB status (published/draft)
        $status = $this->map_status_from_frontend(sanitize_text_field($params['status'] ?? 'active'));

        // Map frontend is_public (bool) to DB visibility (public/private)
        $visibility = sanitize_text_field($params['visibility'] ?? 'public');
        if (isset($params['is_public'])) {
            $visibility = $params['is_public'] ? 'public' : 'private';
        }

        $id = $this->collections->insert([
            'name'          => $name,
            'slug'          => $slug,
            'description'   => sanitize_textarea_field($params['description'] ?? ''),
            'image_id'      => !empty($params['image_id']) ? (int) $params['image_id'] : null,
            'type'          => $type,
            'status'        => $status,
            'is_featured'   => (int) ($params['is_featured'] ?? 0),
            'display_order' => (int) ($params['display_order'] ?? 0),
            'user_id'       => get_current_user_id(),
            'visibility'    => $visibility,
            'created_at'    => current_time('mysql'),
        ]);

        if (!$id) {
            return Failure::make(__('Failed to create collection.', 'plugixa-recipe'), 500);
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
        $existing = $this->collections->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        // Accept 'title' (frontend) or 'name' (DB)
        if (isset($params['title']) || isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['title'] ?? $params['name']);
        }
        if (isset($params['slug'])) {
            $slug = sanitize_title($params['slug']);
            if ($slug === '') {
                $slug = sanitize_title($params['name'] ?? $existing['name']);
            }
            if ($slug !== $existing['slug']) {
                $slug = $this->unique_slug($slug, $id);
            }
            $data['slug'] = $slug;
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['image_id'])) {
            $data['image_id'] = !empty($params['image_id']) ? (int) $params['image_id'] : null;
        }
        if (isset($params['type'])) {
            $data['type'] = in_array($params['type'], self::TYPES) ? $params['type'] : $existing['type'];
        }
        if (isset($params['status'])) {
            $data['status'] = $this->map_status_from_frontend(sanitize_text_field($params['status']));
        }
        if (isset($params['is_featured'])) {
            $data['is_featured'] = (int) $params['is_featured'];
        }
        if (isset($params['display_order'])) {
            $data['display_order'] = (int) $params['display_order'];
        }
        // Accept 'is_public' (frontend bool) or 'visibility' (DB string)
        if (isset($params['is_public'])) {
            $data['visibility'] = $params['is_public'] ? 'public' : 'private';
        } elseif (isset($params['visibility'])) {
            $data['visibility'] = sanitize_text_field($params['visibility']);
        }

        $data['modified_at'] = current_time('mysql');

        $this->collections->update($id, $data);

        return true;
    }

    /**
     * Delete a collection and its pivot entries.
     *
     * @return true|WP_Error
     */
    public function delete(int $id) {
        if (!$this->collections->exists($id)) {
            return Failure::make(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        $this->collections->delete($id);

        return true;
    }

    /**
     * The full recipe rows of one collection.
     *
     * @return array<int, array<string, mixed>>|null Null when there is no such collection.
     */
    public function recipes(int $id): ?array {
        if (null === $this->collections->find_uncached($id)) {
            return null;
        }

        $recipes = $this->collections->recipes($id);

        foreach ($recipes as &$recipe) {
            if (!empty($recipe['image_id'])) {
                $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium');
            }
            $recipe['rating_average'] = (float) $recipe['rating_average'];
        }
        unset($recipe);

        return $recipes;
    }

    /**
     * Add a recipe to a collection, after the ones already in it.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array<string, mixed>|WP_Error The pivot row that was inserted.
     */
    public function add_recipe(int $id, array $params) {
        $recipe_id = (int) ($params['recipe_id'] ?? 0);

        if (!$recipe_id) {
            return Failure::make(__('Recipe ID is required.', 'plugixa-recipe'));
        }

        // Verify both exist
        $collection = $this->collections->find_uncached($id);
        if (null === $collection) {
            return Failure::make(__('Collection not found.', 'plugixa-recipe'), 404);
        }

        if (!$this->collections->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Get next display_order
        $max_order = $this->collections->max_recipe_order($id);

        $insert_data = [
            'collection_id' => $id,
            'recipe_id'     => $recipe_id,
            'display_order' => $max_order + 1,
            'added_at'      => current_time('mysql'),
        ];

        // Meal plan support: day_number and meal_type
        if ($collection['type'] === 'meal_plan') {
            if (isset($params['day_number'])) {
                $insert_data['day_number'] = (int) $params['day_number'];
            }
            if (isset($params['meal_type'])) {
                $insert_data['meal_type'] = sanitize_text_field($params['meal_type']);
            }
        }

        if (!$this->collections->add_recipe($id, $insert_data)) {
            return Failure::make(__('Recipe already exists in this collection or could not be added.', 'plugixa-recipe'));
        }

        return $insert_data;
    }

    /**
     * Remove a recipe from a collection.
     *
     * @return true|WP_Error
     */
    public function remove_recipe(int $id, int $recipe_id) {
        if (!$this->collections->remove_recipe($id, $recipe_id)) {
            return Failure::make(__('Recipe not found in this collection.', 'plugixa-recipe'), 404);
        }

        return true;
    }

    /**
     * Reorder recipes in a collection.
     *
     * @param mixed $items Raw payload value: a list of {recipe_id, display_order, day_number?, meal_type?}.
     * @return true|WP_Error
     */
    public function reorder_recipes(int $id, $items) {
        if (empty($items) || !is_array($items)) {
            return Failure::make(__('Items are required.', 'plugixa-recipe'));
        }

        $rows = [];

        foreach ($items as $item) {
            $update = ['display_order' => (int) ($item['display_order'] ?? 0)];
            if (isset($item['day_number'])) {
                $update['day_number'] = (int) $item['day_number'];
            }
            if (isset($item['meal_type'])) {
                $update['meal_type'] = sanitize_text_field($item['meal_type']);
            }
            $rows[] = [
                'recipe_id' => (int) $item['recipe_id'],
                'update'    => $update,
            ];
        }

        $this->collections->reorder_recipes($id, $rows);

        return true;
    }

    /**
     * Map frontend status (active/inactive) to DB status (published/draft).
     */
    private function map_status_from_frontend(string $status): string {
        return match ($status) {
            'active'  => 'published',
            'inactive' => 'draft',
            default   => $status,
        };
    }

    /**
     * Map DB status (published/draft) to frontend status (active/inactive).
     */
    private function map_status_to_frontend(string $status): string {
        return match ($status) {
            'published' => 'active',
            'draft'     => 'inactive',
            default     => $status,
        };
    }

    /** The slug itself, or the first of slug-2, slug-3, ... that no other collection uses. */
    private function unique_slug(string $slug, int $exclude_id = 0): string {
        $original = $slug;
        $counter  = 1;

        while ($this->collections->slug_taken($slug, $exclude_id)) {
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
