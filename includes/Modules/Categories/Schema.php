<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Categories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the tables this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The tables this module owns: categories, recipe_categories.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['categories', 'recipe_categories'];
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

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}categories (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            language_code varchar(10) DEFAULT NULL,
            translation_group varchar(36) DEFAULT NULL,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            image_id bigint(20) unsigned DEFAULT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY display_order (display_order),
            KEY language_code (language_code),
            KEY translation_group (translation_group)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipe_categories (
            recipe_id bigint(20) unsigned NOT NULL,
            category_id bigint(20) unsigned NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (recipe_id, category_id),
            KEY category_id (category_id),
            KEY cat_display_order (category_id, display_order)
        ) {$charset_collate};");
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
