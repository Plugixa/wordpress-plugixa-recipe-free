<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Views;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the table this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The table this module owns: views.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['views'];
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
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    /**
     * Default rows. Each routine does nothing unless its table exists and is empty.
     *
     * @param string[]|null $only Limit to these tables (the ones install() just created).
     *                            Null - activation - considers every seedable table.
     */
    public static function seed(?array $only = null): void {
        if (null === $only || in_array('views', $only, true)) {
            self::seed_views();
        }
    }

    private static function seed_views(): void {
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
}
