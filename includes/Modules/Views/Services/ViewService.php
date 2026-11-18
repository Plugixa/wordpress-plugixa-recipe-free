<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Views\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Views\Repositories\ViewRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a saved view is and what may be done to one, as the admin app sees it.
 *
 * The rules live here rather than in the controller - a name is required, a slug is unique,
 * the default view cannot be deleted, a list can only be sorted by a column the query
 * actually orders by - together with the default config of the admin editor. It holds no SQL:
 * every query belongs to the repository.
 *
 * This is the ADMIN side. The frontend reads a view through ViewConfig / CssVars, which
 * carry their own defaults; the two are deliberately not merged.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" is null. The controller
 * maps both onto the response.
 */
final class ViewService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['name', 'created_at', 'modified_at', 'is_default'];

    private ViewRepository $views;

    public function __construct(ViewRepository $views) {
        $this->views = $views;
    }

    /**
     * @param array<string, mixed>               $filters    As read by the controller.
     * @param array{limit: int, offset: int}     $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $search = !empty($filters['search']) ? (string) $filters['search'] : '';

        // is_default is tri-state - absent means no filter, else match the given 0/1 exactly.
        $has_default = isset($filters['is_default']) && $filters['is_default'] !== '';
        $is_default  = $has_default ? (int) $filters['is_default'] : null;

        $layout_style = !empty($filters['layout_style']) ? (string) $filters['layout_style'] : '';

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->views->count($search, $is_default, $layout_style);
        $items = $this->views->page($search, $is_default, $layout_style, $orderby, $order, $pagination['limit'], $pagination['offset']);

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

    /** @return array<string, mixed>|null Null when there is no such view. */
    public function get(int $id): ?array {
        $item = $this->views->find($id);

        if (null === $item) {
            return null;
        }

        return $this->decorate($item);
    }

    /**
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return int|WP_Error The id of the new view.
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
        $refusal = $this->refuse_invalid_styles($config);
        if (null !== $refusal) {
            return $refusal;
        }
        if (isset($params['layout'])) {
            $layout_style = sanitize_text_field($params['layout']);
            $existing_layout = is_array($config['layout'] ?? null) ? $config['layout'] : [];
            $config['layout'] = array_merge($existing_layout, ['style' => $layout_style]);
        }

        $id = $this->views->insert([
            'name'        => $name,
            'slug'        => $slug,
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'config'      => wp_json_encode($config),
            'is_default'  => 0,
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ]);

        if (!$id) {
            return Failure::make(__('Failed to create view.', 'plugixa-recipe'), 500);
        }

        return $id;
    }

    /**
     * Refuse a config whose style values are not valid CSS, naming them.
     *
     * A View's colours, sizes, fonts and shadow are printed into a style attribute on every
     * page that lists recipes. CssVars validates each one as it prints and leaves out what
     * fails; this asks the same validators at save, so the editor is told which value was
     * wrong instead of finding it silently missing from the page. Refused, not corrected:
     * a value we rewrote would be a design the editor did not choose.
     *
     * @param mixed $config The `config` member of the payload: raw, untrusted.
     */
    private function refuse_invalid_styles($config): ?WP_Error {
        if (!is_array($config)) {
            return Failure::make(__('The view configuration is not valid.', 'plugixa-recipe'));
        }

        $rejected = (new CssVars())->rejected_values((new ViewConfig())->normalize($config));
        if (empty($rejected)) {
            return null;
        }

        return Failure::make(sprintf(
            /* translators: %s: comma-separated list of setting names, e.g. "colors.cardBg, shadows.value". */
            __('These style values are not valid: %s', 'plugixa-recipe'),
            implode(', ', $rejected)
        ));
    }

    /**
     * Change the fields present in $params; fields that are absent are left alone. The
     * modified stamp is always written, so there is never "nothing to update".
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return true|WP_Error
     */
    public function update(int $id, array $params) {
        $existing = $this->views->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('View not found.', 'plugixa-recipe'), 404);
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
            $refusal = $this->refuse_invalid_styles($config);
            if (null !== $refusal) {
                return $refusal;
            }
            if (isset($params['layout'])) {
                $layout_style = sanitize_text_field($params['layout']);
                $existing_layout = is_array($config['layout'] ?? null) ? $config['layout'] : [];
                $config['layout'] = array_merge($existing_layout, ['style' => $layout_style]);
            }
            $data['config'] = wp_json_encode($config);
        } elseif (isset($params['layout'])) {
            $existing_config = json_decode($existing['config'], true) ?: [];
            $layout_style = sanitize_text_field($params['layout']);
            $existing_layout = is_array($existing_config['layout'] ?? null) ? $existing_config['layout'] : [];
            $existing_config['layout'] = array_merge($existing_layout, ['style' => $layout_style]);
            $data['config'] = wp_json_encode($existing_config);
        }

        $data['modified_at'] = current_time('mysql');
        $data['modified_by'] = get_current_user_id();

        $this->views->update($id, $data);

        return true;
    }

    /** @return true|WP_Error */
    public function delete(int $id) {
        $view = $this->views->find_uncached($id);
        if (null === $view) {
            return Failure::make(__('View not found.', 'plugixa-recipe'), 404);
        }

        if ($view['is_default']) {
            return Failure::make(__('Cannot delete the default view.', 'plugixa-recipe'));
        }

        $this->views->delete($id);

        return true;
    }

    /**
     * Delete the listed views; the default view is skipped by the query itself.
     *
     * @param mixed $ids Raw payload value.
     * @return int|false|WP_Error Rows deleted.
     */
    public function delete_many($ids) {
        $ids = array_map('intval', is_array($ids) ? $ids : []);

        if (empty($ids)) {
            return Failure::make(__('No IDs provided.', 'plugixa-recipe'));
        }

        return $this->views->delete_many(implode(',', $ids));
    }

    /**
     * Copy a view under "<name> (Copy)" and a fresh slug; the copy is never the default.
     *
     * @return int|WP_Error The id of the copy (0 when the insert failed - the re-read by the caller
     *                      then reports it as not found, as it always has).
     */
    public function duplicate(int $id) {
        $now = current_time('mysql');

        $view = $this->views->find_uncached($id);
        if (null === $view) {
            return Failure::make(__('View not found.', 'plugixa-recipe'), 404);
        }

        $new_name = $view['name'] . ' (Copy)';
        $new_slug = $this->unique_slug(sanitize_title($new_name));

        $new_id = $this->views->insert_copy([
            'name'        => $new_name,
            'slug'        => $new_slug,
            'description' => $view['description'],
            'config'      => $view['config'],
            'is_default'  => 0,
            'created_at'  => $now,
            'created_by'  => get_current_user_id(),
        ]);

        // A failed insert returns 0. Unchecked, the route went on to look up view 0 and
        // answered "not found" for a copy that was never made.
        if (!$new_id) {
            return Failure::make(__('Failed to duplicate view.', 'plugixa-recipe'), 500);
        }

        return $new_id;
    }

    /** @return true|WP_Error */
    public function set_default(int $id) {
        if (!$this->views->exists($id)) {
            return Failure::make(__('View not found.', 'plugixa-recipe'), 404);
        }

        $this->views->make_default($id);

        return true;
    }

    /**
     * The config a new view starts from in the admin editor.
     *
     * @return array<string, mixed>
     */
    public function default_config(): array {
        return [
            'layout' => [
                'style'   => 'grid',
                'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
            ],
            'colors' => [
                'cardBg'         => '#ffffff',
                'textColor'      => '#1a1a2e',
                'titleColor'     => '#1a1a2e',
                'metaColor'      => '#6b7280',
                'starColor'      => '#f59e0b',
                'starEmptyColor' => '#d1d5db',
                'borderColor'    => '#e5e7eb',
                'badgeBg'        => '#f3f4f6',
                'badgeText'      => '#374151',
            ],
            // The palette itself is CssVars::DARK_PALETTE: the frontend falls back to it for a
            // view stored before it existed, so there is one copy.
            'darkMode' => ['enabled' => false] + CssVars::DARK_PALETTE,
            'typography' => [
                'titleFontSize'   => 18,
                'titleFontWeight' => 600,
                'titleFontFamily' => 'inherit',
                'textFontSize'    => 14,
                'textFontFamily'  => 'inherit',
                'metaFontSize'    => 13,
                'metaFontFamily'  => 'inherit',
            ],
            'spacing' => [
                'cardPadding'    => 0,
                'contentPadding' => 16,
                'gap'            => 24,
            ],
            'borders' => [
                'radius' => 12,
                'width'  => 1,
            ],
            'shadows' => [
                'enabled' => true,
                'value'   => '0 1px 3px rgba(0,0,0,0.06)',
            ],
            'image' => [
                'aspectRatio'  => '4/3',
                'objectFit'    => 'cover',
                'size'         => 'medium',
                'borderRadius' => 0,
                'hoverEffect'  => 'lift',
            ],
            'categories' => [
                'displayStyle' => 'none',
                'colors' => [
                    'backgroundColor'       => '#ffffff',
                    'textColor'             => '#374151',
                    'activeBackgroundColor' => '#1a1a2e',
                    'activeTextColor'       => '#ffffff',
                    'borderColor'           => '#e5e7eb',
                ],
                'typography' => ['fontSize' => 14, 'fontWeight' => 500, 'fontFamily' => 'inherit'],
                'spacing'    => ['padding' => 12, 'gap' => 8],
                'border'     => ['width' => 1, 'radius' => 100],
            ],
            'pagination' => [
                'style'     => 'numbers',
                'alignment' => 'center',
                'numbers' => [
                    'bg' => '#ffffff', 'text' => '#1a1a2e', 'border' => '#e5e7eb',
                    'activeBg' => '#1a1a2e', 'activeText' => '#ffffff', 'activeBorder' => '#1a1a2e',
                ],
                'loadMore' => [
                    'bg' => '#1a1a2e', 'text' => '#ffffff', 'border' => '#1a1a2e',
                    'hoverBg' => '#374151', 'hoverText' => '#ffffff', 'hoverBorder' => '#374151',
                ],
                'infiniteScroll' => [
                    'spinnerColor' => '#1a1a2e', 'textColor' => '#6b7280',
                ],
            ],
        ];
    }

    /**
     * A stored row as the admin app reads it: is_default as a bool, config decoded, and the
     * layout style lifted to the top level.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function decorate(array $item): array {
        $item['is_default'] = (bool) $item['is_default'];
        $item['config'] = json_decode($item['config'], true);
        $item['layout'] = $this->extract_layout($item['config']);

        return $item;
    }

    private function extract_layout(array $config): string {
        $layout = $config['layout'] ?? 'grid';
        if (is_array($layout)) {
            return $layout['style'] ?? 'grid';
        }
        return (string) $layout;
    }

    /** The slug itself, or the first of slug-2, slug-3, ... that no other view uses. */
    private function unique_slug(string $slug, int $exclude_id = 0): string {
        $original = $slug;
        $counter  = 1;

        while ($this->views->slug_taken($slug, $exclude_id)) {
            $slug = $original . '-' . (++$counter);
        }

        return $slug;
    }
}
