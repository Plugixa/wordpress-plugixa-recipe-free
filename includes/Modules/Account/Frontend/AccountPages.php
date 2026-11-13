<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Account\Frontend;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope render variables handed to the account views by include, not global-namespace pollution.

use PlugixaRecipe\Modules\Account\Repositories\AccountRepository;

defined('ABSPATH') || exit;

/**
 * The visitor's pages.
 *
 *   [plugixa_saved_recipes]   the recipes the visitor saved
 *   [plugixa_my_submissions]  the recipes the visitor sent in, and whether they are live
 *   [plugixa_shopping_list]   a shopping list (works signed out, in the browser)
 *   [plugixa_recipe_account]  the three, as tabs
 *
 * Each prints the SAME markup for everyone - no name, no row, no nonce - so a page cache
 * can serve one copy to all. The script then asks `plugixa_recipe_whoami` who is looking
 * and fetches that person's rows over REST.
 */
final class AccountPages {

    /** Shortcode => the view it draws. */
    private const PAGES = [
        'plugixa_saved_recipes'  => 'account/saved.php',
        'plugixa_my_submissions' => 'account/submissions.php',
        'plugixa_shopping_list'  => 'account/shopping-list.php',
        'plugixa_recipe_account' => 'account/account.php',
    ];

    private const LIST_PAGE_TRANSIENT = 'plugixa_recipe_shopping_list_page';

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

        // "Add to shopping list" on a recipe.
        add_action('plugixa_recipe_single_assets', [$this, 'enqueue']);
        add_action('plugixa_recipe_single_ingredient_tools', [$this, 'add_to_list_button'], 20, 2);

        // The page that holds the list may have just been made, moved or removed.
        add_action('save_post_page', [self::class, 'forget_list_page']);
        add_action('deleted_post', [self::class, 'forget_list_page']);
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
        wp_enqueue_script('plugixa-recipe-shopping-list', $base . 'shopping-list.js', ['plugixa-recipe-account'], PLUGIXA_RECIPE_VERSION, true);

        // Addresses and words. No nonce and no name: this object is in the cached page.
        wp_localize_script('plugixa-recipe-account', 'plugixaRecipeAccount', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'restUrl'     => esc_url_raw(rest_url('plugixa-recipe/v1/')),
            'listUrl'     => self::list_page_url(),
            'favorites'   => plugixa_recipe_app_enabled('favorites'),
            'serverLists' => plugixa_recipe_app_enabled('shopping_lists'),
            'i18n'        => [
                'error'     => __('Something went wrong. Please reload the page.', 'plugixa-recipe'),
                'removed'   => __('Removed from your saved recipes.', 'plugixa-recipe'),
                'copied'    => __('Copied', 'plugixa-recipe'),
                /* translators: %d: number of items. */
                'added'     => __('%d items added to your shopping list.', 'plugixa-recipe'),
                'addedOne'  => __('1 item added to your shopping list.', 'plugixa-recipe'),
                'nothing'   => __('There is nothing left to add.', 'plugixa-recipe'),
                'viewList'  => __('View list', 'plugixa-recipe'),
                'synced'    => __('Saved to your account.', 'plugixa-recipe'),
                'local'     => __('Kept on this device.', 'plugixa-recipe'),
                /* translators: %s: an ingredient. */
                'remove'    => __('Remove %s', 'plugixa-recipe'),
                'confirm'   => __('Clear the whole list?', 'plugixa-recipe'),
            ],
        ]);
    }

    /**
     * @param object $recipe    The presented recipe.
     * @param string $unique_id The page's id prefix.
     */
    public function add_to_list_button($recipe, $unique_id): void {
        $template = plugixa_recipe_template('account/add-to-list.php');
        if ('' !== $template) {
            include $template;
        }
    }

    private function render(string $shortcode): string {
        $template = plugixa_recipe_template(self::PAGES[$shortcode]);
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

    /**
     * The page a visitor's shopping list is on, if the site has one: the first published
     * page that carries the list or the account shortcode. '' when there is none - the
     * button on a recipe then adds to the list without a link to it.
     */
    public static function list_page_url(): string {
        $page_id = get_transient(self::LIST_PAGE_TRANSIENT);

        if (false === $page_id) {
            $page_id = (new AccountRepository())->page_with_shortcode(['[plugixa_shopping_list', '[plugixa_recipe_account']);
            // 0 is kept too: "there is none" is an answer worth not asking again every page.
            set_transient(self::LIST_PAGE_TRANSIENT, (int) $page_id, DAY_IN_SECONDS);
        }

        $page_id = (int) $page_id;
        // The stamp says a page WAS found; whether it is still there is asked now.
        if (!$page_id || 'publish' !== get_post_status($page_id)) {
            return '';
        }

        return (string) get_permalink($page_id);
    }

    public static function forget_list_page(): void {
        delete_transient(self::LIST_PAGE_TRANSIENT);
    }
}
