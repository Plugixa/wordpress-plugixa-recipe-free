<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema installer for the tables this module owns: direct queries run on activation and on the migration self-heal, so there is nothing to cache, and CREATE/ALTER TABLE is the purpose of the file. None of these three is a security sniff. The security SQL sniffs are NOT disabled file-wide - they are disabled only around create_tables(), which says why.

use PlugixaRecipe\Core\Database\Schema as Tables;

defined('ABSPATH') || exit;

/**
 * The tables this module owns: reviews, review_helpful_votes.
 *
 * Called from Module::migrate() - on activation, and on plugins_loaded whenever the plugin
 * version or the set of modules changed, or a table that should exist does not.
 */
final class Schema {

    /** @return string[] Unprefixed table names. */
    public static function tables(): array {
        return ['reviews', 'review_helpful_votes'];
    }

    /**
     * Create what is missing, bring older tables up to date. Idempotent.
     */
    public static function install(): void {
        self::create_tables();
        Tables::forget();
        self::upgrade();
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE only. The table name alone could bind with %i, but get_charset_collate() cannot: it is SQL syntax (DEFAULT CHARACTER SET x COLLATE y) that is empty on some installs, so it is neither a value nor an identifier. Both interpolated parts come from $wpdb itself; no request data reaches these statements. Scoped to this method on purpose: everything outside it binds with %i / %s, and SqlLiteralTest fails the build if that stops being true.

    public static function create_tables(): void {
        global $wpdb;

        $prefix          = $wpdb->prefix . 'plugixa_recipe_';
        $charset_collate = $wpdb->get_charset_collate();

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
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    /**
     * Columns added after a table first shipped. Every statement binds its table with %i:
     * unlike CREATE TABLE above, nothing here needs an exemption.
     */
    private static function upgrade(): void {
        global $wpdb;

        $reviews_table = Tables::table('reviews');

        // Migration: add admin_reply columns to reviews table.
        $review_cols = $wpdb->get_col($wpdb->prepare('DESCRIBE %i', $reviews_table), 0);
        if (!in_array('admin_reply', $review_cols, true)) {
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN admin_reply longtext DEFAULT NULL AFTER helpful_count', $reviews_table));
            $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN admin_reply_at datetime DEFAULT NULL AFTER admin_reply', $reviews_table));
        }
    }
}
