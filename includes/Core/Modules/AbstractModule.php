<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Modules;

use PlugixaRecipe\Core\Container;
use PlugixaRecipe\Core\Database\Schema;

defined('ABSPATH') || exit;

/**
 * Defaults for the ModuleInterface / AppInterface methods most modules leave alone.
 *
 * A module overrides only what it does. Everything here is a no-op or the permissive
 * answer, so a module that forgets a method loses a feature, never breaks boot.
 */
abstract class AbstractModule implements ModuleInterface, AppInterface {

    /**
     * This module's Schema class, when it owns tables - by convention the class named
     * Schema beside its Module.php. Null for a module that owns none.
     *
     * @return class-string|null
     */
    public function schema(): ?string {
        $class = substr(static::class, 0, (int) strrpos(static::class, '\\')) . '\\Schema';

        return class_exists($class) && method_exists($class, 'install') ? $class : null;
    }

    /** Create and upgrade the tables this module owns. A module without tables does nothing. */
    public function migrate(): void {
        $schema = $this->schema();

        if (null !== $schema) {
            $schema::install();
        }
    }

    /**
     * Are this module's tables actually there?
     *
     * The presence half of the migration guard: a version stamp records that migrate() ran,
     * not that what it created survived. All modules share one SHOW TABLES per request.
     */
    public function healthy(): bool {
        $schema = $this->schema();

        return null === $schema || [] === Schema::missing($schema::tables());
    }

    public function registerServices(): void {}

    public function registerRoutes(): void {}

    public function registerHooks(): void {}

    public function app(): array {
        return [];
    }

    public function usage(): array {
        return [];
    }

    public function blocker(): ?string {
        return null;
    }

    /** The shared container - how a module reaches its own services and repositories. */
    protected function container(): Container {
        return ModuleRegistry::instance()->container();
    }

    /**
     * Register REST controllers by class name. Each must expose register_routes().
     *
     * Resolved through the container, so a controller may take its service in its
     * constructor and the service its repository, without a `new` chain here.
     *
     * @param class-string[] $controllers
     */
    protected function routes(array $controllers): void {
        foreach ($controllers as $controller) {
            $this->container()->get($controller)->register_routes();
        }
    }

    /**
     * Count the rows of one of our own tables - the usual `usage()` figure.
     *
     * @param string $table Unprefixed table name, e.g. 'reviews'.
     */
    protected function count_rows(string $table): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- App Manager only, never on the boot path.
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'plugixa_recipe_' . $table)
        );
    }
}
