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

\PlugixaRecipe\Core\Security\Kses::register();

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
        return \PlugixaRecipe\Core\Security\Kses::allowed_html( $context );
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
        return \PlugixaRecipe\Core\Security\Kses::esc( $html, $context );
    }
}

if ( ! function_exists( 'plugixa_recipe_user_can' ) ) {
    /**
     * Whether the current user holds a plugin capability, or the WordPress capability
     * that granted the same access before the plugin capabilities were enforced.
     *
     * Single source of truth for the permission model. Both sides must agree: the REST
     * layer (PlugixaRecipe\Api\RestController::can) decides what the API accepts, and
     * the admin bootstrap (PlugixaRecipe\Core\Admin\Assets::get_user_permissions) decides
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

if ( ! function_exists( 'plugixa_recipe_rest_nonce' ) ) {
    /**
     * The REST nonce a PUBLIC page carries: one for a signed-in user, none for a visitor.
     *
     * A visitor needs no nonce on a public route - WordPress treats a cookie request
     * without one as signed out, which a visitor is. But a nonce that does not verify is
     * not ignored: rest_cookie_check_errors() answers 403 rest_cookie_invalid_nonce before
     * the route runs. A page cache keeps a page far longer than the 12 to 24 hours a nonce
     * lives, so every public page used to carry a request header that would start failing
     * the next day - on loading more reviews, voting one helpful, posting a review or
     * submitting a recipe. A page with no nonce in it has nothing that can go stale.
     *
     * The scripts send the header only when this is not empty. An EMPTY header or an empty
     * `_wpnonce` field is no better than a stale one: both are "a nonce that does not verify".
     *
     * Every frontend script config and form reads its nonce from here;
     * CachingTest fails on a frontend file that calls wp_create_nonce( 'wp_rest' ) itself.
     *
     * @since 1.2.0
     */
    function plugixa_recipe_rest_nonce(): string {
        return is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '';
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

if ( ! function_exists( 'plugixa_recipe_link_rel' ) ) {
    /**
     * The `rel` of a link an author attached to an ingredient, a tool or a source.
     *
     * A link to this site gets none and opens in place. A link elsewhere is not vouched
     * for by default (`nofollow`) - it is usually a shop - and opens in a new tab, which
     * is what `noopener` is for.
     *
     * @since 1.2.0
     *
     * @param string $url The link's address.
     * @return string Empty for a link to this site.
     */
    function plugixa_recipe_link_rel( string $url ): string {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        $own  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        if ( '' === $host || 0 === strcasecmp( $host, $own ) ) {
            return '';
        }

        /**
         * Filters the rel attribute of an outbound recipe link.
         *
         * @since 1.2.0
         *
         * @param string $rel Space-separated link types. Default 'nofollow noopener'.
         * @param string $url The link's address.
         */
        return trim( (string) apply_filters( 'plugixa_recipe_link_rel', 'nofollow noopener', $url ) );
    }
}

if ( ! function_exists( 'plugixa_recipe_current_url' ) ) {
    /**
     * URL of the page being served, for a "log in and come back" link.
     *
     * Not get_permalink(): a recipe or archive URL is a rewrite rule over the blog query,
     * so the "current post" there is the newest blog post.
     *
     * @since 1.2.0
     *
     * @return string
     */
    function plugixa_recipe_current_url(): string {
        global $wp;

        $path = isset( $wp->request ) ? (string) $wp->request : '';

        if ( '' === $path ) {
            // Front page, or plain permalinks, where the path carries nothing.
            return is_singular() ? (string) get_permalink() : home_url( '/' );
        }

        return home_url( user_trailingslashit( $path ) );
    }
}

if ( ! function_exists( 'plugixa_recipe_app_enabled' ) ) {
    /**
     * Is an App Manager module switched on?
     *
     * The one question templates and shortcodes ask before drawing a feature. It replaces
     * the old Settings > General `enable_*` switches, which were migrated into the
     * `plugixa_recipe_apps` option and then deleted (the App Manager's LegacyToggles).
     *
     * A module with no App Manager card (an add-on) is ON - it must not render as silently
     * missing. An id that names no installed module is OFF: `schema` in the free build is
     * not a switched-on feature, it is an absent one.
     *
     * @param string $id Module id, e.g. 'reviews', 'favorites', 'submissions'.
     */
    function plugixa_recipe_app_enabled( string $id ): bool {
        return \PlugixaRecipe\Core\Modules\ModuleRegistry::instance()->enabled( $id );
    }
}

if ( ! function_exists( 'plugixa_recipe_template' ) ) {
    /**
     * Absolute path of one of the plugin's own templates, by name.
     *
     * Templates live in the module that owns them (includes/Modules/<Name>/views/), and a
     * template that includes another - a layout drawing the recipe card - asks for it here
     * rather than by path. A function, not a class constant or a path, on purpose: a theme
     * may copy a template to override it, and a copy that says
     * `include plugixa_recipe_template( 'recipe-card.php' )` keeps working whichever module
     * the card belongs to next year.
     *
     * Returns '' for a name no module provides.
     *
     * @param string $name File name relative to a views/ directory, e.g. 'recipe-card.php'
     *                     or 'widgets/categories.php'.
     */
    function plugixa_recipe_template( string $name ): string {
        return \PlugixaRecipe\Core\Frontend\Templates::locate( $name );
    }
}
