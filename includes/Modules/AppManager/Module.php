<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\AppManager;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Core\Modules\ModuleRegistry;
use PlugixaRecipe\Modules\AppManager\Http\AppController;
use PlugixaRecipe\Modules\AppManager\Services\AppCatalogue;
use PlugixaRecipe\Modules\AppManager\Services\LegacyToggles;

defined('ABSPATH') || exit;

/**
 * App Manager - switch optional modules on and off. Core: it cannot switch itself off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'app_manager';
    }

    public function app(): array {
        return [
            'order'    => 100,
            'category' => 'core',
            'core'     => true,
            'icon'     => 'apps',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Apps', 'plugixa-recipe'),
            'description' => __('Switch features on and off. Switching one off keeps all of its data.', 'plugixa-recipe'),
        ];
    }

    /**
     * The one-time move of the old enable_* settings into the App Manager. Hooked here, in
     * the method that runs for every module, because it has to happen whatever is switched
     * on; and on this action rather than directly, because it must follow the settings
     * default merge (see LegacyToggles).
     */
    public function registerServices(): void {
        add_action('plugixa_recipe_settings_ready', [LegacyToggles::class, 'migrate']);
    }

    public function registerHooks(): void {
        /*
         * The admin app decides at module load - before React renders - which screens and
         * menu items exist, so the resolved state has to already be on the page. Usage
         * counts are left out: they cost a query per module and only the Apps screen shows
         * them, which fetches its own.
         */
        add_filter('plugixa_recipe_admin_config', static function (array $config): array {
            $config['apps']        = ModuleRegistry::instance()->enabled_map();
            $config['appRegistry'] = AppCatalogue::describe(false);
            return $config;
        });

    }

    public function registerRoutes(): void {
        $this->routes([AppController::class]);
    }
}
