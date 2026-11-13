<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The pre-1.0.0 table rename: direct queries that run on activation and on a migration, with nothing to cache, and RENAME TABLE is their purpose. None of these three is a security sniff; every statement here binds its identifiers with %i.

use PlugixaRecipe\Core\Database\Schema;
use PlugixaRecipe\Core\Modules\ModuleRegistry;
use PlugixaRecipe\Core\Security\Capabilities;

defined('ABSPATH') || exit;

/**
 * Activation, and the per-request upkeep that keeps an upgraded site whole.
 *
 * This class creates no table and names none. Each module owns its DDL (its Schema.php) and
 * installs it from migrate(); this only decides WHEN.
 *
 * register_activation_hook fires without plugins_loaded, so nothing a module would register
 * on a hook exists yet. That is why activation does not go through a filter: the registry
 * finds modules by scanning the disk, and the autoloaders are registered by the main plugin
 * file itself, so ModuleRegistry::instance()->migrate() works from inside the hook - premium
 * modules included, whenever pro/ is on disk.
 */
final class Activator {

    public static function activate(): void {
        $modules = ModuleRegistry::instance();

        // migrate() renames pre-1.0.0 tables first, then lets every module create its own.
        $modules->migrate();

        Capabilities::grant();
        Settings::ensure_defaults();

        // Activation seeds any seedable table that is empty. (A migration on a later request
        // is stricter: it seeds only a table it has just created.)
        foreach ($modules->schemas() as $schema) {
            if (method_exists($schema, 'seed')) {
                $schema::seed();
            }
        }

        flush_rewrite_rules();
    }

    /**
     * Upkeep on every request, from Plugin's constructor - AFTER registerServices(), which
     * is where a module adds its settings defaults. With that order reversed, a site
     * upgraded in place from free to premium would never get the premium settings keys.
     *
     * In-place upgrades never fire activation, so this is the only thing that brings such a
     * site up to date. It costs one SHOW TABLES and three option reads when nothing is due.
     */
    public static function maintain(): void {
        Settings::migrate_cuisine_slug();
        Settings::purge_custom_code_settings();
        Settings::purge_unread_settings();

        ModuleRegistry::instance()->maybe_migrate();

        Settings::ensure_defaults();

        /**
         * The settings option exists and carries every default key.
         *
         * For one-time settings migrations that DELETE keys: they must run after the default
         * merge above, or a key the defaults still listed would be put straight back.
         */
        do_action('plugixa_recipe_settings_ready');
    }

    /**
     * Rename pre-1.0.0 `plugixarecipe_` tables and options to `plugixa_recipe_`.
     *
     * The plugin folder, text domain and Freemius slug were unified on `plugixa-recipe`
     * before the first WordPress.org release; the data layer moved with them. This runs
     * before create_tables() so an installation carrying the old names is renamed in
     * place instead of getting a second, empty set of tables alongside the populated
     * ones. A fresh install finds nothing to rename and this is a no-op.
     *
     * Public because ModuleRegistry::migrate() calls it before any module creates a table:
     * an in-place upgrade never fires the activation hook.
     */
    public static function migrate_legacy_names(): void {
        global $wpdb;

        $old_prefix = $wpdb->prefix . 'plugixarecipe_';
        $new_prefix = $wpdb->prefix . 'plugixa_recipe_';

        $legacy_tables = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $old_prefix ) . '%'
            )
        );

        foreach ( (array) $legacy_tables as $legacy_table ) {
            $suffix = substr( $legacy_table, strlen( $old_prefix ) );
            $target = $new_prefix . $suffix;

            // Never clobber an existing table - a half-finished migration would otherwise
            // lose the populated side. esc_like() matters here: the target name is full of
            // underscores, which LIKE would otherwise treat as single-character wildcards.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) );
            if ( $exists ) {
                continue;
            }

            // Both names bind as identifiers. This used to be interpolated on the grounds that
            // "RENAME TABLE accepts no placeholders" - true of a %s value, but %i produces a
            // backtick-quoted identifier, which is exactly what RENAME TABLE takes.
            $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy_table, $target ) );
        }

        // Option keys moved with the tables. `plugixarecipe_settings` holds every setting
        // on the site, so losing it would silently reset the plugin to defaults.
        $legacy_options = [
            'plugixarecipe_settings'        => 'plugixa_recipe_settings',
            'plugixarecipe_demo_loaded'     => 'plugixa_recipe_demo_loaded',
            'plugixarecipe_demo_image_ids'  => 'plugixa_recipe_demo_image_ids',
            'plugixarecipe_ml_migrated'     => 'plugixa_recipe_ml_migrated',
            'plugixarecipe_ml_v2_migrated'  => 'plugixa_recipe_ml_v2_migrated',
            'plugixarecipe_pro_db_version'  => 'plugixa_recipe_pro_db_version',
            'pxr_archive_rules_flushed'     => 'plugixa_recipe_archive_rules_flushed',
        ];

        foreach ( $legacy_options as $old_key => $new_key ) {
            $value = get_option( $old_key, null );
            if ( null === $value ) {
                continue;
            }
            if ( false === get_option( $new_key, false ) ) {
                update_option( $new_key, $value );
            }
            delete_option( $old_key );
        }
    }
}
