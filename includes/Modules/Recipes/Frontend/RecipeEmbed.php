<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope render variables handed to single-recipe.php by include, not global-namespace pollution.

use PlugixaRecipe\Modules\Recipes\RenderedRecipes;
use PlugixaRecipe\Modules\Recipes\Services\RecipePresenter;
use PlugixaRecipe\Modules\Views\Services\CssVars;

defined('ABSPATH') || exit;

/**
 * A recipe inside a post or page.
 *
 *   [plugixa_single_recipe id="12"]      the whole recipe, as its own page draws it
 *   [plugixa_recipe_roundup ids="3,8,12"] a numbered list of recipes, each linking to its page
 *   [plugixa_jump_to_recipe]             "Jump to recipe" / "Print" buttons, placed by hand
 *
 * ([plugixa_recipe id] was taken: it is a saved LISTING's id.)
 *
 * The embed draws single-recipe.php through SingleView, so a recipe in a post and on its
 * own page are one template and one set of assets. It registers the recipe as the page's
 * SUBJECT, which is what gives the post a full Recipe in its JSON-LD; a round-up registers
 * list entries, which gives it an ItemList.
 */
final class RecipeEmbed {

    /** The block's name; its saved comment is how a post is recognised as holding a recipe. */
    public const BLOCK = 'plugixa-recipe/recipe';

    private SingleView $view;

    /** @var array<int, int> recipe id => times drawn on this page, for unique element ids. */
    private static array $drawn = [];

    public function __construct() {
        $this->view = new SingleView();
    }

    public function init(): void {
        add_shortcode('plugixa_single_recipe', [$this, 'render']);
        add_shortcode('plugixa_recipe_roundup', [$this, 'render_roundup']);
        add_shortcode('plugixa_jump_to_recipe', [$this, 'render_jump']);

        // 9: before do_shortcode (11) and do_blocks (9 runs in registration order, and core's
        // was added first), while the content still says which recipe it embeds.
        add_filter('the_content', [$this, 'prepend_jump'], 8);
    }

