<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Schema;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Schema\Repositories\SchemaFlagRepository;
use PlugixaRecipe\Modules\Schema\Services\JsonLd;

defined('ABSPATH') || exit;

/**
 * Recipe JSON-LD.
 *
 * Free since 1.0.1 (it was a premium module): structured data is what puts a recipe's
 * rating and cooking time in a search result, and a recipe plugin that keeps it behind
 * a paywall is competing with six that do not. The module id is unchanged, so a site
 * that had it switched off in the App Manager still has.
 *
 * Describes ONLY what the page rendered: the render paths register recipes with
 * RenderedRecipes and JsonLd reads that registry. See SchemaScopeTest.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'schema';
    }

    public function app(): array {
        return [
            'order'    => 15,
            'category' => 'tools',
            'default'  => true,
            'icon'     => 'data_object',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Schema Markup', 'plugixa-recipe'),
            'description' => __('Recipe structured data (JSON-LD) for Google rich results, for every recipe a page shows.', 'plugixa-recipe'),
        ];
    }

    public function registerServices(): void {
        add_filter('plugixa_recipe_settings_defaults', static fn(array $d): array => array_merge($d, [
            'enable_schema' => true,
            // Read by Services\JsonLd: one BreadcrumbList on a recipe's own page.
            'enable_breadcrumb_schema' => true,
        ]));
        add_filter('plugixa_recipe_sanitize_settings', static function (array $settings, array $params): array {
            foreach (['enable_schema', 'enable_breadcrumb_schema'] as $switch) {
                if (isset($params[$switch])) {
                    $settings[$switch] = (bool) $params[$switch];
                }
            }
            return $settings;
        }, 10, 2);

    }

    /**
     * `schema_type` offered "HowTo" and only swapped the @type on a payload that is a
     * Recipe in every other property - invalid markup either way. The control is gone;
     * this removes what it stored. No stamp: it acts only while the key is there.
     */
    public function migrate(): void {
        $settings = get_option('plugixa_recipe_settings');

        if (is_array($settings) && array_key_exists('schema_type', $settings)) {
            unset($settings['schema_type']);
            update_option('plugixa_recipe_settings', $settings);
        }
    }

    public function registerHooks(): void {
        (new JsonLd())->init();

        // The per-recipe "include structured data" switch the editor's SEO card sends.
        add_action('plugixa_recipe_save_recipe', static function (int $recipe_id, array $params): void {
            if (!array_key_exists('enable_schema', $params)) {
                return;
            }
            (new SchemaFlagRepository())->set_enabled($recipe_id, (bool) $params['enable_schema']);
        }, 10, 2);
    }
}
