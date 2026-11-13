<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Modules;

use PlugixaRecipe\Core\Activator;
use PlugixaRecipe\Core\Container;
use PlugixaRecipe\Core\Database\Schema;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Discovers modules on disk and boots them - plugixa-inventory's registry.
 *
 * Discovery is a filesystem glob in two roots:
 *
 *   includes/Modules/<Name>/Module.php      ->  PlugixaRecipe\Modules\<Name>\Module
 *   pro/includes/Modules/<Name>/Module.php  ->  PlugixaRecipe\Pro\Modules\<Name>\Module
 *
 * THE SECOND ROOT IS THE ENTIRE FREE/PREMIUM SEAM, and it works by ABSENCE. The free ZIP has
 * no `pro/`, so the glob finds nothing and premium modules DO NOT EXIST - as opposed to
 * existing behind a licence check, which WordPress.org rejects as trialware. There is
 * deliberately no `is_premium()` here; the only question asked is whether a file is on disk.
 *
 * A third source is the `plugixa_recipe_modules` filter, for add-on plugins.
 *
 * A disabled module sits out `registerHooks()` and `registerRoutes()` only. Discovery,
 * `registerServices()` and `migrate()` run for every module, so a switched-off module's data
 * is still migrated and still cleaned up when its recipe is deleted.
 */
final class ModuleRegistry {

    /** `VERSION|fingerprint` of the last completed migrate(). */
    private const MIGRATED_OPTION = 'plugixa_recipe_modules_migrated';

    private static ?self $instance = null;

    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /** @var array<string, array<string, mixed>> */
    private array $descriptors = [];

    /** @var array<string, bool> */
    private array $enabled = [];

    private AppState $state;

    private Container $container;

    // PHP 8.0 floor (Requires PHP: 8.0): no `readonly`, no `new` in an initializer.
    private function __construct() {
        $this->state     = new AppState();
        $this->container = new Container();
    }

