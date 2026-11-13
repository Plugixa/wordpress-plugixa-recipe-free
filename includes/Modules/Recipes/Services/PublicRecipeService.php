<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Repositories\PublicRecipeRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a visitor may do with recipes: browse the published ones, read one, and - when logged
 * in - like one.
 *
 * It decides which columns a public list can be sorted by and how a recipe is decorated for
 * display (image URLs, casts, the visitor's own favourite state), and fires the filter the
 * premium overlay attaches its public data through. It holds no SQL: every query, each pinned
 * to published recipes and a public column list, is PublicRecipeRepository's.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" on a read is null. The
 * controller maps both onto the response.
 */
final class PublicRecipeService {

    /** Columns the public list can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['title', 'created_at', 'rating_average', 'total_time', 'display_order', 'published_at'];

    private PublicRecipeRepository $recipes;

    public function __construct(PublicRecipeRepository $recipes) {
        $this->recipes = $recipes;
    }

    /**
     * Published recipes for frontend display.
     *
     * @param array<string, mixed>                  $criteria   The request's filters, coerced by the controller.
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $criteria, array $pagination, array $sort): array {
        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'published_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total   = $this->recipes->count_published($criteria);
        $recipes = $this->recipes->published_page($criteria, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($recipes as &$recipe) {
            $rid = (int) $recipe['id'];

            if (!empty($recipe['image_id'])) {
                $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium_large');
                $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
            }

            $recipe['rating_average'] = (float) $recipe['rating_average'];
            $recipe['rating_count'] = (int) $recipe['rating_count'];

            // Categories — child rows cached under the owning recipe.
            $recipe['categories'] = $this->recipes->listed_categories($rid);

            // Cuisines — child rows cached under the owning recipe.
            $recipe['cuisines'] = $this->recipes->listed_cuisines($rid);

            // Check favorite for logged-in user (per-user state: not cached).
            if (is_user_logged_in()) {
                $recipe['is_favorited'] = $this->recipes->is_favorited(get_current_user_id(), $rid);
            }
        }
        unset($recipe);

        return [
            'items'  => $recipes,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /**
     * Single published recipe with full data. Reading it counts a view.
     *
     * @return array<string, mixed>|null Null when there is no such recipe, or it is not published.
     */
    public function get(int $id): ?array {
        $recipe = $this->recipes->find_published($id);

        if (null === $recipe) {
            return null;
        }

        // Increment view count. Deliberately does NOT flush the cache - see the repository.
        $this->recipes->increment_view_count($id);

        // Image
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'large');
            $recipe['image_thumbnail'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
        }

        // Gallery
        if (!empty($recipe['gallery'])) {
            $gallery_ids = json_decode($recipe['gallery'], true);
            $recipe['gallery_urls'] = [];
            if (is_array($gallery_ids)) {
                foreach ($gallery_ids as $gid) {
                    $recipe['gallery_urls'][] = [
                        'id'  => (int) $gid,
                        'url' => wp_get_attachment_image_url((int) $gid, 'large'),
                    ];
                }
            }
        }

        // Categories — child rows cached under the owning recipe.
        $recipe['categories'] = $this->recipes->categories($id);

        // Cuisines — child rows cached under the owning recipe.
        $recipe['cuisines'] = $this->recipes->cuisines($id);

        // Courses — child rows cached under the owning recipe.
        $recipe['courses'] = $this->recipes->courses($id);

        // Ingredients — child rows cached under the owning recipe.
        $recipe['ingredients'] = $this->recipes->ingredients($id);

        // Instructions — child rows cached under the owning recipe.
        $recipe['instructions'] = $this->recipes->instructions($id);
        foreach ($recipe['instructions'] as &$inst) {
            if (!empty($inst['image_id'])) {
                $inst['image_url'] = wp_get_attachment_image_url((int) $inst['image_id'], 'medium');
            }
        }
        unset($inst);

        // Nutrition — child row cached under the owning recipe.
        $recipe['nutrition'] = $this->recipes->nutrition($id);

        /**
         * Filter the recipe returned to the public frontend.
         *
         * The premium overlay attaches the data belonging to its own features. The free
         * build ships none of that machinery, so nothing is attached.
         *
         * @param array<string,mixed> $recipe
         * @param int                 $id
         */
        $recipe = apply_filters('plugixa_recipe_prepare_recipe_frontend', $recipe, $id);

        // Favorite status (per-user state: not cached).
        if (is_user_logged_in()) {
            $recipe['is_favorited'] = $this->recipes->is_favorited(get_current_user_id(), $id);
        }

        $recipe['rating_average'] = (float) $recipe['rating_average'];
        $recipe['rating_count'] = (int) $recipe['rating_count'];

        return $recipe;
    }

    /**
     * Toggle the logged-in caller's like on a published recipe.
     *
     * The like row is the caller's own; the recipe's like counter moves with it and the
     * count returned is read back live, because the counter update does not flush the cache.
     *
     * @return array{is_liked: bool, like_count: int}|WP_Error
     */
    public function toggle_like(int $recipe_id) {
        $user_id = get_current_user_id();

        if (!$this->recipes->published_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $existing = $this->recipes->like_id($user_id, $recipe_id);

        if ($existing) {
            $this->recipes->remove_like($user_id, $recipe_id);
            $this->recipes->decrement_like_count($recipe_id);
        } else {
            $this->recipes->add_like([
                'user_id' => $user_id, 'recipe_id' => $recipe_id, 'created_at' => current_time('mysql'),
            ]);
            $this->recipes->increment_like_count($recipe_id);
        }

        $like_count = $this->recipes->like_count($recipe_id);

        $is_liked = !$existing;

        return [
            'is_liked'   => $is_liked,
            'like_count' => $like_count,
        ];
    }
}
