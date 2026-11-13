<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The stylesheet and script every recipe listing and widget needs.
 *
 * Two routines, because they were always two: a listing localises the full string set (load
 * more, errors) and may add Swiper or the form script; a widget needs only the like/save
 * tooltips. Each runs its common part once per request - the flags are static, where they
 * used to sit on whichever renderer instance happened to be asked. Layout-specific extras
 * are still evaluated on every call, since a slider can appear further down a page whose
 * first listing was a grid.
 */
final class Assets {

    /** @var bool The listing bundle has been enqueued on this request. */
    private static bool $listing_enqueued = false;

    /** @var bool The widget bundle has been enqueued on this request. */
    private static bool $widgets_enqueued = false;

    /**
     * Enqueue what a recipe listing needs; $layout_style adds its own extras
     * ('slider' -> Swiper, 'form' -> the submission form script).
     */
    public static function listing(string $layout_style = 'grid'): void {
        // Layout-specific extras are evaluated on EVERY call: a slider or
        // submission form can appear further down the page after another
        // shortcode already enqueued the common assets, and the early-return
        // below would otherwise skip them (wp_enqueue_* dedupes by handle,
        // so repeated calls are harmless).

        // Recipe submission form JS
        if ($layout_style === 'form') {
            $form_js = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipe-form.js';
            if (file_exists($form_js)) {
                // The two scripts the form is built on. The parser is the SAME file the admin
                // editor imports, so a pasted line means one thing on both sides.
                wp_register_script('plugixa-recipe-parser', PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipe-text-parser.js', [], PLUGIXA_RECIPE_VERSION, true);
                wp_register_script('plugixa-recipe-sortable', PLUGIXA_RECIPE_URL . 'assets/frontend/js/sortable-list.js', [], PLUGIXA_RECIPE_VERSION, true);

                wp_enqueue_script(
                    'plugixa-recipe-form',
                    PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipe-form.js',
                    ['plugixa-recipe-parser', 'plugixa-recipe-sortable'],
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
                    '14.3.0'
                );
            }

            if (file_exists($swiper_js)) {
                wp_enqueue_script(
                    'swiper',
                    PLUGIXA_RECIPE_URL . 'assets/frontend/vendor/swiper/swiper-bundle.min.js',
                    [],
                    '14.3.0',
                    true
                );
            }
        }

        if (self::$listing_enqueued) {
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

            self::localize_config();
        }

        // Auto dark mode: toggle pxr-dark class based on OS preference
        wp_add_inline_script('plugixa-recipe-frontend', '(function(){var e=document.querySelectorAll("[data-pxr-dark-mode=auto]");if(!e.length)return;var m=window.matchMedia("(prefers-color-scheme:dark)");function t(q){e.forEach(function(el){el.classList.toggle("pxr-dark",q.matches)})}t(m);m.addEventListener("change",t)})();');

        self::$listing_enqueued = true;
    }

    /**
     * Print `plugixaRecipeConfig` for recipes.js - the whole object, once per page.
     *
     * Four places used to print it, each with the keys it happened to need, and
     * wp_localize_script() APPENDS: the last declaration on the page replaced the others.
     * On a recipe's page that was the related-recipes widget's, which carried no
     * `reviewHelpfulEnabled` - so the "Helpful" button under a review did nothing at all.
     * Every key is here, for every page, and a second call adds nothing.
     *
     * No REST nonce for a visitor (plugixa_recipe_rest_nonce()): the page is cached.
     *
     * @param string|null $return_to Where "log in" brings the visitor back to; the page
     *                               being read when null.
     */
    public static function localize_config(?string $return_to = null): void {
        if (false !== strpos((string) wp_scripts()->get_data('plugixa-recipe-frontend', 'data'), 'plugixaRecipeConfig')) {
            return;
        }

        $settings = (array) get_option('plugixa_recipe_settings', []);

        wp_localize_script('plugixa-recipe-frontend', 'plugixaRecipeConfig', [
            'ajaxUrl'              => admin_url('admin-ajax.php'),
            'restUrl'              => esc_url_raw(rest_url('plugixa-recipe/v1/')),
            'nonce'                => wp_create_nonce('plugixa_recipe_frontend'),
            'restNonce'            => plugixa_recipe_rest_nonce(),
            'isLoggedIn'           => is_user_logged_in(),
            'loginUrl'             => wp_login_url($return_to ?? plugixa_recipe_current_url()),
            'reviewsPerPage'       => (int) ($settings['reviews_per_page'] ?? 10),
            'reviewHelpfulEnabled' => !empty($settings['review_enable_helpful_votes']),
            'i18n'                 => [
                'loadMore'    => __('Load More Recipes', 'plugixa-recipe'),
                'loading'     => __('Loading...', 'plugixa-recipe'),
                'noMore'      => __('No more recipes to load.', 'plugixa-recipe'),
                'error'       => __('An error occurred while loading recipes.', 'plugixa-recipe'),
                'pleaseLogIn' => __('Please log in', 'plugixa-recipe'),
                'helpful'     => __('Helpful', 'plugixa-recipe'),
                /* translators: %d: how many readers found a review helpful. */
                'helpfulCount' => __('Helpful (%d)', 'plugixa-recipe'),
                'tooltip'     => [
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

    /**
     * Enqueue frontend CSS (shared with main shortcode CSS).
     */
    public static function widgets(): void {
        if ( self::$widgets_enqueued ) {
            return;
        }

        $css_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Frontend JS for interactive features (likes, favorites)
        $js_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipes.js';
        if ( file_exists( $js_path ) ) {
            wp_enqueue_script(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipes.js',
                [],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            self::localize_config();
        }

        self::$widgets_enqueued = true;
    }
}
