<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the table this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The table this module owns: food_matches.
 *
 * One row per ingredient name the site owner has matched to a food in USDA FoodData
 * Central: which food, its nutrients per 100 g as they were when it was matched, and how
 * many grams the owner said one of each unit weighs. It is what makes the second recipe
 * with "flour" in it a matter of one click.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['food_matches'];
    }

    /**
     * Create what is missing. Idempotent.
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

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}food_matches (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ingredient_key varchar(191) NOT NULL,
            fdc_id bigint(20) unsigned NOT NULL,
            description varchar(500) NOT NULL,
            nutrients text NOT NULL,
            unit_grams text DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ingredient_key (ingredient_key)
        ) {$charset_collate};");
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
