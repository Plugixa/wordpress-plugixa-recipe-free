<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use PlugixaRecipe\Modules\Recipes\Frontend\RecipeEmbed;
use PlugixaRecipe\Modules\Shortcodes\Blocks\SingleRecipeBlock;

/**
 * Elementor widget: one whole recipe. The same embed as [plugixa_single_recipe] and the
 * Recipe block.
 *
 * Extends an Elementor class, so it is only ever loaded from Elementor's own hook.
 */
class SingleRecipeWidget extends Widget_Base {

    public function get_name(): string {
        return 'plugixa_recipe_single';
    }

    public function get_title(): string {
        return __('Plugixa Recipe', 'plugixa-recipe');
    }

    public function get_icon(): string {
        return 'eicon-post-content';
    }

    public function get_categories(): array {
        return ['plugixa-recipe'];
    }

    public function get_keywords(): array {
        return ['recipe', 'recipe card', 'food', 'cooking', 'plugixa'];
    }

    protected function register_controls(): void {
        $this->start_controls_section('content_section', [
            'label' => __('Recipe', 'plugixa-recipe'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $options = [];
        foreach (SingleRecipeBlock::recipe_options() as $recipe) {
            $options[(string) $recipe['value']] = $recipe['label'];
        }

        $this->add_control('recipe_id', [
            'label'       => __('Recipe', 'plugixa-recipe'),
            'type'        => Controls_Manager::SELECT2,
            'options'     => $options,
            'label_block' => true,
            'description' => __('The published recipe to show here.', 'plugixa-recipe'),
        ]);

        $this->add_control('reviews', [
            'label'        => __('Show reviews', 'plugixa-recipe'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();
        $id       = absint($settings['recipe_id'] ?? 0);

        if (!$id) {
            return;
        }

        $html = (new RecipeEmbed())->render([
            'id'      => $id,
            'reviews' => 'yes' === ($settings['reviews'] ?? 'yes') ? 'yes' : 'no',
        ]);

        // Late-escaped through the plugin's central allowlist (Core\Security\Kses). It keeps
        // every element and attribute the recipe template emits - ReaderToolsTest and
        // RecipeEmbedTest compare a recipe before and after it.
        echo wp_kses($html, plugixa_recipe_allowed_html());
    }
}
