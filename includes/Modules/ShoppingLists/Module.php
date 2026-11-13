<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ShoppingLists;

use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\ShoppingLists\Http\FrontendShoppingListController;
use PlugixaRecipe\Modules\ShoppingLists\Http\ShoppingListsController;

defined('ABSPATH') || exit;

/**
 * Shopping Lists - an optional module the App Manager can switch off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'shopping_lists';
    }

    public function app(): array {
        return [
            'order'    => 30,
            'category' => 'engagement',
            'core'     => false,
            'default'  => true,
            'icon'     => 'shopping_cart',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Shopping Lists', 'plugixa-recipe'),
            'description' => __('Shopping lists built from a recipe or a meal plan, kept in the admin.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('shopping_lists');

        /* translators: %d: number of items */
        return [sprintf(_n('%d shopping list', '%d shopping lists', $count, 'plugixa-recipe'), $count)];
    }

    public function registerRoutes(): void {
        $this->routes([
            ShoppingListsController::class,
            FrontendShoppingListController::class,
        ]);
    }
}
