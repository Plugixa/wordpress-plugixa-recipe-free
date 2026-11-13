<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Cuisines;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the tables this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The tables this module owns: cuisines, recipe_cuisines.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['cuisines', 'recipe_cuisines'];
    }

    /**
     * Create what is missing, bring older tables up to date, and seed a table
     * this call created. Idempotent.
     */
    public static function install(): void {
        // Which tables are new - only those get seed rows. A table that already existed and
        // is empty was emptied by the site owner, and must stay that way.
        $created = Tables::missing(self::tables());

        self::create_tables();
        Tables::forget();
        self::seed($created);
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE only. The table name alone could bind with %i, but get_charset_collate() cannot: it is SQL syntax (DEFAULT CHARACTER SET x COLLATE y) that is empty on some installs, so it is neither a value nor an identifier. Both interpolated parts come from $wpdb itself; no request data reaches these statements. Scoped to this method on purpose: everything outside it binds with %i / %s, and SqlLiteralTest fails the build if that stops being true.

    public static function create_tables(): void {
        global $wpdb;

        $prefix          = $wpdb->prefix . 'plugixa_recipe_';
        $charset_collate = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}cuisines (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            image_id bigint(20) unsigned DEFAULT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            language_code varchar(10) DEFAULT NULL,
            translation_group varchar(36) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY display_order (display_order)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipe_cuisines (
            recipe_id bigint(20) unsigned NOT NULL,
            cuisine_id bigint(20) unsigned NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (recipe_id, cuisine_id),
            KEY cuisine_id (cuisine_id)
        ) {$charset_collate};");
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    /**
     * Default rows. Each routine does nothing unless its table exists and is empty.
     *
     * @param string[]|null $only Limit to these tables (the ones install() just created).
     *                            Null - activation - considers every seedable table.
     */
    public static function seed(?array $only = null): void {
        if (null === $only || in_array('cuisines', $only, true)) {
            self::seed_cuisines();
        }
    }

    private static function seed_cuisines(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixa_recipe_cuisines';
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!$existing) {
            return;
        }
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($count > 0) {
            return;
        }

        $cuisines = [
            'Italian', 'French', 'Chinese', 'Japanese', 'Indian',
            'Mexican', 'Thai', 'Greek', 'Spanish', 'Middle Eastern',
            'Korean', 'Vietnamese', 'American', 'Mediterranean', 'Brazilian', 'Moroccan',
        ];

        foreach ($cuisines as $i => $name) {
            $wpdb->insert($table, [
                'name'          => $name,
                'slug'          => sanitize_title($name),
                'display_order' => $i,
            ]);
        }
    }
}
