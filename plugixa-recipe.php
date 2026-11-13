<?php

/**
 * Plugin Name: Plugixa Recipe
 * Plugin URI: https://www.plugixa.com/plugins/plugixa-recipe
 * Description: A modern recipe management plugin with React admin panel, nutrition facts, user reviews, collections, meal planning, and multiple display layouts.
 * Version: 1.0.0
 * Author: Plugixa
 * Author URI: https://plugixa.com
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugixa-recipe
 * Requires at least: 6.5
 * Requires PHP: 8.0
 *
 * All premium code and dependencies live under pro/: PHP in pro/includes/, the PDF
 * Parser library in pro/vendor/ with its own autoloader, and the compiled pro admin
 * bundle in pro/assets/backend/build/. Freemius strips these paths from the
 * auto-generated free version, so the free build ships no premium code or
 * premium-only libraries. The human-readable app source (assets/backend/app,
 * assets/frontend) ships inside the release ZIPs; the free build carries the
 * free-buildable set only (the pro admin modules under assets/backend/app/src/pro
 * are premium). Build instructions are in readme.txt (== Source Code ==).
 *
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Plugin constants
define( 'PLUGIXA_RECIPE_VERSION', '1.0.0' );
define( 'PLUGIXA_RECIPE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIXA_RECIPE_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIXA_RECIPE_BASENAME', plugin_basename( __FILE__ ) );
// Core Composer autoloader.
if ( file_exists( PLUGIXA_RECIPE_PATH . 'vendor/autoload.php' ) ) {
    require_once PLUGIXA_RECIPE_PATH . 'vendor/autoload.php';
}
// Premium dependencies (PDF Parser, used by the document importer) live in pro/vendor
// and are absent from the free build. Load them only when the overlay is present.
if ( file_exists( PLUGIXA_RECIPE_PATH . 'pro/vendor/autoload.php' ) ) {
    require_once PLUGIXA_RECIPE_PATH . 'pro/vendor/autoload.php';
}
// --- Freemius SDK bootstrap --------------------------------------------------
if ( !function_exists( 'plugixa_recipe_fs_pxr' ) ) {
    // Create a helper function for easy SDK access.
    function plugixa_recipe_fs_pxr() {
        global $plugixa_recipe_fs_pxr;
        if ( !isset( $plugixa_recipe_fs_pxr ) ) {
            // Activate multisite network integration.
            if ( !defined( 'WP_FS__PRODUCT_27466_MULTISITE' ) ) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- name mandated by the Freemius SDK, which looks it up as WP_FS__PRODUCT_{id}_MULTISITE. Renaming it would silently disable multisite network integration.
                define( 'WP_FS__PRODUCT_27466_MULTISITE', true );
            }
            // Include Freemius SDK.
            // SDK is auto-loaded through Composer: vendor/composer/autoload_files.php
            // registers start.php, and vendor/autoload.php is required at the top of this file.
            $plugixa_recipe_fs_pxr = fs_dynamic_init( array(
                'id'               => '27466',
                'slug'             => 'plugixa-recipe',
                'type'             => 'plugin',
                'public_key'       => 'pk_d4efae3d93c40d63f87132ce4f363',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'plugixa-recipe',
                    'support' => false,
                ),
                'is_live'          => true,
            ) );
            // No trial is offered. Suppress every Freemius trial promotion - the
            // "Start free trial" admin banner, the "Start Trial" menu item, and the
            // trial pricing CTA - so the wp.org free build shows only "Upgrade".
            $plugixa_recipe_fs_pxr->add_filter( 'show_trial', '__return_false' );
        }
        return $plugixa_recipe_fs_pxr;
    }

    // Init Freemius.
    plugixa_recipe_fs_pxr();
    // Signal that the SDK is loaded (standard Freemius integration hook).
    do_action( 'plugixa_recipe_fs_pxr_loaded' );
}
/**
 * PSR-4 style autoloader for plugin classes.
 * Resolves from pro/includes/ first (when shipped), falls back to includes/.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'PlugixaRecipe\\';
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $relative_class = substr( $class, $len );
    $parts = explode( '\\', $relative_class );
    $class_name = array_pop( $parts );
    // Convert CamelCase to kebab-case: RecipesController -> recipes-controller
    $file_name = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) . '.php';
    $dir_path = '';
    if ( !empty( $parts ) ) {
        $dir_path = strtolower( implode( '/', $parts ) ) . '/';
    }
    foreach ( [PLUGIXA_RECIPE_PATH . 'pro/includes/', PLUGIXA_RECIPE_PATH . 'includes/'] as $base_dir ) {
        $file = $base_dir . $dir_path . $file_name;
        if ( file_exists( $file ) ) {
            require $file;
            return;
        }
    }
} );
/**
 * Plugin activation hook
 */
register_activation_hook( __FILE__, function () {
    \PlugixaRecipe\Activator::activate();
} );
/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, function () {
    \PlugixaRecipe\Deactivator::deactivate();
} );
/**
 * Plugin uninstall cleanup.
 *
 * Two links in one chain. WordPress calls Uninstaller::on_uninstall(), which hands over
 * to Freemius; Freemius then fires `after_uninstall`, which runs the cleanup below.
 *
 * Registering our own hook is the point: Freemius registers its uninstall callback only
 * during its ADMIN activation flow, so anything activated with `wp plugin activate` had
 * no callback at all and was never cleaned up -- including the "Delete all data on
 * uninstall" setting, which silently did nothing. This call runs on every load while the
 * plugin is active, so the registration is always present. It overwrites the Freemius
 * one (WordPress stores a single callback per plugin) and on_uninstall() then invokes
 * Freemius itself, so Freemius's own cleanup and its free/premium counterpart guard are
 * both preserved.
 *
 * The callback must stay a named static: register_uninstall_hook() serialises it into an
 * option, so a closure cannot be used here.
 */
register_uninstall_hook( __FILE__, array('\\PlugixaRecipe\\Uninstaller', 'on_uninstall') );
plugixa_recipe_fs_pxr()->add_action( 'after_uninstall', function () {
    \PlugixaRecipe\Uninstaller::uninstall();
} );
/**
 * Initialize the plugin
 */
add_action( 'plugins_loaded', function () {
    \PlugixaRecipe\Plugin::instance();
} );