<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Shortcodes\Frontend;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Frontend\Assets;
use PlugixaRecipe\Modules\Recipes\RenderedRecipes;
use PlugixaRecipe\Modules\Shortcodes\Repositories\ListingRepository;
use PlugixaRecipe\Modules\Views\Services\CssVars;
use PlugixaRecipe\Modules\Views\Services\ViewConfig;

/**
 * [plugixa_recipe] / [plugixa_recipes] - a recipe listing in a grid, list, masonry or slider.
 *
 * Also what the Gutenberg block, the Elementor widget and the WooCommerce product tab render
 * through. The view's design is read by Views\Services\ViewConfig and turned into inline
 * custom properties by Views\Services\CssVars; this class owns the filters, the template and
 * the "load more" endpoint. Every read goes through Repositories\ListingRepository.
 *
 * A listing is public and is expected to sit in a page cache, so the page has to keep
 * working for as long as a cache keeps it. Two things on it used to expire first:
 *
 * - its config. "Load more", the page numbers and infinite scroll repeat the page's own
 *   query, and the config for that was parked in a transient for a day, with only its key
 *   on the page. A page older than a day - or any page on a site whose object cache had
 *   evicted the entry - answered "Configuration expired". The config now travels WITH the
 *   page, signed (seal_config() / open_config()), so there is no server state to lose.
 * - its nonce, which lives 12 to 24 hours. The script asks for a current one and retries
 *   once when the page's own is refused (ajax_fresh_nonce()).
 */
class ListingShortcode {

    private ViewConfig $views;

    private CssVars $css;

    private ListingRepository $recipes;

    /**
     * Attachment size used for recipe card images, taken from the active view config.
     *
     * Declared rather than assigned dynamically: PHP 8.2 deprecates creating an undeclared
     * property (it logs on every render with WP_DEBUG on) and PHP 9 removes it outright.
     *
     * @var string
     */
    private string $image_size = 'medium_large';

    public function __construct() {
        $this->views = new ViewConfig();
        $this->css   = new CssVars();

        $this->recipes = new ListingRepository();
    }

