<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Dashboard;

use PlugixaRecipe\Core\Modules\AbstractModule;

defined('ABSPATH') || exit;

/**
 * Dashboard - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'dashboard';
    }

    public function app(): array {
        return [
            'order'    => 10,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'dashboard',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Dashboard', 'plugixa-recipe'),
            'description' => __('Totals, trends and recent activity at a glance.', 'plugixa-recipe'),
        ];
    }
}