    /**
     * [plugixa_single_recipe id="12" reviews="yes|no"]
     *
     * @param array<string, mixed>|string $atts
     */
    public function render($atts): string {
        $atts = shortcode_atts(['id' => 0, 'reviews' => ''], is_array($atts) ? $atts : [], 'plugixa_single_recipe');
        $id   = absint($atts['id']);

        // The block editor previews through REST and a page builder through admin-ajax:
        // neither is a reader.
        $preview = ( defined('REST_REQUEST') && REST_REQUEST ) || wp_doing_ajax() || is_admin();
        $recipe  = $id ? $this->view->recipe($id, !$preview) : null;
        if (!$recipe) {
            // Nothing for a visitor; a line for someone who can fix it.
            return current_user_can('edit_posts')
                ? '<p class="pxr-embed-missing">' . esc_html(sprintf(
                    /* translators: %d: recipe ID. */
                    __('Recipe %d was not found or is not published.', 'plugixa-recipe'),
                    $id
                )) . '</p>'
                : '';
        }

        RenderedRecipes::add([$id], true);
        $this->view->enqueue($recipe);

        $view_config = $this->view->config();
        if ('' !== $atts['reviews']) {
            $on = in_array(strtolower((string) $atts['reviews']), ['yes', '1', 'true', 'on'], true);
            $view_config['show_reviews']   = $on;
            $view_config['enable_reviews'] = $on;
        }

        self::$drawn[$id] = (self::$drawn[$id] ?? 0) + 1;
        $first            = 1 === self::$drawn[$id];

        $css_vars_style = (new CssVars())->build_css_vars($view_config);
        $unique_id      = 'pxr-embed-' . $id . ($first ? '' : '-' . self::$drawn[$id]);
        // The post has its own h1.
        $title_level = 2;

        ob_start();
        // The anchor "Jump to recipe" points at; once per recipe, ids being unique.
        printf('<div class="pxr-recipes-wrapper pxr-embed"%s>', $first ? ' id="' . esc_attr(self::anchor($id)) . '"' : '');
        include plugixa_recipe_template('single-recipe.php');
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * [plugixa_recipe_roundup ids="3,8,12"]
     *
     * @param array<string, mixed>|string $atts
     */
    public function render_roundup($atts): string {
        $atts = shortcode_atts(['ids' => ''], is_array($atts) ? $atts : [], 'plugixa_recipe_roundup');
        $ids  = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) $atts['ids'])))));

        // The presenter alone: a line in a list is not a view of the recipe.
        $presenter = new RecipePresenter();
        $recipes   = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            $recipe = $presenter->recipe($id);
            if ($recipe) {
                $recipes[] = $recipe;
            }
        }

        if (!$recipes) {
            return '';
        }

        RenderedRecipes::add(array_map(static fn(object $recipe): int => (int) $recipe->id, $recipes));
        wp_enqueue_style('plugixa-recipe-frontend', PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css', [], PLUGIXA_RECIPE_VERSION);

        ob_start();
        include plugixa_recipe_template('roundup.php');

        return (string) ob_get_clean();
    }

    /**
     * [plugixa_jump_to_recipe id="12"] - the id is optional: the first recipe the post embeds.
     *
     * @param array<string, mixed>|string $atts
     */
    public function render_jump($atts): string {
        $atts = shortcode_atts(['id' => 0], is_array($atts) ? $atts : [], 'plugixa_jump_to_recipe');
        $id   = absint($atts['id']);

        if (!$id) {
            $post = get_post();
            $ids  = $post ? self::embedded_ids((string) $post->post_content) : [];
            $id   = $ids[0] ?? 0;
        }

        return $id ? self::jump_markup($id) : '';
    }

    /**
     * Buttons above a post that embeds a recipe - the story comes first, the reader who
     * came for the recipe gets a way past it.
     *
     * @param string $content
     */
    public function prepend_jump($content) {
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $content = (string) $content;
        // Placed by hand already.
        if (false !== strpos($content, '[plugixa_jump_to_recipe')) {
            return $content;
        }

        $ids = self::embedded_ids($content);

        /**
         * Filters whether "Jump to recipe" / "Print" buttons are added above a post that
         * embeds a recipe.
         *
         * @since 1.2.0
         *
         * @param bool $show    Default true.
         * @param int  $post_id The post being displayed.
         */
        if (!$ids || !apply_filters('plugixa_recipe_show_jump_buttons', true, (int) get_the_ID())) {
            return $content;
        }

        wp_enqueue_style('plugixa-recipe-frontend', PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css', [], PLUGIXA_RECIPE_VERSION);

        return self::jump_markup($ids[0]) . $content;
    }

    /**
     * The recipes a piece of content embeds WHOLE (not the ones a round-up lists), in order.
     *
     * @return int[]
     */
    public static function embedded_ids(string $content): array {
        $ids = [];

        // [plugixa_single_recipe id="12"], and the block's saved comment:
        // <!-- wp:plugixa-recipe/recipe {"recipeId":12} /-->
        if (preg_match_all(
            '/\[plugixa_single_recipe\b[^\]]*?\bid\s*=\s*["\']?(\d+)|<!--\s+wp:' . preg_quote(self::BLOCK, '/') . '\s+\{[^}]*?"recipeId"\s*:\s*(\d+)/',
            $content,
            $found,
            PREG_SET_ORDER
        )) {
            foreach ($found as $match) {
                $ids[] = (int) ('' !== $match[1] ? $match[1] : ($match[2] ?? 0));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public static function anchor(int $recipe_id): string {
        return 'pxr-recipe-' . $recipe_id;
    }

    private static function jump_markup(int $recipe_id): string {
        return sprintf(
            '<p class="pxr-jump"><a class="pxr-jump__link" href="#%1$s">%2$s</a> <a class="pxr-jump__link pxr-jump__link--plain" href="%3$s" rel="nofollow">%4$s</a></p>',
            esc_attr(self::anchor($recipe_id)),
            esc_html__('Jump to recipe', 'plugixa-recipe'),
            esc_url(home_url("/recipe-print/{$recipe_id}/?auto=1")),
            esc_html__('Print recipe', 'plugixa-recipe')
        );
    }
}
