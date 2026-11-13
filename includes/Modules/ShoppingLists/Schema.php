<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ShoppingLists;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the tables this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The tables this module owns: shopping_lists, shopping_list_items.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['shopping_lists', 'shopping_list_items'];
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

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}shopping_lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            name varchar(200) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}shopping_list_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shopping_list_id bigint(20) unsigned NOT NULL,
            recipe_id bigint(20) unsigned DEFAULT NULL,
            ingredient_name varchar(500) NOT NULL,
            quantity varchar(50) DEFAULT NULL,
            unit varchar(50) DEFAULT NULL,
            is_checked tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            added_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY shopping_list_id (shopping_list_id),
            KEY recipe_id (recipe_id)
        ) {$charset_collate};");
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
