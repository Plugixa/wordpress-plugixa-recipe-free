<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

use PlugixaRecipe\Modules\Recipes\Repositories\SinglePageRepository;

defined('ABSPATH') || exit;

/**
 * "Surprise me": a link that opens a recipe picked at random.
 *
 *   [plugixa_surprise_recipe label="Surprise me"]
 *
 * The link is the same for everyone and for ever - it names no recipe - so the page that
 * carries it can be cached. The choice is made when the link is followed: the request is
 * answered with a redirect that no cache may keep, or every visitor would be "surprised"
 * with the recipe the first one got.
 */
final class Surprise {

    /** The query argument the link carries. */
    public const ARG = 'plugixa_recipe_surprise';

    public function init(): void {
        add_shortcode('plugixa_surprise_recipe', [$this, 'render']);
        add_action('template_redirect', [$this, 'redirect'], 1);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function render($atts): string {
        $atts = shortcode_atts(['label' => ''], is_array($atts) ? $atts : [], 'plugixa_surprise_recipe');

        $label = sanitize_text_field((string) $atts['label']);
        if ('' === $label) {
            $label = __('Surprise me', 'plugixa-recipe');
        }

        wp_enqueue_style('plugixa-recipe-frontend', PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css', [], PLUGIXA_RECIPE_VERSION);

        // nofollow: a crawler following it would index a different recipe at this address each time.
        return sprintf(
            '<a class="pxr-jump__link pxr-surprise" href="%s" rel="nofollow">%s</a>',
            esc_url(add_query_arg(self::ARG, '1', home_url('/'))),
            esc_html($label)
        );
    }

    public function redirect(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only public link that changes nothing; a nonce would expire inside a cached page.
        if (!isset($_GET[self::ARG])) {
            return;
        }

        $recipe = (new SinglePageRepository())->random_published();

        nocache_headers();
        // No published recipe: the front page, rather than a link that goes nowhere.
        wp_safe_redirect($recipe ? plugixa_recipe_permalink((int) $recipe['id'], (string) $recipe['slug']) : home_url('/'), 302);
        exit;
    }
}
