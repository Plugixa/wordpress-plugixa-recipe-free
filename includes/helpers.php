<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * Global helper functions for Plugixa Recipe.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

require_once PLUGIXA_RECIPE_PATH . 'includes/class-kses.php';
\PlugixaRecipe\Kses::register();

if ( ! function_exists( 'plugixa_recipe_allowed_html' ) ) {
    /**
     * Allowed-HTML map for wp_kses() in a plugin rendering context.
     *
     * Intended to be used as the second argument of a DIRECT wp_kses() call at the echo:
     *
     *     echo wp_kses( $fragment, plugixa_recipe_allowed_html() );
     *
     * WPCS recognises only the direct call as escaping - wrapping wp_kses() in a helper
     * and echoing that helper's return value is still reported as unescaped output.
     *
     * @since 1.0.0
     *
     * @param string $context 'frontend' (plugin-rendered fragments) or 'content'
     *                        (user-authored recipe copy).
     * @return array<string,array<string,bool>>
     */
    function plugixa_recipe_allowed_html( string $context = 'frontend' ): array {
        return \PlugixaRecipe\Kses::allowed_html( $context );
    }
}

if ( ! function_exists( 'plugixa_recipe_kses' ) ) {
    /**
     * Late-escape a plugin-rendered HTML fragment and return it.
     *
     * For building a value that is echoed elsewhere. At an actual echo, prefer the direct
     * wp_kses() call shown in plugixa_recipe_allowed_html().
     *
     * @since 1.0.0
     *
     * @param string $html    Fragment to escape.
     * @param string $context Allowlist context.
     * @return string Escaped fragment, safe to echo.
     */
    function plugixa_recipe_kses( string $html, string $context = 'frontend' ): string {
        return \PlugixaRecipe\Kses::esc( $html, $context );
    }
}

if ( ! function_exists( 'plugixa_recipe_user_can' ) ) {
    /**
     * Whether the current user holds a plugin capability, or the WordPress capability
     * that granted the same access before the plugin capabilities were enforced.
     *
     * Single source of truth for the permission model. Both sides must agree: the REST
     * layer (PlugixaRecipe\Api\RestController::can) decides what the API accepts, and
     * the admin bootstrap (PlugixaRecipe\Admin\Assets::get_user_permissions) decides
     * what the React app shows. When they diverge the UI either hides controls the API
     * would accept, or shows controls it refuses - RestPermissionsTest asserts they do
     * not, and caught exactly that drift for the editor role.
     *
     * The WordPress capability is accepted as a fallback so that sites upgrading from a
     * version where recipe management only required 'edit_posts' do not lose access.
     * Note the consequence: while the fallback is in place a plugin capability can
     * *grant* access to a role that lacks the WordPress one, but it cannot *revoke*
     * access from a role that already has it. Removing the fallback is a breaking
     * change and belongs in a major version.
     *
     * @since 1.0.0
     *
     * @param string $plugin_cap   Plugin capability, e.g. 'plugixa_recipe_delete'.
     * @param string $fallback_cap WordPress capability historically accepted.
     * @return bool
     */
    function plugixa_recipe_user_can( string $plugin_cap, string $fallback_cap ): bool {
        return current_user_can( $plugin_cap ) || current_user_can( $fallback_cap );
    }
}

if ( ! function_exists( 'plugixa_recipe_url_slug' ) ) {
    /**
     * The URL slug for one of the plugin's public route families.
     *
     * Single source of truth for the three Settings > Display fields. They were editable
     * for a release without being read anywhere: the rewrite rules hard-coded their
     * values, so changing one had no effect at all.
     *
     * Defaults are the slugs the plugin has always shipped, so an untouched site keeps
     * exactly the URLs it had. Note `cuisine` defaults to `recipe-cuisine`, not
     * `cuisine`: the stored default said `cuisine` while the registered rule said
     * `recipe-cuisine`, and the rule is what real links point at.
     *
     * @since 1.0.0
     *
     * @param string $route One of 'recipe', 'category', 'cuisine'.
     * @return string Sanitized slug, never empty.
     */
    function plugixa_recipe_url_slug( string $route ): string {
        $defaults = [
            'recipe'   => 'recipe',
            'category' => 'recipe-category',
            'cuisine'  => 'recipe-cuisine',
        ];

        if ( ! isset( $defaults[ $route ] ) ) {
            return '';
        }

        $settings = (array) get_option( 'plugixa_recipe_settings', [] );
        $slug     = sanitize_title( (string) ( $settings[ $route . '_slug' ] ?? '' ) );

        return '' !== $slug ? $slug : $defaults[ $route ];
    }
}

if ( ! function_exists( 'plugixa_recipe_permalink' ) ) {
    /**
     * Public URL of a single recipe, honouring the configured recipe slug.
     *
     * @since 1.0.0
     *
     * @param int    $recipe_id Recipe ID.
     * @param string $slug      Optional trailing title slug, purely cosmetic.
     * @return string
     */
    function plugixa_recipe_permalink( int $recipe_id, string $slug = '' ): string {
        $path = '/' . plugixa_recipe_url_slug( 'recipe' ) . '/' . $recipe_id . '/';

        if ( '' !== $slug ) {
            $path .= $slug . '/';
        }

        return home_url( $path );
    }
}

if ( ! function_exists( 'plugixa_recipe_archive_url' ) ) {
    /**
     * Public URL of a category or cuisine archive, honouring the configured slugs.
     *
     * @since 1.0.0
     *
     * @param string $type      'category' or 'cuisine'.
     * @param string $term_slug Optional term slug; omit for the archive base itself.
     * @return string
     */
    function plugixa_recipe_archive_url( string $type, string $term_slug = '' ): string {
        $path = '/' . plugixa_recipe_url_slug( 'cuisine' === $type ? 'cuisine' : 'category' ) . '/';

        if ( '' !== $term_slug ) {
            $path .= rawurlencode( $term_slug ) . '/';
        }

        return home_url( $path );
    }
}
