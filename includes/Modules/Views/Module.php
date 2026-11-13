<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Views;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Views\Http\ViewsController;
use PlugixaRecipe\Modules\Views\Http\GoogleFontsController;

defined('ABSPATH') || exit;

/**
 * Views - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'views';
    }

    public function app(): array {
        return [
            'order'    => 50,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'view_quilt',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Views', 'plugixa-recipe'),
            'description' => __('Design the grid, list, slider and masonry layouts recipes are shown in.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('views');

        /* translators: %d: number of items */
        return [sprintf(_n('%d view', '%d views', $count, 'plugixa-recipe'), $count)];
    }

    public function registerRoutes(): void {
        $this->routes([
            ViewsController::class,
            GoogleFontsController::class,
        ]);
    }
}
