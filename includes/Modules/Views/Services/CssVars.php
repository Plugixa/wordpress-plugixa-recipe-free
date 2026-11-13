<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Views\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a view config into the --pxr-* custom properties a listing wrapper carries inline.
 *
 * Every value is VALIDATED AS CSS before it is written, by the type of the property it
 * feeds. The string this returns is printed inside a style attribute through esc_attr(),
 * and esc_attr() makes a value safe for HTML, not for CSS: it leaves `;`, `:`, `(` and `)`
 * alone, so a colour saved as `#fff;position:fixed;inset:0` arrived on the page as three
 * declarations. A View is saved by any edit-level user, so that was a Contributor writing
 * arbitrary CSS, and an outbound background URL, onto every page that lists recipes.
 *
 * So nothing reaches the string except through put(), and put() takes only what one of the
 * validators below returned. A value that is not what its property expects is left out, and
 * the stylesheet's own default applies. ViewService asks rejected_values() before it stores
 * a View, so the editor is told instead of the value being dropped quietly; the check here
 * stays, because a row can also arrive from an import, an add-on or an older version.
 *
 * When adding a property: pick the validator for its type. CssVarsTest poisons every leaf of
 * the default config and fails if a single fragment reaches the output.
 */
final class CssVars {

    /** Keywords `object-fit` accepts. */
    private const OBJECT_FIT = ['cover', 'contain', 'fill', 'none', 'scale-down'];

    /**
     * Build inline CSS custom property declarations from view config.
     *
     * @param array $view_config View configuration array.
     * @param array $config      Shortcode configuration overrides.
     * @return string CSS custom properties string (for style attribute).
     */
    public function build_css_vars(array $view_config, array $config = [], string $dark_mode_state = 'disabled'): string {
        $rejected = [];
        $css      = '';

        foreach ($this->collect($view_config, $dark_mode_state, $rejected) as $prop => $value) {
            $css .= $prop . ':' . $value . ';';
        }

        return $css;
    }

    /**
     * The config paths whose value is not valid CSS for the property it feeds, in either
     * palette. Empty for a config the builder would print in full.
     *
     * @param array $view_config View configuration array.
     * @return string[] Paths such as `colors.cardBg` or `darkMode.pagination.numbers.bg`.
     */
    public function rejected_values(array $view_config): array {
        $rejected = [];

        // Two passes: the dark palette is only read when dark mode is on, and it replaces
        // the light values it overrides.
        $this->collect($view_config, 'disabled', $rejected);
        $this->collect($view_config, 'enabled', $rejected);

        return array_values(array_unique($rejected));
    }

