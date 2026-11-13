<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the tables this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The tables this module owns: recipes, ingredients, instructions, courses, recipe_courses, units, likes.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['recipes', 'ingredients', 'instructions', 'courses', 'recipe_courses', 'units', 'likes'];
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
        self::upgrade();
        self::seed($created);
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE only. The table name alone could bind with %i, but get_charset_collate() cannot: it is SQL syntax (DEFAULT CHARACTER SET x COLLATE y) that is empty on some installs, so it is neither a value nor an identifier. Both interpolated parts come from $wpdb itself; no request data reaches these statements. Scoped to this method on purpose: everything outside it binds with %i / %s, and SqlLiteralTest fails the build if that stops being true.

    public static function create_tables(): void {
        global $wpdb;

        $prefix          = $wpdb->prefix . 'plugixa_recipe_';
        $charset_collate = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            language_code varchar(10) DEFAULT NULL,
            translation_group varchar(36) DEFAULT NULL,
            title varchar(500) NOT NULL,
            slug varchar(500) NOT NULL,
            description longtext DEFAULT NULL,
            excerpt text DEFAULT NULL,
            notes longtext DEFAULT NULL,
            tips longtext DEFAULT NULL,
            image_id bigint(20) unsigned DEFAULT NULL,
            gallery longtext DEFAULT NULL,
            tags longtext DEFAULT NULL,
            equipment longtext DEFAULT NULL,
            video_url varchar(500) DEFAULT NULL,
            servings int unsigned DEFAULT 4,
            servings_unit varchar(100) DEFAULT 'servings',
            yield_amount varchar(50) DEFAULT NULL,
            yield_unit varchar(100) DEFAULT NULL,
            prep_time int unsigned DEFAULT NULL,
            cook_time int unsigned DEFAULT NULL,
            rest_time int unsigned DEFAULT NULL,
            total_time int unsigned GENERATED ALWAYS AS (COALESCE(prep_time, 0) + COALESCE(cook_time, 0) + COALESCE(rest_time, 0)) STORED,
            difficulty varchar(20) DEFAULT 'medium',
            cost varchar(20) DEFAULT 'moderate',
            season varchar(20) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            is_editor_pick tinyint(1) NOT NULL DEFAULT 0,
            display_order int(11) NOT NULL DEFAULT 0,
            source varchar(255) DEFAULT NULL,
            source_url varchar(500) DEFAULT NULL,
            author_name varchar(255) DEFAULT NULL,
            view_count bigint(20) unsigned NOT NULL DEFAULT 0,
            rating_average decimal(3,2) DEFAULT 0.00,
            rating_count int unsigned DEFAULT 0,
            like_count int unsigned NOT NULL DEFAULT 0,
            enable_schema tinyint(1) NOT NULL DEFAULT 1,
            seo_score tinyint unsigned DEFAULT NULL,
            seo_title varchar(200) DEFAULT NULL,
            seo_description text DEFAULT NULL,
            seo_keywords varchar(500) DEFAULT NULL,
            is_vegetarian tinyint(1) NOT NULL DEFAULT 0,
            is_vegan tinyint(1) NOT NULL DEFAULT 0,
            is_gluten_free tinyint(1) NOT NULL DEFAULT 0,
            is_dairy_free tinyint(1) NOT NULL DEFAULT 0,
            is_keto tinyint(1) NOT NULL DEFAULT 0,
            is_low_carb tinyint(1) NOT NULL DEFAULT 0,
            is_nut_free tinyint(1) NOT NULL DEFAULT 0,
            is_paleo tinyint(1) NOT NULL DEFAULT 0,
            is_sugar_free tinyint(1) NOT NULL DEFAULT 0,
            is_halal tinyint(1) NOT NULL DEFAULT 0,
            is_kosher tinyint(1) NOT NULL DEFAULT 0,
            published_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            modified_at datetime DEFAULT NULL,
            modified_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY is_featured (is_featured),
            KEY display_order (display_order),
            KEY difficulty (difficulty),
            KEY rating_average (rating_average),
            KEY language_code (language_code),
            KEY translation_group (translation_group)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}ingredients (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipe_id bigint(20) unsigned NOT NULL,
            group_name varchar(200) DEFAULT NULL,
            group_order int(11) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            quantity varchar(50) DEFAULT NULL,
            unit varchar(50) DEFAULT NULL,
            name varchar(500) NOT NULL,
            notes varchar(500) DEFAULT NULL,
            link_url varchar(500) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY recipe_id (recipe_id),
            KEY sort_order (recipe_id, group_order, sort_order)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}instructions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipe_id bigint(20) unsigned NOT NULL,
            group_name varchar(200) DEFAULT NULL,
            group_order int(11) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            title varchar(500) DEFAULT NULL,
            instruction_text longtext NOT NULL,
            image_id bigint(20) unsigned DEFAULT NULL,
            video_url varchar(500) DEFAULT NULL,
            duration int unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            KEY recipe_id (recipe_id),
            KEY sort_order (recipe_id, group_order, sort_order)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}courses (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            display_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY display_order (display_order)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipe_courses (
            recipe_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (recipe_id, course_id),
            KEY course_id (course_id)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}units (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            abbreviation varchar(20) NOT NULL,
            -- `system` is a RESERVED WORD in MySQL 8.0.3+, so it must be quoted here and
            -- at every read. Unquoted, this whole CREATE TABLE fails with a syntax error,
            -- the units table is never created, and the failure repeats on every page load
            -- because maybe_create_tables() runs on plugins_loaded.
            `system` varchar(20) NOT NULL DEFAULT 'metric',
            `type` varchar(20) NOT NULL DEFAULT 'volume',
            conversion_factor decimal(15,6) DEFAULT 1.000000,
            PRIMARY KEY (id),
            KEY system_type (`system`, `type`)
        ) {$charset_collate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}likes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            recipe_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_recipe (user_id, recipe_id),
            KEY recipe_id (recipe_id)
        ) {$charset_collate};");
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    /**
     * Columns added after a table first shipped. Every statement binds its table with %i:
     * unlike CREATE TABLE above, nothing here needs an exemption.
     */
    private static function upgrade(): void {
        global $wpdb;

        $recipes_table      = Tables::table('recipes');
        $instructions_table = Tables::table('instructions');

        // Migration: add title column to instructions
        $col = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $instructions_table, $wpdb->esc_like('title')));
        if (empty($col)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN title varchar(500) DEFAULT NULL AFTER sort_order', $instructions_table));
        }

        // Migration: add like_count column to recipes
        $col = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $recipes_table, $wpdb->esc_like('like_count')));
        if (empty($col)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN like_count int unsigned NOT NULL DEFAULT 0 AFTER rating_count', $recipes_table));
        }

        // Migration: add SEO columns to recipes
        $col = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $recipes_table, $wpdb->esc_like('seo_title')));
        if (empty($col)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN seo_title varchar(200) DEFAULT NULL AFTER seo_score', $recipes_table));
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN seo_description text DEFAULT NULL AFTER seo_title', $recipes_table));
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN seo_keywords varchar(500) DEFAULT NULL AFTER seo_description', $recipes_table));
        }

        // Migration: add focus_keyword column to recipes
        $col = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $recipes_table, $wpdb->esc_like('focus_keyword')));
        if (empty($col)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN focus_keyword varchar(255) DEFAULT NULL AFTER seo_keywords', $recipes_table));
        }

        // Migration: add missing recipe columns (excerpt, tips, tags, equipment, yield, diet flags, etc.)
        //
        // One literal ALTER per column. This used to be a map of column definitions, each
        // interpolated onto a shared ALTER TABLE prefix at run time - compact, but it hands
        // the database a statement nobody can read at the call site, and a definition pulled
        // out of an array is not a literal however hard-coded the array is. Keep the order:
        // several AFTER clauses name the column added immediately above them.
        $recipe_cols = $wpdb->get_col($wpdb->prepare('DESCRIBE %i', $recipes_table), 0);
        if (!in_array('excerpt', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN excerpt text DEFAULT NULL AFTER description', $recipes_table));
        }
        if (!in_array('tips', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN tips longtext DEFAULT NULL AFTER notes', $recipes_table));
        }
        if (!in_array('tags', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN tags longtext DEFAULT NULL AFTER gallery', $recipes_table));
        }
        if (!in_array('equipment', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN equipment longtext DEFAULT NULL AFTER tags', $recipes_table));
        }
        if (!in_array('yield_amount', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN yield_amount varchar(50) DEFAULT NULL AFTER servings_unit', $recipes_table));
        }
        if (!in_array('yield_unit', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN yield_unit varchar(100) DEFAULT NULL AFTER yield_amount', $recipes_table));
        }
        if (!in_array('is_editor_pick', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN is_editor_pick tinyint(1) NOT NULL DEFAULT 0 AFTER is_featured', $recipes_table));
        }
        if (!in_array('is_nut_free', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN is_nut_free tinyint(1) NOT NULL DEFAULT 0 AFTER is_low_carb', $recipes_table));
        }
        if (!in_array('is_paleo', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN is_paleo tinyint(1) NOT NULL DEFAULT 0 AFTER is_nut_free', $recipes_table));
        }
        if (!in_array('is_sugar_free', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN is_sugar_free tinyint(1) NOT NULL DEFAULT 0 AFTER is_paleo', $recipes_table));
        }
        if (!in_array('is_halal', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN is_halal tinyint(1) NOT NULL DEFAULT 0 AFTER is_sugar_free', $recipes_table));
        }
        if (!in_array('is_kosher', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN is_kosher tinyint(1) NOT NULL DEFAULT 0 AFTER is_halal', $recipes_table));
        }

        // Migration: add submission_ip column to recipes for tracking frontend submissions.
        if (!in_array('submission_ip', $recipe_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN submission_ip varchar(45) DEFAULT NULL AFTER created_by', $recipes_table));
        }
    }

    /**
     * Default rows. Each routine does nothing unless its table exists and is empty.
     *
     * @param string[]|null $only Limit to these tables (the ones install() just created).
     *                            Null - activation - considers every seedable table.
     */
    public static function seed(?array $only = null): void {
        if (null === $only || in_array('courses', $only, true)) {
            self::seed_courses();
        }
        if (null === $only || in_array('units', $only, true)) {
            self::seed_units();
        }
    }

    private static function seed_courses(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixa_recipe_courses';
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!$existing) {
            return;
        }
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($count > 0) {
            return;
        }

        $courses = ['Appetizer', 'Starter', 'Main Course', 'Side Dish', 'Dessert', 'Beverage', 'Snack', 'Breakfast'];

        foreach ($courses as $i => $name) {
            $wpdb->insert($table, [
                'name'          => $name,
                'slug'          => sanitize_title($name),
                'display_order' => $i,
            ]);
        }
    }

    private static function seed_units(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixa_recipe_units';
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!$existing) {
            return;
        }
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($count > 0) {
            return;
        }

        $units = [
            // Metric volume
            ['name' => 'Milliliter', 'abbreviation' => 'ml', 'system' => 'metric', 'type' => 'volume', 'conversion_factor' => 1.0],
            ['name' => 'Liter', 'abbreviation' => 'L', 'system' => 'metric', 'type' => 'volume', 'conversion_factor' => 1000.0],
            // Imperial volume
            ['name' => 'Teaspoon', 'abbreviation' => 'tsp', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 4.92892],
            ['name' => 'Tablespoon', 'abbreviation' => 'tbsp', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 14.7868],
            ['name' => 'Fluid Ounce', 'abbreviation' => 'fl oz', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 29.5735],
            ['name' => 'Cup', 'abbreviation' => 'cup', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 236.588],
            ['name' => 'Pint', 'abbreviation' => 'pt', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 473.176],
            ['name' => 'Quart', 'abbreviation' => 'qt', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 946.353],
            ['name' => 'Gallon', 'abbreviation' => 'gal', 'system' => 'imperial', 'type' => 'volume', 'conversion_factor' => 3785.41],
            // Metric weight
            ['name' => 'Gram', 'abbreviation' => 'g', 'system' => 'metric', 'type' => 'weight', 'conversion_factor' => 1.0],
            ['name' => 'Kilogram', 'abbreviation' => 'kg', 'system' => 'metric', 'type' => 'weight', 'conversion_factor' => 1000.0],
            // Imperial weight
            ['name' => 'Ounce', 'abbreviation' => 'oz', 'system' => 'imperial', 'type' => 'weight', 'conversion_factor' => 28.3495],
            ['name' => 'Pound', 'abbreviation' => 'lb', 'system' => 'imperial', 'type' => 'weight', 'conversion_factor' => 453.592],
            // Count
            ['name' => 'Piece', 'abbreviation' => 'pc', 'system' => 'metric', 'type' => 'count', 'conversion_factor' => 1.0],
            ['name' => 'Pinch', 'abbreviation' => 'pinch', 'system' => 'metric', 'type' => 'count', 'conversion_factor' => 1.0],
            ['name' => 'Clove', 'abbreviation' => 'clove', 'system' => 'metric', 'type' => 'count', 'conversion_factor' => 1.0],
        ];

        foreach ($units as $unit) {
            $wpdb->insert($table, $unit);
        }
    }
}
