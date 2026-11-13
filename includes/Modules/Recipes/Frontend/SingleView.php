<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

use PlugixaRecipe\Modules\Recipes\Repositories\SinglePageRepository;
use PlugixaRecipe\Modules\Recipes\Services\RecipePresenter;

defined('ABSPATH') || exit;

/**
 * What it takes to draw one whole recipe with single-recipe.php: the struct, the assets
 * and the view config.
 *
 * Two callers - the recipe's own page (SinglePage) and a recipe embedded in a post
 * (RecipeEmbed). It was all inside SinglePage; a second copy for the embed would have
 * been the page's assets as they were the day it was copied.
 */
final class SingleView {

    private SinglePageRepository $repository;

    public function __construct(?SinglePageRepository $repository = null) {
        $this->repository = $repository ?? new SinglePageRepository();
    }

    /**
     * The recipe as the views draw it (Services\RecipePresenter), plus the two things
     * that belong to this request: whether the visitor saved it, and one more view
     * (not counted for an editor's preview).
     */
    public function recipe(int $id, bool $count_view = true): ?object {
        $recipe = (new RecipePresenter($this->repository))->recipe($id);

        if (!$recipe) {
            return null;
        }

        // Per-user state: not cached.
        $recipe->is_favorited = is_user_logged_in()
            && $this->repository->is_favorited(get_current_user_id(), $id);

        // Counter increment only: deliberately does NOT flush the cache - this runs on every
        // render, so flushing here would invalidate the whole cache on every page view. Cached
        // lists therefore carry eventually-consistent view counts.
        if ($count_view) {
            $this->repository->increment_view_count($id);
        }

        return $recipe;
    }

    /**
     * Styles and scripts of a drawn recipe. Safe to call more than once on a page.
     *
     * @param object $recipe The presented recipe.
     */
    public function enqueue(object $recipe): void {
        // Enqueue frontend CSS
        $css_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Enqueue frontend JS
        $js_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipes.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipes.js',
                [],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            \PlugixaRecipe\Core\Frontend\Assets::localize_config();
        }

        // GLightbox for gallery lightbox (conditional).
        $recipe_arr = (array) $recipe;
        if ( ! empty( $recipe_arr['gallery_urls'] ) ) {
            wp_enqueue_style(
                'glightbox',
                PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/glightbox/glightbox.min.css',
                [],
                '3.3.1'
            );
            wp_enqueue_script(
                'glightbox',
                PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/glightbox/glightbox.min.js',
                [],
                '3.3.1',
                true
            );
        }

        /**
         * Fires once a single recipe page has enqueued its own assets.
         *
         * @since 1.2.0
         *
         * @param object $recipe The presented recipe.
         */
        do_action('plugixa_recipe_single_assets', $recipe);
    }

    /**
     * The site's default view config, as stored.
     *
     * @return array<string, mixed>
     */
    public function config(): array {
        $row = $this->repository->default_view_row();

        if ($row && !empty($row->config)) {
            $config = json_decode($row->config, true);
            return is_array($config) ? $config : [];
        }

        return [];
    }
}
