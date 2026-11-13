<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query against `units`.
 *
 * Read-only: units are reference data seeded by the activator, so nothing here writes and
 * nothing here flushes.
 */
final class UnitRepository extends Repository {

    protected function entities(): array {
        return ['units'];
    }

    /**
     * All units, optionally narrowed to one system and/or one type. Empty strings mean
     * "no filter".
     *
     * @return array<int, array<string, mixed>> Rows as stored (id and conversion_factor still strings).
     */
    public function all(string $system, string $type): array {
        global $wpdb;

        // Each filter is a gate placeholder: bind 0 and the clause is constant-true, which
        // keeps WHERE literal instead of PHP-assembled. `system` stays backticked because it
        // became a reserved word in MySQL 8.0.3; `type` is backticked for symmetry.
        $args = [
            $this->table('units'),
            $system !== '' ? 1 : 0, $system,
            $type !== '' ? 1 : 0, $type,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text. The ORDER BY
        // here is entirely literal, so there is no identifier to bind.
        return (array) $this->remember('items|' . md5(wp_json_encode($args)), function () use ($wpdb, $args) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i
                  WHERE ( %d = 0 OR `system` = %s )
                    AND ( %d = 0 OR `type` = %s )
                  ORDER BY `system` ASC, `type` ASC, name ASC, id ASC',
                ...$args
            ), ARRAY_A);
        });
    }

    /**
     * Look up one unit by abbreviation or name.
     *
     * @return array<string, mixed>|null Null when no unit matches.
     */
    public function lookup(string $unit): ?array {
        global $wpdb;

        $row = $this->remember("lookup:{$unit}", function () use ($wpdb, $unit) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM %i WHERE abbreviation = %s OR name = %s LIMIT 1',
                $this->table('units'),
                $unit, $unit
            ), ARRAY_A);
        });

        return $row ?: null;
    }
}
