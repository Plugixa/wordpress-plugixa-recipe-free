<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Favorites;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Favorites\Http\FrontendFavoritesController;
use PlugixaRecipe\Modules\Favorites\Http\FavoritesController;

defined('ABSPATH') || exit;

/**
 * Favorites - an optional module the App Manager can switch off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'favorites';
    }

    public function app(): array {
        return [
            'order'    => 20,
            'category' => 'engagement',
            'core'     => false,
            'default'  => true,
            'icon'     => 'favorite',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Favorites', 'plugixa-recipe'),
            'description' => __('Let logged-in visitors save recipes to a favourites list.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('favorites');

        /* translators: %d: number of items */
        return [sprintf(_n('%d favorite', '%d favorites', $count, 'plugixa-recipe'), $count)];
    }

    public function registerRoutes(): void {
        $this->routes([
            FavoritesController::class,
            FrontendFavoritesController::class,
        ]);
    }
}
