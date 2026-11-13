<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer, upgrade migrations and seed rows for the tables this plugin owns: every statement is a direct query on a plugin table, run on activation and on the plugins_loaded self-heal, so there is nothing to cache; and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Activator - Creates database tables, capabilities, default options, seed views
 */
class Activator {

    public static function activate(): void {
        self::migrate_legacy_names();
        self::create_tables();
        self::add_capabilities();
        self::set_default_options();
        self::seed_default_views();
        self::seed_default_cuisines();
        self::seed_default_courses();
        self::seed_default_units();
        flush_rewrite_rules();
    }

    /**
     * Rename pre-1.0.0 `plugixarecipe_` tables and options to `plugixa_recipe_`.
     *
     * The plugin folder, text domain and Freemius slug were unified on `plugixa-recipe`
     * before the first WordPress.org release; the data layer moved with them. This runs
     * before create_tables() so an installation carrying the old names is renamed in
     * place instead of getting a second, empty set of tables alongside the populated
     * ones. A fresh install finds nothing to rename and this is a no-op.
     *
     * Public so ProBootstrap::maybe_upgrade_db() can call it: an in-place free -> premium
     * overlay upgrade never fires the activation hook.
     */
    public static function migrate_legacy_names(): void {
        global $wpdb;

        $old_prefix = $wpdb->prefix . 'plugixarecipe_';
        $new_prefix = $wpdb->prefix . 'plugixa_recipe_';

        $legacy_tables = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $old_prefix ) . '%'
            )
        );

        foreach ( (array) $legacy_tables as $legacy_table ) {
            $suffix = substr( $legacy_table, strlen( $old_prefix ) );
            $target = $new_prefix . $suffix;

            // Never clobber an existing table - a half-finished migration would otherwise
            // lose the populated side. esc_like() matters here: the target name is full of
            // underscores, which LIKE would otherwise treat as single-character wildcards.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) );
            if ( $exists ) {
                continue;
            }

            // Both names bind as identifiers. This used to be interpolated on the grounds that
            // "RENAME TABLE accepts no placeholders" - true of a %s value, but %i produces a
            // backtick-quoted identifier, which is exactly what RENAME TABLE takes.
            $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy_table, $target ) );
        }

        // Option keys moved with the tables. `plugixarecipe_settings` holds every setting
        // on the site, so losing it would silently reset the plugin to defaults.
        $legacy_options = [
            'plugixarecipe_settings'        => 'plugixa_recipe_settings',
            'plugixarecipe_demo_loaded'     => 'plugixa_recipe_demo_loaded',
            'plugixarecipe_demo_image_ids'  => 'plugixa_recipe_demo_image_ids',
            'plugixarecipe_ml_migrated'     => 'plugixa_recipe_ml_migrated',
            'plugixarecipe_ml_v2_migrated'  => 'plugixa_recipe_ml_v2_migrated',
            'plugixarecipe_pro_db_version'  => 'plugixa_recipe_pro_db_version',
            'pxr_archive_rules_flushed'     => 'plugixa_recipe_archive_rules_flushed',
        ];

        foreach ( $legacy_options as $old_key => $new_key ) {
            $value = get_option( $old_key, null );
            if ( null === $value ) {
                continue;
            }
            if ( false === get_option( $new_key, false ) ) {
                update_option( $new_key, $value );
            }
            delete_option( $old_key );
        }
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE only. The table name alone could bind with %i, but get_charset_collate() cannot: it is SQL syntax (DEFAULT CHARACTER SET x COLLATE y) that is empty on some installs, so it is neither a value nor an identifier. Both interpolated parts come from $wpdb itself ($wpdb->prefix and get_charset_collate()); no request data reaches these statements. Scoped to this one method on purpose: every probe, migration and seed check outside it binds with %i / %s, and SqlLiteralTest fails the build if one stops doing so. Until the post-1.0.2 re-check this disable was file-wide, and 30 of those queries were still interpolated behind it.
    private static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // 1. Recipes table
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

        // 2. Categories table
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

        // 3. Recipe categories pivot
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipe_categories (
            recipe_id bigint(20) unsigned NOT NULL,
            category_id bigint(20) unsigned NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (recipe_id, category_id),
            KEY category_id (category_id),
            KEY cat_display_order (category_id, display_order)
        ) {$charset_collate};");

        // 4. Cuisines table
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

        // 5. Recipe cuisines pivot
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipe_cuisines (
            recipe_id bigint(20) unsigned NOT NULL,
            cuisine_id bigint(20) unsigned NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (recipe_id, cuisine_id),
            KEY cuisine_id (cuisine_id)
        ) {$charset_collate};");

        // 6. Courses table
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

        // 7. Recipe courses pivot
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}recipe_courses (
            recipe_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (recipe_id, course_id),
            KEY course_id (course_id)
        ) {$charset_collate};");

        // 8. Ingredients table
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

        // 9. Instructions table
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

        // 10. Nutrition table (1:1 with recipes)
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

        // 11. Reviews table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}reviews (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipe_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            author_name varchar(255) NOT NULL,
            author_email varchar(255) DEFAULT NULL,
            author_ip varchar(45) DEFAULT NULL,
            rating tinyint unsigned NOT NULL DEFAULT 5,
            review_text longtext DEFAULT NULL,
            images longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            is_verified tinyint(1) NOT NULL DEFAULT 0,
            helpful_count int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            modified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY recipe_id (recipe_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY rating (rating)
        ) {$charset_collate};");

        // 12. Views table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}views (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            config longtext NOT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            modified_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY is_default (is_default)
        ) {$charset_collate};");

        // 13. Shortcodes table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}shortcodes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            config longtext NOT NULL,
            created_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            modified_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$charset_collate};");

        // 16. Collections table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}collections (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            slug varchar(200) NOT NULL,
            description text,
            image_id bigint(20) unsigned DEFAULT NULL,
            type varchar(20) NOT NULL DEFAULT 'collection',
            status varchar(20) NOT NULL DEFAULT 'published',
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            display_order int(11) NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned DEFAULT NULL,
            visibility varchar(20) NOT NULL DEFAULT 'public',
            created_at datetime DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY type (type),
            KEY status (status),
            KEY user_id (user_id)
        ) {$charset_collate};");

        // 17. Collection recipes pivot
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}collection_recipes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            collection_id bigint(20) unsigned NOT NULL,
            recipe_id bigint(20) unsigned NOT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            day_number int unsigned DEFAULT NULL,
            meal_type varchar(50) DEFAULT NULL,
            added_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY collection_recipe (collection_id, recipe_id, day_number, meal_type),
            KEY recipe_id (recipe_id)
        ) {$charset_collate};");

        // 18. Shopping lists table
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

        // 19. Shopping list items table
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

        // 20. Favorites table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}favorites (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            recipe_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_recipe (user_id, recipe_id),
            KEY recipe_id (recipe_id)
        ) {$charset_collate};");

        // 21. Likes table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}likes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            recipe_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_recipe (user_id, recipe_id),
            KEY recipe_id (recipe_id)
        ) {$charset_collate};");

        // 23. Units table
        // 24. Review helpful votes tracking table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$prefix}review_helpful_votes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            review_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_vote (review_id, user_id),
            KEY ip_vote (review_id, ip_address)
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

        // The premium overlay owns its own tables. ProActivator lives in pro/includes/
        // and is absent from the free build, so class_exists() is the only gate -- and it
        // must be a DIRECT check, not a filter: register_activation_hook fires activate_*
        // without running the plugin's plugins_loaded callback, so ProBootstrap has not
        // registered any filters at this point. class_exists() triggers the autoloader,
        // which resolves pro/includes/ first.
        if (class_exists(ProActivator::class)) {
            ProActivator::create_tables();
        }
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    public static function maybe_create_tables(): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        self::migrate_cuisine_slug();
        self::purge_custom_code_settings();

        $recipes_table = $prefix . 'recipes';
        $views_table = $prefix . 'views';
        $units_table = $prefix . 'units';
        $instructions_table = $prefix . 'instructions';
        $reviews_table = $prefix . 'reviews';
        $likes_table = $prefix . 'likes';
        $helpful_table = $prefix . 'review_helpful_votes';

        // Every probe and migration below binds its table with %i and its LIKE pattern with
        // %s, so the string handed to prepare() is a literal - only create_tables() is
        // exempt, and only for CREATE TABLE. The LIKE patterns go through esc_like(): table
        // and column names here are full of underscores, which LIKE otherwise treats as
        // single-character wildcards. (This is also exactly how core's maybe_create_table()
        // probes for a table.)
        $r_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($recipes_table)));
        $v_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($views_table)));
        $u_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($units_table)));

        if (!$r_exists || !$v_exists || !$u_exists) {
            self::create_tables();

            // create_tables() only creates; the seed rows come from activate(). A site that
            // self-heals here never ran activation for the missing table, so seed it now or
            // it stays empty forever. This is not hypothetical: the units DDL used to fail
            // outright because `system` is a MySQL 8.0.3+ reserved word and was unquoted, so
            // every existing installation reaches this branch with no units at all.
            if (!$u_exists) {
                self::seed_default_units();
            }
        }

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

        // Migration: create likes table if missing
        $l_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($likes_table)));
        if (!$l_exists) {
            self::create_tables();
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

        // Migration: add admin_reply columns to reviews table.
        $review_cols = $wpdb->get_col($wpdb->prepare('DESCRIBE %i', $reviews_table), 0);
        if (!in_array('admin_reply', $review_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN admin_reply longtext DEFAULT NULL AFTER helpful_count', $reviews_table));
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN admin_reply_at datetime DEFAULT NULL AFTER admin_reply', $reviews_table));
        }

        // Migration: create review_helpful_votes table if missing.
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($helpful_table)))) {
            self::create_tables();
        }

        // Migration: ensure all default settings keys exist.
        self::set_default_options();
    }

    private static function add_capabilities(): void {
        $admin = get_role('administrator');

        if ($admin) {
            $admin->add_cap('plugixa_recipe_manage');
            $admin->add_cap('plugixa_recipe_edit');
            $admin->add_cap('plugixa_recipe_delete');
            $admin->add_cap('plugixa_recipe_publish');
            $admin->add_cap('plugixa_recipe_settings');
        }
    }

    /**
     * The plugin's default settings.
     *
     * Free keys only. The premium overlay adds its own defaults through the
     * `plugixa_recipe_settings_defaults` filter, so the free build never names a premium
     * setting. This is also the allow-list the settings REST controller validates
     * against, which is what stops a stale bundle persisting a premium key on a free site.
     *
     * @return array<string,mixed>
     */
    /**
     * One-time repair of a stored `cuisine_slug` that predates the setting being read.
     *
     * The shipped default was `cuisine` while the registered rewrite rule said
     * `recipe-cuisine`. Nothing read the setting, so the two could disagree indefinitely
     * without symptom - but the moment the setting IS honoured, every existing site's
     * cuisine archive moves from /recipe-cuisine/ to /cuisine/ and every inbound link
     * 404s. Caught exactly that way on the test site.
     *
     * Only the specific stale value is rewritten, and only once: a site that genuinely
     * wants /cuisine/ can set it again afterwards and this will not undo the choice.
     */
    private static function migrate_cuisine_slug(): void {
        if ( get_option( 'plugixa_recipe_cuisine_slug_migrated' ) ) {
            return;
        }

        $settings = get_option( 'plugixa_recipe_settings' );

        // Nothing stored yet means a fresh install, which already gets the right default.
        if ( is_array( $settings ) && ( $settings['cuisine_slug'] ?? '' ) === 'cuisine' ) {
            $settings['cuisine_slug'] = 'recipe-cuisine';
            update_option( 'plugixa_recipe_settings', $settings );
        }

        update_option( 'plugixa_recipe_cuisine_slug_migrated', '1' );
    }

    /**
     * One-time purge of the removed `custom_css` / `custom_js` settings.
     *
     * Both keys are gone from get_default_settings(), which is the allow-list the
     * settings REST controller intersects the payload against - so nothing can write
     * them again. That is not enough on its own: update_settings() persists with
     * array_merge( $current, $sanitized ) and set_default_options() only ever ADDS
     * missing keys. Neither ever removes one, so a value stored before the removal
     * would sit in the option row forever with no UI left to clear it.
     *
     * The feature went because arbitrary CSS/JS held in an option and echoed back out
     * on the frontend is stored code execution: `wp_strip_all_tags()` was the only
     * filter applied on save, and it does nothing at all to JavaScript.
     */
    private static function purge_custom_code_settings(): void {
        if ( get_option( 'plugixa_recipe_custom_code_purged' ) ) {
            return;
        }

        $settings = get_option( 'plugixa_recipe_settings' );

        // Nothing stored yet means a fresh install, which never had the keys.
        if ( is_array( $settings )
            && ( array_key_exists( 'custom_css', $settings ) || array_key_exists( 'custom_js', $settings ) )
        ) {
            unset( $settings['custom_css'], $settings['custom_js'] );
            update_option( 'plugixa_recipe_settings', $settings );
        }

        update_option( 'plugixa_recipe_custom_code_purged', '1' );
    }

    public static function get_default_settings(): array {
        $defaults = [
            'items_per_page'        => 20,
            'default_status'        => 'draft',
            'date_format'           => 'M j, Y',
            'excerpt_length'        => 150,
            'default_servings'      => 4,
            'measurement_system'    => 'metric',
            'show_nutrition'        => true,
            'show_reviews'          => true,
            'review_moderation'     => true,
            'review_default_status' => 'pending',
            'enable_print'          => true,
            'enable_favorites'      => true,
            'enable_shopping_lists' => true,
            'enable_submissions'    => false,
            'submission_require_login' => false,
            'submission_status'     => 'pending',
            'max_upload_size'       => 5,
            'success_message'       => 'Your recipe has been submitted successfully and is pending review.',
            // Cloudflare Turnstile. There is no score threshold: Turnstile returns a
            // pass/fail verdict, not reCAPTCHA v3's 0.0-1.0 score.
            'turnstile_form_enabled'    => false,
            'turnstile_reviews_enabled' => false,
            'turnstile_site_key'    => '',
            'turnstile_secret_key'  => '',
            'notification_enabled'  => false,
            'notification_recipients' => '',
            'notification_subject'  => 'New recipe review submitted',
            'notification_message'  => 'A new review has been submitted for "{recipe_title}" by {author_name}.',

            // General
            'plugin_name'           => 'PlugixaRecipe',
            'default_language'      => 'en',
            'enable_reviews'        => true,
            'enable_collections'    => true,
            'enable_nutrition'      => true,
            'enable_seo'            => true,
            'enable_unit_conversion' => true,

            // Display
            'default_layout'        => 'grid',
            'recipe_slug'           => 'recipe',
            'category_slug'         => 'recipe-category',
            'cuisine_slug'          => 'recipe-cuisine',
            'show_breadcrumbs'      => true,

            // Reviews
            'allow_guest_reviews'       => false,
            'require_rating'            => true,
            'review_enable_images'      => false,
            'review_max_images'         => 3,
            'review_enable_helpful_votes' => true,
            'review_enable_admin_reply' => true,
            'reviews_per_page'          => 10,
            'reviews_default_sort'      => 'newest',

            // SEO
            'enable_sitemap'            => true,
            'default_seo_title'         => '',
            'default_seo_description'   => '',
            'enable_open_graph'         => true,
            'enable_twitter_cards'      => true,

            // Performance
            'enable_lazy_load'          => true,
            'enable_cache'              => false,
            'cache_duration'            => 24,
            'image_optimization'        => false,
            'thumbnail_quality'         => 82,

            // API
            'enable_rest_api'           => true,
            'enable_public_api'         => false,
            'api_rate_limit'            => 100,
            'public_api_key'            => '',

            // Advanced
            'delete_data_on_uninstall'  => false,
            'enable_debug'              => false,
        ];

        /**
         * Filter the plugin's default settings.
         *
         * An overlay adds its own keys here. The free build ships none of them, and
         * deliberately does not name them: see the note on the free-keys-only list above.
         *
         * @param array<string,mixed> $defaults
         */
        return apply_filters('plugixa_recipe_settings_defaults', $defaults);
    }

    private static function set_default_options(): void {
        $defaults = self::get_default_settings();

        if (get_option('plugixa_recipe_settings') === false) {
            add_option('plugixa_recipe_settings', $defaults);
        }

        // Migration: add new keys to existing installs.
        $existing = get_option('plugixa_recipe_settings', []);
        if (is_array($existing)) {
            $updated = false;
            foreach ($defaults as $key => $default) {
                if (!array_key_exists($key, $existing)) {
                    $existing[$key] = $default;
                    $updated = true;
                }
            }
            if ($updated) {
                update_option('plugixa_recipe_settings', $existing);
            }
        }
    }

    public static function seed_default_views(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'plugixa_recipe_views';
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!$existing) {
            return;
        }
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($count > 0) {
            return;
        }

        $user_id = get_current_user_id();
        $now = current_time('mysql');

        $grid_config = [
            'layout' => [
                'style' => 'grid',
                'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
            ],
            'display' => [
                'showImage' => true,
                'showRating' => true,
                'showTime' => true,
                'showDifficulty' => true,
                'showServings' => true,
                'showCuisine' => true,
                'showDietBadges' => true,
                'showCategories' => false,
                'showExcerpt' => true,
                'excerptLength' => 120,
            ],
            'colors' => [
                'cardBg' => '#ffffff',
                'textColor' => '#1d2327',
                'titleColor' => '#1d2327',
                'metaColor' => '#646970',
                'starColor' => '#f59e0b',
                'starEmptyColor' => '#d1d5db',
                'borderColor' => '#e5e7eb',
                'badgeBg' => '#f0fdf4',
                'badgeText' => '#166534',
            ],
            'typography' => [
                'titleFontSize' => 18,
                'titleFontWeight' => 600,
                'textFontSize' => 14,
                'textFontWeight' => 400,
                'metaFontSize' => 13,
            ],
            'spacing' => [
                'cardPadding' => 0,
                'contentPadding' => 16,
                'gap' => 24,
            ],
            'borders' => [
                'radius' => 8,
                'width' => 1,
            ],
            'shadows' => [
                'enabled' => true,
                'value' => '0 1px 3px rgba(0,0,0,0.1)',
            ],
            'image' => [
                'aspectRatio' => '16/9',
                'objectFit' => 'cover',
                'borderRadius' => 0,
            ],
        ];

        $slider_config = array_replace_recursive($grid_config, [
            'layout' => [
                'style' => 'slider',
                'slidesPerView' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
                'autoplay' => true,
                'autoplaySpeed' => 5000,
                'showArrows' => true,
                'showDots' => true,
                'loop' => true,
                'effect' => 'slide',
                'paginationType' => 'bullets',
                'keyboard' => true,
                'freeMode' => false,
                'lazy' => true,
                'speed' => 400,
                'spaceBetween' => 24,
                'pauseOnHover' => true,
            ],
        ]);

        $masonry_config = array_replace_recursive($grid_config, [
            'layout' => [
                'style' => 'masonry',
                'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1],
            ],
        ]);

        $list_config = array_replace_recursive($grid_config, [
            'layout' => [
                'style' => 'list',
                'columns' => ['desktop' => 1, 'tablet' => 1, 'mobile' => 1],
            ],
            'display' => [
                'excerptLength' => 200,
            ],
        ]);

        $views = [
            ['name' => 'Grid', 'slug' => 'grid', 'description' => 'Responsive grid layout with recipe cards.', 'config' => wp_json_encode($grid_config), 'is_default' => 1, 'created_at' => $now, 'created_by' => $user_id],
            ['name' => 'Slider', 'slug' => 'slider', 'description' => 'Carousel slider with autoplay and navigation.', 'config' => wp_json_encode($slider_config), 'is_default' => 0, 'created_at' => $now, 'created_by' => $user_id],
            ['name' => 'Masonry', 'slug' => 'masonry', 'description' => 'Pinterest-style masonry layout.', 'config' => wp_json_encode($masonry_config), 'is_default' => 0, 'created_at' => $now, 'created_by' => $user_id],
            ['name' => 'List', 'slug' => 'list', 'description' => 'Full-width list layout with larger excerpts.', 'config' => wp_json_encode($list_config), 'is_default' => 0, 'created_at' => $now, 'created_by' => $user_id],
        ];

        foreach ($views as $view) {
            $wpdb->insert($table, $view);
        }
    }

    private static function seed_default_cuisines(): void {
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

    private static function seed_default_courses(): void {
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

    private static function seed_default_units(): void {
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
