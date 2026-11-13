<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Shortcodes\Repositories\ShortcodeRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a saved shortcode is and what may be done to one.
 *
 * The rules live here rather than in the controller - a name is required, a slug is unique,
 * the view a shortcode points at is stored inside its config, a list can only be sorted by a
 * column the query actually orders by. It holds no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" is null. The controller
 * maps both onto the response.
 */
final class ShortcodeService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['name', 'created_at', 'modified_at'];

    private ShortcodeRepository $shortcodes;

    public function __construct(ShortcodeRepository $shortcodes) {
        $this->shortcodes = $shortcodes;
    }

    /**
     * @param array<string, mixed>                  $filters    As read by the controller.
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $search = !empty($filters['search']) ? (string) $filters['search'] : '';

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->shortcodes->count($search);
        $items = $this->shortcodes->page($search, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            $item = $this->decorate($item);
        }
        unset($item);

        return [
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /** @return array<string, mixed>|null Null when there is no such shortcode. */
    public function get(int $id): ?array {
        $item = $this->shortcodes->find($id);

        if (null === $item) {
            return null;
        }

        return $this->decorate($item);
    }

    /**
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The new shortcode's id.
     */
    public function create(array $params) {
        $now = current_time('mysql');

        $name = sanitize_text_field($params['name'] ?? '');
        if (empty($name)) {
            return Failure::make(__('Name is required.', 'plugixa-recipe'));
        }

        $slug = sanitize_title($params['slug'] ?? '') ?: sanitize_title($name);
        $slug = $this->unique_slug($slug);

        $config = $params['config'] ?? [];
        if (isset($params['view_id'])) {
            $config['view_id'] = (int) $params['view_id'];
        }

        $id = $this->shortcodes->insert([
            'name'        => $name,
            'slug'        => $slug,
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'config'      => wp_json_encode($config),
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ]);

        if (!$id) {
            return Failure::make(__('Failed to create shortcode.', 'plugixa-recipe'), 500);
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
        $existing = $this->shortcodes->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Shortcode not found.', 'plugixa-recipe'), 404);
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
        if (isset($params['config'])) {
            $config = $params['config'];
            if (isset($params['view_id'])) {
                $config['view_id'] = (int) $params['view_id'];
            }
            $data['config'] = wp_json_encode($config);
        } elseif (isset($params['view_id'])) {
            $existing_config = json_decode($existing['config'], true) ?: [];
            $existing_config['view_id'] = (int) $params['view_id'];
            $data['config'] = wp_json_encode($existing_config);
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        $this->shortcodes->update($id, $data);

        return true;
    }

    /** @return true|WP_Error */
    public function delete(int $id) {
        if (!$this->shortcodes->exists($id)) {
            return Failure::make(__('Shortcode not found.', 'plugixa-recipe'), 404);
        }

        $this->shortcodes->delete($id);

        return true;
    }

    /**
     * @param mixed $ids Raw payload value.
     * @return int|false|WP_Error Rows deleted, as the database reports it.
     */
    public function delete_many($ids) {
        $ids = array_map('intval', is_array($ids) ? $ids : []);

        if (empty($ids)) {
            return Failure::make(__('No IDs provided.', 'plugixa-recipe'));
        }

        return $this->shortcodes->delete_many(implode(',', $ids));
    }

    /**
     * Copy a shortcode under a new name and slug.
     *
     * @return int|WP_Error The copy's id.
     */
    public function duplicate(int $id) {
        $now = current_time('mysql');

        $item = $this->shortcodes->find_uncached($id);
        if (null === $item) {
            return Failure::make(__('Shortcode not found.', 'plugixa-recipe'), 404);
        }

        $new_name = $item['name'] . ' (Copy)';
        $new_slug = $this->unique_slug(sanitize_title($new_name));

        $new_id = $this->shortcodes->insert_copy([
            'name'        => $new_name,
            'slug'        => $new_slug,
            'description' => $item['description'],
            'config'      => $item['config'],
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ]);

        // A failed insert returns 0. Unchecked, the route went on to look up shortcode 0
        // and answered "not found" for a copy that was never made.
        if (!$new_id) {
            return Failure::make(__('Failed to duplicate shortcode.', 'plugixa-recipe'), 500);
        }

        return $new_id;
    }

    /**
     * Decode the stored config and lift the view it points at onto the row.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function decorate(array $item): array {
        $item['config'] = json_decode($item['config'], true);

        $item['view_id'] = (int) ($item['config']['view_id'] ?? 0);
        if ($item['view_id']) {
            $item['view_name'] = $this->shortcodes->view_name($item['view_id']);
        } else {
            $item['view_name'] = '';
        }

        return $item;
    }

    /** The slug itself, or the first of slug-2, slug-3, ... that no other shortcode uses. */
    private function unique_slug(string $slug, int $exclude_id = 0): string {
        $original = $slug;
        $counter  = 1;

        while ($this->shortcodes->slug_taken($slug, $exclude_id)) {
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
