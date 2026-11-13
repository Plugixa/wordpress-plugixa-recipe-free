<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Settings;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Settings\Http\SettingsController;

defined('ABSPATH') || exit;

/**
 * Settings - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'settings';
    }

    public function app(): array {
        return [
            'order'    => 90,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'settings',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Settings', 'plugixa-recipe'),
            'description' => __('Plugin-wide display, SEO, performance and privacy settings.', 'plugixa-recipe'),
        ];
    }

    public function registerRoutes(): void {
        $this->routes([
            SettingsController::class,
        ]);
    }
}