    /**
     * @param array    $view_config
     * @param string[] $rejected Paths of the values that were left out, appended to.
     * @return array<string, string> Property => validated value.
     */
    private function collect(array $view_config, string $dark_mode_state, array &$rejected): array {
        $vars = [];
        $dark = 'enabled' === $dark_mode_state;

        // Colors — swap to dark mode palette when enabled
        $colors      = $this->branch($view_config, 'colors');
        $dark_colors = $this->branch($view_config, 'darkMode');
        $color_from  = [];

        if ($dark && !empty($dark_colors)) {
            $dark_keys = ['cardBg', 'textColor', 'titleColor', 'metaColor', 'starColor', 'starEmptyColor', 'borderColor', 'badgeBg', 'badgeText'];
            foreach ($dark_keys as $dk) {
                if (!empty($dark_colors[$dk])) {
                    $colors[$dk]     = $dark_colors[$dk];
                    $color_from[$dk] = 'darkMode.' . $dk;
                }
            }
            if (!empty($dark_colors['containerBg'])) {
                $this->put($vars, $rejected, '--pxr-container-bg', 'darkMode.containerBg', $this->color($dark_colors['containerBg']));
            }
        }

        $color_map = [
            'cardBg'         => '--pxr-card-bg',
            'textColor'      => '--pxr-text-color',
            'titleColor'      => '--pxr-title-color',
            'titleHoverColor' => '--pxr-title-hover-color',
            'metaColor'       => '--pxr-meta-color',
            'starColor'      => '--pxr-star-color',
            'starEmptyColor' => '--pxr-star-empty-color',
            'borderColor'    => '--pxr-border-color',
            'badgeBg'        => '--pxr-badge-bg',
            'badgeText'      => '--pxr-badge-text',
        ];

        foreach ($color_map as $key => $var) {
            if (!empty($colors[$key])) {
                $this->put($vars, $rejected, $var, $color_from[$key] ?? 'colors.' . $key, $this->color($colors[$key]));
            }
        }

        // Typography
        $typography = $this->branch($view_config, 'typography');
        $type_map = [
            'titleFontSize'   => ['--pxr-title-font-size', 'px'],
            'titleFontWeight' => ['--pxr-title-font-weight', ''],
            'textFontSize'    => ['--pxr-text-font-size', 'px'],
            'textFontWeight'  => ['--pxr-text-font-weight', ''],
            'metaFontSize'    => ['--pxr-meta-font-size', 'px'],
        ];

        foreach ($type_map as $key => [$var, $unit]) {
            if ($this->given($typography, $key)) {
                $this->put(
                    $vars,
                    $rejected,
                    $var,
                    'typography.' . $key,
                    'px' === $unit ? $this->px($typography[$key]) : $this->font_weight($typography[$key])
                );
            }
        }

        // Typography - font families (individual per element)
        $font_family_map = [
            'titleFontFamily' => '--pxr-title-font-family',
            'textFontFamily'  => '--pxr-text-font-family',
            'metaFontFamily'  => '--pxr-meta-font-family',
        ];
        foreach ($font_family_map as $key => $var) {
            if (!empty($typography[$key]) && $typography[$key] !== 'inherit') {
                $this->put($vars, $rejected, $var, 'typography.' . $key, $this->font_family($typography[$key]));
            }
        }
        // Legacy single fontFamily (backward compat)
        if (!empty($typography['fontFamily']) && $typography['fontFamily'] !== 'inherit') {
            $this->put($vars, $rejected, '--pxr-font-family', 'typography.fontFamily', $this->font_family($typography['fontFamily']));
        }

        // Spacing
        $spacing = $this->branch($view_config, 'spacing');
        foreach (['cardPadding' => '--pxr-card-padding', 'contentPadding' => '--pxr-content-padding', 'gap' => '--pxr-gap'] as $key => $var) {
            if ($this->given($spacing, $key)) {
                $this->put($vars, $rejected, $var, 'spacing.' . $key, $this->px($spacing[$key]));
            }
        }

        // Borders
        $borders = $this->branch($view_config, 'borders');
        foreach (['radius' => '--pxr-border-radius', 'width' => '--pxr-border-width'] as $key => $var) {
            if ($this->given($borders, $key)) {
                $this->put($vars, $rejected, $var, 'borders.' . $key, $this->px($borders[$key]));
            }
        }

        // Shadows
        $shadows = $this->branch($view_config, 'shadows');
        $vars['--pxr-box-shadow'] = 'none';
        if (!empty($shadows['enabled']) && !empty($shadows['value'])) {
            $this->put($vars, $rejected, '--pxr-box-shadow', 'shadows.value', $this->shadow($shadows['value']));
        }

        // Image
        $image = $this->branch($view_config, 'image');
        if (!empty($image['aspectRatio'])) {
            $this->put($vars, $rejected, '--pxr-image-aspect-ratio', 'image.aspectRatio', $this->aspect_ratio($image['aspectRatio']));
        }
        if (!empty($image['borderRadius'])) {
            $this->put($vars, $rejected, '--pxr-image-border-radius', 'image.borderRadius', $this->px($image['borderRadius']));
        }
        if (!empty($image['objectFit'])) {
            $this->put($vars, $rejected, '--pxr-image-object-fit', 'image.objectFit', $this->keyword($image['objectFit'], self::OBJECT_FIT));
        }

        // Video
        $vars['--pxr-video-play-icon-size'] = '48px';

        // Categories — swap to dark mode category colors when enabled
        $categories = $this->branch($view_config, 'categories');
        $dark_cats  = $dark ? $this->branch($dark_colors, 'categories') : [];
        $cat_colors = array_merge($this->branch($categories, 'colors'), $dark_cats);
        $cat_color_map = [
            'backgroundColor'       => '--pxr-cat-bg',
            'textColor'             => '--pxr-cat-text',
            'activeBackgroundColor' => '--pxr-cat-active-bg',
            'activeTextColor'       => '--pxr-cat-active-text',
            'borderColor'           => '--pxr-cat-border-color',
        ];
        foreach ($cat_color_map as $key => $var) {
            if (!empty($cat_colors[$key])) {
                $from = array_key_exists($key, $dark_cats) ? 'darkMode.categories.' : 'categories.colors.';
                $this->put($vars, $rejected, $var, $from . $key, $this->color($cat_colors[$key]));
            }
        }
        $cat_typo = $this->branch($categories, 'typography');
        if ($this->given($cat_typo, 'fontSize')) {
            $this->put($vars, $rejected, '--pxr-cat-font-size', 'categories.typography.fontSize', $this->px($cat_typo['fontSize']));
        }
        if ($this->given($cat_typo, 'fontWeight')) {
            $this->put($vars, $rejected, '--pxr-cat-font-weight', 'categories.typography.fontWeight', $this->font_weight($cat_typo['fontWeight']));
        }
        if (!empty($cat_typo['fontFamily']) && $cat_typo['fontFamily'] !== 'inherit') {
            $this->put($vars, $rejected, '--pxr-cat-font-family', 'categories.typography.fontFamily', $this->font_family($cat_typo['fontFamily']));
        }
        $cat_spacing = $this->branch($categories, 'spacing');
        foreach (['padding' => '--pxr-cat-padding', 'gap' => '--pxr-cat-gap'] as $key => $var) {
            if ($this->given($cat_spacing, $key)) {
                $this->put($vars, $rejected, $var, 'categories.spacing.' . $key, $this->px($cat_spacing[$key]));
            }
        }
        $cat_border = $this->branch($categories, 'border');
        foreach (['width' => '--pxr-cat-border-width', 'radius' => '--pxr-cat-border-radius'] as $key => $var) {
            if ($this->given($cat_border, $key)) {
                $this->put($vars, $rejected, $var, 'categories.border.' . $key, $this->px($cat_border[$key]));
            }
        }

        // Pagination colors — swap to dark mode when enabled
        $pagination = $this->branch($view_config, 'pagination');
        $pag_alignment = $pagination['alignment'] ?? 'center';
        $align_map = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
        $vars['--pxr-pag-alignment'] = is_string($pag_alignment) && isset($align_map[$pag_alignment]) ? $align_map[$pag_alignment] : 'center';

        $dark_pag = $dark ? $this->branch($dark_colors, 'pagination') : [];

        $pag_maps = [
            'numbers' => [
                'bg' => '--pxr-pag-bg', 'text' => '--pxr-pag-text', 'border' => '--pxr-pag-border',
                'activeBg' => '--pxr-pag-active-bg', 'activeText' => '--pxr-pag-active-text', 'activeBorder' => '--pxr-pag-active-border',
            ],
            'loadMore' => [
                'bg' => '--pxr-pag-lm-bg', 'text' => '--pxr-pag-lm-text', 'border' => '--pxr-pag-lm-border',
                'hoverBg' => '--pxr-pag-lm-hover-bg', 'hoverText' => '--pxr-pag-lm-hover-text', 'hoverBorder' => '--pxr-pag-lm-hover-border',
            ],
            'infiniteScroll' => [
                'spinnerColor' => '--pxr-pag-spinner', 'textColor' => '--pxr-pag-loading-text',
            ],
        ];
        foreach ($pag_maps as $group => $map) {
            $override = $this->branch($dark_pag, $group);
            $values   = array_merge($this->branch($pagination, $group), $override);
            foreach ($map as $key => $var) {
                if (!empty($values[$key])) {
                    $from = array_key_exists($key, $override) ? 'darkMode.pagination.' : 'pagination.';
                    $this->put($vars, $rejected, $var, $from . $group . '.' . $key, $this->color($values[$key]));
                }
            }
        }

        // Layout columns
        $layout  = $this->branch($view_config, 'layout');
        $source  = isset($layout['columns']) ? 'columns' : 'slidesPerView';
        $columns = $this->branch($layout, $source);
        foreach (['desktop' => '--pxr-columns-desktop', 'tablet' => '--pxr-columns-tablet', 'mobile' => '--pxr-columns-mobile'] as $key => $var) {
            if (!empty($columns[$key])) {
                $this->put($vars, $rejected, $var, 'layout.' . $source . '.' . $key, $this->count($columns[$key]));
            }
        }

        return $vars;
    }

