<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Reviews\Http\FrontendReviewsController;
use PlugixaRecipe\Modules\Reviews\Http\ReviewsController;

defined('ABSPATH') || exit;

/**
 * Reviews - an optional module the App Manager can switch off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'reviews';
    }

    public function app(): array {
        return [
            'order'    => 10,
            'category' => 'engagement',
            'core'     => false,
            'default'  => true,
            'icon'     => 'rate_review',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Reviews', 'plugixa-recipe'),
            'description' => __('Visitor ratings and reviews, with moderation, photos and helpful votes.', 'plugixa-recipe'),
        ];
    }

    public function usage(): array {
        $count = $this->count_rows('reviews');

        /* translators: %d: number of items */
        return [sprintf(_n('%d review', '%d reviews', $count, 'plugixa-recipe'), $count)];
    }

    /** This module's templates resolve by name through plugixa_recipe_template(). */
    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');
    }

    public function registerRoutes(): void {
        $this->routes([
            ReviewsController::class,
            FrontendReviewsController::class,
        ]);
    }
}
