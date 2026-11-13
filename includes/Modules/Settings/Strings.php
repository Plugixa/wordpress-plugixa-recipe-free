<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Settings;

defined('ABSPATH') || exit;

/**
 * This module's admin-app strings, keyed the way the React app asks for them.
 *
 * Merged into the page config by ModuleRegistry::strings() - for every module, switched on
 * or not, because a disabled module's name still appears on its App Manager card.
 *
 * The rail labels `reviews` and `seo` are not here: the Reviews module and a premium module
 * already own those keys, and a key has exactly one owner (StringsTest). The React app
 * falls back to its own English text for a key no build declares.
 */
final class Strings {

    /** @return array<string, string> i18n key => translated text */
    public static function all(): array {
        return [
            'settings'             => __('Settings', 'plugixa-recipe'),
            'saving'               => __('Saving…', 'plugixa-recipe'),
            'settings_saved'       => __('Settings saved', 'plugixa-recipe'),
            'settings_save_failed' => __('Failed to save settings', 'plugixa-recipe'),
            'unsaved_changes'      => __('You have unsaved changes.', 'plugixa-recipe'),

            // The rail.
            'general'     => __('General', 'plugixa-recipe'),
            'display'     => __('Display', 'plugixa-recipe'),
            'performance' => __('Performance', 'plugixa-recipe'),
            'api'         => __('API', 'plugixa-recipe'),
            'captcha'     => __('Captcha', 'plugixa-recipe'),
            'form'        => __('Submission form', 'plugixa-recipe'),
            'advanced'    => __('Advanced', 'plugixa-recipe'),

            // Section headings and the line under each.
            'settings_general'          => __('General', 'plugixa-recipe'),
            'settings_general_hint'     => __('Basic plugin configuration.', 'plugixa-recipe'),
            'settings_display'          => __('Display', 'plugixa-recipe'),
            'settings_display_hint'     => __('How recipes appear on your site.', 'plugixa-recipe'),
            'settings_reviews'          => __('Reviews', 'plugixa-recipe'),
            'settings_reviews_hint'     => __('How recipe reviews are collected and shown.', 'plugixa-recipe'),
            'settings_seo'              => __('SEO', 'plugixa-recipe'),
            'settings_seo_hint'         => __('Meta tags and social cards.', 'plugixa-recipe'),
            'settings_performance'      => __('Performance', 'plugixa-recipe'),
            'settings_performance_hint' => __('Loading speed and caching.', 'plugixa-recipe'),
            'settings_api'              => __('API', 'plugixa-recipe'),
            'settings_api_hint'         => __('REST API access and rate limiting.', 'plugixa-recipe'),
            'settings_captcha'          => __('Captcha', 'plugixa-recipe'),
            'settings_captcha_hint'     => __('Cloudflare Turnstile credentials.', 'plugixa-recipe'),
            'settings_form'             => __('Submission form', 'plugixa-recipe'),
            'settings_form_hint'        => __('The frontend recipe submission form.', 'plugixa-recipe'),
            'settings_advanced'         => __('Advanced', 'plugixa-recipe'),
            'settings_advanced_hint'    => __('Debug mode and what uninstalling removes.', 'plugixa-recipe'),

            // A section whose app is switched off.
            'settings_app_off'      => __('This app is switched off in Apps.', 'plugixa-recipe'),
            'settings_app_off_hint' => __('Whether it runs at all is decided there. These options only apply while it is on, and nothing here has been lost.', 'plugixa-recipe'),
            'open_apps'             => __('Open Apps', 'plugixa-recipe'),
        ];
    }
}
