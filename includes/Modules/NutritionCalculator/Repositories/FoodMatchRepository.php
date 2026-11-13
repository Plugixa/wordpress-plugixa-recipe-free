<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache; the write flushes it. Every query is a constant literal with the table bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The foods the site owner has matched to ingredient names (`food_matches`).
 */
final class FoodMatchRepository extends Repository {

    protected function entities(): array {
        return ['food_matches'];
    }

    /**
     * The remembered food for an ingredient key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array {
        global $wpdb;

        $row = $this->remember('key|' . md5($key), function () use ($wpdb, $key) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT ingredient_key, fdc_id, description, nutrients, unit_grams FROM %i WHERE ingredient_key = %s',
                $this->table('food_matches'),
                $key
            ), ARRAY_A);
        });

        return $row ?: null;
    }

    /**
     * Remember (or replace) the food for an ingredient key.
     *
     * @param array<string, float> $nutrients  Per 100 g, keyed by our nutrition column.
     * @param array<string, float> $unit_grams Grams in one of a unit, keyed by the unit's text.
     */
    public function save(string $key, int $fdc_id, string $description, array $nutrients, array $unit_grams): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (ingredient_key, fdc_id, description, nutrients, unit_grams, modified_at)
             VALUES (%s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE fdc_id = VALUES(fdc_id), description = VALUES(description),
                 nutrients = VALUES(nutrients), unit_grams = VALUES(unit_grams), modified_at = VALUES(modified_at)',
            $this->table('food_matches'),
            $key,
            $fdc_id,
            $description,
            (string) wp_json_encode($nutrients),
            (string) wp_json_encode((object) $unit_grams),
            current_time('mysql')
        ));

        $this->flush();
    }
}
