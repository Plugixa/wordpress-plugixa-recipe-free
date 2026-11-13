<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Recipes\Http\FrontendRecipesController;
use PlugixaRecipe\Modules\Recipes\Http\RecipesController;
use PlugixaRecipe\Modules\Recipes\Http\IngredientsController;
use PlugixaRecipe\Modules\Recipes\Http\InstructionsController;
use PlugixaRecipe\Modules\Recipes\Http\CoursesController;
use PlugixaRecipe\Modules\Recipes\Http\UnitsController;
use PlugixaRecipe\Modules\Recipes\Frontend\ArchivePage;
use PlugixaRecipe\Modules\Recipes\Frontend\MetaTags;
use PlugixaRecipe\Modules\Recipes\Frontend\SitemapProvider;
use PlugixaRecipe\Modules\Recipes\Frontend\Surprise;
use PlugixaRecipe\Modules\Recipes\Frontend\PrintPage;
use PlugixaRecipe\Modules\Recipes\Frontend\RecipeEmbed;
use PlugixaRecipe\Modules\Recipes\Services\CanonicalPosts;
use PlugixaRecipe\Modules\Recipes\Frontend\SinglePage;

defined('ABSPATH') || exit;

/**
 * Recipes - a core module: always on, no App Manager toggle.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'recipes';
    }

    public function app(): array {
        return [
            'order'    => 20,
            'category' => 'core',
            'core'     => true,
            'default'  => true,
            'icon'     => 'restaurant',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Recipes', 'plugixa-recipe'),
            'description' => __('Create and manage recipes with ingredients, instructions, times and servings.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('recipes');

        /* translators: %d: number of items */
        return [sprintf(_n('%d recipe', '%d recipes', $count, 'plugixa-recipe'), $count)];
    }

    /** This module's templates resolve by name through plugixa_recipe_template(). */
    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');
    }

    /**
     * The public recipe pages. A core module, so this always runs.
     *
     * The order is load-bearing and is the order these were always wired in: the archive
     * first, because it owns the rewrite flush that the single and print rules rely on;
     * the single page last. Recipe JSON-LD is not here - it is the Schema module's, which
     * reads what these pages registered in RenderedRecipes.
     */
    public function registerHooks(): void {
        (new ArchivePage())->init();
        (new MetaTags())->init();
        (new PrintPage())->init();
        (new SinglePage())->init();
        // One recipe inside a post: [plugixa_single_recipe], the round-up, the jump buttons.
        (new RecipeEmbed())->init();
        // ...and the recipe's own page then names that post as its canonical.
        (new CanonicalPosts())->init();
        // [plugixa_surprise_recipe]: a link that opens a recipe picked at random.
        (new Surprise())->init();

        // Recipes are not posts, so core's sitemap lists them only through this.
        add_action('init', [SitemapProvider::class, 'register']);
    }

    public function registerRoutes(): void {
        $this->routes([
            RecipesController::class,
            IngredientsController::class,
            InstructionsController::class,
            CoursesController::class,
            UnitsController::class,
            FrontendRecipesController::class,
        ]);
    }
}
