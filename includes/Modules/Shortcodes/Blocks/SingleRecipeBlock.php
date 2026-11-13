<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Blocks;

use PlugixaRecipe\Modules\Recipes\Frontend\RecipeEmbed;
use PlugixaRecipe\Modules\Shortcodes\Repositories\EditorOptionsRepository;

defined('ABSPATH') || exit;

/**
 * The "Recipe" block (plugixa-recipe/recipe): one whole recipe inside a post.
 *
 * Server-rendered through RecipeEmbed, the same code [plugixa_single_recipe] runs, so the
 * block, the shortcode and the recipe's own page draw one template. The block stores one
 * attribute, the recipe's id; RecipeEmbed::embedded_ids() reads it back out of the saved
 * comment to know which recipe a post is about.
 */
final class SingleRecipeBlock {

    public function register(): void {
        if (!function_exists('register_block_type')) {
            return;
        }

        $editor_js = PLUGIXA_RECIPE_PATH . 'assets/blocks/single-recipe-block/index.js';
        if (file_exists($editor_js)) {
            // Hand-written against the wp.* globals, like the Recipes block: no build step,
            // the shipped file is the source.
            wp_register_script(
                'plugixa-recipe-single-block-editor',
                PLUGIXA_RECIPE_URL . 'assets/blocks/single-recipe-block/index.js',
                ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            // Only where the editor is: the list is a query, and this runs on every `init`.
            add_action('enqueue_block_editor_assets', static function (): void {
                wp_localize_script('plugixa-recipe-single-block-editor', 'plugixaRecipeSingleBlockData', [
                    'recipes' => self::recipe_options(),
                ]);
            });

            wp_set_script_translations('plugixa-recipe-single-block-editor', 'plugixa-recipe');
        }

        register_block_type(RecipeEmbed::BLOCK, [
            'api_version'     => 3,
            'editor_script'   => 'plugixa-recipe-single-block-editor',
            // Registered by RecipeBlock: the frontend stylesheet, so the preview is styled.
            'editor_style'    => 'plugixa-recipe-block-editor-style',
            'render_callback' => [$this, 'render'],
            'supports'        => ['html' => false, 'align' => ['wide', 'full']],
            'attributes'      => [
                'recipeId' => ['type' => 'number', 'default' => 0],
                'align'    => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render($attributes): string {
        $id = absint($attributes['recipeId'] ?? 0);
        if (!$id) {
            return '';
        }

        $recipe = (new RecipeEmbed())->render(['id' => $id]);
        if ('' === $recipe) {
            return '';
        }

        // The wrapper carries the block's class and the alignment the author chose.
        return sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $recipe);
    }

    /**
     * @return array<int, array{value:int, label:string}>
     */
    public static function recipe_options(): array {
        $options = [];
        foreach ((new EditorOptionsRepository())->recipes() ?: [] as $row) {
            $options[] = ['value' => (int) $row['id'], 'label' => (string) $row['title']];
        }

        return $options;
    }
}
