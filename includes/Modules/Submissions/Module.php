<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Submissions;

use PlugixaRecipe\Core\Frontend\Templates;
use PlugixaRecipe\Core\Modules\AbstractModule;
use PlugixaRecipe\Modules\Submissions\Frontend\SubmissionFormShortcode;
use PlugixaRecipe\Modules\Submissions\Http\SubmissionsController;

defined('ABSPATH') || exit;

/**
 * Recipe Submissions - an optional module the App Manager can switch off.
 */
final class Module extends AbstractModule {

    public function id(): string {
        return 'submissions';
    }

    public function app(): array {
        return [
            'order'    => 40,
            'category' => 'engagement',
            'core'     => false,
            'default'  => false,
            'icon'     => 'upload',
        ];
    }

    public function labels(): array {
        return [
            'label'       => __('Recipe Submissions', 'plugixa-recipe'),
            'description' => __('A frontend form that lets visitors submit recipes for review.', 'plugixa-recipe'),
        ];
    }

    /** This module's templates resolve by name through plugixa_recipe_template(). */
    /**
     * Runs for every module, switched on or not - deliberately. [plugixa_recipe_form] has
     * always been a registered tag that renders nothing while Submissions is off; leaving it
     * unregistered instead would print the raw shortcode text on the page.
     */
    public function registerServices(): void {
        Templates::add_path(__DIR__ . '/views');

        add_action('init', [new SubmissionFormShortcode(), 'register']);
    }

    public function registerRoutes(): void {
        $this->routes([
            SubmissionsController::class,
        ]);
    }
}
