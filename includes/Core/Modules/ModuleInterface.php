<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Modules;

defined('ABSPATH') || exit;

/**
 * One feature of the plugin, discovered from disk by ModuleRegistry.
 *
 * The contract plugixa-inventory uses, minus its DI container: a module is a folder
 * (`includes/Modules/<Name>/` or `pro/includes/Modules/<Name>/`) whose Module.php says what
 * it is and wires itself in. Core never names a feature - it runs these four loops, and
 * the App Manager decides which modules sit two of them out:
 *
 *   registerServices()  ALWAYS  - anything that must hold even while the module is off:
 *                                 delete-cascade hooks, settings defaults, i18n keys.
 *   registerHooks()     enabled - frontend output, actions, filters.
 *   registerRoutes()    enabled - REST routes. A disabled module is an honest 404.
 *   migrate()           ALWAYS  - "switching off keeps the data" is a promise about the
 *                                 schema too.
 */
interface ModuleInterface {

    /** Stable id - the key in the `plugixa_recipe_apps` option and in `appEnabled()`. */
    public function id(): string;

    /** Idempotent schema work. Runs on every module, enabled or not. */
    public function migrate(): void;

    /**
     * Is this module's schema actually on disk? The presence half of the migration guard:
     * a version stamp records that work happened, not that its result survived.
     */
    public function healthy(): bool;

    public function registerServices(): void;

    public function registerRoutes(): void;

    public function registerHooks(): void;
}
