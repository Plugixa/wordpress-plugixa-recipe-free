<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Repositories\CourseRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a course is and what may be done to one.
 *
 * The rules live here rather than in the controller - a name is required, a slug is unique,
 * a list can only be sorted by a column the query actually orders by - so they hold for any
 * caller, not only the REST route. It holds no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" is null. The controller
 * maps both onto the response.
 */
final class CourseService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['name', 'display_order'];

    private CourseRepository $courses;

    public function __construct(CourseRepository $courses) {
        $this->courses = $courses;
    }

    /**
     * @param array<string, mixed>               $filters    As read by the controller.
     * @param array{limit: int, offset: int}     $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $search = !empty($filters['search']) ? (string) $filters['search'] : '';

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'display_order';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->courses->count($search);
        $items = $this->courses->page($search, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            $item['recipe_count'] = (int) $item['recipe_count'];
        }
        unset($item);

        return [
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /** @return array<string, mixed>|null Null when there is no such course. */
    public function get(int $id): ?array {
        $item = $this->courses->find($id);

        if (null === $item) {
            return null;
        }

        $item['recipe_count'] = $this->courses->recipe_count($id);

        return $item;
    }

    /**
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new course's id.
     */
    public function create(array $params) {
        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return Failure::make(__('Name is required.', 'plugixa-recipe'));
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);

        $id = $this->courses->insert([
            'name'          => $name,
            'slug'          => $this->unique_slug($slug),
            'description'   => sanitize_textarea_field($params['description'] ?? ''),
            'display_order' => (int) ($params['display_order'] ?? 0),
        ]);

        if (!$id) {
            return Failure::make(__('Failed to create course.', 'plugixa-recipe'), 500);
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
        $existing = $this->courses->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Course not found.', 'plugixa-recipe'), 404);
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
        if (isset($params['display_order'])) {
            $data['display_order'] = (int) $params['display_order'];
        }

        if (empty($data)) {
            return Failure::make(__('No fields to update.', 'plugixa-recipe'));
        }

        $this->courses->update($id, $data);

        return true;
    }

    /** @return true|WP_Error */
    public function delete(int $id) {
        if (!$this->courses->exists($id)) {
            return Failure::make(__('Course not found.', 'plugixa-recipe'), 404);
        }

        $this->courses->delete($id);

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

        $this->courses->reorder($items);

        return true;
    }

    /** The slug itself, or the first of slug-2, slug-3, ... that no other course uses. */
    private function unique_slug(string $slug, int $exclude_id = 0): string {
        $original = $slug;
        $counter  = 1;

        while ($this->courses->slug_taken($slug, $exclude_id)) {
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
