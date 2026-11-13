<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * SEO Class - Outputs Open Graph and Twitter Card meta tags for recipe pages.
 */
class MetaTags {

    public function init(): void {
        add_action('wp_head', [$this, 'output_meta_tags'], 5);
    }

    /**
     * Output Open Graph and Twitter Card meta tags on single recipe pages.
     */
    public function output_meta_tags(): void {
        $recipe_data = $GLOBALS['plugixa_recipe_single_recipe_data'] ?? null;
        if (empty($recipe_data) || empty($recipe_data['recipe'])) {
            return;
        }

        $settings = (array) get_option('plugixa_recipe_settings', []);
        $recipe = $recipe_data['recipe'];
        $title = $recipe->title ?? '';
        $description = $recipe->description ?? '';
        $site_name = get_bloginfo('name');

        // Build SEO title from template or fallback.
        $seo_title = $this->replace_variables(
            $settings['default_seo_title'] ?? '',
            $title,
            $description,
            $site_name,
            $recipe
        );
        if (empty($seo_title)) {
            $seo_title = $title . ' | ' . $site_name;
        }

        // Build SEO description from template or fallback.
        $seo_description = $this->replace_variables(
            $settings['default_seo_description'] ?? '',
            $title,
            $description,
            $site_name,
            $recipe
        );
        if (empty($seo_description)) {
            $seo_description = wp_trim_words(wp_strip_all_tags($description), 30, '...');
        }

        // Get image URL.
        $image_url = '';
        if (!empty($recipe->image_id)) {
            $image_url = wp_get_attachment_image_url((int) $recipe->image_id, 'large') ?: '';
        }

        $page_url = get_permalink();

        echo "\n<!-- PlugixaRecipe SEO Meta Tags -->\n";

        // Canonical URL.
        echo '<link rel="canonical" href="' . esc_url($page_url) . '" />' . "\n";

        // Robots meta for non-published recipes.
        if (!empty($settings['noindex_draft_recipes'])) {
            $recipe_status = $recipe->status ?? 'published';
            if ($recipe_status !== 'published') {
                echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
            }
        }

        // Open Graph tags.
        if ($settings['enable_open_graph'] ?? true) {
            echo '<meta property="og:type" content="article" />' . "\n";
            echo '<meta property="og:title" content="' . esc_attr($seo_title) . '" />' . "\n";
            echo '<meta property="og:description" content="' . esc_attr($seo_description) . '" />' . "\n";
            echo '<meta property="og:url" content="' . esc_url($page_url) . '" />' . "\n";
            echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '" />' . "\n";
            if ($image_url) {
                echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";
            }
        }

        // Twitter Card tags.
        if ($settings['enable_twitter_cards'] ?? true) {
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr($seo_title) . '" />' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr($seo_description) . '" />' . "\n";
            if ($image_url) {
                echo '<meta name="twitter:image" content="' . esc_url($image_url) . '" />' . "\n";
            }
        }
    }

    /**
     * Replace template variables in SEO strings.
     */
    private function replace_variables(string $template, string $title, string $description, string $site_name, object $recipe): string {
        if (empty($template)) {
            return '';
        }

        $excerpt = wp_trim_words(wp_strip_all_tags($description), 25, '...');
        $prep_time = isset($recipe->prep_time) ? ((int) $recipe->prep_time) . ' min' : '';
        $cook_time = isset($recipe->cook_time) ? ((int) $recipe->cook_time) . ' min' : '';

        return str_replace(
            ['{recipe_title}', '{site_name}', '{excerpt}', '{prep_time}', '{cook_time}'],
            [$title, $site_name, $excerpt, $prep_time, $cook_time],
            $template
        );
    }
}
