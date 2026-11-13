<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Account\Frontend;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope render variables handed to the account views by include, not global-namespace pollution.

defined('ABSPATH') || exit;

/**
 * The visitor's pages.
 *
 *   [plugixa_saved_recipes]   the recipes the visitor saved
 *   [plugixa_recipe_account]  the pages, as tabs (plugixa_recipe_account_tabs adds more)
 *
 * Each prints the SAME markup for everyone - no name, no row, no nonce - so a page cache
 * can serve one copy to all. The script then asks `plugixa_recipe_whoami` who is looking
 * and fetches that person's rows over REST. A module that adds a page draws it through
 * page() and enqueues its script on plugixa_recipe_account_assets.
 */
final class AccountPages {

    /** Shortcode => the view it draws. */
    private const PAGES = [
        'plugixa_saved_recipes'  => 'account/saved.php',
        'plugixa_recipe_account' => 'account/account.php',
    ];

    public function init(): void {
        foreach (array_keys(self::PAGES) as $shortcode) {
            add_shortcode($shortcode, function () use ($shortcode): string {
                return $this->render($shortcode);
            });
        }

        // admin-ajax, not REST: it authenticates by cookie alone, and handing out the REST
        // nonce is the point. Another site cannot read the answer, so it leaks nothing.
        add_action('wp_ajax_plugixa_recipe_whoami', [$this, 'whoami']);
        add_action('wp_ajax_nopriv_plugixa_recipe_whoami', [$this, 'whoami']);
    }

    /** Who is looking at this (possibly cached) page. Reads nothing from the request. */
    public function whoami(): void {
        nocache_headers();
        wp_send_json_success([
            'loggedIn'  => is_user_logged_in(),
            'restNonce' => plugixa_recipe_rest_nonce(),
        ]);
    }

    public function enqueue(): void {
        // Two shortcodes on one page, or the tabs: wp_localize_script() APPENDS, so a
        // second pass would print the object twice.
        if (wp_script_is('plugixa-recipe-account', 'enqueued')) {
            return;
        }

        wp_enqueue_style('plugixa-recipe-frontend', PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css', [], PLUGIXA_RECIPE_VERSION);

        $base = PLUGIXA_RECIPE_URL . 'assets/frontend/js/';
        wp_enqueue_script('plugixa-recipe-account', $base . 'account.js', [], PLUGIXA_RECIPE_VERSION, true);

        // Addresses and words. No nonce and no name: this object is in the cached page.
        wp_localize_script('plugixa-recipe-account', 'plugixaRecipeAccount', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'restUrl'   => esc_url_raw(rest_url('plugixa-recipe/v1/')),
            'favorites' => plugixa_recipe_app_enabled('favorites'),
            'i18n'      => [
                'error'   => __('Something went wrong. Please reload the page.', 'plugixa-recipe'),
                'removed' => __('Removed from your saved recipes.', 'plugixa-recipe'),
                /* translators: %s: a recipe title. */
                'remove'  => __('Remove %s', 'plugixa-recipe'),
            ],
        ]);

        /**
         * Fires once per page, after the account script is enqueued: a module that adds a
         * page through plugixa_recipe_account_tabs enqueues its own script here, with
         * 'plugixa-recipe-account' as a dependency (window.PlugixaRecipeAccount).
         *
         * @since 1.4.0
         */
        do_action('plugixa_recipe_account_assets');
    }

    private function render(string $shortcode): string {
        return $this->page(self::PAGES[$shortcode]);
    }

    /**
     * One visitor page, drawn from the view of that NAME with the account script on the
     * page. A module's own page shortcode calls this too, so every page is drawn alike.
     *
     * @since 1.4.0
     */
    public function page(string $view): string {
        $template = plugixa_recipe_template($view);
        if ('' === $template) {
            return '';
        }

        $this->enqueue();

        // Where a "log in" link returns to: this page.
        $login_url = wp_login_url(plugixa_recipe_current_url());
        // A module switched off has no route to answer: its panel is not drawn.
        $show_saved = plugixa_recipe_app_enabled('favorites');

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

}