    /**
     * The ONLY way a config value enters the output: a validator's answer, or nothing.
     *
     * @param array<string, string> $vars
     * @param string[]              $rejected
     * @param string|null           $css      What a validator returned; null = not valid.
     */
    private function put(array &$vars, array &$rejected, string $prop, string $path, ?string $css): void {
        if (null === $css) {
            $rejected[] = $path;
            return;
        }

        $vars[$prop] = $css;
    }

    /** A sub-array of the config, or [] when the key holds anything else. */
    private function branch(array $from, string $key): array {
        return isset($from[$key]) && is_array($from[$key]) ? $from[$key] : [];
    }

    /** Whether a key carries a value: set, and not the blank a cleared number field sends. */
    private function given(array $from, string $key): bool {
        return isset($from[$key]) && '' !== $from[$key];
    }

    /**
     * A colour: hex, rgb()/hsl() with only numbers inside, or a bare keyword.
     *
     * @param mixed $value
     */
    private function color($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) {
            return $value;
        }
        if (preg_match('/^(?:rgb|hsl)a?\((?:[0-9.,%\/ ]|deg)+\)$/i', $value)) {
            return $value;
        }
        // A named colour, `transparent`, `inherit`, `currentColor`: letters only, so it
        // cannot end the declaration whatever word it is.
        if (preg_match('/^[a-z]{3,30}$/i', $value)) {
            return $value;
        }

