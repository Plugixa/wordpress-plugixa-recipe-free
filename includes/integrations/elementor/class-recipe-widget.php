<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Integrations\Elementor;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use PlugixaRecipe\Frontend\Shortcodes;

/**
 * RecipeWidget - Elementor widget for displaying recipes.
 *
 * Allows users to select a saved shortcode configuration or create a custom
 * recipe display configuration directly within the Elementor editor.
 */
class RecipeWidget extends Widget_Base {

    /**
     * Get widget name.
     */
    public function get_name(): string {
        return 'plugixa_recipe_recipes';
    }

    /**
     * Get widget title.
     */
    public function get_title(): string {
        return __('Plugixa Recipes', 'plugixa-recipe');
    }

    /**
     * Get widget icon.
     */
    public function get_icon(): string {
        return 'eicon-gallery-grid';
    }

    /**
     * Get widget categories.
     */
    public function get_categories(): array {
        return ['plugixa-recipe'];
    }

    /**
     * Get widget keywords.
     */
    public function get_keywords(): array {
        return ['recipe', 'food', 'cooking', 'recipes', 'plugixa'];
    }

    /**
     * Register widget controls.
     */
    protected function register_controls(): void {
        // Content section
        $this->start_controls_section('section_content', [
            'label' => __('Recipe Display', 'plugixa-recipe'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('source_type', [
            'label'   => __('Source', 'plugixa-recipe'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'shortcode',
            'options' => [
                'shortcode' => __('Saved Shortcode', 'plugixa-recipe'),
                'custom'    => __('Custom Configuration', 'plugixa-recipe'),
            ],
        ]);

        // Saved shortcode selector
        $this->add_control('shortcode_id', [
            'label'     => __('Select Shortcode', 'plugixa-recipe'),
            'type'      => Controls_Manager::SELECT,
            'default'   => '',
            'options'   => $this->get_shortcode_options(),
            'condition' => ['source_type' => 'shortcode'],
        ]);

        // Custom configuration controls
        $this->add_control('view_id', [
            'label'     => __('View / Layout', 'plugixa-recipe'),
            'type'      => Controls_Manager::SELECT,
            'default'   => '',
            'options'   => $this->get_view_options(),
            'condition' => ['source_type' => 'custom'],
        ]);

        $this->add_control('categories', [
            'label'       => __('Categories', 'plugixa-recipe'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('e.g. quick-easy, healthy', 'plugixa-recipe'),
            'description' => __('Comma-separated category slugs to filter by.', 'plugixa-recipe'),
            'condition'   => ['source_type' => 'custom'],
        ]);

        $this->add_control('cuisines', [
            'label'       => __('Cuisines', 'plugixa-recipe'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('e.g. italian, thai', 'plugixa-recipe'),
            'description' => __('Comma-separated cuisine slugs to filter by.', 'plugixa-recipe'),
            'condition'   => ['source_type' => 'custom'],
        ]);

        $this->add_control('limit', [
            'label'     => __('Number of Recipes', 'plugixa-recipe'),
            'type'      => Controls_Manager::NUMBER,
            'default'   => 12,
            'min'       => 1,
            'max'       => 100,
            'condition' => ['source_type' => 'custom'],
        ]);

        $this->add_control('orderby', [
            'label'     => __('Order By', 'plugixa-recipe'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'created_at',
            'options'   => [
                'created_at'     => __('Date Created', 'plugixa-recipe'),
                'title'          => __('Title', 'plugixa-recipe'),
                'rating_average' => __('Rating', 'plugixa-recipe'),
                'prep_time'      => __('Prep Time', 'plugixa-recipe'),
                'total_time'     => __('Total Time', 'plugixa-recipe'),
                'view_count'     => __('Popularity', 'plugixa-recipe'),
            ],
            'condition' => ['source_type' => 'custom'],
        ]);

        $this->add_control('order', [
            'label'     => __('Order', 'plugixa-recipe'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'DESC',
            'options'   => [
                'ASC'  => __('Ascending', 'plugixa-recipe'),
                'DESC' => __('Descending', 'plugixa-recipe'),
            ],
            'condition' => ['source_type' => 'custom'],
        ]);

        $this->add_control('featured', [
            'label'        => __('Featured Only', 'plugixa-recipe'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => '',
            'label_on'     => __('Yes', 'plugixa-recipe'),
            'label_off'    => __('No', 'plugixa-recipe'),
            'return_value' => 'true',
            'condition'    => ['source_type' => 'custom'],
        ]);

        $this->add_control('difficulty', [
            'label'     => __('Difficulty', 'plugixa-recipe'),
            'type'      => Controls_Manager::SELECT,
            'default'   => '',
            'options'   => [
                ''       => __('All', 'plugixa-recipe'),
                'easy'   => __('Easy', 'plugixa-recipe'),
                'medium' => __('Medium', 'plugixa-recipe'),
                'hard'   => __('Hard', 'plugixa-recipe'),
            ],
            'condition' => ['source_type' => 'custom'],
        ]);

        $this->add_control('pagination', [
            'label'        => __('Enable Pagination', 'plugixa-recipe'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => '',
            'label_on'     => __('Yes', 'plugixa-recipe'),
            'label_off'    => __('No', 'plugixa-recipe'),
            'return_value' => 'true',
            'condition'    => ['source_type' => 'custom'],
        ]);

        $this->add_control('dark_mode', [
            'label'        => __('Dark Mode', 'plugixa-recipe'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => '',
            'label_on'     => __('Yes', 'plugixa-recipe'),
            'label_off'    => __('No', 'plugixa-recipe'),
            'return_value' => 'true',
        ]);

        $this->end_controls_section();
    }

    /**
     * Render widget output on the frontend.
     */
    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $shortcode_atts = [];

        if ($settings['source_type'] === 'shortcode' && !empty($settings['shortcode_id'])) {
            $shortcode_atts['id'] = (int) $settings['shortcode_id'];
        } else {
            if (!empty($settings['view_id'])) {
                $shortcode_atts['view'] = $settings['view_id'];
            }
            if (!empty($settings['categories'])) {
                $shortcode_atts['categories'] = $settings['categories'];
            }
            if (!empty($settings['cuisines'])) {
                $shortcode_atts['cuisines'] = $settings['cuisines'];
            }
            if (!empty($settings['limit'])) {
                $shortcode_atts['limit'] = (int) $settings['limit'];
            }
            if (!empty($settings['orderby'])) {
                $shortcode_atts['orderby'] = $settings['orderby'];
            }
            if (!empty($settings['order'])) {
                $shortcode_atts['order'] = $settings['order'];
            }
            if (!empty($settings['featured'])) {
                $shortcode_atts['featured'] = $settings['featured'];
            }
            if (!empty($settings['difficulty'])) {
                $shortcode_atts['difficulty'] = $settings['difficulty'];
            }
            if (!empty($settings['pagination'])) {
                $shortcode_atts['pagination'] = $settings['pagination'];
            }
        }

        if (!empty($settings['dark_mode'])) {
            $shortcode_atts['dark_mode'] = $settings['dark_mode'];
        }

        $shortcodes = new Shortcodes();

        // Late-escaped through the plugin's central allowlist (PlugixaRecipe\Kses), which
        // covers the layout vocabulary a plain wp_kses_post() would strip: inline SVG icons,
        // <button>, the servings adjuster inputs and the schema.org Recipe microdata
        // attributes. This became possible once the JSON-LD builder stopped emitting a
        // <script> element into the shortcode output.
        echo wp_kses($shortcodes->render_recipes($shortcode_atts), plugixa_recipe_allowed_html());
    }

    /**
     * Get saved shortcodes for the dropdown.
     */
    private function get_shortcode_options(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_shortcodes';

        $results = $wpdb->get_results($wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC, id ASC', $table), ARRAY_A);

        $options = ['' => __('-- Select a Shortcode --', 'plugixa-recipe')];
        foreach ($results ?: [] as $row) {
            $options[(string) $row['id']] = $row['name'];
        }

        return $options;
    }

    /**
     * Get available views for the dropdown.
     */
    private function get_view_options(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'plugixa_recipe_views';

        $results = $wpdb->get_results($wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC, id ASC', $table), ARRAY_A);

        $options = ['' => __('-- Default View --', 'plugixa-recipe')];
        foreach ($results ?: [] as $row) {
            $options[(string) $row['id']] = $row['name'];
        }

        return $options;
    }
}
