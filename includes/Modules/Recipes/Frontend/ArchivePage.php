<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $plugixa_recipe_archive_data is the documented hand-off global read by templates/archive-recipes.php. "pxr" is the plugin's established public prefix (CSS classes, --pxr-* custom properties, plugixa_recipe_* transients) and is unique to it; the sniff only objects because it is under its 4-character minimum.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Modules\Recipes\RenderedRecipes;
use PlugixaRecipe\Modules\Recipes\Repositories\ArchivePageRepository;

/**
 * ArchivePage Class — Handles category and cuisine archive pages.
 *
 * Registers rewrite rules for /recipe-category/{slug}/ and /recipe-cuisine/{slug}/
 * and renders an archive page with a recipe grid. Every query it needs lives in
 * ArchivePageRepository; the rewrite rules and their self-healing flush stay here.
 */
class ArchivePage {

    /** @var ArchivePageRepository */
    private $repository;

    public function __construct() {
        $this->repository = new ArchivePageRepository();
    }

    /**
     * Revision of the rewrite rules + query var names registered below.
     *
     * Bump on any change to either, so existing sites flush their cached rules instead
     * of 404ing. See register_rewrite_rules().
     */
    private const RULES_REVISION = '2';

    /**
     * Initialize archive page hooks.
     */
    public function init(): void {
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );
        // Flush on wp_loaded rather than inside init: SinglePage and PrintPage add
        // their own rules on init too, and flushing part-way through init would persist a
        // rule set missing whichever of them happened to register later.
        add_action( 'wp_loaded', [ $this, 'maybe_flush_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_filter( 'template_include', [ $this, 'handle_archive' ] );
    }

    /**
     * The rewrite-rule signature for the CURRENT configuration.
     *
     * The revision alone was not enough once the archive slugs became configurable: a
     * site that changes "Category slug" rewrites its own URLs, and the stored rules have
     * to be regenerated or every archive 404s. Folding the slugs into the stored value
     * makes that automatic, so no one has to remember to bump anything when a setting
     * changes - the revision is now only for changes to the rule SHAPE or query vars.
     */
    private function rules_signature(): string {
        return self::RULES_REVISION . ':' . md5(
            implode(
                '|',
                [
                    plugixa_recipe_url_slug( 'recipe' ),
                    plugixa_recipe_url_slug( 'category' ),
                    plugixa_recipe_url_slug( 'cuisine' ),
                ]
            )
        );
    }

    /**
     * Register archive rewrite rules.
     */
    public function register_rewrite_rules(): void {
        // Slugs are configurable (Settings > Display). preg_quote keeps a slug containing
        // regex metacharacters from corrupting the pattern; sanitize_title() already
        // strips most of them, but the rule is built from user input either way.
        $category = preg_quote( plugixa_recipe_url_slug( 'category' ), '#' );
        $cuisine  = preg_quote( plugixa_recipe_url_slug( 'cuisine' ), '#' );

        // Category archive
        add_rewrite_rule(
            '^' . $category . '/([^/]+)/page/(\d+)/?$',
            'index.php?plugixa_recipe_archive_type=category&plugixa_recipe_archive_slug=$matches[1]&plugixa_recipe_archive_page=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^' . $category . '/([^/]+)/?$',
            'index.php?plugixa_recipe_archive_type=category&plugixa_recipe_archive_slug=$matches[1]',
            'top'
        );

        // Cuisine archive
        add_rewrite_rule(
            '^' . $cuisine . '/([^/]+)/page/(\d+)/?$',
            'index.php?plugixa_recipe_archive_type=cuisine&plugixa_recipe_archive_slug=$matches[1]&plugixa_recipe_archive_page=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^' . $cuisine . '/([^/]+)/?$',
            'index.php?plugixa_recipe_archive_type=cuisine&plugixa_recipe_archive_slug=$matches[1]',
            'top'
        );
    }

    /**
     * Flush the stored rules when the revision or the configured slugs have changed.
     *
     * The stored rules live in the database, so a rename leaves every existing site
     * serving 404s until something flushes. Revision 2: query vars moved from pxr_* to
     * plugixa_recipe_*.
     */
    public function maybe_flush_rules(): void {
        $signature = $this->rules_signature();

        if ( get_option( 'plugixa_recipe_archive_rules_flushed', '' ) !== $signature ) {
            flush_rewrite_rules();
            update_option( 'plugixa_recipe_archive_rules_flushed', $signature );
            return;
        }

        $this->repair_missing_rules();
    }

    /**
     * Re-flush when the rules are gone but the revision option says they were written.
     *
     * The revision option records that a flush HAPPENED, not that the rules survived. A
     * partial database restore, a filtered staging dump, a host migration that skips the
     * options row, or another plugin doing its own hard flush at the wrong moment can drop
     * our rules while the option persists - and the version check above then short-circuits
     * forever, leaving every archive URL 404ing with no recovery path. So verify presence,
     * not history.
     *
     * Front end only, where WP loads the rewrite rules anyway to parse the request, so the
     * option read is served from cache rather than adding a query. The transient bounds the
     * repair to once an hour: if some other plugin filters our rules out permanently, a
     * flush on every request would be far worse than the 404 it is trying to fix.
     */
    private function repair_missing_rules(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        // Plain permalinks: there are no stored rules to be missing, and our archive URLs
        // do not apply at all. Nothing to repair.
        if ( ! get_option( 'permalink_structure' ) ) {
            return;
        }

        $rules = get_option( 'rewrite_rules' );
        if ( ! is_array( $rules ) || ! $rules ) {
            // Empty or absent: WP regenerates on its own next request. Leave it alone.
            return;
        }

        // Built the same way register_rewrite_rules() builds it, so a configured slug is
        // looked for rather than the shipped default - otherwise every site with a custom
        // slug would look "broken" here and re-flush once an hour forever.
        $category_rule = '^' . preg_quote( plugixa_recipe_url_slug( 'category' ), '#' ) . '/([^/]+)/?$';

        if ( isset( $rules[ $category_rule ] ) ) {
            return;
        }

        if ( get_transient( 'plugixa_recipe_rules_repair' ) ) {
            return;
        }

        set_transient( 'plugixa_recipe_rules_repair', 1, HOUR_IN_SECONDS );
        flush_rewrite_rules();
    }

    /**
     * Register custom query variables.
     */
    public function register_query_vars( array $vars ): array {
        $vars[] = 'plugixa_recipe_archive_type';
        $vars[] = 'plugixa_recipe_archive_slug';
        $vars[] = 'plugixa_recipe_archive_page';
        return $vars;
    }

    /**
     * Handle archive page rendering via template_include.
     */
    public function handle_archive( string $template ): string {
        $type = get_query_var( 'plugixa_recipe_archive_type' );
        $slug = get_query_var( 'plugixa_recipe_archive_slug' );

        if ( empty( $type ) || empty( $slug ) ) {
            return $template;
        }

        $type = sanitize_key( $type );
        $slug = sanitize_title( $slug );

        if ( ! in_array( $type, [ 'category', 'cuisine' ], true ) ) {
            return Templates::not_found( $template );
        }

        // Look up taxonomy
        $taxonomy = $this->repository->find_term( $type, $slug );
        if ( ! $taxonomy ) {
            return Templates::not_found( $template );
        }

        $page     = max( 1, absint( get_query_var( 'plugixa_recipe_archive_page', 1 ) ) );
        $per_page = 12;

        // Count total recipes
        $total = $this->repository->count_published( $type, (int) $taxonomy->id );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );

        // Query recipes for this page
        $recipes = $this->repository->published_page( $type, (int) $taxonomy->id, $page, $per_page );

        // Tell the JSON-LD builder exactly which recipes this page puts on screen, so the
        // structured data describes the visible content instead of guessing. This is a render
        // path that displays recipes as page content, so it MUST register - without this an
        // archive shows a full grid and emits no structured data at all. Note the set is the
        // paginated slice, not every recipe in the taxonomy. See PlugixaRecipe\Modules\Recipes\RenderedRecipes.
        RenderedRecipes::add( $recipes );

        // Enrich recipes for recipe-card.php
        $enriched = [];
        foreach ( $recipes as $recipe ) {
            $enriched[] = $this->enrich_recipe_for_archive( (array) $recipe );
        }

        // Prevent 404
        global $wp_query;
        $wp_query->is_404 = false;
        status_header( 200 );

        // SEO: Set page title
        add_filter( 'document_title_parts', function ( $parts ) use ( $taxonomy, $type ) {
            $type_label = $type === 'category'
                ? __( 'Category', 'plugixa-recipe' )
                : __( 'Cuisine', 'plugixa-recipe' );
            $parts['title'] = sprintf(
                /* translators: 1: taxonomy name, 2: type label */
                __( '%1$s %2$s Recipes', 'plugixa-recipe' ),
                $taxonomy->name,
                $type_label
            );
            return $parts;
        } );

        // Body classes
        add_filter( 'body_class', function ( $classes ) use ( $type ) {
            $classes = array_diff( $classes, [ 'home', 'blog' ] );
            $classes[] = 'wp-singular';
            $classes[] = 'pxr-archive';
            $classes[] = 'pxr-archive-' . $type;
            return $classes;
        } );

        // Enqueue frontend CSS
        $css_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Enqueue frontend JS
        $js_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipes.js';
        if ( file_exists( $js_path ) ) {
            wp_enqueue_script(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipes.js',
                [],
                PLUGIXA_RECIPE_VERSION,
                true
            );
            \PlugixaRecipe\Core\Frontend\Assets::localize_config( plugixa_recipe_archive_url( $type, (string) $taxonomy->slug ) );
        }

        // Build archive base URL for pagination
        $archive_base = plugixa_recipe_url_slug( $type === 'cuisine' ? 'cuisine' : 'category' );

        // Pass data to template via globals (same pattern as SinglePage)
        $GLOBALS['plugixa_recipe_archive_data'] = [
            'taxonomy'     => $taxonomy,
            'type'         => $type,
            'recipes'      => $enriched,
            'page'         => $page,
            'total_pages'  => $total_pages,
            'total'        => $total,
            'per_page'     => $per_page,
            'archive_base' => $archive_base,
            'view_config'  => $this->get_default_view_config(),
        ];

        return plugixa_recipe_template( 'archive-wrapper.php' );
    }

