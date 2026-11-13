<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Categories\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Categories\Repositories\CategoryRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a category is and what may be done to one.
 *
 * The rules live here rather than in the controller - a name is required, a slug is unique,
 * a list can only be sorted by a column the query actually orders by - so they hold for any
 * caller, not only the REST route. It holds no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" is null. The controller
 * maps both onto the response.
 */
final class CategoryService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['name', 'created_at', 'display_order'];

    private CategoryRepository $categories;

    public function __construct(CategoryRepository $categories) {
        $this->categories = $categories;
    }

    /**
     * @param array<string, mixed>               $filters    As read by the controller.
     * @param array{limit: int, offset: int}     $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $search   = !empty($filters['search']) ? (string) $filters['search'] : '';
        $language = !empty($filters['language_code']) ? (string) $filters['language_code'] : '';

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'display_order';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->categories->count($search, $language);
        $items = $this->categories->page($search, $language, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            $item['recipe_count'] = (int) $item['recipe_count'];
            if (!empty($item['image_id'])) {
                $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'thumbnail');
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

    /** @return array<string, mixed>|null Null when there is no such category. */
    public function get(int $id): ?array {
        $item = $this->categories->find($id);

        if (null === $item) {
            return null;
        }

        $item['recipe_count'] = $this->categories->recipe_count($id);

        if (!empty($item['image_id'])) {
            $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'medium');
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new category's id.
     */
    public function create(array $params) {
        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return Failure::make(__('Name is required.', 'plugixa-recipe'));
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);

        $id = $this->categories->insert([
            'name'              => $name,
            'slug'              => $this->unique_slug($slug),
            'description'       => sanitize_textarea_field($params['description'] ?? ''),
            'image_id'          => !empty($params['image_id']) ? (int) $params['image_id'] : null,
            'display_order'     => (int) ($params['display_order'] ?? 0),
            'language_code'     => sanitize_text_field($params['language_code'] ?? ''),
            'translation_group' => sanitize_text_field($params['translation_group'] ?? ''),
            'created_at'        => current_time('mysql'),
        ]);

        if (!$id) {
            return Failure::make(__('Failed to create category.', 'plugixa-recipe'), 500);
        }

        return $id;
    }

    /**
     * Change the fields present in $params; fields that are absent are left alone.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return true|WP_Error
     */
    public function update(int $id, array $params) {
        $existing = $this->categories->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Category not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['name']);
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
        if (isset($params['display_order'])) {
            $data['display_order'] = (int) $params['display_order'];
        }
        if (isset($params['language_code'])) {
            $data['language_code'] = sanitize_text_field($params['language_code']);
        }
        if (isset($params['translation_group'])) {
            $data['translation_group'] = sanitize_text_field($params['translation_group']);
        }

        if (empty($data)) {
            return Failure::make(__('No fields to update.', 'plugixa-recipe'));
        }

        $this->categories->update($id, $data);

        return true;
    }

    /** @return true|WP_Error */
    public function delete(int $id) {
        if (!$this->categories->exists($id)) {
            return Failure::make(__('Category not found.', 'plugixa-recipe'), 404);
        }

        $this->categories->delete($id);

        return true;
    }

    /**
     * @param mixed $ids Raw payload value.
     * @return true|WP_Error
     */
    public function delete_many($ids) {
        $ids = array_map('intval', is_array($ids) ? $ids : []);

        if (empty($ids)) {
            return Failure::make(__('No IDs provided.', 'plugixa-recipe'));
        }

        $this->categories->delete_many(implode(',', $ids));

        return true;
    }

    /**
     * @param mixed $items Raw payload value: a list of {id, display_order}.
     * @return true|WP_Error
     */
    public function reorder($items) {
        if (empty($items) || !is_array($items)) {
            return Failure::make(__('Items are required.', 'plugixa-recipe'));
        }

        $this->categories->reorder($items);

        return true;
    }

    /** The slug itself, or the first of slug-2, slug-3, ... that no other category uses. */
    private function unique_slug(string $slug, int $exclude_id = 0): string {
        $original = $slug;
        $counter  = 1;

        while ($this->categories->slug_taken($slug, $exclude_id)) {
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
