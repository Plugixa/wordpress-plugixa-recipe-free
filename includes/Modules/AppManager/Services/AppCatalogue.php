<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\AppManager\Services;

use PlugixaRecipe\Core\Modules\AppInterface;
use PlugixaRecipe\Core\Modules\ModuleRegistry;

defined('ABSPATH') || exit;

/**
 * The App Manager's cards: every module that describes itself, as the admin app draws it.
 *
 * Built from the registry, so a premium module appears exactly when its folder is on disk -
 * the free build lists free modules and nothing else, with no "upgrade" card for anything
 * it does not contain.
 */
final class AppCatalogue {

    /**
     * @param bool $with_usage Include each module's usage line (a COUNT query per module).
     *                         Off for the page-load config, on for the App Manager's fetch.
     * @return array<int, array<string, mixed>>
     */
    public static function describe(bool $with_usage): array {
        $registry = ModuleRegistry::instance();
        $cards    = [];

        foreach ($registry->descriptors() as $id => $descriptor) {
            $module = $registry->module($id);
            if (!$module instanceof AppInterface) {
                continue;
            }

            $labels  = $module->labels();
            $blocker = $module->blocker();

            $cards[] = $descriptor + [
                'label'       => (string) ($labels['label'] ?? $id),
                'description' => (string) ($labels['description'] ?? ''),
                // A blocked module (WooCommerce without WooCommerce) does nothing, so it is
                // not "Active" whatever its switch says.
                'enabled'     => $registry->enabled($id) && null === $blocker,
                // Stored choice, separate from `enabled`: a module can be switched on but
                // held off by a dependency.
                'flag'        => (bool) ($registry->state()->flags()[$id] ?? $descriptor['default']),
                'can_toggle'  => !$descriptor['core'] && null === $blocker,
                'blocker'     => $blocker,
                'usage'       => $with_usage ? $module->usage() : [],
            ];
        }

        usort($cards, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return $cards;
    }
}