    /**
     * Register the listing shortcode and its pagination endpoint.
     */
    public function register(): void {
        add_shortcode('plugixa_recipe', [$this, 'render_recipes']);
        add_shortcode('plugixa_recipes', [$this, 'render_recipes']); // backward compat

        // AJAX pagination handler
        add_action('wp_ajax_plugixa_recipe_load_recipes', [$this, 'ajax_load_recipes']);
        add_action('wp_ajax_nopriv_plugixa_recipe_load_recipes', [$this, 'ajax_load_recipes']);

        // A current nonce for that handler, for a page that outlived its own.
        add_action('wp_ajax_plugixa_recipe_fresh_nonce', [$this, 'ajax_fresh_nonce']);
        add_action('wp_ajax_nopriv_plugixa_recipe_fresh_nonce', [$this, 'ajax_fresh_nonce']);
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
        $config = self::with_builder_sort(array_merge($shortcode_config, $explicit_atts), $explicit_atts);
        $config = self::with_builder_source($config, $explicit_atts);
        $config = $this->with_visitor_search($config);

        // Load view config — check both inline 'view' attribute and DB 'view_id'
        $view_identifier = $config['view'] ?? '';
        if (empty($view_identifier) && !empty($config['view_id'])) {
            $view_identifier = $config['view_id'];
        }
        $view_config = $this->views->get_view_config($view_identifier);

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
        // PlugixaRecipe\Modules\Recipes\RenderedRecipes.
        RenderedRecipes::add($recipes);

        // Resolve display settings: shortcode config > view config > defaults
        $display_settings = $this->resolve_display_settings($config, $view_config);
        $view_config = array_merge($view_config, $display_settings);

        // Bulk-load ingredient names for ingredient filter (single query, not N+1)
        $show_ingredient_filter = !empty($display_settings['show_ingredient_filter']);
        if ($show_ingredient_filter) {
            $recipe_ids = array_map(function ($r) { return (int) $r->id; }, $recipes);
            if (!empty($recipe_ids)) {
                $ingredient_rows = $this->recipes->ingredient_names($recipe_ids);
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
        Assets::listing($layout_style);

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
            $dark_mode_state = $this->views->resolve_dark_mode_state($sc_dark);
        }
        if ($dark_mode_state === 'disabled' && !empty($view_config['dark_mode'])) {
            $dark_mode_state = $this->views->resolve_dark_mode_state($view_config['dark_mode']);
        }
        if ($dark_mode_state === 'disabled' && !empty($view_config['darkMode']['enabled'])) {
            $dark_mode_state = 'enabled';
        }
        $dark_mode = ($dark_mode_state === 'enabled');
        $dark_mode_auto = ($dark_mode_state === 'auto');

        // Build CSS variables (dark mode state determines which colors to use)
        $css_vars = $this->css->build_css_vars($view_config, $config, $dark_mode_state);
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

        // The config goes to the page signed, and comes back with every "load more". Still
        // called the config key: the templates print it as data-config-key and a theme's
        // copy of one keeps working.
        $config_key = $this->seal_config($shortcode_config);

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

        // Pagination — "numbers" is handled by grid.php template, others rendered here.
        // Only when the first page came back full: a short page is the last one, and a
        // "Load more" under three recipes fetched nothing and then vanished. The same rule
        // the AJAX handler answers `has_more` with.
        if ($pagination_enabled && $pagination_style !== 'numbers' && $per_page > 0 && count($recipes) >= $per_page) {
            $this->render_pagination($data);
        }

        echo '</div>';

        return ob_get_clean();
    }

    /** What the config signature is bound to, so it cannot be replayed as anything else. */
    private const CONFIG_PURPOSE = 'plugixa_recipe_listing_config|';

    /**
     * Sign a listing's config so the page can carry it.
     *
     * The visitor sees the config and cannot change it: open_config() takes back only what
     * this signed. That is the property the transient gave ("no client-side config
     * accepted"), without the transient - a visitor can still only ask for another page of
     * the list the site owner configured.
     *
     * @param array<string, mixed> $config
     * @return string `<json as hex>.<hmac-sha256 hex>` - hex, not base64: the config is
     *                meant to be readable, and base64 in a plugin reads as obfuscation.
     */
    private function seal_config(array $config): string {
        $json = (string) wp_json_encode($config);

        return bin2hex($json) . '.' . hash_hmac('sha256', self::CONFIG_PURPOSE . $json, wp_salt('nonce'));
    }

    /**
     * Read a config back from what the page sent.
     *
     * @return array<string, mixed>|null Null when it is not one this site signed.
     */
    private function open_config(string $token): ?array {
        // A page rendered before 1.0.1, still in a cache, carries the KEY of a transient
        // that lives for a day. Honoured until those are gone; nothing writes one any more.
        if (preg_match('/^plugixa_recipe_pgn_[a-f0-9]{32}$/', $token)) {
            $config = get_transient($token);

            return is_array($config) ? $config : null;
        }

        if (!preg_match('/^((?:[a-f0-9]{2})+)\.([a-f0-9]{64})$/', $token, $parts)) {
            return null;
        }

        $json = hex2bin($parts[1]);
        if (false === $json || !hash_equals(hash_hmac('sha256', self::CONFIG_PURPOSE . $json, wp_salt('nonce')), $parts[2])) {
            return null;
        }

        $config = json_decode($json, true);

        return is_array($config) ? $config : null;
    }

    /**
     * A current nonce for ajax_load_recipes().
     *
     * A page cache serves a listing for days and a nonce lives 12 to 24 hours, so the one
     * printed on the page stops verifying while the page is still being served. The script
     * calls this when its request comes back 403, and retries once with the answer.
     *
     * It reads nothing from the request, so there is nothing here for a nonce to protect,
     * and it gives out nothing a visitor could not get by loading any page with a listing
     * on it. admin-ajax.php sends no-cache headers for every response.
     */
    public function ajax_fresh_nonce(): void {
        wp_send_json_success(['nonce' => wp_create_nonce('plugixa_recipe_frontend')]);
    }

    /**
     * AJAX handler for paginated recipe loading.
     */
    public function ajax_load_recipes(): void {
        check_ajax_referer('plugixa_recipe_frontend', 'nonce');

        $page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;

        // The config is the one this site signed into the page; nothing else is accepted.
        $config_key = isset($_POST['config_key']) ? sanitize_text_field(wp_unslash($_POST['config_key'])) : '';
        $config     = '' === $config_key ? null : $this->open_config($config_key);
        if (null === $config) {
            wp_send_json_error(['message' => __('This list could not be loaded. Please reload the page.', 'plugixa-recipe')]);
        }

        $per_page = (int) ($config['perPage'] ?? $config['per_page'] ?? 12);
        $config['limit'] = $per_page;
        $config['offset'] = ($page - 1) * $per_page;

        $recipes = $this->query_recipes($config);
        $view_config = $this->views->get_view_config($config['view'] ?? '');
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
            $allowed = ['categories', 'cuisines', 'courses'];
            if (!in_array($taxonomy, $allowed, true)) {
                return array_values(array_unique(array_filter($slugs)));
            }

            // $taxonomy is one of the three names allow-listed above; the repository reads
            // that table and caches the result under that entity.
            $found = $this->recipes->slugs_for_ids($taxonomy, $ids);

            if (!empty($found)) {
                $slugs = array_merge($slugs, $found);
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    private function query_recipes(array $config): array {
        // Every optional filter reaches the repository as a value plus an on/off gate; how
        // the gates keep the SQL a constant literal is documented on
        // ListingRepository::published(). Slugs are sanitize_title() output, so they can
        // never contain the comma the query splits a slug list on.
        if (!empty($config['no_results'])) {
            return [];
        }

        $featured = filter_var($config['featured'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $search   = isset($config['search']) ? (string) $config['search'] : '';

        $min_rating = !empty($config['min_rating']) ? (float) $config['min_rating'] : 0.0;
        $difficulty = !empty($config['difficulty']) ? sanitize_text_field($config['difficulty']) : '';

        // Taxonomy filters. The value may arrive as a comma-separated string (hand-written
        // shortcode attribute) or as an array of IDs (saved shortcode from the admin
        // builder's multi-select), so it is normalised to slugs before it reaches SQL.
        // See resolve_taxonomy_slugs().
        $cat_slugs     = $this->resolve_taxonomy_slugs('categories', $config['categories'] ?? null);
        $cuisine_slugs = $this->resolve_taxonomy_slugs('cuisines', $config['cuisines'] ?? null);
        $course_slugs  = $this->resolve_taxonomy_slugs('courses', $config['courses'] ?? null);

        // A filter that names only terms which no longer exist matches nothing. With no
        // slugs its gate would bind 0 and the list would widen to every recipe.
        foreach (['categories' => $cat_slugs, 'cuisines' => $cuisine_slugs, 'courses' => $course_slugs] as $taxonomy => $slugs) {
            if (!empty($config[$taxonomy]) && !$slugs) {
                return [];
            }
        }

        // Language filter
        $multilingual = \PlugixaRecipe\Modules\Multilingual\Services\Multilingual::instance();
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

        $results = $this->recipes->published(
            $featured,
            $min_rating,
            $difficulty,
            $lang,
            $cat_slugs,
            $cuisine_slugs,
            $course_slugs,
            $orderby,
            $order,
            $limit,
            $offset,
            $search,
            max(0, (int) ($config['max_time'] ?? 0)),
            sanitize_key((string) ($config['diet'] ?? ''))
        );

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
        $recipe['categories'] = $this->recipes->card_categories($recipe_id);

        // Cuisines — child rows cached under the owning recipe.
        $recipe['cuisines'] = $this->recipes->card_cuisines($recipe_id);

        // Nutrition (single row) — child row cached under the owning recipe.
        $recipe['nutrition'] = $this->recipes->card_nutrition($recipe_id);

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
            $recipe['is_liked'] = $this->recipes->has_liked($current_user_id, $recipe_id);
            $recipe['is_favorited'] = $this->recipes->has_favorited($current_user_id, $recipe_id);
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
        $row = $this->recipes->saved_config($id);

        if (!$row || empty($row->config)) {
            return [];
        }

        $config = json_decode($row->config, true);
        return is_array($config) ? $config : [];
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
     * Locate the template file for a given layout style.
     */
    /**
     * Apply what a visitor asked for through [plugixa_recipe_search].
     *
     * That widget is a GET form: words, a category, a cuisine, a course, a difficulty, a
     * longest "ready in" and a diet (VisitorFilters reads and narrows all seven). A
     * listing narrows itself to them, on whichever page the form points at.
     *
     * The values are folded into the config rather than read at query time, because the
     * config is what the "load more" / pagination request gets back from its transient:
     * page 2 of a search has to be page 2 of the same search.
     *
     * A visitor can only NARROW a listing. A category outside the ones the shortcode was
     * limited to returns nothing rather than widening it.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function with_visitor_search(array $config): array {
        $asked = VisitorFilters::read();

        if ('' !== $asked['search']) {
            $config['search'] = $asked['search'];
        }

        foreach (['categories' => $asked['category'], 'cuisines' => $asked['cuisine'], 'courses' => $asked['course']] as $taxonomy => $slug) {
            if ('' === $slug) {
                continue;
            }
            $limited_to = $this->resolve_taxonomy_slugs($taxonomy, $config[$taxonomy] ?? null);
            if ($limited_to && !in_array($slug, $limited_to, true)) {
                $config['no_results'] = true;
                continue;
            }
            $config[$taxonomy] = $slug;
        }

        // A listing already limited to one difficulty cannot be asked for another.
        if ('' !== $asked['difficulty']) {
            $own = sanitize_key((string) ($config['difficulty'] ?? ''));
            if ('' !== $own && $own !== $asked['difficulty']) {
                $config['no_results'] = true;
            }
            $config['difficulty'] = $asked['difficulty'];
        }

        // The listing has no time or diet limit of its own, so these two only ever narrow.
        if ($asked['max_time'] > 0) {
            $config['max_time'] = $asked['max_time'];
        }
        if ('' !== $asked['diet']) {
            $config['diet'] = $asked['diet'];
        }

        return $config;
    }

    /**
     * Translate the shortcode builder's sort fields into the ones the query reads.
     *
     * The builder stores `sort_by` (date, title, rating, popular) and `sort_order`; the query
     * reads `orderby` and `order`. Nothing connected the two, so a saved shortcode always
     * came out newest-first whatever its builder said. An `orderby` / `order` written on the
     * shortcode tag itself still wins.
     *
     * @param array<string, mixed> $config   The saved config with the tag's attributes merged in.
     * @param array<string, mixed> $explicit The attributes the tag actually set.
     * @return array<string, mixed>
     */
    private static function with_builder_sort(array $config, array $explicit): array {
        $columns = [
            'date'    => 'created_at',
            'title'   => 'title',
            'rating'  => 'rating_average',
            'popular' => 'view_count',
        ];

        $sort_by = (string) ($config['sort_by'] ?? '');
        if (!isset($explicit['orderby']) && isset($columns[$sort_by])) {
            $config['orderby'] = $columns[$sort_by];
        }
        if (!isset($explicit['order']) && !empty($config['sort_order'])) {
            $config['order'] = strtoupper((string) $config['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
        }

        return $config;
    }

    /**
     * Translate the shortcode builder's query fields into the ones the query reads.
     *
     * The builder stores `source` (all, category, cuisine, course) with the ids picked for
     * it in `category_ids` / `cuisine_ids` / `course_ids`, and `featured_only`; the query
     * reads `categories` / `cuisines` / `courses` and `featured`. Nothing connected the two,
     * so a saved shortcode listed every recipe whatever its Source said. Only the ids of the
     * chosen source apply: the builder hides the other pickers, and what they still hold is
     * left over from an earlier choice. An attribute written on the tag itself still wins.
     *
     * @param array<string, mixed> $config   The saved config with the tag's attributes merged in.
     * @param array<string, mixed> $explicit The attributes the tag actually set.
     * @return array<string, mixed>
     */
    private static function with_builder_source(array $config, array $explicit): array {
        $sources = [
            'category' => ['category_ids', 'categories'],
            'cuisine'  => ['cuisine_ids', 'cuisines'],
            'course'   => ['course_ids', 'courses'],
        ];

        $source = (string) ($config['source'] ?? '');
        if (isset($sources[$source])) {
            [$ids_key, $filter] = $sources[$source];
            $ids = array_values(array_filter(array_map('absint', (array) ($config[$ids_key] ?? []))));
            if (!isset($explicit[$filter]) && $ids) {
                $config[$filter] = $ids;
            }
        }

        if (!isset($explicit['featured']) && !empty($config['featured_only'])) {
            $config['featured'] = 'true';
        }

        return $config;
    }

    private function locate_template(string $layout_style): ?string {
        $template_name = sanitize_file_name($layout_style) . '.php';

        // Check theme override first
        $theme_template = get_stylesheet_directory() . '/plugixa-recipe/' . $template_name;
        if (file_exists($theme_template)) {
            return $theme_template;
        }

        // Plugin template
        $plugin_template = plugixa_recipe_template($template_name);
        if ('' !== $plugin_template) {
            return $plugin_template;
        }

        // Fallback to grid
        $fallback = plugixa_recipe_template('grid.php');
        return '' !== $fallback ? $fallback : null;
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
     * Get plugin settings.
     */
    private function get_settings(): array {
        $defaults = [
            'show_nutrition'       => true,
            'show_reviews'         => true,
            'enable_print'         => true,
            'excerpt_length'       => 150,
            'date_format'          => 'M j, Y',
            'measurement_system'   => 'metric',
            'enable_lazy_load'     => true,
        ];

        return array_merge($defaults, (array) get_option('plugixa_recipe_settings', []));
    }
}
