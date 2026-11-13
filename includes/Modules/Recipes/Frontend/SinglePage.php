<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $plugixa_recipe_single_recipe_data is the documented hand-off global read by templates/single-recipe.php. "pxr" is the plugin's established public prefix (CSS classes, --pxr-* custom properties, plugixa_recipe_* transients) and is unique to it; the sniff only objects because it is under its 4-character minimum.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Modules\Recipes\RenderedRecipes;
use PlugixaRecipe\Modules\Recipes\Repositories\SinglePageRepository;
use PlugixaRecipe\Modules\Views\Services\CssVars;

/**
 * SinglePage Class - Handles the single recipe frontend page.
 *
 * Registers a rewrite rule for /recipe/{id}/{slug} that renders a full
 * single recipe page using the theme's header/footer and templates/single-recipe.php.
 * Every query it needs lives in SinglePageRepository.
 */
class SinglePage {

    /** @var SinglePageRepository */
    private $repository;

    /** @var SingleView */
    private $view;

    public function __construct() {
        $this->repository = new SinglePageRepository();
        $this->view       = new SingleView($this->repository);
    }

    /**
     * Initialize single recipe page hooks.
     */
    public function init(): void {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('template_include', [$this, 'handle_single_recipe']);
    }

    /**
     * Register the single recipe rewrite rule.
     */
    public function register_rewrite_rules(): void {
        // The base is configurable (Settings > Display). ArchivePage owns the flush:
        // its stored signature includes this slug, so changing the setting re-flushes
        // every rule, this one included.
        add_rewrite_rule(
            '^' . preg_quote( plugixa_recipe_url_slug( 'recipe' ), '#' ) . '/(\d+)(?:/([^/]+))?/?$',
            'index.php?plugixa_recipe_id=$matches[1]',
            'top'
        );
    }

    /**
     * Register custom query variables.
     */
    public function register_query_vars(array $vars): array {
        $vars[] = 'plugixa_recipe_id';
        return $vars;
    }

    /**
     * Handle the single recipe page via template_include.
     */
    public function handle_single_recipe(string $template): string {
        $recipe_id = get_query_var('plugixa_recipe_id');
        if (empty($recipe_id)) {
            return $template;
        }

        $recipe_id = (int) $recipe_id;
        $recipe = $this->view->recipe($recipe_id);

        if (!$recipe) {
            return Templates::not_found($template);
        }

        // The page's subject is this one recipe: register it so the JSON-LD builder
        // describes it. Before the registry existed the builder gated on "was a shortcode
        // used", which is false here, so single-recipe pages carried no structured data at
        // all while unrelated pages carried too much.
        RenderedRecipes::add([$recipe_id], true);

        // Prevent 404
        global $wp_query;
        $wp_query->is_404 = false;
        status_header(200);

        // Set page title
        add_filter('document_title_parts', function ($parts) use ($recipe) {
            $parts['title'] = $recipe->title;
            return $parts;
        });

        // Fix body classes for block theme compatibility.
        add_filter( 'body_class', function ( $classes ) {
            $classes = array_diff( $classes, [ 'home', 'blog' ] );
            $classes[] = 'wp-singular';
            $classes[] = 'pxr-single-recipe';
            return $classes;
        } );

        $this->view->enqueue($recipe);

        // Build template data
        $view_config = $this->view->config();
        $css_vars_style = (new CssVars())->build_css_vars($view_config);
        $unique_id = 'pxr-single-' . $recipe_id;

        // Use a wrapper template that calls get_header/get_footer
        $GLOBALS['plugixa_recipe_single_recipe_data'] = [
            'recipe'        => $recipe,
            'view_config'   => $view_config,
            'css_vars_style' => $css_vars_style,
            'unique_id'     => $unique_id,
        ];

        // Return our wrapper template
        return plugixa_recipe_template( 'single-recipe-wrapper.php' );
    }
}
