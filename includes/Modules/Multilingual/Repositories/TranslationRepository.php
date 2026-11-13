<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Multilingual\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query behind the translation routes.
 *
 * Unlike the other repositories this one has no table of its own: it reads and writes the
 * `language_code` / `translation_group` columns of whichever translatable entity the request
 * named. The table is therefore a parameter of every method - always a value the service
 * took from its allow-list, never one read from the request - and always binds with %i.
 *
 * Nothing here is cached, so the only cache duty is the flush. Three writes flush, exactly
 * as they did in the controller; the others are documented as not flushing where they stand.
 */
final class TranslationRepository extends Repository {

    /** The entity is resolved at runtime, so a write invalidates every translatable entity. */
    protected function entities(): array {
        return ['recipes', 'categories', 'cuisines'];
    }

    /** Full name of one of the plugin's tables, for the service's entity allow-list. */
    public function table_name(string $name): string {
        return $this->table($name);
    }

    /**
     * The translation columns of one row.
     *
     * @return array<string, mixed>|null
     */
    public function find_translation_info(string $table, int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, language_code, translation_group FROM %i WHERE id = %d',
            $table,
            $id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Every other row of a translation group, each with its display value.
     *
     * @param string $display_field A column from the service's allow-list.
     * @return mixed The get_results() value, untouched.
     */
    public function siblings(string $table, string $display_field, $group, int $id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, language_code, translation_group, %i AS display_value
            FROM %i
            WHERE translation_group = %s AND id != %d
            ORDER BY id ASC',
            $display_field, $table, $group, $id
        ), ARRAY_A);
    }

    /**
     * The whole row as it is in the database right now.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(string $table, int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);

        return $row ?: null;
    }

    /**
     * The id of the row holding a language within a group, if there is one.
     *
     * @return string|null
     */
    public function translation_in_group(string $table, $group, string $language) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE translation_group = %s AND language_code = %s',
            $table,
            $group, $language
        ));
    }

    public function slug_taken(string $table, string $slug): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, $slug));
    }

    /**
     * The translation group of one row.
     *
     * @return array<string, mixed>|null
     */
    public function find_group(string $table, int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT translation_group FROM %i WHERE id = %d',
            $table,
            $id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * The other row of a group that holds a language.
     *
     * @return array<string, mixed>|null
     */
    public function find_other_translation(string $table, $group, string $language, int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id FROM %i WHERE translation_group = %s AND language_code = %s AND id != %d',
            $table,
            $group, $language, $id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Write translation columns on one row WITHOUT flushing.
     *
     * Not an oversight of the split: neither caller flushed here before it. Creating a
     * translation stamps the source's group and flushes once at the end, through
     * flush_translation_created(), and only when the copy was inserted. Linking an `items`
     * list has its own method, link_items(), which does flush.
     *
     * @param array<string, mixed> $data
     */
    public function update_translation_fields(string $table, int $id, array $data): void {
        global $wpdb;

        $wpdb->update($table, $data, ['id' => $id]);
    }

    /**
     * Insert the translated copy of a row. Does not flush - see flush_translation_created().
     *
     * @param array<string, mixed> $copy
     * @return mixed $wpdb->insert_id, untouched; falsy when the insert failed.
     */
    public function insert_copy(string $table, array $copy) {
        global $wpdb;

        $wpdb->insert($table, $copy);

        return $wpdb->insert_id;
    }

    /**
     * Copy a recipe's ingredients, instructions, nutrition and term pivots onto its
     * translation. Does not flush - see flush_translation_created().
     */
    public function copy_recipe_children($new_id, int $id): void {
        global $wpdb;

        // Each copy names its table twice (INSERT target and SELECT source), so both
        // bind as identifiers with %i, interleaved in the positional argument list.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, quantity, unit, name, notes, link_url, amount, amount_max, is_optional, substitution)
                SELECT %d, group_name, group_order, sort_order, quantity, unit, name, notes, link_url, amount, amount_max, is_optional, substitution
                FROM %i WHERE recipe_id = %d',
            $this->table('ingredients'),
            $new_id,
            $this->table('ingredients'),
            $id
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, title, instruction_text, image_id, video_url, duration, timer_seconds, tip)
                SELECT %d, group_name, group_order, sort_order, title, instruction_text, image_id, video_url, duration, timer_seconds, tip
                FROM %i WHERE recipe_id = %d',
            $this->table('instructions'),
            $new_id,
            $this->table('instructions'),
            $id
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source)
                SELECT %d, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source
                FROM %i WHERE recipe_id = %d',
            $this->table('nutrition'),
            $new_id,
            $this->table('nutrition'),
            $id
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, category_id, display_order)
                SELECT %d, category_id, display_order FROM %i WHERE recipe_id = %d',
            $this->table('recipe_categories'),
            $new_id,
            $this->table('recipe_categories'),
            $id
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, cuisine_id, display_order)
                SELECT %d, cuisine_id, display_order FROM %i WHERE recipe_id = %d',
            $this->table('recipe_cuisines'),
            $new_id,
            $this->table('recipe_cuisines'),
            $id
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (recipe_id, course_id)
                SELECT %d, course_id FROM %i WHERE recipe_id = %d',
            $this->table('recipe_courses'),
            $new_id,
            $this->table('recipe_courses'),
            $id
        ));
    }

    /**
     * The flush that ends "create a translation".
     *
     * It is its own method because the plugixa_recipe_duplicate_recipe action fires between
     * the copy and the flush, and the action is the service's to fire: listeners copy their
     * own recipe-keyed rows, and the flush has to come after them.
     */
    public function flush_translation_created(): void {
        // A new row was inserted (and, for recipes, its pivot rows were copied).
        $this->flush('courses');
    }

    /**
     * Put a list of rows in one translation group, each with its language.
     *
     * Ends with the same flush as link_pair(). This path used to write through
     * update_translation_fields() and never flushed, so rows linked from an `items` list
     * kept their old language and group in every cached list until something else wrote.
     *
     * @param array<int, string> $languages Row id => language code.
     */
    public function link_items(string $table, array $languages, string $group): void {
        global $wpdb;

        foreach ($languages as $id => $language_code) {
            $wpdb->update($table, ['translation_group' => $group, 'language_code' => $language_code], ['id' => (int) $id]);
        }

        // The entity is resolved at runtime, so invalidate every translatable entity.
        $this->flush();
    }

    /** Put two rows in the same translation group. */
    public function link_pair(string $table, int $source_id, int $target_id, string $group): void {
        global $wpdb;

        $wpdb->update($table, ['translation_group' => $group], ['id' => $source_id]);
        $wpdb->update($table, ['translation_group' => $group], ['id' => $target_id]);

        // The entity is resolved at runtime, so invalidate every translatable entity.
        $this->flush();
    }

    /**
     * Take one row out of its translation group.
     *
     * @param mixed $translation_id The id of the row to detach, as read from the database.
     * @param mixed $group          The group it is leaving.
     */
    public function unlink(string $table, $translation_id, $group): void {
        global $wpdb;

        // Remove translation_group from the target (give it a new unique group)
        $wpdb->update($table, ['translation_group' => null], ['id' => $translation_id]);

        // If only one item remains in group, clear its group too
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE translation_group = %s',
            $table,
            $group
        ));

        if ($remaining <= 1) {
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET translation_group = NULL WHERE translation_group = %s',
                $table,
                $group
            ));
        }

        // The entity is resolved at runtime, so invalidate every translatable entity.
        $this->flush();
    }
}
