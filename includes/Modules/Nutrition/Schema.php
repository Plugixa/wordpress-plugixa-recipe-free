<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Nutrition;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the table this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The table this module owns: nutrition.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['nutrition'];
    }

    /**
     * Create what is missing, bring older tables up to date. Idempotent.
     */
    public static function install(): void {
        self::create_tables();
        Tables::forget();
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE only. The table name alone could bind with %i, but get_charset_collate() cannot: it is SQL syntax (DEFAULT CHARACTER SET x COLLATE y) that is empty on some installs, so it is neither a value nor an identifier. Both interpolated parts come from $wpdb itself; no request data reaches these statements. Scoped to this method on purpose: everything outside it binds with %i / %s, and SqlLiteralTest fails the build if that stops being true.

    public static function create_tables(): void {
        global $wpdb;

        $prefix          = $wpdb->prefix . 'plugixa_recipe_';
        $charset_collate = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}nutrition (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipe_id bigint(20) unsigned NOT NULL,
            serving_size varchar(100) DEFAULT NULL,
            calories decimal(8,2) DEFAULT NULL,
            total_fat decimal(8,2) DEFAULT NULL,
            saturated_fat decimal(8,2) DEFAULT NULL,
            trans_fat decimal(8,2) DEFAULT NULL,
            cholesterol decimal(8,2) DEFAULT NULL,
            sodium decimal(8,2) DEFAULT NULL,
            total_carbohydrate decimal(8,2) DEFAULT NULL,
            dietary_fiber decimal(8,2) DEFAULT NULL,
            total_sugars decimal(8,2) DEFAULT NULL,
            protein decimal(8,2) DEFAULT NULL,
            vitamin_a decimal(8,2) DEFAULT NULL,
            vitamin_c decimal(8,2) DEFAULT NULL,
            calcium decimal(8,2) DEFAULT NULL,
            iron decimal(8,2) DEFAULT NULL,
            potassium decimal(8,2) DEFAULT NULL,
            source varchar(50) DEFAULT 'manual',
            PRIMARY KEY (id),
            UNIQUE KEY recipe_id (recipe_id)
        ) {$charset_collate};");
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
