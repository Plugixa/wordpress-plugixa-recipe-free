<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Modules\Recipes\Repositories\PrintPageRepository;

/**
 * Print Class - Handles print-friendly recipe output.
 *
 * Registers a rewrite rule for /recipe-print/{id} that outputs a clean,
 * ad-free HTML page suitable for printing with recipe details. Every query it
 * needs lives in PrintPageRepository.
 */
class PrintPage {

    /** @var PrintPageRepository */
    private $repository;

    public function __construct() {
        $this->repository = new PrintPageRepository();
    }

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
        $recipe = $this->repository->find_published($recipe_id);

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

        $template = plugixa_recipe_template( 'print.php' );
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
        $recipe['ingredient_groups']  = $this->group_ingredients($this->repository->ingredients($recipe_id));
        $recipe['instruction_groups'] = $this->group_instructions($this->repository->instructions($recipe_id));

        // Nutrition (cast to object for $nutrition->calories syntax).
        $nutrition = $this->repository->nutrition($recipe_id);
        $recipe['nutrition'] = $nutrition ? (object) $nutrition : null;

        // Cuisine name from first assigned cuisine.
        $cuisines = $this->repository->cuisines($recipe_id);
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
}
