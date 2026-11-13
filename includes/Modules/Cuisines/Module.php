<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Cuisines;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Cuisines\Http\CuisinesController;

defined('ABSPATH') || exit;

/**
 * Cuisines - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'cuisines';
    }

    public function app(): array {
        return [
            'order'    => 40,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'public',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Cuisines', 'plugixa-recipe'),
            'description' => __('Tag recipes by cuisine and browse them by cuisine archive.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('cuisines');

        /* translators: %d: number of items */
        return [sprintf(_n('%d cuisine', '%d cuisines', $count, 'plugixa-recipe'), $count)];
    }

    public function registerRoutes(): void {
        $this->routes([
            CuisinesController::class,
        ]);
    }
}
