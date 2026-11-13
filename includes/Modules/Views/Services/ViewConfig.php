<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Views\Services;

use PlugixaRecipe\Modules\Views\Repositories\ViewReadRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A saved view, read and normalised for rendering.
 *
 * A view is the design half of a listing - layout, colours, typography, what a card shows.
 * Stored configs span every version of the editor, so normalize_view_config() fills in
 * whatever an older one lacks; renderers can then read any key without checking for it.
 */
final class ViewConfig {

    private ViewReadRepository $views;

    public function __construct() {
        $this->views = new ViewReadRepository();
    }

    /**
     * Load view configuration from the database.
     */
    public function get_view_config($view_identifier): array {
        $row = null;

        if (!empty($view_identifier)) {
            if (is_numeric($view_identifier)) {
                $row = $this->views->config_by_id((int) $view_identifier);
            } else {
                $row = $this->views->config_by_slug(sanitize_text_field($view_identifier));
            }
        }

        // Fall back to default view
        if (!$row) {
            $row = $this->views->default_config();
        }

        // Fall back to first view
        if (!$row) {
            $row = $this->views->first_config();
        }

        if ($row && !empty($row->config)) {
            $config = json_decode($row->config, true);
            return is_array($config) ? $this->normalize_view_config($config) : $this->normalize_view_config([]);
        }

        return $this->normalize_view_config([]);
    }

    /**
     * The config a renderer would read for a stored (or about-to-be-stored) config.
     *
     * For ViewService, which checks a View's style values before it saves them: they have
     * to be judged in the shape the CSS builder reads, and that shape is made here (a flat
     * `card_bg_color` only becomes `colors.cardBg` below).
     *
     * @param array $config Raw config.
     */
    public function normalize(array $config): array {
        return $this->normalize_view_config($config);
    }

