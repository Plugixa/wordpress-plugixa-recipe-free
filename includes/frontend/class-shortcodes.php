<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Frontend;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the shortcode renderers. The listing and its child rows go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the per-user like/favourite lookups and the small view/shortcode config reads are deliberately uncached. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` listing queries report "found 1 replacement, expected 23". The values are passed in one pre-built positional array (see $args above the call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use PlugixaRecipe\RenderedRecipes;

/**
 * Shortcodes Class - Registers and renders frontend recipe shortcodes.
 *
 * [plugixa_recipes] - Display recipes in grid/slider/masonry/list layouts
 * [plugixa_recipe_form] - Frontend recipe submission form
 */
class Shortcodes {

    /** @var bool Track whether assets have been enqueued */
    private bool $assets_enqueued = false;

    /** @var bool Track whether shortcode is present on the page */
    private static bool $has_shortcode = false;

    /**
     * Attachment size used for recipe card images, taken from the active view config.
     *
     * Declared rather than assigned dynamically: PHP 8.2 deprecates creating an undeclared
     * property (it logs on every render with WP_DEBUG on) and PHP 9 removes it outright.
     *
     * @var string
     */
    private string $image_size = 'medium_large';

    /**
     * Register shortcodes.
     */
    public function register(): void {
        add_shortcode('plugixa_recipe', [$this, 'render_recipes']);
        add_shortcode('plugixa_recipes', [$this, 'render_recipes']); // backward compat
        add_shortcode('plugixa_recipe_form', [$this, 'render_submission_form']);

        // AJAX pagination handler
        add_action('wp_ajax_plugixa_recipe_load_recipes', [$this, 'ajax_load_recipes']);
        add_action('wp_ajax_nopriv_plugixa_recipe_load_recipes', [$this, 'ajax_load_recipes']);
    }

    /**
     * Render the [plugixa_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Rendered HTML output.
     */
    public function render_recipes($atts): string {
        $atts = shortcode_atts([
            'id'         => '',        // Shortcode ID (loads config from DB)
            'view'       => '',        // View ID or slug
            'categories' => '',        // Comma-separated category slugs or IDs
            'cuisines'   => '',        // Comma-separated cuisine slugs or IDs
            'courses'    => '',        // Comma-separated course slugs or IDs
            'limit'      => 12,
            'orderby'    => 'created_at',
            'order'      => 'DESC',
            'featured'   => '',        // 'true' to show only featured
            'min_rating' => '',
            'difficulty' => '',        // easy, medium, hard
            'columns'    => '',        // Override columns (desktop)
            'dark_mode'        => 'false',
            'pagination'       => 'false',
            'pagination_style' => '',
            'per_page'         => 12,
        ], $atts, 'plugixa_recipes');

        self::$has_shortcode = true;

        // If shortcode ID is provided, merge DB config
        $shortcode_config = [];
        if (!empty($atts['id'])) {
            $shortcode_config = $this->get_shortcode_config((int) $atts['id']);
        }

        // Merge: DB config takes precedence; only inline attributes explicitly
        // different from defaults should override (avoids 'pagination'=>'false' default clobbering DB values)
        $defaults = ['dark_mode' => 'false', 'pagination' => 'false', 'per_page' => 12, 'limit' => 12, 'orderby' => 'created_at', 'order' => 'DESC'];
        $explicit_atts = array_filter($atts, function ($v, $k) use ($defaults) {
            if ($v === '' || $v === null) return false;
            // Skip if value matches the default (user didn't explicitly set it)
            if (isset($defaults[$k]) && (string) $v === (string) $defaults[$k]) return false;
            return true;
        }, ARRAY_FILTER_USE_BOTH);
        $config = array_merge($shortcode_config, $explicit_atts);

        // Load view config — check both inline 'view' attribute and DB 'view_id'
        $view_identifier = $config['view'] ?? '';
        if (empty($view_identifier) && !empty($config['view_id'])) {
            $view_identifier = $config['view_id'];
        }
        $view_config = $this->get_view_config($view_identifier);

        // Override columns if specified
        if (!empty($config['columns'])) {
            $view_config['layout']['columns']['desktop'] = (int) $config['columns'];
        }

        // Determine layout style
        $layout_style = $view_config['layout']['style'] ?? 'grid';

        // Store image size for enrich_recipe()
        $this->image_size = $view_config['image']['size'] ?? 'medium_large';

        // When pagination is enabled, limit initial fetch to perPage
        $pagination_on = !empty($config['paginationEnabled'])
            || filter_var($config['pagination'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($pagination_on) {
            $pp = (int) ($config['perPage'] ?? $config['per_page'] ?? 12);
            if ($pp > 0 && ((int) ($config['limit'] ?? 0) <= 0 || (int) ($config['limit'] ?? 0) > $pp)) {
                $config['limit'] = $pp;
            }
        }

        // Fetch recipes
        $recipes = $this->query_recipes($config);

        if (empty($recipes)) {
            return '<div class="pxr-no-recipes">' . esc_html__('No recipes found.', 'plugixa-recipe') . '</div>';
        }

        // Tell the JSON-LD builder exactly which recipes this page puts on screen, so the
        // structured data describes the visible content instead of guessing. See
        // PlugixaRecipe\RenderedRecipes.
        RenderedRecipes::add($recipes);

        // Resolve display settings: shortcode config > view config > defaults
        $display_settings = $this->resolve_display_settings($config, $view_config);
        $view_config = array_merge($view_config, $display_settings);

        // Bulk-load ingredient names for ingredient filter (single query, not N+1)
        $show_ingredient_filter = !empty($display_settings['show_ingredient_filter']);
        if ($show_ingredient_filter) {
            global $wpdb;
            $prefix = $wpdb->prefix . 'plugixa_recipe_';
            $recipe_ids = array_map(function ($r) { return (int) $r->id; }, $recipes);
            if (!empty($recipe_ids)) {
                // The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
                // IN (...), which keeps the query a constant literal for any result size.
                $id_list = implode(',', $recipe_ids);
                // Read-through object cache; key = the recipe ids in the result set.
                $ingredient_rows = Cache::remember('recipes', 'ingredient_filter|' . wp_json_encode($recipe_ids), function () use ($wpdb, $prefix, $id_list) {
                    return $wpdb->get_results($wpdb->prepare(
                        'SELECT recipe_id, GROUP_CONCAT(DISTINCT LOWER(name) SEPARATOR \',\') as names
                         FROM %i WHERE FIND_IN_SET(recipe_id, %s) GROUP BY recipe_id',
                        $prefix . 'ingredients',
                        $id_list
                    )) ?: [];
                });
                $ingredient_map = [];
                foreach ($ingredient_rows as $row) {
                    $ingredient_map[(int) $row->recipe_id] = $row->names;
                }
                foreach ($recipes as $recipe) {
                    $recipe->ingredient_names = $ingredient_map[(int) $recipe->id] ?? '';
                }
            }
        }

        // Enqueue assets
        $this->enqueue_frontend_assets($layout_style);

        // Enqueue self-hosted fonts for custom typography (no external CDN — see
        // assets/frontend/fonts/. Only the woff2 actually used by the rendered text
        // is downloaded; the rest are just @font-face declarations.)
        $uses_custom_font = false;
        foreach (['titleFontFamily', 'textFontFamily', 'metaFontFamily'] as $fk) {
            $font = $view_config['typography'][$fk] ?? '';
            if ($font && $font !== 'inherit') {
                $uses_custom_font = true;
                break;
            }
        }
        if ($uses_custom_font) {
            wp_enqueue_style(
                'pxr-fonts',
                PLUGIXA_RECIPE_URL . 'assets/frontend/fonts/fonts.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Determine dark mode BEFORE building CSS vars (so colors can be swapped)
        $dark_mode_state = 'disabled';
        $sc_dark = $config['darkMode'] ?? $config['dark_mode'] ?? null;
        if ($sc_dark !== null && $sc_dark !== 'false' && $sc_dark !== false) {
            $dark_mode_state = $this->resolve_dark_mode_state($sc_dark);
        }
        if ($dark_mode_state === 'disabled' && !empty($view_config['dark_mode'])) {
            $dark_mode_state = $this->resolve_dark_mode_state($view_config['dark_mode']);
        }
        if ($dark_mode_state === 'disabled' && !empty($view_config['darkMode']['enabled'])) {
            $dark_mode_state = 'enabled';
        }
        $dark_mode = ($dark_mode_state === 'enabled');
        $dark_mode_auto = ($dark_mode_state === 'auto');

        // Build CSS variables (dark mode state determines which colors to use)
        $css_vars = $this->build_css_vars($view_config, $config, $dark_mode_state);
        $unique_id = 'pxr-' . ($config['id'] ?? uniqid());

        // Pagination data — support both new nested keys and legacy flat keys
        $pagination_enabled = !empty($config['paginationEnabled'])
            || filter_var($config['pagination'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || !empty($display_settings['show_pagination']);
        $per_page = (int) ($config['perPage'] ?? $config['per_page'] ?? $display_settings['items_per_page'] ?? 12);
        $pagination_style = $config['paginationStyle'] ?? $config['pagination_style'] ?? $display_settings['pagination_type'] ?? 'numbers';

        // For "numbers" pagination, compute total pages for the template
        $total_recipes = count($recipes);
        if ($pagination_enabled && $pagination_style === 'numbers') {
            // Count total recipes matching the query (without limit)
            $count_config = $config;
            $count_config['limit'] = -1;
            $count_config['offset'] = 0;
            $all_recipes = $this->query_recipes($count_config);
            $total_recipes = count($all_recipes);
        }
        $total_pages = $per_page > 0 ? (int) ceil($total_recipes / $per_page) : 1;

        // Build template data
        $shortcode_config = array_merge($config, [
            'page'        => 1,
            'total_pages' => $total_pages,
            'per_page'    => $per_page,
        ]);

        // Store config server-side and pass only a key to the frontend
        $config_key = 'plugixa_recipe_pgn_' . md5(wp_json_encode($shortcode_config));
        set_transient($config_key, $shortcode_config, DAY_IN_SECONDS);

        $data = [
            'recipes'          => $recipes,
            'view_config'      => $view_config,
            'config'           => $config,
            'shortcode_config' => $shortcode_config,
            'config_key'       => $config_key,
            'css_vars'         => $css_vars,
            'css_vars_style'   => $css_vars,   // alias for templates
            'unique_id'        => $unique_id,
            'dark_mode'        => $dark_mode,
            'pagination'       => $pagination_enabled,
            'pagination_style' => $pagination_style,
            'per_page'         => $per_page,
            'total_pages'      => $total_pages,
            'layout_style'     => $layout_style,
            'settings'         => $this->get_settings(),
        ];

        // Render template
        ob_start();

        $wrapper_classes = 'pxr-recipes-wrapper';
        if ($dark_mode) {
            $wrapper_classes .= ' pxr-dark';
        }
        // Emitted in parts so every dynamic value is escaped inline at its own output site.
        echo '<div id="' . esc_attr($unique_id) . '" class="' . esc_attr($wrapper_classes) . '"';
        if ($dark_mode_auto) {
            echo ' data-pxr-dark-mode="auto"';
        }
        echo ' style="' . esc_attr($css_vars) . '">';

        $template = $this->locate_template($layout_style);
        if ($template && file_exists($template)) {
            extract($data, EXTR_SKIP);
            // Ensure pagination vars are available to template even if EXTR_SKIP skipped them
            $shortcode_config = $data['shortcode_config'];
            $total_pages = $data['total_pages'];
            include $template;
        }

        // Pagination — "numbers" is handled by grid.php template, others rendered here
        if ($pagination_enabled && $pagination_style !== 'numbers') {
            $this->render_pagination($data);
        }

        echo '</div>';

        return ob_get_clean();
    }

    /**
     * Render the [plugixa_recipe_form] shortcode for frontend submissions.
     */
    /**
     * Enqueue Cloudflare Turnstile for a public form.
     *
     * The templates used to print a raw <script src> tag, which WordPress.org rejects
     * (WordPress.WP.EnqueuedResources.NonEnqueuedScript). It only loads when the site
     * owner has switched the captcha on and supplied their own site key, so the plugin
     * contacts Cloudflare only on an explicit opt-in.
     *
     * The site key is NOT put in the URL (reCAPTCHA v3 needed `?render=<key>`; Turnstile
     * reads it from the widget's data-sitekey attribute), so the script URL is constant
     * and cacheable, and the key is not sprayed across referrer logs.
     *
     * @param bool   $enabled  Whether the captcha is switched on for this form.
     * @param string $site_key The site owner's Turnstile site key.
     */
    public static function enqueue_turnstile(bool $enabled, string $site_key): void {
        if (!$enabled || $site_key === '') {
            return;
        }

        wp_enqueue_script(
            'plugixa-recipe-turnstile',
            // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Cloudflare Turnstile is a CAPTCHA service, not asset offloading. Plugin Check's offloading list carries the pattern '(?<!api\.)cloudflare\.com', whose lookbehind exempts only api.cloudflare.com, so every other *.cloudflare.com host matches and Turnstile's challenges.cloudflare.com is caught by a rule aimed at CDN-hosted JS/CSS. The endpoint cannot be self-hosted: Cloudflare requires both the widget script and the siteverify call to go to its own host, and a token verified anywhere else would be meaningless. It is off by default, requires the site owner's own keys, and is disclosed in readme.txt under == External services ==.
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            [],
            null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Third-party Cloudflare Turnstile endpoint: always served current, and must not carry a plugin ?ver= cache-buster we do not control.
            true
        );
    }

    public function render_submission_form($atts): string {
        $atts = shortcode_atts([
            'style'      => 'inline',
            'categories' => '',
            'cuisines'   => '',
            'redirect'   => '',
        ], $atts, 'plugixa_recipe_form');

        $settings = $this->get_settings();
        if (empty($settings['enable_submissions'])) {
            return '';
        }

        // Login gate (configurable via settings).
        $require_login = ! empty($settings['submission_require_login']);
        if ($require_login && ! is_user_logged_in()) {
            return '<div class="pxr-custom-submission-login pxr:max-w-2xl pxr:mx-auto pxr:text-center pxr:py-12">'
                 . '<p class="pxr:text-gray-600 pxr:text-sm pxr:mb-4">'
                 . esc_html__('You must be logged in to submit a recipe.', 'plugixa-recipe')
                 . '</p>'
                 . '<a href="' . esc_url(wp_login_url(get_permalink())) . '" class="pxr:inline-block pxr:rounded-md pxr:bg-indigo-600 pxr:px-4 pxr:py-2 pxr:text-sm pxr:font-semibold pxr:text-white hover:pxr:bg-indigo-500">'
                 . esc_html__('Log In', 'plugixa-recipe')
                 . '</a></div>';
        }

        self::$has_shortcode = true;
        $this->enqueue_frontend_assets('form');

        // Template variables.
        $unique_id     = 'pxr-form-' . wp_unique_id();
        $display_mode  = sanitize_text_field($atts['style']);
        $nonce         = wp_create_nonce('wp_rest');
        $rest_url      = rest_url('plugixa-recipe/v1/frontend/submit-recipe');
        $max_file_size = (int) ($settings['max_upload_size'] ?? 5);

        $turnstile_enabled  = ! empty($settings['turnstile_form_enabled']);
        $turnstile_site_key = $settings['turnstile_site_key'] ?? '';

        self::enqueue_turnstile($turnstile_enabled, $turnstile_site_key);

        // Fetch categories and cuisines from DB.
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // Read-through object cache; these queries take no inputs.
        $category_options = Cache::remember('categories', 'form_options', function () use ($wpdb, $prefix) {
            return $wpdb->get_results(
                $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $prefix . 'categories'),
                ARRAY_A
            ) ?: [];
        });
        $cuisine_options = Cache::remember('cuisines', 'form_options', function () use ($wpdb, $prefix) {
            return $wpdb->get_results(
                $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $prefix . 'cuisines'),
                ARRAY_A
            ) ?: [];
        });
        $course_options = Cache::remember('courses', 'form_options', function () use ($wpdb, $prefix) {
            return $wpdb->get_results(
                $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $prefix . 'courses'),
                ARRAY_A
            ) ?: [];
        });

        $difficulty_options = [
            'easy'   => __('Easy', 'plugixa-recipe'),
            'medium' => __('Medium', 'plugixa-recipe'),
            'hard'   => __('Hard', 'plugixa-recipe'),
        ];
        $cost_options = [
            'budget'    => __('Budget', 'plugixa-recipe'),
            'medium'    => __('Medium', 'plugixa-recipe'),
            'expensive' => __('Expensive', 'plugixa-recipe'),
        ];
        $season_options = [
            'all'    => __('All Seasons', 'plugixa-recipe'),
            'spring' => __('Spring', 'plugixa-recipe'),
            'summer' => __('Summer', 'plugixa-recipe'),
            'autumn' => __('Autumn', 'plugixa-recipe'),
            'winter' => __('Winter', 'plugixa-recipe'),
        ];
        $unit_options = [
            ''      => __('Unit', 'plugixa-recipe'),
            'tsp'   => 'tsp',
            'tbsp'  => 'tbsp',
            'cup'   => 'cup',
            'oz'    => 'oz',
            'lb'    => 'lb',
            'g'     => 'g',
            'kg'    => 'kg',
            'ml'    => 'ml',
            'l'     => 'l',
            'piece' => 'piece',
            'slice' => 'slice',
            'pinch' => 'pinch',
            'dash'  => 'dash',
            'clove' => 'clove',
            'can'   => 'can',
            'bunch' => 'bunch',
            'sprig' => 'sprig',
        ];

        ob_start();

        $template = PLUGIXA_RECIPE_PATH . 'templates/submission-form.php';
        if (file_exists($template)) {
            include $template;
        }

        return ob_get_clean();
    }

    /**
     * Build inline CSS custom property declarations from view config.
     *
     * @param array $view_config View configuration array.
     * @param array $config      Shortcode configuration overrides.
     * @return string CSS custom properties string (for style attribute).
     */
    public function build_css_vars(array $view_config, array $config = [], string $dark_mode_state = 'disabled'): string {
        $vars = [];

        // Colors — swap to dark mode palette when enabled
        $colors = $view_config['colors'] ?? [];
        $dark_colors = $view_config['darkMode'] ?? [];

        if ($dark_mode_state === 'enabled' && !empty($dark_colors)) {
            $dark_keys = ['cardBg', 'textColor', 'titleColor', 'metaColor', 'starColor', 'starEmptyColor', 'borderColor', 'badgeBg', 'badgeText'];
            foreach ($dark_keys as $dk) {
                if (!empty($dark_colors[$dk])) {
                    $colors[$dk] = $dark_colors[$dk];
                }
            }
            if (!empty($dark_colors['containerBg'])) {
                $vars['--pxr-container-bg'] = $dark_colors['containerBg'];
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
                $vars[$var] = $colors[$key];
            }
        }

        // Typography
        $typography = $view_config['typography'] ?? [];
        $type_map = [
            'titleFontSize'   => ['--pxr-title-font-size', 'px'],
            'titleFontWeight' => ['--pxr-title-font-weight', ''],
            'textFontSize'    => ['--pxr-text-font-size', 'px'],
            'textFontWeight'  => ['--pxr-text-font-weight', ''],
            'metaFontSize'    => ['--pxr-meta-font-size', 'px'],
        ];

        foreach ($type_map as $key => [$var, $unit]) {
            if (isset($typography[$key])) {
                $vars[$var] = $typography[$key] . $unit;
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
                $vars[$var] = '"' . $typography[$key] . '", sans-serif';
            }
        }
        // Legacy single fontFamily (backward compat)
        if (!empty($typography['fontFamily']) && $typography['fontFamily'] !== 'inherit') {
            $vars['--pxr-font-family'] = '"' . $typography['fontFamily'] . '", sans-serif';
        }

        // Spacing
        $spacing = $view_config['spacing'] ?? [];
        if (isset($spacing['cardPadding'])) {
            $vars['--pxr-card-padding'] = $spacing['cardPadding'] . 'px';
        }
        if (isset($spacing['contentPadding'])) {
            $vars['--pxr-content-padding'] = $spacing['contentPadding'] . 'px';
        }
        if (isset($spacing['gap'])) {
            $vars['--pxr-gap'] = $spacing['gap'] . 'px';
        }

        // Borders
        $borders = $view_config['borders'] ?? [];
        if (isset($borders['radius'])) {
            $vars['--pxr-border-radius'] = $borders['radius'] . 'px';
        }
        if (isset($borders['width'])) {
            $vars['--pxr-border-width'] = $borders['width'] . 'px';
        }

        // Shadows
        $shadows = $view_config['shadows'] ?? [];
        if (!empty($shadows['enabled']) && !empty($shadows['value'])) {
            $vars['--pxr-box-shadow'] = $shadows['value'];
        } else {
            $vars['--pxr-box-shadow'] = 'none';
        }

        // Image
        $image = $view_config['image'] ?? [];
        if (!empty($image['aspectRatio'])) {
            $vars['--pxr-image-aspect-ratio'] = $image['aspectRatio'];
        }
        if (!empty($image['borderRadius'])) {
            $vars['--pxr-image-border-radius'] = $image['borderRadius'] . 'px';
        }
        if (!empty($image['objectFit'])) {
            $vars['--pxr-image-object-fit'] = $image['objectFit'];
        }

        // Video
        $vars['--pxr-video-play-icon-size'] = '48px';

        // Categories — swap to dark mode category colors when enabled
        $categories = $view_config['categories'] ?? [];
        $cat_colors = $categories['colors'] ?? [];
        if ($dark_mode_state === 'enabled' && !empty($dark_colors['categories'])) {
            $cat_colors = array_merge($cat_colors, $dark_colors['categories']);
        }
        $cat_color_map = [
            'backgroundColor'       => '--pxr-cat-bg',
            'textColor'             => '--pxr-cat-text',
            'activeBackgroundColor' => '--pxr-cat-active-bg',
            'activeTextColor'       => '--pxr-cat-active-text',
            'borderColor'           => '--pxr-cat-border-color',
        ];
        foreach ($cat_color_map as $key => $var) {
            if (!empty($cat_colors[$key])) {
                $vars[$var] = $cat_colors[$key];
            }
        }
        $cat_typo = $categories['typography'] ?? [];
        if (isset($cat_typo['fontSize'])) {
            $vars['--pxr-cat-font-size'] = $cat_typo['fontSize'] . 'px';
        }
        if (isset($cat_typo['fontWeight'])) {
            $vars['--pxr-cat-font-weight'] = $cat_typo['fontWeight'];
        }
        if (!empty($cat_typo['fontFamily']) && $cat_typo['fontFamily'] !== 'inherit') {
            $vars['--pxr-cat-font-family'] = '"' . $cat_typo['fontFamily'] . '", sans-serif';
        }
        $cat_spacing = $categories['spacing'] ?? [];
        if (isset($cat_spacing['padding'])) {
            $vars['--pxr-cat-padding'] = $cat_spacing['padding'] . 'px';
        }
        if (isset($cat_spacing['gap'])) {
            $vars['--pxr-cat-gap'] = $cat_spacing['gap'] . 'px';
        }
        $cat_border = $categories['border'] ?? [];
        if (isset($cat_border['width'])) {
            $vars['--pxr-cat-border-width'] = $cat_border['width'] . 'px';
        }
        if (isset($cat_border['radius'])) {
            $vars['--pxr-cat-border-radius'] = $cat_border['radius'] . 'px';
        }

        // Pagination colors — swap to dark mode when enabled
        $pagination = $view_config['pagination'] ?? [];
        $pag_alignment = $pagination['alignment'] ?? 'center';
        $align_map = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
        $vars['--pxr-pag-alignment'] = $align_map[$pag_alignment] ?? 'center';

        $pag_numbers = $pagination['numbers'] ?? [];
        $pag_load_more = $pagination['loadMore'] ?? [];
        $pag_infinite = $pagination['infiniteScroll'] ?? [];

        if ($dark_mode_state === 'enabled' && !empty($dark_colors['pagination'])) {
            $dp = $dark_colors['pagination'];
            if (!empty($dp['numbers'])) $pag_numbers = array_merge($pag_numbers, $dp['numbers']);
            if (!empty($dp['loadMore'])) $pag_load_more = array_merge($pag_load_more, $dp['loadMore']);
            if (!empty($dp['infiniteScroll'])) $pag_infinite = array_merge($pag_infinite, $dp['infiniteScroll']);
        }

        $pag_num_map = [
            'bg' => '--pxr-pag-bg', 'text' => '--pxr-pag-text', 'border' => '--pxr-pag-border',
            'activeBg' => '--pxr-pag-active-bg', 'activeText' => '--pxr-pag-active-text', 'activeBorder' => '--pxr-pag-active-border',
        ];
        foreach ($pag_num_map as $key => $var) {
            if (!empty($pag_numbers[$key])) {
                $vars[$var] = $pag_numbers[$key];
            }
        }

        $pag_lm_map = [
            'bg' => '--pxr-pag-lm-bg', 'text' => '--pxr-pag-lm-text', 'border' => '--pxr-pag-lm-border',
            'hoverBg' => '--pxr-pag-lm-hover-bg', 'hoverText' => '--pxr-pag-lm-hover-text', 'hoverBorder' => '--pxr-pag-lm-hover-border',
        ];
        foreach ($pag_lm_map as $key => $var) {
            if (!empty($pag_load_more[$key])) {
                $vars[$var] = $pag_load_more[$key];
            }
        }

        if (!empty($pag_infinite['spinnerColor'])) {
            $vars['--pxr-pag-spinner'] = $pag_infinite['spinnerColor'];
        }
        if (!empty($pag_infinite['textColor'])) {
            $vars['--pxr-pag-loading-text'] = $pag_infinite['textColor'];
        }

        // Layout columns
        $layout = $view_config['layout'] ?? [];
        $columns = $layout['columns'] ?? $layout['slidesPerView'] ?? [];
        if (!empty($columns['desktop'])) {
            $vars['--pxr-columns-desktop'] = $columns['desktop'];
        }
        if (!empty($columns['tablet'])) {
            $vars['--pxr-columns-tablet'] = $columns['tablet'];
        }
        if (!empty($columns['mobile'])) {
            $vars['--pxr-columns-mobile'] = $columns['mobile'];
        }

        // Build CSS string
        $css = '';
        foreach ($vars as $prop => $value) {
            $css .= $prop . ':' . $value . ';';
        }

        return $css;
    }

    /**
     * AJAX handler for paginated recipe loading.
     */
    public function ajax_load_recipes(): void {
        check_ajax_referer('plugixa_recipe_frontend', 'nonce');

        $page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;

        // Load config from server-side transient (no client-side config accepted)
        $config_key = isset($_POST['config_key']) ? sanitize_text_field(wp_unslash($_POST['config_key'])) : '';
        if (!$config_key || !preg_match('/^plugixa_recipe_pgn_[a-f0-9]{32}$/', $config_key)) {
            wp_send_json_error(['message' => 'Invalid configuration key.']);
        }

        $config = get_transient($config_key);
        if (!is_array($config)) {
            wp_send_json_error(['message' => 'Configuration expired. Please refresh the page.']);
        }

        $per_page = (int) ($config['perPage'] ?? $config['per_page'] ?? 12);
        $config['limit'] = $per_page;
        $config['offset'] = ($page - 1) * $per_page;

        $recipes = $this->query_recipes($config);
        $view_config = $this->get_view_config($config['view'] ?? '');
        $layout_style = $view_config['layout']['style'] ?? 'grid';

        // Resolve display settings: shortcode config > view config > defaults
        $display_settings = $this->resolve_display_settings($config, $view_config);
        $view_config = array_merge($view_config, $display_settings);

        ob_start();

        $template = $this->locate_template($layout_style);
        if ($template && file_exists($template)) {
            $data = [
                'recipes'     => $recipes,
                'view_config' => $view_config,
                'config'      => $config,
                'settings'    => $this->get_settings(),
            ];
            extract($data, EXTR_SKIP);
            include $template;
        }

        $html = ob_get_clean();

        wp_send_json_success([
            'html'     => $html,
            'has_more' => count($recipes) >= $per_page,
        ]);
    }

    /**
     * Query recipes from the database based on config.
     */
    /**
     * Normalise a taxonomy filter value to a list of slugs.
     *
     * The same config key reaches this code in two different shapes, and the queries below
     * filter by SLUG:
     *
     * - a hand-written shortcode attribute is a comma-separated STRING of slugs
     *   ([plugixa_recipe categories="desserts,healthy-bowls"]);
     * - a shortcode saved from the admin builder stores an ARRAY OF IDS, because the
     *   builder's picker is a multi-select over the taxonomy table.
     *
     * Before this helper, both were fed straight to explode(), so an admin-built shortcode
     * with a category filter raised "explode(): Argument #2 must be of type string, array
     * given" and took the whole page down with a fatal on PHP 8. Even coerced to a string,
     * the IDs would never have matched a slug column, which is the same bug plugixa-faq hit
     * (resolve_category_slugs, June 2026) - here it just failed louder.
     *
     * @param string            $taxonomy 'categories', 'cuisines' or 'courses'.
     * @param string|array|null $value    Raw config value.
     * @return string[] Slugs, de-duplicated; empty when there is nothing to filter on.
     */
    private function resolve_taxonomy_slugs(string $taxonomy, $value): array {
        if (empty($value)) {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);

        $ids   = [];
        $slugs = [];
        foreach ($values as $entry) {
            // The builder may store either scalars or {id, name, slug} objects.
            if (is_array($entry) || is_object($entry)) {
                $entry = ((array) $entry)['slug'] ?? ((array) $entry)['id'] ?? '';
            }
            if (is_numeric($entry)) {
                $ids[] = (int) $entry;
            } elseif (is_string($entry) && trim($entry) !== '') {
                $slugs[] = sanitize_title(trim($entry));
            }
        }

        if (!empty($ids)) {
            global $wpdb;
            $allowed = ['categories', 'cuisines', 'courses'];
            if (!in_array($taxonomy, $allowed, true)) {
                return array_values(array_unique(array_filter($slugs)));
            }

            // $taxonomy is one of the three names allow-listed above, so the table name is
            // internal; it still binds as an identifier with %i. The id list binds as ONE
            // %s via FIND_IN_SET, keeping the query a constant literal for any id count.
            $table   = $wpdb->prefix . 'plugixa_recipe_' . $taxonomy;
            $id_list = implode(',', $ids);

            $found = Cache::remember(
                $taxonomy,
                'resolve_slugs|' . wp_json_encode($ids),
                function () use ($wpdb, $table, $id_list) {
                    return $wpdb->get_col($wpdb->prepare('SELECT slug FROM %i WHERE FIND_IN_SET(id, %s)', $table, $id_list));
                }
            );

            if (!empty($found)) {
                $slugs = array_merge($slugs, $found);
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    private function query_recipes(array $config): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // Every optional filter is a gate placeholder: bind 0 and its clause is
        // constant-true, which keeps WHERE literal instead of PHP-assembled.
        //
        // The three taxonomy filters are gated EXISTS subqueries rather than optional
        // INNER JOINs. Two reasons: a JOIN cannot be gated away by a placeholder (it is
        // structure, not a predicate), and EXISTS selects the same row set without the
        // row multiplication a JOIN causes - which is what DISTINCT was undoing. The slug
        // list binds as ONE %s via FIND_IN_SET instead of a variable-length IN (...), so
        // the SQL stays a constant literal no matter how many slugs arrive. Slugs are
        // sanitize_title() output, so they can never contain the comma FIND_IN_SET splits on.
        $featured = filter_var($config['featured'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $min_rating = !empty($config['min_rating']) ? (float) $config['min_rating'] : 0.0;
        $difficulty = !empty($config['difficulty']) ? sanitize_text_field($config['difficulty']) : '';

        // Taxonomy filters. The value may arrive as a comma-separated string (hand-written
        // shortcode attribute) or as an array of IDs (saved shortcode from the admin
        // builder's multi-select), so it is normalised to slugs before it reaches SQL.
        // See resolve_taxonomy_slugs().
        $cat_slugs     = $this->resolve_taxonomy_slugs('categories', $config['categories'] ?? null);
        $cuisine_slugs = $this->resolve_taxonomy_slugs('cuisines', $config['cuisines'] ?? null);
        $course_slugs  = $this->resolve_taxonomy_slugs('courses', $config['courses'] ?? null);

        $cat_list     = implode(',', $cat_slugs);
        $cuisine_list = implode(',', $cuisine_slugs);
        $course_list  = implode(',', $course_slugs);

        // Language filter
        $multilingual = \PlugixaRecipe\Multilingual::instance();
        $lang         = $multilingual->is_active() ? $multilingual->get_current_language() : '';

        // Order
        $allowed_orderby = ['created_at', 'title', 'rating_average', 'prep_time', 'cook_time', 'total_time', 'display_order', 'view_count'];
        $orderby = in_array($config['orderby'] ?? '', $allowed_orderby) ? $config['orderby'] : 'created_at';
        $order = strtoupper($config['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Limit & offset — limit=-1 (or 0) means unlimited. A direction keyword cannot be
        // bound, so ASC/DESC is two literal queries; "unlimited" binds a limit larger than
        // any possible row count rather than adding a third and fourth literal.
        //
        // The queries below sort by r.id last as a deterministic tiebreaker: seeded and
        // imported recipes routinely share a created_at/published_at to the second, and a
        // sort with ties may return any permutation, so paged output could repeat one
        // recipe and skip another. Sorting by the primary key last makes paging stable.
        $limit  = (int) ($config['limit'] ?? 12);
        $offset = (int) ($config['offset'] ?? 0);
        if ($limit <= 0) {
            $limit  = PHP_INT_MAX;
            $offset = 0;
        }

        // Bound values for the shared WHERE block, in placeholder order. The %i table
        // identifiers are interleaved at the call site because %i and %s/%d share one
        // positional argument list.
        $args = [
            $prefix . 'recipes',
            $featured,
            $min_rating !== 0.0 ? 1 : 0, $min_rating,
            $difficulty !== '' ? 1 : 0, $difficulty,
            $lang !== '' ? 1 : 0, $lang,
            $cat_slugs ? 1 : 0, $prefix . 'recipe_categories', $prefix . 'categories', $cat_list,
            $cuisine_slugs ? 1 : 0, $prefix . 'recipe_cuisines', $prefix . 'cuisines', $cuisine_list,
            $course_slugs ? 1 : 0, $prefix . 'recipe_courses', $prefix . 'courses', $course_list,
            $orderby,
            $limit,
            $offset,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - it must NOT embed the SQL text, which now lives inline in prepare().
        // It captures every input that changes the result: categories/cuisines/courses,
        // featured, min_rating, difficulty, language, orderby/order, limit/offset.
        $cache_key = 'shortcode|' . md5(wp_json_encode($args) . '|' . $order);

        // The column list below is Columns::recipe_public('r') written out as a literal, so
        // the string handed to prepare() is a constant in both arms. SqlLiteralTest asserts
        // the two stay identical.
        $results = Cache::remember('recipes', $cache_key, function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results(
                        $wpdb->prepare(
                    'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                        r.description, r.excerpt, r.notes, r.tips, r.image_id,
                        r.gallery, r.tags, r.equipment, r.video_url, r.servings,
                        r.servings_unit, r.yield_amount, r.yield_unit, r.prep_time, r.cook_time,
                        r.rest_time, r.total_time, r.difficulty, r.cost, r.season,
                        r.is_featured, r.is_editor_pick, r.display_order, r.source, r.source_url,
                        r.author_name, r.view_count, r.rating_average, r.rating_count, r.like_count,
                        r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free, r.is_keto,
                        r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free, r.is_halal,
                        r.is_kosher, r.published_at, r.created_at
                      FROM %i r
                      WHERE r.status = \'published\'
                        AND ( %d = 0 OR r.is_featured = 1 )
                        AND ( %d = 0 OR r.rating_average >= %f )
                        AND ( %d = 0 OR r.difficulty = %s )
                        AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                                 WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu INNER JOIN %i cu ON rcu.cuisine_id = cu.id
                                                 WHERE rcu.recipe_id = r.id AND FIND_IN_SET(cu.slug, %s)) )
                        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco INNER JOIN %i co ON rco.course_id = co.id
                                                 WHERE rco.recipe_id = r.id AND FIND_IN_SET(co.slug, %s)) )
                      ORDER BY r.%i ASC, r.id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A) ?: [];
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.notes, r.tips, r.image_id,
                    r.gallery, r.tags, r.equipment, r.video_url, r.servings,
                    r.servings_unit, r.yield_amount, r.yield_unit, r.prep_time, r.cook_time,
                    r.rest_time, r.total_time, r.difficulty, r.cost, r.season,
                    r.is_featured, r.is_editor_pick, r.display_order, r.source, r.source_url,
                    r.author_name, r.view_count, r.rating_average, r.rating_count, r.like_count,
                    r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free, r.is_keto,
                    r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free, r.is_halal,
                    r.is_kosher, r.published_at, r.created_at
                  FROM %i r
                  WHERE r.status = \'published\'
                    AND ( %d = 0 OR r.is_featured = 1 )
                    AND ( %d = 0 OR r.rating_average >= %f )
                    AND ( %d = 0 OR r.difficulty = %s )
                    AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                             WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu INNER JOIN %i cu ON rcu.cuisine_id = cu.id
                                             WHERE rcu.recipe_id = r.id AND FIND_IN_SET(cu.slug, %s)) )
                    AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco INNER JOIN %i co ON rco.course_id = co.id
                                             WHERE rco.recipe_id = r.id AND FIND_IN_SET(co.slug, %s)) )
                  ORDER BY r.%i DESC, r.id DESC
                  LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A) ?: [];
        });

        if (empty($results)) {
            return [];
        }

        // Enrich recipes with related data
        return array_map([$this, 'enrich_recipe'], $results);
    }

    /**
     * Enrich a recipe with its ingredients, nutrition, categories, and image URL.
     */
    private function enrich_recipe(array $recipe): object {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $recipe['id'];

        // Image URL — use configured image size or fall back to medium_large
        $image_size = $this->image_size ?? 'medium_large';
        if (!empty($recipe['image_id'])) {
            $image_url = wp_get_attachment_image_url((int) $recipe['image_id'], $image_size);
            $recipe['image_url'] = $image_url ?: '';
            $recipe['image_srcset'] = wp_get_attachment_image_srcset((int) $recipe['image_id'], $image_size) ?: '';
        } else {
            $recipe['image_url'] = '';
            $recipe['image_srcset'] = '';
        }

        // Categories — child rows cached under the owning recipe.
        $recipe['categories'] = Cache::remember('recipes', 'card_categories|' . $recipe_id, function () use ($wpdb, $prefix, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                 INNER JOIN %i rc ON c.id = rc.category_id
                 WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC',
                $prefix . 'categories',
                $prefix . 'recipe_categories',
                $recipe_id
            ), ARRAY_A) ?: [];
        });

        // Cuisines — child rows cached under the owning recipe.
        $recipe['cuisines'] = Cache::remember('recipes', 'card_cuisines|' . $recipe_id, function () use ($wpdb, $prefix, $recipe_id) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                 INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                 WHERE rcu.recipe_id = %d',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines',
                $recipe_id
            ), ARRAY_A) ?: [];
        });

        // Nutrition (single row) — child row cached under the owning recipe.
        $nutrition_table = $prefix . 'nutrition';
        $recipe['nutrition'] = Cache::remember('recipes', 'card_nutrition|' . $recipe_id, function () use ($wpdb, $nutrition_table, $recipe_id) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT id, recipe_id, serving_size, calories, total_fat,
                    saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                    dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                    calcium, iron, potassium, source
                  FROM %i
                  WHERE recipe_id = %d',
                $nutrition_table,
                $recipe_id
            ), ARRAY_A) ?: [];
        });

        // Diet badges
        $recipe['diet_badges'] = [];
        if (!empty($recipe['is_vegetarian'])) $recipe['diet_badges'][] = __('Vegetarian', 'plugixa-recipe');
        if (!empty($recipe['is_vegan'])) $recipe['diet_badges'][] = __('Vegan', 'plugixa-recipe');
        if (!empty($recipe['is_gluten_free'])) $recipe['diet_badges'][] = __('Gluten-Free', 'plugixa-recipe');
        if (!empty($recipe['is_dairy_free'])) $recipe['diet_badges'][] = __('Dairy-Free', 'plugixa-recipe');
        if (!empty($recipe['is_keto'])) $recipe['diet_badges'][] = __('Keto', 'plugixa-recipe');
        if (!empty($recipe['is_low_carb'])) $recipe['diet_badges'][] = __('Low-Carb', 'plugixa-recipe');

        // Excerpt from description (strip tags, truncate)
        $excerpt_length = (int) ($this->get_settings()['excerpt_length'] ?? 150);
        if (!empty($recipe['description'])) {
            $plain = wp_strip_all_tags($recipe['description']);
            $recipe['excerpt'] = mb_strlen($plain) > $excerpt_length
                ? mb_substr($plain, 0, $excerpt_length) . '…'
                : $plain;
        } else {
            $recipe['excerpt'] = '';
        }

        // Like count
        $recipe['like_count'] = isset($recipe['like_count']) ? (int) $recipe['like_count'] : 0;

        // Logged-in user: check like & favorite status (per-user state: not cached).
        if (is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            $recipe['is_liked'] = (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
                $prefix . 'likes', $current_user_id, $recipe_id
            ));
            $recipe['is_favorited'] = (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d',
                $prefix . 'favorites', $current_user_id, $recipe_id
            ));
        } else {
            $recipe['is_liked'] = false;
            $recipe['is_favorited'] = false;
        }

        // Template-friendly aliases
        $recipe['rating'] = $recipe['rating_average'] ?? 0;
        $recipe['cuisine'] = !empty($recipe['cuisines'][0]['name']) ? $recipe['cuisines'][0]['name'] : '';

        // Video type detection for card templates
        $recipe['video_type'] = '';
        $recipe['video_id']   = '';
        if ( ! empty( $recipe['video_url'] ) ) {
            $vurl = $recipe['video_url'];
            if ( preg_match( '/youtube\.com|youtu\.be/', $vurl ) ) {
                $recipe['video_type'] = 'youtube';
                preg_match( '/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $vurl, $m );
                $recipe['video_id'] = $m[1] ?? '';
            } elseif ( preg_match( '/vimeo\.com/', $vurl ) ) {
                $recipe['video_type'] = 'vimeo';
                preg_match( '/vimeo\.com\/(\d+)/', $vurl, $m );
                $recipe['video_id'] = $m[1] ?? '';
            } else {
                $ext = strtolower( pathinfo( wp_parse_url( $vurl, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
                if ( in_array( $ext, array( 'mp4', 'webm', 'ogg' ), true ) ) {
                    $recipe['video_type'] = 'self-hosted';
                }
            }
        }

        // Permalink & print URL
        $slug = sanitize_title($recipe['title'] ?? '');
        $recipe['permalink'] = plugixa_recipe_permalink((int) $recipe_id, $slug);
        $recipe['print_url'] = home_url("/recipe-print/{$recipe_id}/?auto=1");

        return (object) $recipe;
    }

    /**
     * Load shortcode config from the database by ID.
     */
    private function get_shortcode_config(int $id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_shortcodes';

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT config FROM %i WHERE id = %d',
            $table,
            $id
        ));

        if (!$row || empty($row->config)) {
            return [];
        }

        $config = json_decode($row->config, true);
        return is_array($config) ? $config : [];
    }

    /**
     * Load view configuration from the database.
     */
    private function get_view_config($view_identifier): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_views';
        $row = null;

        if (!empty($view_identifier)) {
            if (is_numeric($view_identifier)) {
                $row = $wpdb->get_row($wpdb->prepare(
                    'SELECT config FROM %i WHERE id = %d', $table, (int) $view_identifier
                ));
            } else {
                $row = $wpdb->get_row($wpdb->prepare(
                    'SELECT config FROM %i WHERE slug = %s', $table, sanitize_text_field($view_identifier)
                ));
            }
        }

        // Fall back to default view
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM %i WHERE is_default = 1 LIMIT 1', $table));
        }

        // Fall back to first view
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM %i ORDER BY id ASC LIMIT 1', $table));
        }

        if ($row && !empty($row->config)) {
            $config = json_decode($row->config, true);
            return is_array($config) ? $this->normalize_view_config($config) : $this->normalize_view_config([]);
        }

        return $this->normalize_view_config([]);
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
     * Resolve show/hide and filter navigation settings.
     * Priority: shortcode config > view config > hardcoded defaults.
     */
    private function resolve_display_settings(array $shortcode_config, array $view_config): array {
        // Map nested camelCase keys to flat snake_case keys
        $nested_display_map = [
            'showImage' => 'show_image', 'showTitle' => 'show_title', 'showExcerpt' => 'show_excerpt',
            'showMeta' => 'show_meta', 'showRating' => 'show_rating', 'showDietBadges' => 'show_diet_badges',
            'showDifficulty' => 'show_difficulty', 'showTime' => 'show_time', 'showServings' => 'show_servings',
            'showAuthor' => 'show_author', 'showCategory' => 'show_category', 'showCuisine' => 'show_cuisine',
            'showFavoriteButton' => 'show_favorite_button', 'showPrintButton' => 'show_print_button',
        ];
        $nested_filter_nav_map = [
            'showCategoryNav' => 'show_category_nav', 'showCuisineNav' => 'show_cuisine_nav',
            'showAlphaFilter' => 'show_alpha_filter', 'showIngredientFilter' => 'show_ingredient_filter',
        ];

        // Flatten nested display/filterNav into flat keys for resolution
        $display_nested  = $shortcode_config['display'] ?? [];
        $filter_nested   = $shortcode_config['filterNav'] ?? [];
        foreach ($nested_display_map as $camel => $flat) {
            if (isset($display_nested[$camel]) && !isset($shortcode_config[$flat])) {
                $shortcode_config[$flat] = $display_nested[$camel];
            }
        }
        foreach ($nested_filter_nav_map as $camel => $flat) {
            if (isset($filter_nested[$camel]) && !isset($shortcode_config[$flat])) {
                $shortcode_config[$flat] = $filter_nested[$camel];
            }
        }

        // Map new paginationEnabled to flat show_pagination
        if (isset($shortcode_config['paginationEnabled']) && !isset($shortcode_config['show_pagination'])) {
            $shortcode_config['show_pagination'] = (bool) $shortcode_config['paginationEnabled'];
        }

        $defaults = [
            'show_image'             => true,
            'show_title'             => true,
            'show_excerpt'           => true,
            'show_meta'              => true,
            'show_rating'            => true,
            'show_diet_badges'       => false,
            'show_diet'              => false,
            'show_difficulty'        => true,
            'show_time'              => true,
            'show_servings'          => false,
            'show_author'            => false,
            'show_category'          => true,
            'show_cuisine'           => false,
            'show_favorite_button'   => true,
            'show_print_button'      => false,
            'show_category_nav'      => false,
            'show_cuisine_nav'       => false,
            'show_alpha_filter'      => false,
            'show_ingredient_filter' => false,
            'show_pagination'        => false,
        ];

        $resolved = [];
        foreach ($defaults as $key => $default) {
            if (isset($shortcode_config[$key])) {
                $resolved[$key] = (bool) $shortcode_config[$key];
            } elseif (isset($view_config[$key])) {
                $resolved[$key] = (bool) $view_config[$key];
            } else {
                $resolved[$key] = $default;
            }
        }

        // Alias: show_diet mirrors show_diet_badges
        if (!isset($shortcode_config['show_diet']) && !isset($view_config['show_diet'])) {
            $resolved['show_diet'] = $resolved['show_diet_badges'];
        }

        // Pagination settings — support both old and new config shapes
        $pagination_type = $shortcode_config['pagination_type'] ?? null;
        if (!$pagination_type && !empty($shortcode_config['paginationEnabled'])) {
            $pagination_type = $shortcode_config['paginationStyle'] ?? $view_config['pagination_type'] ?? 'numbers';
        }
        $resolved['pagination_type'] = $pagination_type ?? $view_config['pagination_type'] ?? 'none';

        $items_per_page = $shortcode_config['items_per_page'] ?? $shortcode_config['perPage'] ?? null;
        $resolved['items_per_page'] = (int) ($items_per_page ?? $view_config['items_per_page'] ?? 12);

        // Dark mode — support tri-state string ('disabled'|'enabled'|'auto') or legacy boolean
        $dark_mode = $shortcode_config['darkMode'] ?? $shortcode_config['dark_mode'] ?? 'disabled';
        if ($dark_mode === true || $dark_mode === 'enabled') {
            $resolved['dark_mode'] = 'enabled';
        } elseif ($dark_mode === 'auto') {
            $resolved['dark_mode'] = 'auto';
        } else {
            $resolved['dark_mode'] = 'disabled';
        }

        // Schema
        $resolved['schema'] = $shortcode_config['schema'] ?? true;

        return $resolved;
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
    private function resolve_dark_mode_state($value): string {
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

    /**
     * Locate the template file for a given layout style.
     */
    private function locate_template(string $layout_style): ?string {
        $template_name = sanitize_file_name($layout_style) . '.php';

        // Check theme override first
        $theme_template = get_stylesheet_directory() . '/plugixa-recipe/' . $template_name;
        if (file_exists($theme_template)) {
            return $theme_template;
        }

        // Plugin template
        $plugin_template = PLUGIXA_RECIPE_PATH . 'templates/' . $template_name;
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }

        // Fallback to grid
        $fallback = PLUGIXA_RECIPE_PATH . 'templates/grid.php';
        return file_exists($fallback) ? $fallback : null;
    }

    /**
     * Render pagination controls (load_more or infinite_scroll).
     * "numbers" style is handled by the grid/list/masonry template directly.
     */
    private function render_pagination(array $data): void {
        $style = $data['pagination_style'] ?? 'load_more';
        $config_key = $data['config_key'] ?? '';
        $label = $data['config']['loadMoreLabel'] ?? __('Load More Recipes', 'plugixa-recipe');

        if ($style === 'infinite_scroll') {
            ?>
            <div class="pxr-pagination pxr-pagination--infinite" data-config-key="<?php echo esc_attr($config_key); ?>" data-style="infinite_scroll">
                <div class="pxr-infinite-sentinel" data-page="2" data-nonce="<?php echo esc_attr(wp_create_nonce('plugixa_recipe_frontend')); ?>"></div>
                <span class="pxr-loading-spinner" style="display:none;">
                    <?php esc_html_e('Loading...', 'plugixa-recipe'); ?>
                </span>
            </div>
            <?php
        } else {
            // load_more (default for non-numbers)
            ?>
            <div class="pxr-pagination pxr-pagination--load-more" data-config-key="<?php echo esc_attr($config_key); ?>" data-style="load_more">
                <button type="button" class="pxr-load-more" data-page="2" data-nonce="<?php echo esc_attr(wp_create_nonce('plugixa_recipe_frontend')); ?>">
                    <?php echo esc_html($label); ?>
                </button>
                <span class="pxr-loading-spinner" style="display:none;">
                    <?php esc_html_e('Loading...', 'plugixa-recipe'); ?>
                </span>
            </div>
            <?php
        }
    }

    /**
     * Enqueue frontend CSS and JS conditionally.
     */
    private function enqueue_frontend_assets(string $layout_style = 'grid'): void {
        // Layout-specific extras are evaluated on EVERY call: a slider or
        // submission form can appear further down the page after another
        // shortcode already enqueued the common assets, and the early-return
        // below would otherwise skip them (wp_enqueue_* dedupes by handle,
        // so repeated calls are harmless).

        // Recipe submission form JS
        if ($layout_style === 'form') {
            $form_js = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipe-form.js';
            if (file_exists($form_js)) {
                wp_enqueue_script(
                    'plugixa-recipe-form',
                    PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipe-form.js',
                    [],
                    PLUGIXA_RECIPE_VERSION,
                    true
                );
            }
        }

        // Swiper for slider layout
        if ($layout_style === 'slider') {
            $swiper_css = PLUGIXA_RECIPE_PATH . 'assets/frontend/vendor/swiper/swiper-bundle.min.css';
            $swiper_js = PLUGIXA_RECIPE_PATH . 'assets/frontend/vendor/swiper/swiper-bundle.min.js';

            if (file_exists($swiper_css)) {
                wp_enqueue_style(
                    'swiper',
                    PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/swiper/swiper-bundle.min.css',
                    [],
                    '12.1.2'
                );
            }

            if (file_exists($swiper_js)) {
                wp_enqueue_script(
                    'swiper',
                    PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/swiper/swiper-bundle.min.js',
                    [],
                    '12.1.2',
                    true
                );
            }
        }

        if ($this->assets_enqueued) {
            return;
        }

        // Frontend CSS
        $css_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Frontend JS
        $js_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipes.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipes.js',
                $layout_style === 'slider' ? ['swiper'] : [],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            wp_localize_script('plugixa-recipe-frontend', 'plugixaRecipeConfig', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'restUrl'    => esc_url_raw(rest_url('plugixa-recipe/v1/')),
                'nonce'      => wp_create_nonce('plugixa_recipe_frontend'),
                'restNonce'  => wp_create_nonce('wp_rest'),
                'isLoggedIn' => is_user_logged_in(),
                'loginUrl'   => wp_login_url(get_permalink()),
                'i18n'       => [
                    'loadMore'  => __('Load More Recipes', 'plugixa-recipe'),
                    'loading'   => __('Loading...', 'plugixa-recipe'),
                    'noMore'    => __('No more recipes to load.', 'plugixa-recipe'),
                    'error'     => __('An error occurred while loading recipes.', 'plugixa-recipe'),
                    'tooltip'   => [
                        'like'       => __('Like this recipe', 'plugixa-recipe'),
                        'likeActive' => __('Unlike this recipe', 'plugixa-recipe'),
                        'save'       => __('Save to favorites', 'plugixa-recipe'),
                        'saveActive' => __('Remove from saved', 'plugixa-recipe'),
                        'loginLike'  => __('Log in to like this recipe', 'plugixa-recipe'),
                        'loginSave'  => __('Log in to save this recipe', 'plugixa-recipe'),
                        'loginLink'  => __('Log in', 'plugixa-recipe'),
                    ],
                ],
            ]);
        }

        // Auto dark mode: toggle pxr-dark class based on OS preference
        wp_add_inline_script('plugixa-recipe-frontend', '(function(){var e=document.querySelectorAll("[data-pxr-dark-mode=auto]");if(!e.length)return;var m=window.matchMedia("(prefers-color-scheme:dark)");function t(q){e.forEach(function(el){el.classList.toggle("pxr-dark",q.matches)})}t(m);m.addEventListener("change",t)})();');

        $this->assets_enqueued = true;
    }

    /**
     * Get plugin settings.
     */
    private function get_settings(): array {
        $defaults = [
            'show_nutrition'       => true,
            'show_reviews'         => true,
            'enable_print'         => true,
            'enable_favorites'     => true,
            'enable_submissions'   => false,
            'excerpt_length'       => 150,
            'date_format'          => 'M j, Y',
            'enable_collections'   => true,
            'enable_nutrition'     => true,
            'enable_unit_conversion' => true,
            'measurement_system'   => 'metric',
            'enable_lazy_load'     => true,
            'enable_cache'         => false,
            'cache_duration'       => 24,
            'show_breadcrumbs'     => true,
        ];

        return array_merge($defaults, (array) get_option('plugixa_recipe_settings', []));
    }

    /**
     * Check if the shortcode has been used on the current page.
     */
    public static function has_shortcode(): bool {
        return self::$has_shortcode;
    }
}
