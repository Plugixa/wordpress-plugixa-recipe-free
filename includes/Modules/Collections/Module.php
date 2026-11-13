<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Collections;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Collections\Http\FrontendCollectionsController;
use PlugixaRecipe\Modules\Collections\Http\CollectionsController;

defined('ABSPATH') || exit;

/**
 * Collections - an optional module the App Manager can switch off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'collections';
    }

    public function app(): array {
        return [
            'order'    => 50,
            'category' => 'content',
            'core'     => false,
            'default'  => true,
            'icon'     => 'collections_bookmark',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Collections', 'plugixa-recipe'),
            'description' => __('Hand-picked groups of recipes, each with its own page.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('collections');

        /* translators: %d: number of items */
        return [sprintf(_n('%d collection', '%d collections', $count, 'plugixa-recipe'), $count)];
    }

    public function registerRoutes(): void {
        $this->routes([
            CollectionsController::class,
            FrontendCollectionsController::class,
        ]);
    }
}
