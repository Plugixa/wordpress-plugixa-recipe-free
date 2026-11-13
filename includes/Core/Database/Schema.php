<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Database;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One SHOW TABLES per request, memoised in a static below; it is the presence probe the migration guard depends on, so it must read the database rather than a cache that could outlive a dropped table. Not a security sniff: the statement is a literal with its pattern bound.

/**
 * Which of the plugin's tables exist - asked once per request, for every module at once.
 *
 * This is the presence half of the migration guard. A version stamp records that the tables
 * were created, not that they are still there: they have been dropped under a matching stamp
 * before, and every public page then logged "Table ... doesn't exist" with no recovery. So
 * each module's healthy() asks here, and all of them share one SHOW TABLES.
 *
 * It creates nothing. Each module owns its DDL in its own Schema.php.
 */
final class Schema {

    /** @var array<string, true>|null Unprefixed names of the tables that exist. */
    private static ?array $present = null;

    /** Full name of one of the plugin's tables. */
    public static function table(string $name): string {
        global $wpdb;

        return $wpdb->prefix . 'plugixa_recipe_' . $name;
    }

    public static function exists(string $name): bool {
        return isset(self::present()[$name]);
    }

    /**
     * The tables from this list that do not exist.
     *
     * @param string[] $names Unprefixed table names.
     * @return string[]
     */
    public static function missing(array $names): array {
        return array_values(array_filter($names, static fn(string $name): bool => !self::exists($name)));
    }

    /** Drop the memo - after creating or dropping a table in the same request. */
    public static function forget(): void {
        self::$present = null;
    }

    /** @return array<string, true> */
    private static function present(): array {
        if (null !== self::$present) {
            return self::$present;
        }

        global $wpdb;

        $prefix = self::table('');

        // esc_like(): the prefix is full of underscores, which LIKE would otherwise treat as
        // single-character wildcards.
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'));

        self::$present = [];
        foreach ($tables as $table) {
            self::$present[substr((string) $table, strlen($prefix))] = true;
        }

        return self::$present;
    }
}
