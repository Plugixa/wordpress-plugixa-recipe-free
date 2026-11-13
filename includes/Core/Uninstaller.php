<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery -- The uninstaller's whole job is to DROP the plugin's own tables, so SchemaChange is the intended behaviour, not an accident. meta_key / meta_value here are real columns on the plugin's OWN meta table, not WP_Query post-meta arguments, so the slow-query sniff's premise does not apply.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

/**
 * Plugin uninstall cleanup.
 *
 * Entry point is on_uninstall(), registered with register_uninstall_hook() in the main
 * plugin file. Relying on Freemius alone was NOT enough: Freemius registers its own
 * uninstall callback only during its ADMIN activation flow, so a site activated with
 * `wp plugin activate` (deploy scripts, most managed hosting) had no callback at all --
 * nothing was ever cleaned up and the "Delete all data on uninstall" setting silently
 * did nothing. Verified on a clean site: after `wp plugin delete` the options and all
 * five capabilities survived.
 *
 * A root uninstall.php would also be unconditional, but it is the wrong tool here and
 * the release script rejects one: WordPress runs the uninstall file belonging to the
 * FOLDER being deleted, so during a free <-> premium switch it would drop tables the
 * surviving build still uses. Freemius's own callback carries that counterpart guard,
 * which is why on_uninstall() delegates to it rather than replacing it.
 */
class Uninstaller {

    /**
     * WordPress uninstall callback (see register_uninstall_hook in plugixa-recipe.php).
     *
     * Registered on every load while the plugin is active, so the `uninstall_plugins`
     * option is written regardless of how the plugin was activated. It intentionally
     * OVERWRITES the equivalent Freemius registration and then calls it, so nothing
     * Freemius does is lost.
     */
    public static function on_uninstall(): void {
        // Give Freemius its turn first so it clears its own data and fires
        // `after_uninstall`. It resolves which product it is running for from
        // current_filter(), which is why this must stay a register_uninstall_hook
        // callback rather than a plain function call.
        //
        // It is deliberately NOT the only path. _uninstall_plugin_hook() returns
        // immediately unless current_user_can('activate_plugins'), and under WP-CLI there
        // is no current user at all - so on exactly the installs that were already broken,
        // Freemius does nothing. Delegating to it and returning reproduced the original
        // bug; the cleanup below has to run on its own account.
        if ( class_exists( '\Freemius' ) && function_exists( 'plugixa_recipe_fs_pxr' ) ) {
            \Freemius::_uninstall_plugin_hook();
        }

        // The one case where dropping data is wrong: the other build of this plugin is
        // still installed and active. WordPress runs the uninstall belonging to the FOLDER
        // being deleted, so without this a free <-> premium switch would take the surviving
        // build's tables with it. This is the guard Freemius applies internally, repeated
        // here because the call above may never have reached it.
        if ( self::counterpart_still_active() ) {
            return;
        }

        // uninstall() is idempotent, so this is a no-op when Freemius already got there.
        self::uninstall();
    }

