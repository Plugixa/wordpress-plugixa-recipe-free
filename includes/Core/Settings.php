<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core;

defined('ABSPATH') || exit;

/**
 * The plugin's settings option: its defaults, and the one-time repairs it has needed.
 *
 * get_default_settings() is more than a list of defaults. It is the ALLOW-LIST the settings
 * REST controller intersects a payload against, so a key that is not in it can never be
 * written - which is how the removed custom CSS/JS fields stay removed.
 */
final class Settings {

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
            'default_language'      => 'en',

            // Display
            'recipe_slug'           => 'recipe',
            'category_slug'         => 'recipe-category',
            'cuisine_slug'          => 'recipe-cuisine',

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
            'default_seo_title'         => '',
            'default_seo_description'   => '',
            'enable_open_graph'         => true,
            'enable_twitter_cards'      => true,
            // Read by Recipes\Frontend\MetaTags. It was on the SEO screen without being
            // declared here, so the save allow-list dropped it and the switch never stuck.
            'noindex_draft_recipes'     => true,

            // Performance
            'enable_lazy_load'          => true,
            'image_optimization'        => false,
            'thumbnail_quality'         => 82,

            // API
            'api_rate_limit'            => 100,

            // Advanced
            'delete_data_on_uninstall'  => false,
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

    /**
     * Create the settings option, or add any default key an existing one lacks.
     *
     * Only ever ADDS. A key is removed from a stored option solely by code written to remove
     * it - see purge_custom_code_settings() and the App Manager's LegacyToggles.
     */
    public static function ensure_defaults(): void {
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
    public static function migrate_cuisine_slug(): void {
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
    public static function purge_custom_code_settings(): void {
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

    /**
     * Settings that were on the Settings screen, saved, and read by nothing.
     *
     * Each one drew a control that changed no behaviour: a "plugin name" no screen showed, a
     * default layout no listing used, a breadcrumb switch with no breadcrumbs, a sitemap
     * switch with no sitemap, a cache switch over a cache that is always on, three REST / API
     * switches over routes that are always registered, and a debug switch that logged nothing.
     * They are gone from get_default_settings(), so nothing can write them again.
     *
     * @var string[]
     */
    private const UNREAD_SETTINGS = [
        'plugin_name', 'default_layout', 'show_breadcrumbs', 'enable_sitemap', 'enable_cache',
        'cache_duration', 'enable_rest_api', 'enable_public_api', 'public_api_key', 'enable_debug',
    ];

    /**
     * One-off removal of the stored values of UNREAD_SETTINGS.
     *
     * Same reason as purge_custom_code_settings(): dropping a key from the defaults stops
     * new writes, but the save path merges onto the stored row and never removes anything,
     * so the old values would stay in the option for ever. public_api_key in particular
     * looked like a credential and was not one - better gone than left to be trusted.
     */
    public static function purge_unread_settings(): void {
        if ( get_option( 'plugixa_recipe_unread_settings_purged' ) ) {
            return;
        }

        $settings = get_option( 'plugixa_recipe_settings' );

        // Nothing stored yet means a fresh install, which never had the keys.
        if ( is_array( $settings ) && array_intersect_key( $settings, array_flip( self::UNREAD_SETTINGS ) ) !== [] ) {
            update_option( 'plugixa_recipe_settings', array_diff_key( $settings, array_flip( self::UNREAD_SETTINGS ) ) );
        }

        update_option( 'plugixa_recipe_unread_settings_purged', '1' );
    }

    /**
     * Settings > General feature switches that became App Manager modules.
     * setting key => module id.
     */
    private const FEATURE_TOGGLES = [
        'enable_reviews'        => 'reviews',
        'enable_favorites'      => 'favorites',
        'enable_collections'    => 'collections',
        'enable_shopping_lists' => 'shopping_lists',
        'enable_nutrition'      => 'nutrition',
        'enable_submissions'    => 'submissions',
    ];

    /**
     * Switches that were read by nothing (`enable_seo` never reached any code path;
     * `enable_unit_conversion` gated no UI), so they have no module to become.
     */
    private const DEAD_TOGGLES = [ 'enable_seo', 'enable_unit_conversion' ];
}
