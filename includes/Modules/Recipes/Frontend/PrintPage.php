<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Modules\Recipes\Services\RecipePresenter;

/**
 * Print Class - Handles print-friendly recipe output.
 *
 * Registers a rewrite rule for /recipe-print/{id} that outputs a clean,
 * ad-free HTML page suitable for printing with recipe details. Every query it
 * shows comes from Services\RecipePresenter - the struct the recipe's own page draws.
 */
class PrintPage {

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
        // The presenter alone: printing a recipe is not another visit to it.
        $recipe = (new RecipePresenter())->recipe($recipe_id);

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

        /**
         * Filters the script handles the print view's own script depends on. They are
         * printed with it: the print view prints by handle, never the whole queue.
         *
         * @since 1.2.0
         *
         * @param string[] $handles Registered script handles.
         */
        $dependencies = array_values(array_filter(
            (array) apply_filters('plugixa_recipe_print_script_dependencies', []),
            static fn($handle): bool => is_string($handle) && wp_script_is($handle, 'registered')
        ));

        wp_enqueue_script(
            'plugixa-recipe-print',
            PLUGIXA_RECIPE_URL . 'assets/frontend/js/print.js',
            $dependencies,
            PLUGIXA_RECIPE_VERSION,
            true
        );
    }
}
