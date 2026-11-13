<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Account;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Account\Frontend\AccountPages;
use PlugixaRecipe\Modules\Account\Http\AccountController;

defined('ABSPATH') || exit;

/**
 * A visitor's own corner of the site: the recipes they saved. Another module can add a
 * page to it (plugixa_recipe_account_tabs) and draw it the same way (AccountPages::page()).
 *
 * The pages are shells that are the same for everyone, so a page cache can keep them; the
 * script on them asks who is looking and fetches that person's rows. Every route here
 * answers only for the caller.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'account';
    }

    public function app(): array {
        return [
            'order'    => 22,
            'category' => 'engagement',
            'core'     => false,
            'default'  => true,
            'icon'     => 'account_circle',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Visitor Account', 'plugixa-recipe'),
            'description' => __('A page for your visitors with the recipes they saved, which a page cache can serve to everyone.', 'plugixa-recipe'),
        ];
    }

    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');
    }

    public function registerHooks(): void {
        (new AccountPages())->init();
    }

    public function registerRoutes(): void {
        $this->routes([AccountController::class]);
    }
}
