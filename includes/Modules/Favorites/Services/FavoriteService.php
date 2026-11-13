<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Favorites\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Favorites\Repositories\FavoriteRepository;
use PlugixaRecipe\Modules\Favorites\Repositories\PublicFavoriteRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a favourite is and what may be done to one.
 *
 * A favourite belongs to one user and one recipe, and every operation here is keyed on the
 * user it is given - the caller's own id, never one read from the request. It holds no SQL:
 * the admin routes' queries are FavoriteRepository's, the public toggle's are
 * PublicFavoriteRepository's, whose recipe lookup is pinned to published recipes.
 *
 * A refusal is returned as a WP_Error, never thrown. The controller maps it onto the response.
 */
final class FavoriteService {

    /** Sort columns that live on the recipe; anything else sorts by the favourite's created_at. */
    private const SORTABLE_ON_RECIPE = ['title', 'rating_average'];

    private FavoriteRepository $favorites;

    private PublicFavoriteRepository $public_favorites;

    public function __construct(FavoriteRepository $favorites, PublicFavoriteRepository $public_favorites) {
        $this->favorites        = $favorites;
        $this->public_favorites = $public_favorites;
    }

    /**
     * The user's favourite recipes, one page of them.
     *
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(int $user_id, array $pagination, array $sort): array {
        $total = $this->favorites->count_for_user($user_id);

        $on_recipe = in_array($sort['orderby'], self::SORTABLE_ON_RECIPE, true);
        $orderby   = $on_recipe ? $sort['orderby'] : 'created_at';
        $order     = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $items = $this->favorites->page_for_user($user_id, $on_recipe, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            if (!empty($item['image_id'])) {
                $item['image_url'] = wp_get_attachment_image_url((int) $item['image_id'], 'medium');
                $item['image_thumbnail'] = wp_get_attachment_image_url((int) $item['image_id'], 'thumbnail');
            }
            $item['rating_average'] = (float) $item['rating_average'];
            $item['rating_count'] = (int) $item['rating_count'];
            $item['is_favorited'] = true;
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
     * Add the recipe to the user's favourites, or remove it if it is already there.
     * The admin route: a recipe in any status can be favourited.
     *
     * @return bool|WP_Error Whether the recipe is a favourite AFTER the toggle.
     */
    public function toggle(int $user_id, int $recipe_id) {
        // Verify recipe exists
        if (!$this->favorites->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Check if already favorited
        if ($this->favorites->is_favorited_uncached($user_id, $recipe_id)) {
            // Remove favorite
            $this->favorites->remove($user_id, $recipe_id);

            return false;
        }

        // Add favorite
        $this->favorites->add($user_id, $recipe_id, current_time('mysql'));

        return true;
    }

    /**
     * The public toggle: the same, but only a PUBLISHED recipe can be favourited - anything
     * else is "not found", so the route never confirms that a draft exists.
     *
     * @return bool|WP_Error Whether the recipe is a favourite AFTER the toggle.
     */
    public function toggle_published(int $user_id, int $recipe_id) {
        if (!$this->public_favorites->published_recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        if ($this->public_favorites->is_favorited($user_id, $recipe_id)) {
            $this->public_favorites->remove($user_id, $recipe_id);

            return false;
        }

        $this->public_favorites->add($user_id, $recipe_id, current_time('mysql'));

        return true;
    }

    /** Is the recipe one of the user's favourites? */
    public function is_favorited(int $user_id, int $recipe_id): bool {
        return $this->favorites->is_favorited($user_id, $recipe_id);
    }
}