    /**
     * Enrich a recipe for recipe-card.php (archive context).
     */
    private function enrich_recipe_for_archive( array $recipe ): object {
        $recipe_id = (int) $recipe['id'];

        // Image
        if ( ! empty( $recipe['image_id'] ) ) {
            $recipe['image_url']    = wp_get_attachment_image_url( (int) $recipe['image_id'], 'medium_large' ) ?: '';
            $recipe['image_srcset'] = wp_get_attachment_image_srcset( (int) $recipe['image_id'], 'medium_large' ) ?: '';
        } else {
            $recipe['image_url']    = '';
            $recipe['image_srcset'] = '';
        }

        // Categories
        $recipe['categories'] = $this->repository->categories( $recipe_id );

        // Cuisines
        $recipe['cuisines'] = $this->repository->cuisines( $recipe_id );

        // Diet badges
        $recipe['diet_badges'] = [];
        if ( ! empty( $recipe['is_vegetarian'] ) ) $recipe['diet_badges'][] = __( 'Vegetarian', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_vegan'] ) )      $recipe['diet_badges'][] = __( 'Vegan', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_gluten_free'] ) ) $recipe['diet_badges'][] = __( 'Gluten-Free', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_dairy_free'] ) )  $recipe['diet_badges'][] = __( 'Dairy-Free', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_keto'] ) )        $recipe['diet_badges'][] = __( 'Keto', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_low_carb'] ) )    $recipe['diet_badges'][] = __( 'Low-Carb', 'plugixa-recipe' );

        // Excerpt
        if ( ! empty( $recipe['description'] ) ) {
            $plain = wp_strip_all_tags( $recipe['description'] );
            $recipe['excerpt'] = mb_strlen( $plain ) > 150
                ? mb_substr( $plain, 0, 150 ) . "\u{2026}"
                : $plain;
        } else {
            $recipe['excerpt'] = '';
        }

        // Like/favorite
        $recipe['like_count'] = isset( $recipe['like_count'] ) ? (int) $recipe['like_count'] : 0;
        if ( is_user_logged_in() ) {
            $uid = get_current_user_id();
            $recipe['is_liked']     = $this->repository->is_liked( $uid, $recipe_id );
            $recipe['is_favorited'] = $this->repository->is_favorited( $uid, $recipe_id );
        } else {
            $recipe['is_liked']     = false;
            $recipe['is_favorited'] = false;
        }

        // Aliases
        $recipe['rating']    = $recipe['rating_average'] ?? 0;
        $recipe['cuisine']   = ! empty( $recipe['cuisines'][0]['name'] ) ? $recipe['cuisines'][0]['name'] : '';
        $slug                = sanitize_title( $recipe['title'] ?? '' );
        $recipe['permalink'] = plugixa_recipe_permalink( (int) $recipe_id, $slug );
        $recipe['print_url'] = home_url( "/recipe-print/{$recipe_id}/?auto=1" );

        return (object) $recipe;
    }

    /**
     * Get default view config for archive grid display.
     */
    private function get_default_view_config(): array {
        return [
            'show_image'      => true,
            'show_rating'     => true,
            'show_meta'       => true,
            'show_difficulty' => true,
            'show_diet'       => false,
            'show_cuisine'    => false,
            'show_excerpt'    => true,
            'layout' => [
                'style'   => 'grid',
                'columns' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ],
            ],
        ];
    }
}
