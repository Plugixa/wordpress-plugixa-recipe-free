<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Frontend;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the print view: one page render, six small keyed reads, deliberately uncached (the print URL is not a hot path). Every query is a constant literal with table names bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

defined( 'ABSPATH' ) || exit;


/**
 * Print Class - Handles print-friendly recipe output.
 *
 * Registers a rewrite rule for /recipe-print/{id} that outputs a clean,
 * ad-free HTML page suitable for printing with recipe details.
 */
class RecipePrint {

    /**
     * Initialize print route hooks.
     */
    public function init(): void {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'handle_print_request']);
    }

    /**
     * Register the print rewrite rule.
     */
    public function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^recipe-print/(\d+)/?$',
            'index.php?plugixa_recipe_print=$matches[1]',
            'top'
        );
    }

    /**
     * Register custom query variables.
     */
    public function register_query_vars(array $vars): array {
        $vars[] = 'plugixa_recipe_print';
        return $vars;
    }

    /**
     * Handle the print request and output print-friendly HTML.
     */
    public function handle_print_request(): void {
        $recipe_id = get_query_var('plugixa_recipe_print');
        if (empty($recipe_id)) {
            return;
        }

        $recipe_id = (int) $recipe_id;
        $recipe = $this->get_recipe($recipe_id);

        if (!$recipe) {
            wp_die(
                esc_html__('Recipe not found.', 'plugixa-recipe'),
                esc_html__('Recipe Not Found', 'plugixa-recipe'),
                ['response' => 404]
            );
        }

        // Check if printing is enabled
        $settings = (array) get_option('plugixa_recipe_settings', []);
        if (isset($settings['enable_print']) && !$settings['enable_print']) {
            wp_die(
                esc_html__('Printing is not available for this recipe.', 'plugixa-recipe'),
                esc_html__('Print Disabled', 'plugixa-recipe'),
                ['response' => 403]
            );
        }

        // The print view is a standalone document: it is served from template_redirect and never
        // reaches the theme, so it calls neither wp_head() nor wp_footer(). Its CSS and JS are still
        // registered through the enqueue system and printed by handle alone (see the
        // wp_print_styles()/wp_print_scripts() calls in templates/print.php), which keeps the assets
        // versioned, filterable and dequeuable without dragging the whole theme into a print page.
        $this->register_print_assets();

        $template = PLUGIXA_RECIPE_PATH . 'templates/print.php';
        $data     = $this->prepare_print_data($recipe);
        unset($recipe);
        extract($data, EXTR_SKIP);
        include $template;
        exit;
    }

    /**
     * Register and enqueue the print view's stylesheet and script.
     *
     * Runs on template_redirect, i.e. before wp_enqueue_scripts, so the queue holds only what is
     * registered here. templates/print.php then prints this one handle rather than the whole queue.
     */
    private function register_print_assets(): void {
        wp_enqueue_style(
            'plugixa-recipe-print',
            PLUGIXA_RECIPE_URL . 'assets/frontend/css/print.css',
            [],
            PLUGIXA_RECIPE_VERSION
        );

        wp_enqueue_script(
            'plugixa-recipe-print',
            PLUGIXA_RECIPE_URL . 'assets/frontend/js/print.js',
            [],
            PLUGIXA_RECIPE_VERSION,
            true
        );
    }

    /**
     * Prepare data for the print template.
     *
     * Enriches the raw DB array with computed fields (image_url, permalink,
     * grouped ingredients/instructions, nutrition, cuisine) and casts to
     * object so templates can use $recipe->title syntax.
     */
    private function prepare_print_data(array $recipe): array {
        $recipe_id = (int) $recipe['id'];

        // Image URL from attachment ID.
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium_large') ?: '';
        } else {
            $recipe['image_url'] = '';
        }

        // Permalink.
        $slug = sanitize_title($recipe['title'] ?? '');
        $recipe['permalink'] = plugixa_recipe_permalink((int) $recipe_id, $slug);

        // Grouped ingredients & instructions.
        $recipe['ingredient_groups']  = $this->group_ingredients($this->get_ingredients($recipe_id));
        $recipe['instruction_groups'] = $this->group_instructions($this->get_instructions($recipe_id));

        // Nutrition (cast to object for $nutrition->calories syntax).
        $nutrition = $this->get_nutrition($recipe_id);
        $recipe['nutrition'] = $nutrition ? (object) $nutrition : null;

        // Cuisine name from first assigned cuisine.
        $cuisines = $this->get_cuisines($recipe_id);
        $recipe['cuisine'] = !empty($cuisines) ? $cuisines[0]['name'] : '';

        return [
            'recipe'    => (object) $recipe,
            'site_name' => get_bloginfo('name'),
            'site_url'  => home_url(),
        ];
    }

    /**
     * Group ingredient rows by group_name, mapping DB columns to template fields.
     */
    private function group_ingredients(array $rows): array {
        $groups  = [];
        $current = null;

        foreach ($rows as $row) {
            $name = $row['group_name'] ?? '';
            if ($name !== $current) {
                $current  = $name;
                $groups[] = (object) ['name' => $current, 'items' => []];
            }
            $row['text']   = $row['name'] ?? '';
            $row['amount'] = $row['quantity'] ?? '';
            $groups[count($groups) - 1]->items[] = (object) $row;
        }

        return $groups;
    }

    /**
     * Group instruction rows by group_name, mapping DB columns to template fields.
     */
    private function group_instructions(array $rows): array {
        $groups  = [];
        $current = null;

        foreach ($rows as $row) {
            $name = $row['group_name'] ?? '';
            if ($name !== $current) {
                $current  = $name;
                $groups[] = (object) ['name' => $current, 'steps' => []];
            }
            $row['text']      = $row['instruction_text'] ?? '';
            $row['image_url'] = !empty($row['image_id'])
                ? wp_get_attachment_image_url((int) $row['image_id'], 'medium')
                : '';
            $groups[count($groups) - 1]->steps[] = (object) $row;
        }

        return $groups;
    }
    /**
     * Get a single published recipe by ID.
     */
    private function get_recipe(int $id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_recipes';

        // Column list = Columns::recipe_public(''), written out so the string handed to
        // prepare() is a constant literal. RestSecurityTest asserts the two stay in step.
        $recipe = $wpdb->get_row($wpdb->prepare(
            'SELECT id, language_code, translation_group, title, slug,
                description, excerpt, notes, tips, image_id,
                gallery, tags, equipment, video_url, servings,
                servings_unit, yield_amount, yield_unit, prep_time, cook_time,
                rest_time, total_time, difficulty, cost, season,
                is_featured, is_editor_pick, display_order, source, source_url,
                author_name, view_count, rating_average, rating_count, like_count,
                is_vegetarian, is_vegan, is_gluten_free, is_dairy_free, is_keto,
                is_low_carb, is_nut_free, is_paleo, is_sugar_free, is_halal,
                is_kosher, published_at, created_at
              FROM %i
              WHERE id = %d AND status = \'published\'',
            $table,
            $id
        ), ARRAY_A);

        return $recipe ?: null;
    }

    /**
     * Get recipe ingredients.
     */
    private function get_ingredients(int $recipe_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_ingredients';

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, group_name, group_order, sort_order,
                quantity, unit, name, notes, link_url
              FROM %i
              WHERE recipe_id = %d
              ORDER BY group_order ASC, sort_order ASC',
            $table,
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recipe instructions.
     */
    private function get_instructions(int $recipe_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_instructions';

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, recipe_id, group_name, group_order, sort_order,
                title, instruction_text, image_id, video_url, duration
              FROM %i
              WHERE recipe_id = %d
              ORDER BY group_order ASC, sort_order ASC',
            $table,
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recipe nutrition data.
     */
    private function get_nutrition(int $recipe_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_nutrition';

        $result = $wpdb->get_row($wpdb->prepare(
            'SELECT id, recipe_id, serving_size, calories, total_fat,
                saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate,
                dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c,
                calcium, iron, potassium, source
              FROM %i
              WHERE recipe_id = %d',
            $table,
            $recipe_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Get recipe categories.
     */
    private function get_categories(int $recipe_id): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.name, c.slug FROM %i c
             INNER JOIN %i rc ON c.id = rc.category_id
             WHERE rc.recipe_id = %d',
            $prefix . 'categories',
            $prefix . 'recipe_categories',
            $recipe_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recipe cuisines.
     */
    private function get_cuisines(int $recipe_id): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        return $wpdb->get_results($wpdb->prepare(
            'SELECT cu.name, cu.slug FROM %i cu
             INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
             WHERE rcu.recipe_id = %d',
            $prefix . 'cuisines',
            $prefix . 'recipe_cuisines',
            $recipe_id
        ), ARRAY_A) ?: [];
    }
}