    /**
     * Normalize view config to handle both nested (seed) and flat (admin) schemas.
     *
     * Seed data uses nested keys: layout.style, colors.cardBg, typography.titleFontSize, etc.
     * Admin form saves flat keys: layout (string), card_bg_color, title_font_size, etc.
     *
     * This method produces a unified config with:
     * - Nested keys for build_css_vars() (colors, typography, spacing, borders, shadows, image, layout)
     * - Flat keys for templates (show_image, autoplay, slides_per_view_desktop, etc.)
     *
     * @param array $config Raw config from DB.
     * @return array Normalized config with both nested and flat keys.
     */
    private function normalize_view_config(array $config): array {
        $layout_raw = $config['layout'] ?? 'grid';

        // --- 1. Resolve layout style and columns ---
        if (is_array($layout_raw)) {
            // Schema A (nested): { style: "slider", columns: { desktop: 3, ... } }
            $style = $layout_raw['style'] ?? 'grid';
            $columns_desktop = $layout_raw['columns']['desktop'] ?? $layout_raw['slidesPerView']['desktop'] ?? 3;
            $columns_tablet = $layout_raw['columns']['tablet'] ?? $layout_raw['slidesPerView']['tablet'] ?? min((int) $columns_desktop, 2);
            $columns_mobile = $layout_raw['columns']['mobile'] ?? $layout_raw['slidesPerView']['mobile'] ?? 1;
        } else {
            // Schema B (flat): layout is a string
            $style = is_string($layout_raw) ? $layout_raw : 'grid';
            $columns_desktop = (int) ($config['columns'] ?? 3);
            $columns_tablet = min($columns_desktop, 2);
            $columns_mobile = 1;
        }

        // Ensure layout is always nested
        $config['layout'] = [
            'style' => $style,
            'columns' => [
                'desktop' => (int) $columns_desktop,
                'tablet'  => (int) $columns_tablet,
                'mobile'  => (int) $columns_mobile,
            ],
        ];

        // --- 2. Build nested sections from flat admin keys (if not already nested) ---

        // Colors
        if (!isset($config['colors']) || !is_array($config['colors'])) {
            $config['colors'] = [];
        }
        $color_flat_map = [
            'card_bg_color'     => 'cardBg',
            'title_color'       => 'titleColor',
            'meta_color'        => 'metaColor',
            'card_border_color' => 'borderColor',
            'text_color'        => 'textColor',
            'star_color'        => 'starColor',
            'star_empty_color'  => 'starEmptyColor',
            'badge_bg'          => 'badgeBg',
            'badge_text'        => 'badgeText',
        ];
        foreach ($color_flat_map as $flat => $nested) {
            if (!empty($config[$flat]) && empty($config['colors'][$nested])) {
                $config['colors'][$nested] = $config[$flat];
            }
        }

        // Typography
        if (!isset($config['typography']) || !is_array($config['typography'])) {
            $config['typography'] = [];
        }
        if (!empty($config['title_font_size']) && !isset($config['typography']['titleFontSize'])) {
            $config['typography']['titleFontSize'] = (int) $config['title_font_size'];
        }
        if (!empty($config['meta_font_size']) && !isset($config['typography']['metaFontSize'])) {
            $config['typography']['metaFontSize'] = (int) $config['meta_font_size'];
        }
        if (!empty($config['title_font_family']) && empty($config['typography']['fontFamily'])) {
            $config['typography']['fontFamily'] = $config['title_font_family'];
        }

        // Spacing
        if (!isset($config['spacing']) || !is_array($config['spacing'])) {
            $config['spacing'] = [];
        }
        if (isset($config['gap']) && !isset($config['spacing']['gap'])) {
            $config['spacing']['gap'] = (int) $config['gap'];
        }

        // Borders
        if (!isset($config['borders']) || !is_array($config['borders'])) {
            $config['borders'] = [];
        }
        if (!empty($config['card_border_radius']) && !isset($config['borders']['radius'])) {
            $config['borders']['radius'] = (int) $config['card_border_radius'];
        }

        // Shadows
        if (!isset($config['shadows']) || !is_array($config['shadows'])) {
            $config['shadows'] = [];
        }
        if (!empty($config['card_shadow']) && empty($config['shadows']['value'])) {
            $config['shadows']['value'] = $config['card_shadow'];
            $config['shadows']['enabled'] = true;
        }

        // Image
        if (!isset($config['image']) || !is_array($config['image'])) {
            $config['image'] = [];
        }
        if (!empty($config['image_aspect_ratio']) && empty($config['image']['aspectRatio'])) {
            // Convert "4:3" to "4/3"
            $config['image']['aspectRatio'] = str_replace(':', '/', $config['image_aspect_ratio']);
        }

        // Video
        if (!isset($config['video']) || !is_array($config['video'])) {
            $config['video'] = [];
        }

        // --- 3. Build flat template keys from nested config (for templates that read flat) ---

        // Slider template reads flat keys from $view_config
        if ($style === 'slider') {
            $slider_nested = is_array($layout_raw) ? $layout_raw : [];

            // Admin form keys → template flat keys
            $config['autoplay']               = $config['autoplay'] ?? (bool) ($config['slider_autoplay'] ?? $slider_nested['autoplay'] ?? false);
            $config['autoplay_delay']          = $config['autoplay_delay'] ?? (int) ($config['slider_autoplay_speed'] ?? $slider_nested['autoplaySpeed'] ?? $slider_nested['autoplayDelay'] ?? 5000);
            $config['loop']                    = $config['loop'] ?? (bool) ($config['slider_loop'] ?? $slider_nested['loop'] ?? true);
            $config['show_arrows']             = $config['show_arrows'] ?? (bool) ($config['slider_show_arrows'] ?? $slider_nested['showArrows'] ?? true);
            $config['show_dots']               = $config['show_dots'] ?? (bool) ($config['slider_show_dots'] ?? $slider_nested['showDots'] ?? true);
            $config['speed']                   = $config['speed'] ?? (int) ($config['slider_speed'] ?? $slider_nested['speed'] ?? 400);
            $config['pause_on_hover']          = $config['pause_on_hover'] ?? (bool) ($config['slider_pause_on_hover'] ?? $slider_nested['pauseOnHover'] ?? true);
            $config['space_between']           = $config['space_between'] ?? (int) ($config['slider_space_between'] ?? $slider_nested['spaceBetween'] ?? $config['gap'] ?? 24);

            // Swiper 12 module settings
            $config['effect']           = $config['effect'] ?? ($config['slider_effect'] ?? $slider_nested['effect'] ?? 'slide');
            $config['pagination_style'] = $config['pagination_style'] ?? ($config['slider_pagination_type'] ?? $slider_nested['paginationType'] ?? 'bullets');
            $config['keyboard']         = (bool) ($config['keyboard'] ?? $config['slider_keyboard'] ?? $slider_nested['keyboard'] ?? true);
            $config['free_mode']        = (bool) ($config['free_mode'] ?? $config['slider_free_mode'] ?? $slider_nested['freeMode'] ?? false);
            $config['lazy']             = (bool) ($config['lazy'] ?? $config['slider_lazy'] ?? $slider_nested['lazy'] ?? true);

            $slides_to_show = (int) ($config['slider_slides_to_show'] ?? $slider_nested['slidesPerView']['desktop'] ?? $columns_desktop);
            $config['slides_per_view_desktop'] = $config['slides_per_view_desktop'] ?? $slides_to_show;
            $config['slides_per_view_tablet']  = $config['slides_per_view_tablet'] ?? (int) ($config['slider_slides_tablet'] ?? $slider_nested['slidesPerView']['tablet'] ?? min($slides_to_show, 2));
            $config['slides_per_view_mobile']  = $config['slides_per_view_mobile'] ?? (int) ($config['slider_slides_mobile'] ?? $slider_nested['slidesPerView']['mobile'] ?? 1);

            // Also set as slidesPerView in layout for CSS vars
            $config['layout']['slidesPerView'] = [
                'desktop' => $config['slides_per_view_desktop'],
                'tablet'  => $config['slides_per_view_tablet'],
                'mobile'  => $config['slides_per_view_mobile'],
            ];
        }

        // Masonry/grid/list column_gap
        if (!isset($config['column_gap'])) {
            $config['column_gap'] = $config['spacing']['gap'] ?? (int) ($config['gap'] ?? 24);
        }

        // Display toggle keys for recipe-card.php (map admin keys if not already set)
        if (isset($config['show_diet_badges']) && !isset($config['show_diet'])) {
            $config['show_diet'] = (bool) $config['show_diet_badges'];
        }
        if (isset($config['show_category']) && !isset($config['show_category_nav'])) {
            $config['show_category_nav'] = (bool) $config['show_category'];
        }
        if (isset($config['show_cuisine_nav'])) {
            $config['show_cuisine_nav'] = (bool) $config['show_cuisine_nav'];
        }
        if (isset($config['show_alpha_filter'])) {
            $config['show_alpha_filter'] = (bool) $config['show_alpha_filter'];
        }
        if (isset($config['show_ingredient_filter'])) {
            $config['show_ingredient_filter'] = (bool) $config['show_ingredient_filter'];
        }
        // Dark mode — handle nested darkMode section and legacy boolean/string
        if (isset($config['darkMode']) && is_array($config['darkMode'])) {
            $config['dark_mode'] = !empty($config['darkMode']['enabled']);
        } elseif (isset($config['dark_mode'])) {
            $config['dark_mode'] = ($this->resolve_dark_mode_state($config['dark_mode']) === 'enabled');
        }

        // Ensure darkMode section exists for CSS vars
        if (!isset($config['darkMode']) || !is_array($config['darkMode'])) {
            $config['darkMode'] = ['enabled' => !empty($config['dark_mode'])];
        }

        // Ensure darkMode.pagination defaults exist for dark color swapping
        if (!isset($config['darkMode']['pagination']) || !is_array($config['darkMode']['pagination'])) {
            $config['darkMode']['pagination'] = [
                'numbers' => [
                    'bg' => '#1e1e2e', 'text' => '#e5e7eb', 'border' => '#374151',
                    'activeBg' => '#3b82f6', 'activeText' => '#ffffff', 'activeBorder' => '#3b82f6',
                ],
                'loadMore' => [
                    'bg' => '#3b82f6', 'text' => '#ffffff', 'border' => '#3b82f6',
                    'hoverBg' => '#2563eb', 'hoverText' => '#ffffff', 'hoverBorder' => '#2563eb',
                ],
                'infiniteScroll' => ['spinnerColor' => '#3b82f6', 'textColor' => '#9ca3af'],
            ];
        }

        // Ensure categories section exists
        if (!isset($config['categories']) || !is_array($config['categories'])) {
            $config['categories'] = ['displayStyle' => 'none'];
        }

        // Ensure pagination section exists
        if (!isset($config['pagination']) || !is_array($config['pagination'])) {
            $config['pagination'] = ['enabled' => true, 'style' => 'numbers', 'perPage' => 12];
        }

        // Map nested display section to flat keys (supports both old and new schemas)
        $display = $config['display'] ?? [];
        if (!empty($display)) {
            $display_map = [
                'showImage'          => 'show_image',
                'showTitle'          => 'show_title',
                'showExcerpt'        => 'show_excerpt',
                'showMeta'           => 'show_meta',
                'showRating'         => 'show_rating',
                'showDietBadges'     => 'show_diet_badges',
                'showDifficulty'     => 'show_difficulty',
                'showTime'           => 'show_time',
                'showServings'       => 'show_servings',
                'showAuthor'         => 'show_author',
                'showCategory'       => 'show_category',
                'showCuisine'        => 'show_cuisine',
                'showFavoriteButton' => 'show_favorite_button',
                'showPrintButton'    => 'show_print_button',
            ];
            foreach ($display_map as $nested_key => $flat_key) {
                if (isset($display[$nested_key]) && !isset($config[$flat_key])) {
                    $config[$flat_key] = (bool) $display[$nested_key];
                }
            }
        }

        // Map nested filterNav section to flat keys
        $filter_nav = $config['filterNav'] ?? [];
        if (!empty($filter_nav)) {
            $filter_nav_map = [
                'showCategoryNav'   => 'show_category_nav',
                'showCuisineNav'    => 'show_cuisine_nav',
                'showAlphaFilter'   => 'show_alpha_filter',
                'showIngredientFilter' => 'show_ingredient_filter',
            ];
            foreach ($filter_nav_map as $nested_key => $flat_key) {
                if (isset($filter_nav[$nested_key]) && !isset($config[$flat_key])) {
                    $config[$flat_key] = (bool) $filter_nav[$nested_key];
                }
            }
        }

        return $config;
    }

    /**
     * Resolve dark mode state from various input formats.
     *
     * Handles tri-state strings ('enabled'/'disabled'/'auto'),
     * booleans, and PHP filter_var-compatible strings ('true'/'1'/'on').
     *
     * @param mixed $value The dark mode setting value.
     * @return string 'enabled', 'auto', or 'disabled'
     */
    public function resolve_dark_mode_state($value): string {
        if ($value === true || $value === 'enabled' || $value === 1 || $value === '1') {
            return 'enabled';
        }
        if ($value === 'auto') {
            return 'auto';
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'on', 'yes'], true)) {
            return 'enabled';
        }
        return 'disabled';
    }
}
