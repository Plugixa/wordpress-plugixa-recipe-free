<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ReaderTools;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\ReaderTools\Frontend\ReaderTools;

defined('ABSPATH') || exit;

/**
 * What a cook does with a recipe page: scale it, switch units, copy the list, run a timer,
 * follow it step by step with the screen kept on.
 *
 * Everything runs in the visitor's browser. The module adds markup to the recipe page
 * through the page's own hooks and enqueues three scripts; it has no route, no table and
 * no nonce, so a cached page keeps working for as long as it is served.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'reader_tools';
    }

    public function app(): array {
        return [
            'order'    => 12,
            'category' => 'engagement',
            'core'     => false,
            'default'  => true,
            'icon'     => 'restaurant',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Reader Tools', 'plugixa-recipe'),
            'description' => __('On every recipe page: scale the amounts, switch between metric and US units, copy the ingredients, start step timers and follow the method in cook mode.', 'plugixa-recipe'),
        ];
    }

    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');
    }

    public function registerHooks(): void {
        (new ReaderTools())->init();
    }
}