        return null;
    }

    /**
     * A non-negative length in pixels.
     *
     * @param mixed $value
     */
    private function px($value): ?string {
        $number = $this->number($value);

        return null === $number ? null : $number . 'px';
    }

    /**
     * A non-negative number, written the way it arrived (18, "18" and 1.5 all stay as typed).
     *
     * @param mixed $value
     */
    private function number($value): ?string {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/^\d{1,5}(?:\.\d{1,6})?$/', $value) ? $value : null;
    }

    /**
     * A column count.
     *
     * @param mixed $value
     */
    private function count($value): ?string {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/^[1-9]\d?$/', $value) ? $value : null;
    }

    /**
     * A font weight: 1-1000, or one of the four keywords.
     *
     * @param mixed $value
     */
    private function font_weight($value): ?string {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/^(?:\d{1,4}|normal|bold|bolder|lighter)$/', $value) ? $value : null;
    }

    /**
     * A font family, quoted, with the generic fallback. The name sits inside a CSS string,
     * so the characters that could end one - a quote, a backslash, a line break - are not
     * among those allowed.
     *
     * @param mixed $value
     */
    private function font_family($value): ?string {
        if (!is_string($value) || !preg_match('/^[\p{L}\p{N} ,._\-]{1,100}$/u', $value)) {
            return null;
        }

        return '"' . $value . '", sans-serif';
    }

    /**
     * A box-shadow: lengths, keywords and colours. Once its rgb()/hsl() colours are taken
     * out nothing with a bracket may remain, which is what keeps url() and var() out.
     *
     * @param mixed $value
     */
    private function shadow($value): ?string {
        if (!is_string($value) || strlen($value) > 200) {
            return null;
        }
        $value = trim($value);

        $rest = preg_replace('/(?:rgb|hsl)a?\((?:[0-9.,%\/ ]|deg)+\)/i', '', $value);

        return is_string($rest) && preg_match('/^[a-z0-9 .,#\-]+$/i', $rest) ? $value : null;
    }

    /**
     * An aspect ratio: `auto`, a number, or two numbers around a slash.
     *
     * @param mixed $value
     */
    private function aspect_ratio($value): ?string {
        return is_string($value) && preg_match('/^(?:auto|\d{1,4}(?:\.\d{1,3})?(?: ?\/ ?\d{1,4}(?:\.\d{1,3})?)?)$/', $value) ? $value : null;
    }

    /**
     * One of a fixed list of keywords.
     *
     * @param mixed    $value
     * @param string[] $allowed
     */
    private function keyword($value, array $allowed): ?string {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