    /** The one container: controllers, services and repositories resolve from it. */
    public function container(): Container {
        return $this->container;
    }

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->discover();
        }

        return self::$instance;
    }

    private function discover(): void {
        $classes = array_merge(
            $this->scan(PLUGIXA_RECIPE_PATH . 'includes/Modules', 'PlugixaRecipe\\Modules\\'),
            $this->scan(PLUGIXA_RECIPE_PATH . 'pro/includes/Modules', 'PlugixaRecipe\\Pro\\Modules\\')
        );

        /**
         * Module classes to boot. An add-on plugin appends its own.
         *
         * @param class-string[] $classes
         */
        $classes = (array) apply_filters('plugixa_recipe_modules', $classes);

        foreach ($classes as $class_name) {
            if (!is_string($class_name) || !class_exists($class_name) || !is_subclass_of($class_name, ModuleInterface::class)) {
                continue;
            }

            try {
                $module = new $class_name();
                $this->modules[$module->id()] = $module;
            } catch (Throwable $e) {
                $this->log_failure($class_name, $e);
            }
        }

        $this->describe();
    }

    /** Runs for EVERY module - see the class comment. */
    public function registerServices(): void {
        foreach ($this->modules as $module) {
            $this->attempt($module, static fn() => $module->registerServices());
        }

        // Every module's admin strings, added to the page config. Hooked once, and resolved
        // only when the admin page asks - translating on plugins_loaded would be too early.
        if (!has_filter('plugixa_recipe_admin_i18n_strings', [$this, 'strings'])) {
            add_filter('plugixa_recipe_admin_i18n_strings', [$this, 'strings']);
        }
    }

    /**
     * Merge each module's Strings::all() into the admin i18n map.
     *
     * By convention, not registration: a module that has strings puts a `Strings` class
     * beside its Module.php. Every module is included, enabled or not - a switched-off
     * module still shows its name on the App Manager card that switches it back on. A
     * premium module's Strings.php is absent from the free build with the rest of it.
     *
     * @param array<string, string> $strings
     * @return array<string, string>
     */
    public function strings(array $strings): array {
        foreach ($this->modules as $module) {
            $class = substr($module::class, 0, (int) strrpos($module::class, '\\')) . '\\Strings';

            if (class_exists($class)) {
                $strings = array_merge($strings, (array) $class::all());
            }
        }

        return $strings;
    }

    public function registerHooks(): void {
        foreach ($this->modules as $id => $module) {
            if ($this->enabled($id)) {
                $this->attempt($module, static fn() => $module->registerHooks());
            }
        }
    }

    /**
     * REST routes of ENABLED modules only.
     *
     * A disabled module's routes are simply never registered, so a request for them is
     * WordPress's own 404 - an honest answer, where a 403 would claim the route exists and
     * the caller lacks permission.
     */
    public function registerRoutes(): void {
        foreach ($this->modules as $id => $module) {
            if ($this->enabled($id)) {
                $this->attempt($module, static fn() => $module->registerRoutes());
            }
        }
    }

    /** Every module's schema work, unconditionally. Idempotent. */
    public function migrate(): void {
        // Before any module creates a table: an install that still carries the pre-1.0.0
        // `plugixarecipe_` names is renamed in place, or it would get a second, empty set of
        // tables beside the populated ones.
        Activator::migrate_legacy_names();
        Schema::forget();

        foreach ($this->modules as $module) {
            $this->attempt($module, static fn() => $module->migrate());
        }

        Schema::forget();
        update_option(self::MIGRATED_OPTION, $this->stamp());
    }

    /**
     * The Schema class of every module that owns tables.
     *
     * @return array<int, class-string>
     */
    public function schemas(): array {
        $schemas = [];

        foreach ($this->modules as $module) {
            $schema = $module instanceof AbstractModule ? $module->schema() : null;

            if (null !== $schema) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * Migrate when something changed - OR WHEN THE RESULT IS GONE.
     *
     * The stamp covers the plugin version and the SET of modules on disk, so installing the
     * premium overlay over free at the same version (which never fires activation) still
     * migrates. The `healthy()` probe covers the other half: a stamp records that the work
     * happened, not that its result survived - the premium tables were once dropped under a
     * matching version stamp and every public page then logged `Table doesn't exist`.
     */
    public function maybe_migrate(): void {
        if (get_option(self::MIGRATED_OPTION) === $this->stamp() && $this->all_healthy()) {
            return;
        }

        $this->migrate();
    }

    private function all_healthy(): bool {
        foreach ($this->modules as $module) {
            if (!$module->healthy()) {
                return false;
            }
        }

        return true;
    }

    private function stamp(): string {
        $ids = array_keys($this->modules);
        sort($ids);

        return PLUGIXA_RECIPE_VERSION . '|' . md5(implode('|', $ids));
    }

    /** Is this module on? An id with no descriptor (an add-on) is ON. */
    public function enabled(string $id): bool {
        return $this->enabled[$id] ?? isset($this->modules[$id]);
    }

    /**
     * Re-resolve from the stored option - after the App Manager saved, or after the
     * one-time enable_* migration wrote flags this request's state had already cached.
     */
    public function refresh(): void {
        $this->state->reload();
        $this->enabled = $this->state->resolve($this->descriptors);
    }

    /** @return array<string, array<string, mixed>> */
    public function descriptors(): array {
        return $this->descriptors;
    }

    /** @return array<string, bool> Resolved state of every described module. */
    public function enabled_map(): array {
        return $this->enabled;
    }

    public function module(string $id): ?ModuleInterface {
        return $this->modules[$id] ?? null;
    }

    /** @return array<string, ModuleInterface> */
    public function all(): array {
        return $this->modules;
    }

    public function state(): AppState {
        return $this->state;
    }

    private function describe(): void {
        $this->descriptors = [];

        foreach ($this->modules as $id => $module) {
            if ($module instanceof AppInterface) {
                try {
                    $this->descriptors[$id] = $this->normalise($id, $module->app());
                } catch (Throwable $e) {
                    $this->log_failure($module::class, $e);
                }
            }
        }

        $this->enabled = $this->state->resolve($this->descriptors);
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function normalise(string $id, array $descriptor): array {
        $category = (string) ($descriptor['category'] ?? 'tools');

        return [
            'id'       => $id,
            'order'    => (int) ($descriptor['order'] ?? 100),
            'category' => in_array($category, AppState::CATEGORIES, true) ? $category : 'tools',
            'core'     => (bool) ($descriptor['core'] ?? false),
            'deps'     => array_values(array_filter((array) ($descriptor['deps'] ?? []), 'is_string')),
            'default'  => (bool) ($descriptor['default'] ?? true),
            'icon'     => (string) ($descriptor['icon'] ?? 'apps'),
            'pro'      => (bool) ($descriptor['pro'] ?? false),
        ];
    }

    /** @return string[] */
    private function scan(string $dir, string $namespace_prefix): array {
        if (!is_dir($dir)) {
            return [];
        }

        $classes = [];
        foreach ((array) glob($dir . '/*/Module.php') as $file) {
            $classes[] = $namespace_prefix . basename(dirname((string) $file)) . '\\Module';
        }

        return $classes;
    }

    /** One module failing must not take the others - or the site - down with it. */
    private function attempt(ModuleInterface $module, callable $step): void {
        try {
            $step();
        } catch (Throwable $e) {
            $this->log_failure($module::class, $e);
        }
    }

    private function log_failure(string $class_name, Throwable $e): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a module failing to boot is a developer error that must not be silent; already inside a WP_DEBUG guard.
                sprintf('[Plugixa Recipe] Module "%s" failed: %s', $class_name, $e->getMessage())
            );
        }
    }
}