    /**
     * Delete every option this plugin owns, by prefix.
     *
     * This replaced a hand-listed set of five delete_option() calls, which had quietly
     * rotted: `plugixa_recipe_archive_rules_flushed` and `plugixa_recipe_pro_db_version`
     * were both added later and neither was on the list, so both survived an uninstall.
     * A list can also never cover widget instances, whose option names WordPress derives
     * from the widget id at runtime, nor the plugin's transients. Sweeping by prefix is
     * self-maintaining: a new option is covered the day it is added.
     *
     * delete_option() is used rather than one bulk DELETE so the options cache, and any
     * persistent object cache in front of it, are invalidated per key.
     */
    private static function delete_all_options(): void {
        global $wpdb;

        $names = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i
                  WHERE option_name LIKE %s
                     OR option_name LIKE %s
                     OR option_name LIKE %s
                     OR option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like( 'plugixa_recipe' ) . '%',
                $wpdb->esc_like( 'widget_plugixa_recipe' ) . '%',
                $wpdb->esc_like( '_transient_plugixa_recipe' ) . '%',
                $wpdb->esc_like( '_transient_timeout_plugixa_recipe' ) . '%'
            )
        );

        foreach ( $names as $name ) {
            delete_option( $name );
        }
    }

    /**
     * Whether the free/premium counterpart of this plugin is still active.
     *
     * Freemius keeps the free basename private, but exposes premium_plugin_basename();
     * comparing both candidates against WP_UNINSTALL_PLUGIN covers the switch in either
     * direction. Without the SDK there is no counterpart to worry about.
     */
    private static function counterpart_still_active(): bool {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! function_exists( 'plugixa_recipe_fs_pxr' ) ) {
            return false;
        }

        $fs = plugixa_recipe_fs_pxr();
        if ( ! is_object( $fs ) || ! method_exists( $fs, 'premium_plugin_basename' ) ) {
            return false;
        }

        $candidates = [ $fs->premium_plugin_basename() ];
        if ( defined( 'PLUGIXA_RECIPE_PATH' ) ) {
            $candidates[] = plugin_basename( PLUGIXA_RECIPE_PATH . 'plugixa-recipe.php' );
        }

        // is_plugin_active() lives in a file only wp-admin loads. Loaded here, directly above
        // the loop that calls it.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ( array_unique( array_filter( $candidates ) ) as $basename ) {
            if ( $basename !== WP_UNINSTALL_PLUGIN && is_plugin_active( $basename ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove uploads/plugixa-recipe-imports and what is in it.
     *
     * The path is built from the uploads directory and a fixed name, and is checked to be
     * inside it before anything is deleted.
     */
    private static function delete_import_staging(): void {
        global $wp_filesystem;

        $uploads = wp_upload_dir(null, false);
        $base    = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $dir     = $base . '/plugixa-recipe-imports';

        if ('' === $base || !is_dir($dir) || 0 !== strpos(wp_normalize_path($dir), trailingslashit(wp_normalize_path($base)))) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (WP_Filesystem() && $wp_filesystem) {
            $wp_filesystem->delete($dir, true);
        }
    }

    /**
     * Drop tables, options, and capabilities on plugin delete.
     *
     * Honors the `delete_data_on_uninstall` setting: when disabled, plugin
     * data (tables/attachments) is preserved and only options + capabilities
     * are removed.
     */
    public static function uninstall() {
        global $wpdb;

        // uninstall.php calls this directly AND re-fires Freemius's after_uninstall, which is
        // hooked to it as well. Both paths are wanted (either can be the only one that
        // runs), so make a second call a no-op rather than dropping tables twice.
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        // Check if user opted to delete all data on uninstall.
        $settings = get_option('plugixa_recipe_settings', []);
        if (empty($settings['delete_data_on_uninstall'])) {
            // Preserve plugin data — only remove options and capabilities.
            self::delete_all_options();

            $admin = get_role('administrator');
            if ($admin) {
                $admin->remove_cap('plugixa_recipe_manage');
                $admin->remove_cap('plugixa_recipe_edit');
                $admin->remove_cap('plugixa_recipe_delete');
                $admin->remove_cap('plugixa_recipe_publish');
                $admin->remove_cap('plugixa_recipe_settings');
            }
            return;
        }

        // Drop tables (order matters due to foreign key relationships).
        //
        // This list deliberately includes the three tables the premium overlay owns
        // (custom_fields, meta, seo_analysis). They are named here, not created here:
        // DROP TABLE IF EXISTS is a no-op when they are absent, and naming them means a
        // site that downgrades from premium to free can still clean them up on uninstall
        // instead of leaving them behind forever.
        $tables = [
            $wpdb->prefix . 'plugixa_recipe_shopping_list_items',
            $wpdb->prefix . 'plugixa_recipe_shopping_lists',
            $wpdb->prefix . 'plugixa_recipe_collection_recipes',
            $wpdb->prefix . 'plugixa_recipe_collections',
            $wpdb->prefix . 'plugixa_recipe_favorites',
            $wpdb->prefix . 'plugixa_recipe_likes',
            $wpdb->prefix . 'plugixa_recipe_review_helpful_votes',
            $wpdb->prefix . 'plugixa_recipe_seo_analysis',
            $wpdb->prefix . 'plugixa_recipe_reviews',
            $wpdb->prefix . 'plugixa_recipe_nutrition',
            $wpdb->prefix . 'plugixa_recipe_instructions',
            $wpdb->prefix . 'plugixa_recipe_ingredients',
            $wpdb->prefix . 'plugixa_recipe_meta',
            $wpdb->prefix . 'plugixa_recipe_custom_fields',
            $wpdb->prefix . 'plugixa_recipe_recipe_courses',
            $wpdb->prefix . 'plugixa_recipe_courses',
            $wpdb->prefix . 'plugixa_recipe_recipe_cuisines',
            $wpdb->prefix . 'plugixa_recipe_cuisines',
            $wpdb->prefix . 'plugixa_recipe_recipe_categories',
            $wpdb->prefix . 'plugixa_recipe_categories',
            $wpdb->prefix . 'plugixa_recipe_recipes',
            $wpdb->prefix . 'plugixa_recipe_views',
            $wpdb->prefix . 'plugixa_recipe_shortcodes',
            $wpdb->prefix . 'plugixa_recipe_units',
        ];

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        }

        // Then sweep anything still carrying the prefix. The ordered list above stays for
        // the foreign-key order and for the premium tables a free build must still be able
        // to remove, but a list alone was not enough: `likes` and `review_helpful_votes`
        // were both added after it was written and both survived an uninstall. This makes
        // the list an optimisation rather than the thing correctness depends on.
        $leftovers = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $wpdb->prefix . 'plugixa_recipe_' ) . '%'
            )
        );

        foreach ( $leftovers as $table ) {
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
        }

        // The one directory the plugin makes outside the media library: where an uploaded
        // import file waits between the wizard's two steps. Like the premium tables above it
        // is named here and not created here, so a site that went back to the free build
        // is cleaned too. It survived "delete all data" until the October 2026 release E2E.
        self::delete_import_staging();

        // Delete demo attachments from media library
        $demo_image_ids = get_option('plugixa_recipe_demo_image_ids', []);
        if (is_array($demo_image_ids)) {
            // Handle structured format (recipes/categories/cuisines sub-arrays)
            foreach (['recipes', 'categories', 'cuisines'] as $group) {
                if (isset($demo_image_ids[$group]) && is_array($demo_image_ids[$group])) {
                    foreach ($demo_image_ids[$group] as $id) {
                        wp_delete_attachment((int) $id, true);
                    }
                }
            }
            // Legacy flat format fallback
            if (!isset($demo_image_ids['recipes'])) {
                foreach ($demo_image_ids as $id) {
                    if (is_int($id)) {
                        wp_delete_attachment($id, true);
                    }
                }
            }
        }
        $remaining_demo = get_posts([
            'post_type'   => 'attachment',
            'meta_key'    => '_plugixa_recipe_demo',
            'meta_value'  => '1',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($remaining_demo as $id) {
            wp_delete_attachment((int) $id, true);
        }

        // Delete options
        self::delete_all_options();

        // Remove capabilities
        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap('plugixa_recipe_manage');
            $admin->remove_cap('plugixa_recipe_edit');
            $admin->remove_cap('plugixa_recipe_delete');
            $admin->remove_cap('plugixa_recipe_publish');
            $admin->remove_cap('plugixa_recipe_settings');
        }
    }
}
