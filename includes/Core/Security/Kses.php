<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Central allowed-HTML definitions for late-escaping frontend output.
 *
 * Every fragment the plugin hands to `echo` as a whole - the rendered shortcode
 * output, an Elementor widget's body, a WooCommerce product tab - passes through
 * wp_kses() with the allowlist below at output time. The list is the union of what
 * post content may contain ('content' context) and the vocabulary the plugin's own
 * templates emit: recipe cards, the servings adjuster, the nutrition label, the
 * review form, inline SVG icons, and the schema.org Recipe microdata attributes.
 *
 * Deliberately absent: <script> and <style>. Scripts go through
 * wp_print_inline_script_tag() or an enqueue; per-instance styling travels as
 * `--pxr-*` CSS custom properties in a style attribute, which allow_custom_property_css()
 * below keeps alive through safecss_filter_attr().
 */
final class Kses {

    /**
     * Allowed-HTML map for a rendering context.
     *
     * Contexts:
     * - 'content'  - user-authored recipe copy (description, notes, tips, review text).
     *                wp_kses_allowed_html('post') extended via the
     *                plugixa_recipe_content_allowed_html filter.
     * - 'frontend' - a complete plugin-rendered fragment. Superset of 'content'
     *                (fragments embed that copy) plus the plugin UI vocabulary.
     *
     * @param string $context 'frontend' or 'content'.
     * @return array<string,array<string,bool>> wp_kses() allowlist.
     */
    public static function allowed_html( string $context = 'frontend' ): array {
        /**
         * Filter the allowed HTML for user-authored recipe copy.
         *
         * @param array<string,array<string,bool>> $allowed wp_kses() allowlist.
         */
        $content = apply_filters( 'plugixa_recipe_content_allowed_html', wp_kses_allowed_html( 'post' ) );

        if ( 'content' === $context ) {
            return $content;
        }

        $allowed = $content;

        // Interactive / structural elements the plugin UI emits.
        $allowed['details']    = [ 'open' => true ];
        $allowed['summary']    = [];
        $allowed['nav']        = [];
        $allowed['button']     = [ 'type' => true, 'name' => true, 'value' => true, 'disabled' => true ];
        $allowed['form']       = array_merge( $allowed['form'] ?? [], [ 'action' => true, 'method' => true, 'enctype' => true, 'novalidate' => true, 'target' => true ] );
        $allowed['input']      = array_merge( $allowed['input'] ?? [], [
            'type' => true, 'name' => true, 'value' => true, 'placeholder' => true,
            'required' => true, 'maxlength' => true, 'minlength' => true, 'autocomplete' => true,
            'min' => true, 'max' => true, 'step' => true, 'checked' => true, 'pattern' => true,
            'disabled' => true, 'readonly' => true, 'multiple' => true, 'accept' => true,
        ] );
        $allowed['textarea']   = [ 'name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'maxlength' => true, 'required' => true, 'disabled' => true, 'readonly' => true ];
        $allowed['select']     = array_merge( $allowed['select'] ?? [], [ 'name' => true, 'multiple' => true, 'disabled' => true, 'required' => true ] );
        $allowed['option']     = array_merge( $allowed['option'] ?? [], [ 'value' => true, 'selected' => true, 'disabled' => true ] );
        $allowed['optgroup']   = [ 'label' => true ];
        $allowed['label']      = array_merge( $allowed['label'] ?? [], [ 'for' => true ] );
        $allowed['fieldset']   = [ 'disabled' => true ];
        $allowed['legend']     = [];
        $allowed['figure']     = $allowed['figure'] ?? [];
        $allowed['figcaption'] = $allowed['figcaption'] ?? [];
        $allowed['time']       = [ 'datetime' => true ];
        $allowed['mark']       = [];
        $allowed['meta']       = [ 'content' => true, 'name' => true, 'property' => true ];
        $allowed['template']   = [];

        // oEmbed players. The plugin embeds a recipe video through WordPress's own
        // oEmbed, which returns an <iframe> (YouTube / Vimeo).
        $allowed['iframe'] = [
            'src' => true, 'width' => true, 'height' => true, 'title' => true,
            'frameborder' => true, 'allow' => true, 'allowfullscreen' => true,
            'referrerpolicy' => true, 'loading' => true, 'name' => true, 'sandbox' => true,
        ];
        $allowed['video']  = [ 'src' => true, 'controls' => true, 'poster' => true, 'preload' => true, 'width' => true, 'height' => true, 'playsinline' => true, 'muted' => true, 'loop' => true, 'autoplay' => true ];
        $allowed['source'] = [ 'src' => true, 'type' => true, 'srcset' => true, 'sizes' => true, 'media' => true ];
        $allowed['picture'] = [];

        // Inline SVG icons. Note wp_kses lowercases attribute names, so viewBox must be
        // listed as 'viewbox'.
        $svg_paint = [
            'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true,
            'stroke-linejoin' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
            'fill-rule' => true, 'clip-rule' => true, 'opacity' => true, 'transform' => true,
        ];
        $allowed['svg']      = array_merge( $svg_paint, [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'focusable' => true, 'preserveaspectratio' => true ] );
        $allowed['path']     = array_merge( $svg_paint, [ 'd' => true ] );
        $allowed['circle']   = array_merge( $svg_paint, [ 'cx' => true, 'cy' => true, 'r' => true ] );
        $allowed['ellipse']  = array_merge( $svg_paint, [ 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ] );
        $allowed['line']     = array_merge( $svg_paint, [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ] );
        $allowed['rect']     = array_merge( $svg_paint, [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ] );
        $allowed['polyline'] = array_merge( $svg_paint, [ 'points' => true ] );
        $allowed['polygon']  = array_merge( $svg_paint, [ 'points' => true ] );
        $allowed['g']        = $svg_paint;
        $allowed['defs']     = [];
        $allowed['use']      = array_merge( $svg_paint, [ 'href' => true, 'xlink:href' => true ] );
        $allowed['title']    = [];
        $allowed['desc']     = [];

        // Link/image extras beyond the 'post' defaults.
        $allowed['a']   = array_merge( $allowed['a'] ?? [], [ 'target' => true, 'rel' => true, 'download' => true ] );
        $allowed['img'] = array_merge( $allowed['img'] ?? [], [ 'loading' => true, 'decoding' => true, 'srcset' => true, 'sizes' => true, 'fetchpriority' => true ] );

        // Global attributes applied to every allowed element.
        //
        // wp_kses supports exactly ONE attribute wildcard: data-*. Every aria-* attribute
        // must be named, or it is silently stripped - which would quietly destroy the
        // accessibility of the rendered output while the markup still looked fine.
        //
        // itemprop / itemscope / itemtype carry the schema.org Recipe microdata that the
        // free build emits (the JSON-LD is premium); dropping them would remove the
        // plugin's structured data from every page.
        $global_attrs = [
            'id' => true, 'class' => true, 'style' => true, 'role' => true,
            'title' => true, 'tabindex' => true, 'hidden' => true, 'lang' => true, 'dir' => true,
            'data-*' => true,
            'itemprop' => true, 'itemscope' => true, 'itemtype' => true, 'itemid' => true, 'content' => true,
            'aria-label' => true, 'aria-labelledby' => true, 'aria-describedby' => true,
            'aria-hidden' => true, 'aria-expanded' => true, 'aria-controls' => true,
            'aria-current' => true, 'aria-live' => true, 'aria-atomic' => true,
            'aria-pressed' => true, 'aria-selected' => true, 'aria-disabled' => true,
            'aria-haspopup' => true, 'aria-modal' => true, 'aria-valuenow' => true,
            'aria-valuemin' => true, 'aria-valuemax' => true, 'aria-valuetext' => true,
            'aria-required' => true, 'aria-invalid' => true, 'aria-checked' => true,
            'aria-orientation' => true, 'aria-roledescription' => true,
        ];
        foreach ( $allowed as $tag => $attrs ) {
            $allowed[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : [], $global_attrs );
        }

        /**
         * Filter the frontend allowed-HTML vocabulary.
         *
         * @param array<string,array<string,bool>> $allowed wp_kses() allowlist.
         * @param string                           $context Rendering context.
         */
        return apply_filters( 'plugixa_recipe_allowed_html', $allowed, $context );
    }

    /**
     * Permit the plugin's CSS custom properties inside kses'd style attributes.
     *
     * wp_kses runs every style attribute through safecss_filter_attr(), whose handling of
     * custom-property declarations and var() values differs across WordPress versions.
     * Per-view theming travels entirely as --pxr-* properties, so without this a kses'd
     * fragment would render with the default palette instead of the configured one.
     *
     * @param bool   $allow           Core's verdict for this declaration.
     * @param string $css_test_string The single CSS declaration under test.
     * @return bool
     */
    public static function allow_custom_property_css( $allow, $css_test_string ) {
        if ( $allow ) {
            return $allow;
        }

        // "--pxr-card-bg:#fff" (custom property declaration) or
        // "background-color:var(--pxr-card-bg)" (custom property used as a value).
        if ( preg_match( '/^--[a-zA-Z0-9_-]+\s*:/', (string) $css_test_string )
            || preg_match( '/^[a-zA-Z-]+\s*:\s*[^;]*var\(--[a-zA-Z0-9_-]+/', (string) $css_test_string ) ) {
            return true;
        }

        return $allow;
    }

    /**
     * Hook the CSS custom-property allowance. Idempotent; called from helpers.php.
     */
    public static function register(): void {
        if ( ! has_filter( 'safecss_filter_attr_allow_css', [ self::class, 'allow_custom_property_css' ] ) ) {
            add_filter( 'safecss_filter_attr_allow_css', [ self::class, 'allow_custom_property_css' ], 10, 2 );
        }
    }

    /**
     * Late-escape a plugin-rendered HTML fragment (wp_kses shorthand).
     *
     * NOTE for call sites: WPCS only recognises a DIRECT wp_kses() call as escaping. Use
     * `echo wp_kses( $html, plugixa_recipe_allowed_html() );` at an echo; this helper is
     * for building a value that is echoed elsewhere.
     *
     * @param string $html    Fragment to escape.
     * @param string $context Allowlist context (see allowed_html()).
     * @return string Escaped fragment, safe to echo.
     */
    public static function esc( string $html, string $context = 'frontend' ): string {
        if ( '' === $html ) {
            return '';
        }

        return wp_kses( $html, self::allowed_html( $context ) );
    }
}
