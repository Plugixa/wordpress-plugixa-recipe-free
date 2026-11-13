<?php

/**
 * Plugin Name: Plugixa Recipe – Recipe Cards, Nutrition Facts, Reviews & Schema
 * Plugin URI: https://www.plugixa.com/plugins/plugixa-recipe
 * Description: A modern recipe management plugin with React admin panel, nutrition facts, user reviews, favourites, and multiple display layouts.
 * Version: 1.4.0
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
 * premium-only libraries. The human-readable source of the compiled admin app is
 * assets/backend/app/ (the pro admin modules under assets/backend/app/src/pro are
 * premium and are not part of the free build). Every compiled directory carries a
 * SOURCE.txt that names its source and the exact command that rebuilds it.
 *
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// The free and the premium build live in different folders (plugixa-recipe and
// plugixa-recipe-premium), so for the length of one request both can be on the site: the
// request that activates one while the other is still active. The copy that loaded first
// is the plugin for that request. This one only tells Freemius which file it is - that is
// what makes Freemius deactivate the other build - and stops, instead of defining the
// constants and registering every hook a second time.
if ( function_exists( 'plugixa_recipe_fs_pxr' ) ) {
    plugixa_recipe_fs_pxr()->set_basename( is_dir( __DIR__ . '/pro' ), __FILE__ );
    return;
}
// Plugin constants
define( 'PLUGIXA_RECIPE_VERSION', '1.4.0' );
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
                    'account' => false,
                    'contact' => false,
                    'pricing' => false,
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
 * The free classes are PSR-4 under includes/ and loaded by Composer (composer.json maps
 * PlugixaRecipe\ to includes/). This loader covers the two things Composer must not know:
 * the legacy uninstall callback name, and the premium tree -
 *
 *   PlugixaRecipe\Pro\Modules\Schema\Module  ->  pro/includes/Modules/Schema/Module.php
 *
 * - which is absent from the free build and so stays out of the committed classmap: a free
 * ZIP whose autoloader lists premium classes is naming premium features.
 */
spl_autoload_register( function ( $class ) {
    // The uninstall callback is stored in the database by its class name. A site that last
    // registered it before the Uninstaller moved into Core still holds the old name, and
    // WordPress calls it without consulting this file's register_uninstall_hook() again -
    // so the old name has to keep resolving for as long as such a site can exist.
    if ( 'PlugixaRecipe\\Uninstaller' === $class ) {
        class_alias( 'PlugixaRecipe\\Core\\Uninstaller', $class );
        return;
    }
    $prefix = 'PlugixaRecipe\\Pro\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $file = PLUGIXA_RECIPE_PATH . 'pro/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );
// Global helper functions (plugixa_recipe_allowed_html, plugixa_recipe_user_can, ...) and the
// safecss_filter_attr_allow_css hook that keeps --pxr-* custom properties alive through
// wp_kses(). The one file in includes/ that is not a class, so it is loaded by hand - here
// rather than on plugins_loaded, because activation runs plugin code without that hook.
require_once PLUGIXA_RECIPE_PATH . 'includes/Core/helpers.php';
/**
 * Plugin activation hook
 */
register_activation_hook( __FILE__, function () {
    \PlugixaRecipe\Core\Activator::activate();
} );
/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, function () {
    \PlugixaRecipe\Core\Deactivator::deactivate();
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
register_uninstall_hook( __FILE__, array('\\PlugixaRecipe\\Core\\Uninstaller', 'on_uninstall') );
plugixa_recipe_fs_pxr()->add_action( 'after_uninstall', function () {
    \PlugixaRecipe\Core\Uninstaller::uninstall();
} );
/**
 * Initialize the plugin
 */
add_action( 'plugins_loaded', function () {
    \PlugixaRecipe\Core\Plugin::instance();
} );