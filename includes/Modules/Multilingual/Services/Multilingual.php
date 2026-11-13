<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Multilingual\Services;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpml_current_language / wpml_default_language / wpml_active_languages are filters DEFINED BY WPML. We are the consumer; they must keep WPML's names to resolve. Prefixing them would simply return nothing.

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual Class - WPML/Polylang abstraction layer (Singleton)
 *
 * Detects and wraps WPML or Polylang APIs for seamless multilingual support.
 */
class Multilingual {

    private static ?Multilingual $instance = null;

    /** @var string|null Detected plugin: 'wpml', 'polylang', or null */
    private ?string $detected_plugin = null;

    public static function instance(): Multilingual {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->detect_plugin();
    }

    /**
     * Detect which multilingual plugin is active.
     */
    private function detect_plugin(): void {
        if (defined('ICL_SITEPRESS_VERSION') && class_exists('SitePress')) {
            $this->detected_plugin = 'wpml';
        } elseif (function_exists('pll_current_language') || defined('POLYLANG_VERSION')) {
            $this->detected_plugin = 'polylang';
        }
    }

    /**
     * Check if a multilingual plugin is active.
     */
    public function is_active(): bool {
        return $this->detected_plugin !== null;
    }

    /**
     * Get the name of the detected multilingual plugin.
     */
    public function get_plugin_name(): ?string {
        if ($this->detected_plugin === 'wpml') {
            return 'WPML';
        }
        if ($this->detected_plugin === 'polylang') {
            return 'Polylang';
        }
        return null;
    }

    /**
     * Get the current language code.
     *
     * @return string Two-letter language code (e.g. 'en', 'fr'). Falls back to WP locale prefix.
     */
    public function get_current_language(): string {
        if ($this->detected_plugin === 'wpml') {
            $lang = apply_filters('wpml_current_language', null);
            if ($lang && $lang !== 'all') {
                return $lang;
            }
            return $this->get_default_language();
        }

        if ($this->detected_plugin === 'polylang' && function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if ($lang) {
                return $lang;
            }
        }

        return $this->get_wp_locale_prefix();
    }

    /**
     * Get the default (primary) language code.
     *
     * @return string Two-letter language code.
     */
    public function get_default_language(): string {
        if ($this->detected_plugin === 'wpml') {
            $default = apply_filters('wpml_default_language', null);
            if ($default) {
                return $default;
            }
        }

        if ($this->detected_plugin === 'polylang' && function_exists('pll_default_language')) {
            $default = pll_default_language('slug');
            if ($default) {
                return $default;
            }
        }

        return $this->get_wp_locale_prefix();
    }

    /**
     * Get all available languages.
     *
     * @return array Array of language arrays with keys: code, name, native_name, flag_url, is_default, is_current
     */
    public function get_languages(): array {
        if ($this->detected_plugin === 'wpml') {
            return $this->get_wpml_languages();
        }

        if ($this->detected_plugin === 'polylang') {
            return $this->get_polylang_languages();
        }

        // Fallback: return current WP locale as only language
        $locale = get_locale();
        $code = $this->get_wp_locale_prefix();

        return [
            [
                'code'        => $code,
                'name'        => $this->locale_to_name($locale),
                'native_name' => $this->locale_to_name($locale),
                'flag_url'    => '',
                'is_default'  => true,
                'is_current'  => true,
            ],
        ];
    }

    /**
     * Retrieve languages from WPML.
     */
    private function get_wpml_languages(): array {
        $languages = [];
        $wpml_languages = apply_filters('wpml_active_languages', null, [
            'skip_missing' => 0,
            'orderby'      => 'custom',
        ]);

        if (!is_array($wpml_languages)) {
            return $languages;
        }

        $current = $this->get_current_language();
        $default = $this->get_default_language();

        foreach ($wpml_languages as $lang) {
            $code = $lang['language_code'] ?? $lang['code'] ?? '';
            $languages[] = [
                'code'        => $code,
                'name'        => $lang['english_name'] ?? $lang['translated_name'] ?? $code,
                'native_name' => $lang['native_name'] ?? $lang['english_name'] ?? $code,
                'flag_url'    => $lang['country_flag_url'] ?? '',
                'is_default'  => $code === $default,
                'is_current'  => $code === $current,
            ];
        }

        return $languages;
    }

    /**
     * Retrieve languages from Polylang.
     */
    private function get_polylang_languages(): array {
        $languages = [];

        if (!function_exists('pll_languages_list')) {
            return $languages;
        }

        $slugs = pll_languages_list(['fields' => 'slug']);
        $names = pll_languages_list(['fields' => 'name']);
        $flags = [];

        // Polylang stores flag URLs differently depending on version
        if (function_exists('PLL')) {
            $pll_languages = PLL()->model->get_languages_list();
            foreach ($pll_languages as $pll_lang) {
                $flags[$pll_lang->slug] = $pll_lang->flag_url ?? '';
            }
        }

        $current = $this->get_current_language();
        $default = $this->get_default_language();

        foreach ($slugs as $i => $slug) {
            $languages[] = [
                'code'        => $slug,
                'name'        => $names[$i] ?? $slug,
                'native_name' => $names[$i] ?? $slug,
                'flag_url'    => $flags[$slug] ?? '',
                'is_default'  => $slug === $default,
                'is_current'  => $slug === $current,
            ];
        }

        return $languages;
    }

    /**
     * Extract two-letter language prefix from WP locale.
     */
    private function get_wp_locale_prefix(): string {
        $locale = get_locale();
        return substr($locale, 0, 2);
    }

    /**
     * Convert a WP locale string to a human-readable language name.
     */
    private function locale_to_name(string $locale): string {
        $map = [
            'en_US' => 'English',
            'en_GB' => 'English (UK)',
            'fr_FR' => 'French',
            'de_DE' => 'German',
            'es_ES' => 'Spanish',
            'it_IT' => 'Italian',
            'pt_BR' => 'Portuguese (Brazil)',
            'pt_PT' => 'Portuguese',
            'nl_NL' => 'Dutch',
            'ja'    => 'Japanese',
            'zh_CN' => 'Chinese (Simplified)',
            'ko_KR' => 'Korean',
            'ar'    => 'Arabic',
            'ru_RU' => 'Russian',
            'tr_TR' => 'Turkish',
        ];

        return $map[$locale] ?? ucfirst(substr($locale, 0, 2));
    }
}
