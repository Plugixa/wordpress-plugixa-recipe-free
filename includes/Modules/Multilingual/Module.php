<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Multilingual;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Multilingual\Http\MultilingualController;
use PlugixaRecipe\Modules\Multilingual\Services\Multilingual;

defined('ABSPATH') || exit;

/**
 * Multilingual - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'multilingual';
    }

    public function app(): array {
        return [
            'order'    => 95,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'translate',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Multilingual', 'plugixa-recipe'),
            'description' => __('Works alongside WPML and Polylang to translate recipes.', 'plugixa-recipe'),
        ];
    }

    /** Detects WPML / Polylang once, before any module's hooks can ask which is active. */
    public function registerServices(): void {
        Multilingual::instance();
    }

    public function registerRoutes(): void {
        $this->routes([
            MultilingualController::class,
        ]);
    }
}
