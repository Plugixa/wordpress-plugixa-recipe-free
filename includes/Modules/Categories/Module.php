<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Categories;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Categories\Http\CategoriesController;

defined('ABSPATH') || exit;

/**
 * Categories - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'categories';
    }

    public function app(): array {
        return [
            'order'    => 30,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'category',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Categories', 'plugixa-recipe'),
            'description' => __('Organise recipes into categories with their own archive pages.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('categories');

        /* translators: %d: number of items */
        return [sprintf(_n('%d category', '%d categories', $count, 'plugixa-recipe'), $count)];
    }

    public function registerRoutes(): void {
        $this->routes([
            CategoriesController::class,
        ]);
    }
}
